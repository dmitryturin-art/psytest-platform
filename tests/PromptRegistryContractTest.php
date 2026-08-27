<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;

/**
 * Контракт реестра промптов (PRODUCT_RULES §6).
 *
 * Главное, что здесь стережётся: универсального промпта нет, черновик не
 * попадает в боевой поток, а профессиональный вариант не смягчён общим фильтром.
 */
final class PromptRegistryContractTest extends TestCase
{
    private const PROMPTS_PATH = __DIR__ . '/../prompts';

    private function registry(): PromptRegistry
    {
        return new PromptRegistry(self::PROMPTS_PATH);
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function declaredKeys(): array
    {
        $parsed = [];
        foreach ($this->registry()->keys() as $key) {
            $parts = array_map('trim', explode('|', $key));
            self::assertCount(3, $parts, "Ключ «{$key}» должен быть «методика | режим | вид отчёта».");
            $parsed[] = $parts;
        }

        return $parsed;
    }

    public function testEveryDeclaredKeyHasItsPromptFileOnDisk(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $prompt = $this->registry()->forReview($test, $mode, $kind);

            self::assertInstanceOf(Prompt::class, $prompt);
            self::assertNotSame('', $prompt->text, "Промпт «{$prompt->key()}» пуст.");
        }
    }

    public function testNoPromptFileBelongsToAnUndeclaredKey(): void
    {
        // Прежние версии остаются на диске намеренно: откат — это возврат
        // манифеста на меньший номер версии. Проверяется другое: чтобы на диске
        // не было промпта, чей ключ вообще не объявлен в реестре.
        $declaredKeys = [];
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $declaredKeys[] = sprintf('%s/%s.%s', $test, $mode, $kind);
        }

        foreach (glob(self::PROMPTS_PATH . '/*/*.md') ?: [] as $file) {
            $relative = implode('/', array_slice(explode('/', $file), -2));

            self::assertSame(
                1,
                preg_match('#^(.+/.+)\.v\d+\.md$#', $relative, $matches),
                "Имя {$relative} не соответствует схеме <методика>/<режим>.<вид>.v<N>.md.",
            );
            self::assertContains(
                $matches[1],
                $declaredKeys,
                "Файл {$relative} принадлежит ключу, которого нет в реестре.",
            );
        }
    }

    public function testPreviousVersionsStayOnDiskForRollback(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $current = $this->registry()->forReview($test, $mode, $kind)?->version;
            $available = $this->registry()->availableVersions($test, $mode, $kind);

            if ($current > 1) {
                self::assertContains(
                    $current - 1,
                    $available,
                    "Откат «{$test} | {$mode} | {$kind}» невозможен: предыдущей версии нет на диске.",
                );
            }
        }
    }

    public function testUnknownKeyNeverFallsBackToAGenericPrompt(): void
    {
        $registry = $this->registry();

        self::assertNull($registry->forReview('hads', 'individual', 'professional'));
        self::assertNull($registry->forReview('smil', 'pair', 'professional'));
        self::assertNull($registry->forReview('lazarus', 'individual', 'friendly'));
        self::assertNull($registry->published('unknown', 'individual', 'clear'));
    }

    public function testDraftIsNeverServedToTheProductionPath(): void
    {
        $registry = $this->registry();

        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $review = $registry->forReview($test, $mode, $kind);
            self::assertInstanceOf(Prompt::class, $review);

            if ($review->status === Prompt::STATUS_DRAFT) {
                self::assertNull(
                    $registry->published($test, $mode, $kind),
                    "Черновик «{$review->key()}» не должен отдаваться боевому потоку.",
                );
            }
        }
    }

    public function testPublicationCarriesTheOwnerApprovalThatAuthorisedIt(): void
    {
        // Клиническую формулировку публикует владелец, а не разработчик. Статус
        // published без записи о том, кто и когда одобрил текст, недопустим:
        // иначе одобрение нельзя ни проверить, ни оспорить позже.
        $manifest = json_decode(
            (string) file_get_contents(self::PROMPTS_PATH . '/manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $published = 0;
        foreach ($manifest['prompts'] as $key => $entry) {
            if (($entry['status'] ?? null) !== Prompt::STATUS_PUBLISHED) {
                continue;
            }

            $published++;
            self::assertNotEmpty($entry['approved_by'] ?? '', "«{$key}» опубликован без указания, кто одобрил текст.");
            self::assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}$/',
                (string) ($entry['approved_at'] ?? ''),
                "«{$key}» опубликован без даты одобрения.",
            );
        }

        self::assertGreaterThan(0, $published, 'Предусловие: в реестре есть хотя бы один опубликованный промпт.');
    }

    public function testSmilPromptsCoverAdditionalScalesInBothReportKinds(): void
    {
        // Замечание владельца 26.08: дополнительные шкалы разбираются не только
        // в профессиональном заключении, но и в понятном разборе.
        foreach ([Prompt::KIND_PROFESSIONAL, Prompt::KIND_CLEAR] as $kind) {
            $prompt = $this->registry()->forReview('smil', 'individual', $kind);
            self::assertInstanceOf(Prompt::class, $prompt);

            self::assertMatchesRegularExpression(
                '/дополнительн\w*\s+шкал/iu',
                $prompt->text,
                "«{$prompt->key()}» обязан разбирать дополнительные шкалы.",
            );
        }
    }

    public function testRollbackIsAVersionChangeNotATextEdit(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $versions = $this->registry()->availableVersions($test, $mode, $kind);
            $current = $this->registry()->forReview($test, $mode, $kind)?->version;

            self::assertNotEmpty($versions);
            self::assertContains($current, $versions, 'Манифест обязан указывать на существующую версию.');
            self::assertSame(array_values(array_unique($versions)), $versions, 'Версии не должны повторяться.');
        }
    }

    public function testEveryPromptCarriesTheSharedTechnicalRules(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $prompt = $this->registry()->forReview($test, $mode, $kind);
            self::assertInstanceOf(Prompt::class, $prompt);

            self::assertStringContainsString('Используй только переданные показатели', $prompt->text);
            self::assertStringContainsString('Не ставь диагнозы', $prompt->text);
            self::assertStringContainsString('не округляй', $prompt->text);
        }
    }

    public function testProfessionalPromptsAreNotSoftenedAndClearOnesAreGentle(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $prompt = $this->registry()->forReview($test, $mode, $kind);
            self::assertInstanceOf(Prompt::class, $prompt);

            if ($kind === Prompt::KIND_PROFESSIONAL) {
                self::assertStringContainsString('Не смягчай профессионально значимый материал', $prompt->text);
            } else {
                // Формулировка запрета может отличаться падежом, важен сам запрет.
                self::assertMatchesRegularExpression('/не\s+запугива/iu', $prompt->text);
            }
        }
    }

    public function testProfessionalPromptsAcceptOwnerContextAndClearOnesDoNot(): void
    {
        // Клинический контекст пишет специалист и адресует специалисту.
        // В понятный клиентский разбор он не подмешивается.
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $prompt = $this->registry()->forReview($test, $mode, $kind);
            self::assertInstanceOf(Prompt::class, $prompt);

            self::assertSame(
                $kind === Prompt::KIND_PROFESSIONAL,
                $prompt->allowsOwnerContext,
                "«{$prompt->key()}»: право на клинический контекст должно совпадать с видом отчёта.",
            );
        }
    }

    public function testSmilPromptsPutValidityScalesFirst(): void
    {
        foreach ([Prompt::KIND_PROFESSIONAL, Prompt::KIND_CLEAR] as $kind) {
            $prompt = $this->registry()->forReview('smil', 'individual', $kind);
            self::assertInstanceOf(Prompt::class, $prompt);

            self::assertStringContainsString('достоверн', $prompt->text, 'СМИЛ читается только после оценки достоверности профиля.');
        }
    }

    public function testEveryKeyDeclaresWhereItsTextCameFrom(): void
    {
        foreach ($this->declaredKeys() as [$test, $mode, $kind]) {
            $prompt = $this->registry()->forReview($test, $mode, $kind);

            self::assertNotSame('', $prompt?->source, 'Происхождение клинического текста должно быть прослеживаемым.');
        }
    }
}
