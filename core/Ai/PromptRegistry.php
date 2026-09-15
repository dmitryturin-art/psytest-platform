<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;
use Ramsey\Uuid\Uuid;

/**
 * Реестр версионированных промптов.
 *
 * Файлы промптов лежат в prompts/<test>/<mode>.<kind>.v<N>.md, а manifest.json
 * хранит статус и то, какая версия ключа считается опубликованной. Откат —
 * это изменение номера версии в манифесте, а не правка текста промпта.
 *
 * PRODUCT_RULES §6:
 * - универсального промпта нет: неизвестный ключ не подменяется общим;
 * - владелец правит промпт как draft, проверяет на обезличенных fixtures,
 *   публикует и может откатить версию.
 *
 * Черновик никогда не отдаётся боевому потоку: published() вернёт null, пока
 * владелец не одобрил текст. Для проверки на фикстурах есть forReview().
 *
 * Уточнение владельца 26.08 (07.WP9): промпты редактируются из кабинета, без
 * доступа к файлам. Файлы остаются версионируемым исходным состоянием в Git и
 * никогда не переписываются отсюда; правки владельца ложатся в БД поверх них.
 * Реестр отдаёт версию из БД, если она есть, иначе файл. Без `Database`
 * (тесты, CLI без БД) реестр работает как прежде — чисто файловым.
 */
final class PromptRegistry
{
    private const MANIFEST = 'manifest.json';

    public const SOURCE_FILE = 'file';
    public const SOURCE_OWNER = 'owner';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(
        private readonly string $promptsPath,
        private readonly ?Database $db = null,
    ) {
    }

    public static function default(?Database $db = null): self
    {
        return new self(dirname(__DIR__, 2) . '/prompts', $db);
    }

    /**
     * Промпт, разрешённый к боевому использованию, или null.
     *
     * Возвращает null и когда ключа нет, и когда версия ещё черновик —
     * вызывающий обязан обработать отсутствие, а не получить «что-нибудь».
     */
    public function published(string $test, string $mode, string $kind): ?Prompt
    {
        $override = $this->publishedOverride($test, $mode, $kind);

        if ($override !== null) {
            // Владелец опубликовал конкретный номер. Статус здесь всегда
            // «published»: сам факт публикации из кабинета и есть одобрение.
            return $this->version($test, $mode, $kind, $override, Prompt::STATUS_PUBLISHED);
        }

        $prompt = $this->load($test, $mode, $kind);

        return $prompt !== null && $prompt->isPublished() ? $prompt : null;
    }

    /**
     * Текущая версия ключа в любом статусе — для проверки владельцем на
     * обезличенных фикстурах до публикации.
     */
    public function forReview(string $test, string $mode, string $kind): ?Prompt
    {
        return $this->load($test, $mode, $kind);
    }

    /**
     * Все объявленные ключи реестра.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->entries());
    }

    /**
     * Версии ключа по возрастанию: и файловые, и написанные в кабинете.
     *
     * @return list<int>
     */
    public function availableVersions(string $test, string $mode, string $kind): array
    {
        $versions = array_merge(
            $this->fileVersions($test, $mode, $kind),
            $this->ownerVersions($test, $mode, $kind),
        );

        $versions = array_values(array_unique($versions));
        sort($versions);

        return $versions;
    }

    /**
     * Версии с пометкой источника — для списка в кабинете.
     *
     * @return list<array{version: int, source: string, created_at: ?string, note: ?string}>
     */
    public function versionCatalog(string $test, string $mode, string $kind): array
    {
        $catalog = [];

        foreach ($this->fileVersions($test, $mode, $kind) as $version) {
            $catalog[$version] = [
                'version' => $version,
                'source' => self::SOURCE_FILE,
                'created_at' => null,
                'note' => null,
            ];
        }

        foreach ($this->ownerRows($test, $mode, $kind) as $row) {
            $version = (int) $row['version'];
            $catalog[$version] = [
                'version' => $version,
                'source' => self::SOURCE_OWNER,
                'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                'note' => isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
            ];
        }

        ksort($catalog);

        return array_values($catalog);
    }

    /**
     * Конкретная версия ключа: из БД, если она там есть, иначе файловая.
     *
     * Возвращает null, если такой версии нет вовсе — подставлять соседнюю
     * нельзя, иначе владелец правил бы не тот текст, который видит.
     */
    public function version(string $test, string $mode, string $kind, int $version, ?string $status = null): ?Prompt
    {
        $entry = $this->entries()[Prompt::keyFor($test, $mode, $kind)] ?? null;
        if ($entry === null) {
            return null;
        }

        $row = $this->ownerRow($test, $mode, $kind, $version);

        if ($row !== null) {
            return new Prompt(
                test: $test,
                mode: $mode,
                kind: $kind,
                version: $version,
                status: $status ?? Prompt::STATUS_PUBLISHED,
                text: trim((string) $row['text']),
                allowsOwnerContext: (bool) $row['allows_owner_context'],
                source: 'кабинет владельца'
                    . (isset($row['note']) && $row['note'] !== null && (string) $row['note'] !== ''
                        ? ': ' . (string) $row['note']
                        : ''),
            );
        }

        $text = @file_get_contents($this->filePath($test, $mode, $kind, $version));
        if ($text === false) {
            return null;
        }

        return new Prompt(
            test: $test,
            mode: $mode,
            kind: $kind,
            version: $version,
            status: $status ?? ((int) $entry['version'] === $version
                ? (string) $entry['status']
                : Prompt::STATUS_DRAFT),
            text: trim($text),
            allowsOwnerContext: (bool) ($entry['allows_owner_context'] ?? false),
            source: (string) ($entry['source'] ?? ''),
        );
    }

    /** Версия ключа, объявленная в manifest.json. */
    public function manifestVersion(string $test, string $mode, string $kind): ?int
    {
        $entry = $this->entries()[Prompt::keyFor($test, $mode, $kind)] ?? null;

        return $entry === null ? null : (int) $entry['version'];
    }

    /**
     * Опубликованная владельцем версия ключа или null («как в manifest.json»).
     */
    public function publishedOverride(string $test, string $mode, string $kind): ?int
    {
        if ($this->db === null) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT published_version FROM prompt_publications WHERE test = ? AND mode = ? AND kind = ?',
            [$test, $mode, $kind],
        );

        return $row === null || $row['published_version'] === null ? null : (int) $row['published_version'];
    }

    // ------------------------------------------------------------ редактирование

    /**
     * Новая версия ключа, написанная в кабинете.
     *
     * Номер — следующий за максимальным среди файловых и прежних версий из
     * кабинета: так номер остаётся сквозным и никогда не сталкивается с тем,
     * который позже появится в Git.
     *
     * Файлы при этом не трогаются: исходное состояние живёт в репозитории.
     *
     * @throws \RuntimeException если реестр создан без БД или ключа нет
     */
    public function createOwnerVersion(
        string $test,
        string $mode,
        string $kind,
        string $text,
        ?string $note,
        bool $allowsOwnerContext,
    ): int {
        $db = $this->requireDb();

        if (!isset($this->entries()[Prompt::keyFor($test, $mode, $kind)])) {
            throw new \RuntimeException('Такого ключа в реестре промптов нет.');
        }

        if (trim($text) === '') {
            throw new \RuntimeException('Текст промпта пуст.');
        }

        $versions = $this->availableVersions($test, $mode, $kind);
        $next = ($versions === [] ? 0 : max($versions)) + 1;

        $db->insert('prompt_versions', [
            'id' => Uuid::uuid4()->toString(),
            'test' => $test,
            'mode' => $mode,
            'kind' => $kind,
            'version' => $next,
            'text' => trim($text),
            'note' => $note === null || trim($note) === '' ? null : mb_substr(trim($note), 0, 255),
            'allows_owner_context' => $allowsOwnerContext ? 1 : 0,
        ]);

        return $next;
    }

    /**
     * Сделать версию опубликованной для новых заказов.
     *
     * Уже поставленные задания не меняются: у них заморожен снимок входа
     * (аудит R2).
     */
    public function publishVersion(string $test, string $mode, string $kind, int $version): void
    {
        $db = $this->requireDb();

        if ($this->version($test, $mode, $kind, $version) === null) {
            throw new \RuntimeException('Такой версии промпта нет.');
        }

        $this->savePublication($db, $test, $mode, $kind, $version);
    }

    /** Вернуться к версии из manifest.json (откат правок кабинета). */
    public function resetToManifest(string $test, string $mode, string $kind): void
    {
        $this->savePublication($this->requireDb(), $test, $mode, $kind, null);
    }

    private function savePublication(Database $db, string $test, string $mode, string $kind, ?int $version): void
    {
        $updated = $db->update(
            'prompt_publications',
            ['published_version' => $version],
            'test = ? AND mode = ? AND kind = ?',
            [$test, $mode, $kind],
        );

        if ($updated === 0 && $this->publicationRow($db, $test, $mode, $kind) === null) {
            $db->insert('prompt_publications', [
                'test' => $test,
                'mode' => $mode,
                'kind' => $kind,
                'published_version' => $version,
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function publicationRow(Database $db, string $test, string $mode, string $kind): ?array
    {
        return $db->selectOne(
            'SELECT test FROM prompt_publications WHERE test = ? AND mode = ? AND kind = ?',
            [$test, $mode, $kind],
        );
    }

    private function requireDb(): Database
    {
        if ($this->db === null) {
            throw new \RuntimeException('Реестр промптов открыт без БД: правки владельца недоступны.');
        }

        return $this->db;
    }

    // ------------------------------------------------------------------ чтение

    private function load(string $test, string $mode, string $kind): ?Prompt
    {
        $key = Prompt::keyFor($test, $mode, $kind);
        $entry = $this->entries()[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        $version = (int) $entry['version'];
        $file = $this->filePath($test, $mode, $kind, $version);
        $text = @file_get_contents($file);

        if ($text === false) {
            throw new \RuntimeException("Манифест ссылается на отсутствующий промпт: {$file}");
        }

        return new Prompt(
            test: $test,
            mode: $mode,
            kind: $kind,
            version: $version,
            status: (string) $entry['status'],
            text: trim($text),
            allowsOwnerContext: (bool) ($entry['allows_owner_context'] ?? false),
            source: (string) ($entry['source'] ?? ''),
        );
    }

    private function filePath(string $test, string $mode, string $kind, int $version): string
    {
        return sprintf('%s/%s/%s.%s.v%d.md', $this->promptsPath, $test, $mode, $kind, $version);
    }

    /** @return list<int> */
    private function fileVersions(string $test, string $mode, string $kind): array
    {
        $pattern = sprintf('%s/%s/%s.%s.v*.md', $this->promptsPath, $test, $mode, $kind);
        $versions = [];

        foreach (glob($pattern) ?: [] as $file) {
            if (preg_match('/\.v(\d+)\.md$/', $file, $m) === 1) {
                $versions[] = (int) $m[1];
            }
        }

        sort($versions);

        return $versions;
    }

    /** @return list<int> */
    private function ownerVersions(string $test, string $mode, string $kind): array
    {
        $versions = [];
        foreach ($this->ownerRows($test, $mode, $kind) as $row) {
            $versions[] = (int) $row['version'];
        }

        return $versions;
    }

    /** @return list<array<string, mixed>> */
    private function ownerRows(string $test, string $mode, string $kind): array
    {
        if ($this->db === null) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->db->select(
            'SELECT version, text, note, allows_owner_context, created_at
               FROM prompt_versions
              WHERE test = ? AND mode = ? AND kind = ?
              ORDER BY version',
            [$test, $mode, $kind],
        );

        return $rows;
    }

    /** @return array<string, mixed>|null */
    private function ownerRow(string $test, string $mode, string $kind, int $version): ?array
    {
        if ($this->db === null) {
            return null;
        }

        return $this->db->selectOne(
            'SELECT version, text, note, allows_owner_context, created_at
               FROM prompt_versions
              WHERE test = ? AND mode = ? AND kind = ? AND version = ?',
            [$test, $mode, $kind, $version],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function entries(): array
    {
        if ($this->manifest === null) {
            $path = $this->promptsPath . '/' . self::MANIFEST;
            $raw = @file_get_contents($path);

            if ($raw === false) {
                throw new \RuntimeException("Манифест промптов не найден: {$path}");
            }

            $this->manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }

        /** @var array<string, array<string, mixed>> $prompts */
        $prompts = $this->manifest['prompts'] ?? [];

        return $prompts;
    }
}
