<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\OwnerCaseNoteUpdate;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;

/** Заметка специалиста в шапке карточки кейса (04.D3). */
#[Group('database')]
final class OwnerCaseNoteTest extends TestCase
{
    private Database $db;
    private TestInviteService $invites;
    /** @var array<string, mixed> */
    private array $store = [];
    private OwnerCaseNoteUpdate $form;

    /** @var list<string> */
    private array $inviteIds = [];
    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $sessions = new SessionManager($this->db);
        $this->invites = new TestInviteService($this->db, $sessions);
        $this->store = [];
        $this->form = new OwnerCaseNoteUpdate($this->invites, new FormOnce($this->store));
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        foreach ($this->sessionIds as $id) {
            $this->db->delete('test_sessions', 'id = ?', [$id]);
        }
    }

    public function testSavesTrimmedNoteAndClearsItWithAnEmptyString(): void
    {
        $session = $this->caseWithNote('Первичный приём');

        self::assertSame(TestInviteService::NOTE_SAVED, $this->invites->updateNote($session, "  Повторная встреча\nчерез месяц  "));
        self::assertSame("Повторная встреча\nчерез месяц", $this->noteOf($session));

        self::assertSame(TestInviteService::NOTE_UNCHANGED, $this->invites->updateNote($session, 'Повторная встреча' . "\n" . 'через месяц'));

        self::assertSame(TestInviteService::NOTE_SAVED, $this->invites->updateNote($session, '   '));
        self::assertNull($this->noteOf($session));
    }

    public function testCaseInTheTrashAndUnknownCaseAreReadOnly(): void
    {
        $session = $this->caseWithNote('Старая');
        $invite = $this->inviteOf($session);
        $this->invites->trash([$invite]);

        self::assertSame(TestInviteService::NOTE_MISSING, $this->invites->updateNote($session, 'Новая'));
        self::assertSame('Старая', $this->noteOf($session));
        self::assertSame(TestInviteService::NOTE_MISSING, $this->invites->updateNote('00000000-0000-4000-8000-000000000000', 'x'));
    }

    public function testFormKeepsTheOneTimeKeyAndRejectsReplayAndStaleKeys(): void
    {
        $session = $this->caseWithNote('');
        $key = $this->form->issueKey();

        $first = $this->form->submit($session, ['owner_note' => 'Запрос: сон', 'form_key' => $key]);
        self::assertSame(['type' => 'success', 'message' => 'Заметка сохранена.'], $first);
        self::assertSame('Запрос: сон', $this->noteOf($session));

        // Двойное нажатие: второй POST с тем же ключом получает итог первого и ничего не пишет.
        $this->db->update('test_invites', ['owner_note' => 'изменено вне формы'], 'claimed_session_id = ?', [$session]);
        $replay = $this->form->submit($session, ['owner_note' => 'Другое', 'form_key' => $key]);
        self::assertSame($first, $replay);
        self::assertSame('изменено вне формы', $this->noteOf($session));

        $stale = $this->form->submit($session, ['owner_note' => 'Другое', 'form_key' => 'unknown']);
        self::assertSame('error', $stale['type']);
        self::assertSame('изменено вне формы', $this->noteOf($session));
    }

    public function testTooLongNoteIsRefusedAndTheKeyStaysUsable(): void
    {
        $session = $this->caseWithNote('Было');
        $key = $this->form->issueKey();

        $refused = $this->form->submit($session, ['owner_note' => str_repeat('я', 1001), 'form_key' => $key]);
        self::assertSame('error', $refused['type']);
        self::assertStringContainsString('1000', $refused['message']);
        self::assertSame('Было', $this->noteOf($session));

        // Ровно 1000 символов после обрезки пробелов — допустимо, тем же ключом.
        $ok = $this->form->submit($session, ['owner_note' => '  ' . str_repeat('я', 1000) . '  ', 'form_key' => $key]);
        self::assertSame('success', $ok['type']);
        self::assertSame(str_repeat('я', 1000), $this->noteOf($session));

        $notString = $this->form->submit($session, ['owner_note' => ['x'], 'form_key' => $this->form->issueKey()]);
        self::assertSame('error', $notString['type']);
    }

    public function testFormRefusesTheTrashWithoutChangingTheNote(): void
    {
        $session = $this->caseWithNote('В корзине');
        $this->invites->trash([$this->inviteOf($session)]);

        $result = $this->form->submit($session, ['owner_note' => 'Новая', 'form_key' => $this->form->issueKey()]);
        self::assertSame('error', $result['type']);
        self::assertSame('В корзине', $this->noteOf($session));
    }

    private function caseWithNote(string $note): string
    {
        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'beck-anxiety'");
        self::assertIsArray($test, 'Предусловие: методика BAI зарегистрирована.');
        $session = (new SessionManager($this->db))->createSession((int) $test['id']);
        $sessionId = (string) $session['id'];
        $this->sessionIds[] = $sessionId;
        $this->db->update('test_sessions', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s')], 'id = ?', [$sessionId]);
        $this->inviteIds[] = $this->invites->bindExistingSession($sessionId, (int) $test['id'], null, $note);

        return $sessionId;
    }

    private function inviteOf(string $sessionId): string
    {
        return (string) $this->db->selectOne('SELECT id FROM test_invites WHERE claimed_session_id = ?', [$sessionId])['id'];
    }

    private function noteOf(string $sessionId): ?string
    {
        $row = $this->db->selectOne('SELECT owner_note FROM test_invites WHERE claimed_session_id = ?', [$sessionId]);
        self::assertIsArray($row);

        return $row['owner_note'] === null ? null : (string) $row['owner_note'];
    }
}
