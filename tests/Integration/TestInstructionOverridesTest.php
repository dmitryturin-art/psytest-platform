<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Controllers\TestController;
use PsyTest\Core\Database;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInstructionOverrides;
use PsyTest\Core\TestInviteService;
use PsyTest\Modules\BeckDepression\BeckDepressionModule;

/**
 * Инструкция респонденту, изменённая из кабинета (07.K15): файл методики —
 * исходное состояние, правка владельца лежит в БД поверх него.
 */
#[Group('database')]
final class TestInstructionOverridesTest extends TestCase
{
    private const SLUG = 'bdi';

    private Database $db;
    private TestInstructionOverrides $overrides;

    /** @var list<string> */
    private array $inviteIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->overrides = new TestInstructionOverrides($this->db);
        $this->db->delete('test_instruction_overrides', 'test_slug = ?', [self::SLUG]);
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        $this->db->delete('test_instruction_overrides', 'test_slug = ?', [self::SLUG]);
        $this->db->delete('activity_log', 'action = ?', [TestInstructionOverrides::AUDIT_ACTION]);
    }

    public function testWithoutAnOverrideTheModuleInstructionIsUsed(): void
    {
        $module = new BeckDepressionModule();

        self::assertNull($this->overrides->get(self::SLUG));
        self::assertNull($this->overrides->updatedAt(self::SLUG));
        self::assertSame($module->getInstruction(), $this->overrides->resolve($module));
    }

    public function testSavedOverrideIsReturnedTrimmedAndWinsOverTheFile(): void
    {
        $this->overrides->save(self::SLUG, ["  Первый абзац.  ", '', "Второй <b>абзац</b>.\nС переносом."]);

        self::assertSame(['Первый абзац.', "Второй <b>абзац</b>.\nС переносом."], $this->overrides->get(self::SLUG));
        self::assertSame(['Первый абзац.', "Второй <b>абзац</b>.\nС переносом."], $this->overrides->resolve(new BeckDepressionModule()));
        self::assertNotNull($this->overrides->updatedAt(self::SLUG));
    }

    public function testSavingAgainReplacesTheTextAndResetReturnsTheFile(): void
    {
        $this->overrides->save(self::SLUG, ['Один.']);
        $this->overrides->save(self::SLUG, ['Другой.']);
        self::assertSame(['Другой.'], $this->overrides->get(self::SLUG));

        $this->overrides->reset(self::SLUG);

        self::assertNull($this->overrides->get(self::SLUG));
        self::assertSame((new BeckDepressionModule())->getInstruction(), $this->overrides->resolve(new BeckDepressionModule()));
        // Повторный сброс безопасен.
        $this->overrides->reset(self::SLUG);
    }

    public function testChangesAreAuditedWithoutTheText(): void
    {
        $this->overrides->save(self::SLUG, ['Секретный текст правки.']);
        $this->overrides->reset(self::SLUG);

        $rows = $this->db->select('SELECT details FROM activity_log WHERE action = ? ORDER BY id', [TestInstructionOverrides::AUDIT_ACTION]);
        self::assertCount(2, $rows);
        self::assertSame('saved', json_decode((string) $rows[0]['details'], true)['change']);
        self::assertSame('reset', json_decode((string) $rows[1]['details'], true)['change']);
        self::assertStringNotContainsString('Секретный', (string) $rows[0]['details']);
    }

    public function testValidationErrorsAreRussianAndNothingIsSaved(): void
    {
        foreach ([
            [[], 'не может быть пустой'],
            [['  ', ''], 'не может быть пустой'],
            [array_fill(0, TestInstructionOverrides::MAX_PARAGRAPHS + 1, 'Абзац.'), 'Слишком много абзацев'],
            [[str_repeat('я', TestInstructionOverrides::MAX_LENGTH + 1)], 'слишком длинный'],
        ] as [$paragraphs, $message]) {
            try {
                $this->overrides->save(self::SLUG, $paragraphs);
                self::fail('Expected a validation error for: ' . $message);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }

        self::assertNull($this->overrides->get(self::SLUG));
        // Ровно на границе — принимается.
        $this->overrides->save(self::SLUG, array_fill(0, TestInstructionOverrides::MAX_PARAGRAPHS, str_repeat('я', TestInstructionOverrides::MAX_LENGTH)));
        self::assertCount(TestInstructionOverrides::MAX_PARAGRAPHS, $this->overrides->get(self::SLUG) ?? []);
    }

    public function testPublicInvitePageShowsTheOverrideAndEscapesIt(): void
    {
        $this->overrides->save(self::SLUG, ['Правка владельца для проверки.', 'Тег <script>alert(1)</script> не работает.']);

        $sessions = new SessionManager($this->db);
        $invite = (new TestInviteService($this->db, $sessions))->create(
            (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [self::SLUG])['id'],
            '',
        );
        $this->inviteIds[] = $invite['id'];

        ob_start();
        (new TestController())->invite($invite['token']);
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Правка владельца для проверки.', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        foreach ((new BeckDepressionModule())->getInstruction() as $original) {
            self::assertStringNotContainsString($original, $html, 'The file text is replaced, not appended.');
        }

        $this->overrides->reset(self::SLUG);
        ob_start();
        (new TestController())->invite($invite['token']);
        $restored = (string) ob_get_clean();
        self::assertStringNotContainsString('Правка владельца для проверки.', $restored);
        self::assertStringContainsString((new BeckDepressionModule())->getInstruction()[0], htmlspecialchars_decode($restored, ENT_QUOTES | ENT_HTML5) ?: $restored);
    }
}
