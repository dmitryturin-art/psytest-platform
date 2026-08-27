<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

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
 */
final class PromptRegistry
{
    private const MANIFEST = 'manifest.json';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $promptsPath)
    {
    }

    public static function default(): self
    {
        return new self(dirname(__DIR__, 2) . '/prompts');
    }

    /**
     * Промпт, разрешённый к боевому использованию, или null.
     *
     * Возвращает null и когда ключа нет, и когда версия ещё черновик —
     * вызывающий обязан обработать отсутствие, а не получить «что-нибудь».
     */
    public function published(string $test, string $mode, string $kind): ?Prompt
    {
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
     * Версии, лежащие на диске для ключа, по возрастанию.
     *
     * @return list<int>
     */
    public function availableVersions(string $test, string $mode, string $kind): array
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

    private function load(string $test, string $mode, string $kind): ?Prompt
    {
        $key = Prompt::keyFor($test, $mode, $kind);
        $entry = $this->entries()[$key] ?? null;

        if ($entry === null) {
            return null;
        }

        $version = (int) $entry['version'];
        $file = sprintf('%s/%s/%s.%s.v%d.md', $this->promptsPath, $test, $mode, $kind, $version);
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
