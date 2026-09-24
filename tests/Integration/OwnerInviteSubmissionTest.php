<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\OwnerInviteSubmission;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistClientService;

/**
 * Форма «Новое приглашение» (07.K6a).
 *
 * Дефект владельца: двойное нажатие «Создать ссылку» создало два одинаковых
 * приглашения. Здесь второй POST с тем же ключом формы — это второй клик.
 */
#[Group('database')]
final class OwnerInviteSubmissionTest extends TestCase
{
    private const APP_URL = 'https://psytest.example';

    private Database $db;
    private TherapistClientService $clients;
    private TestInviteService $invites;
    private string $storagePath;

    /** @var array<string, mixed> */
    private array $session = [];

    /** @var list<string> */
    private array $inviteIds = [];

    /** @var list<string> */
    private array $clientIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->storagePath = sys_get_temp_dir() . '/psytest-owner-invite-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $lifecycle = new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath);
        $this->clients = new TherapistClientService($this->db, $lifecycle);
        $this->invites = new TestInviteService($this->db, new SessionManager($this->db));
        $this->session = [];
    }

    protected function tearDown(): void
    {
        foreach ($this->inviteIds as $id) {
            $this->db->delete('test_invites', 'id = ?', [$id]);
        }
        foreach ($this->clientIds as $id) {
            $this->db->delete('therapist_clients', 'id = ?', [$id]);
        }
        @rmdir($this->storagePath);
    }

    public function testDoubleSubmitWithTheSameKeyCreatesOneInviteAndRepeatsItsLink(): void
    {
        $form = $this->submission();
        $post = $this->post($form->issueKey(), ['owner_note' => 'Двойной клик ' . bin2hex(random_bytes(4))]);

        $first = $this->submission()->submit($post, $this->availableIds());
        $second = $this->submission()->submit($post, $this->availableIds());

        self::assertSame('success', $first['type']);
        self::assertArrayHasKey('invite_url', $first);
        $this->track($first);
        self::assertSame($first, $second, 'Второй клик показывает ту же ссылку, а не новую.');
        self::assertSame(1, $this->countInvitesWithNote((string) $post['owner_note']));
    }

    public function testPostWithoutOrWithAnUnknownKeyCreatesNothing(): void
    {
        $note = 'Без ключа ' . bin2hex(random_bytes(4));
        $form = $this->submission();
        $form->issueKey();

        $missing = $form->submit($this->post(null, ['owner_note' => $note]), $this->availableIds());
        $unknown = $form->submit($this->post(str_repeat('ab', 16), ['owner_note' => $note]), $this->availableIds());

        self::assertSame('error', $missing['type']);
        self::assertSame('error', $unknown['type']);
        self::assertArrayNotHasKey('invite_url', $missing);
        self::assertSame(0, $this->countInvitesWithNote($note));
    }

    public function testNewClientIsCreatedTogetherWithTheLinkedInvite(): void
    {
        $label = 'Новый из приглашения ' . bin2hex(random_bytes(4));
        $form = $this->submission();
        $post = $this->post($form->issueKey(), [
            'client_id' => OwnerInviteSubmission::NEW_CLIENT,
            'new_client_label' => '  ' . $label . '  ',
        ]);

        $flash = $form->submit($post, $this->availableIds());
        self::assertSame('success', $flash['type']);
        $invite = $this->track($flash);

        $client = $this->db->selectOne('SELECT id, label, note, email FROM therapist_clients WHERE label = ?', [$label]);
        self::assertIsArray($client, 'Карточка создана с обрезанным именем.');
        $this->clientIds[] = (string) $client['id'];
        self::assertNull($client['note']);
        self::assertNull($client['email']);
        self::assertSame($client['id'], $invite['client_id'], 'Приглашение привязано к новой карточке.');

        // Повтор не создаёт вторую карточку.
        $again = $this->submission()->submit($post, $this->availableIds());
        self::assertSame($flash, $again);
        self::assertSame(1, (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM therapist_clients WHERE label = ?', [$label])['n']);
    }

    public function testInvalidNewClientNameLeavesNoCardAndNoInviteAndKeepsTheKeyUsable(): void
    {
        $note = 'Ошибка имени ' . bin2hex(random_bytes(4));
        $clientsBefore = $this->clientCount();
        $form = $this->submission();
        $key = $form->issueKey();

        foreach (['', '   ', str_repeat('я', TherapistClientService::LABEL_MAX_LENGTH + 1)] as $badName) {
            $flash = $this->submission()->submit($this->post($key, [
                'client_id' => OwnerInviteSubmission::NEW_CLIENT,
                'new_client_label' => $badName,
                'owner_note' => $note,
            ]), $this->availableIds());
            self::assertSame('error', $flash['type']);
            self::assertStringContainsString('нового клиента', $flash['message']);
        }
        self::assertSame($clientsBefore, $this->clientCount());
        self::assertSame(0, $this->countInvitesWithNote($note));

        // Исправленная форма с тем же ключом проходит: ошибка ввода ключ не сжигает.
        $fixed = $this->submission()->submit($this->post($key, ['owner_note' => $note]), $this->availableIds());
        self::assertSame('success', $fixed['type']);
        $this->track($fixed);
        self::assertSame(1, $this->countInvitesWithNote($note));
    }

    public function testFailedInviteRollsBackTheNewClientCard(): void
    {
        $label = 'Откат карточки ' . bin2hex(random_bytes(4));
        $form = $this->submission();
        $key = $form->issueKey();
        // Методика прошла проверку формы, но исчезла к моменту записи.
        $vanished = 999_999;

        try {
            $form->submit($this->post($key, [
                'test_id' => (string) $vanished,
                'client_id' => OwnerInviteSubmission::NEW_CLIENT,
                'new_client_label' => $label,
            ]), [...$this->availableIds(), $vanished]);
            self::fail('Запись приглашения должна была упасть.');
        } catch (\InvalidArgumentException) {
        }

        self::assertFalse($this->db->inTransaction());
        self::assertNull(
            $this->db->selectOne('SELECT id FROM therapist_clients WHERE label = ?', [$label]),
            'Карточка без приглашения не остаётся.',
        );
        // Сбой не сжигает ключ: форму можно отправить снова.
        $retry = $this->submission()->submit($this->post($key), $this->availableIds());
        self::assertSame('success', $retry['type']);
        $this->track($retry);
    }

    public function testUnknownClientOrUnsupportedTestIsRejected(): void
    {
        $note = 'Чужие данные ' . bin2hex(random_bytes(4));
        $form = $this->submission();
        $key = $form->issueKey();

        $badClient = $form->submit($this->post($key, [
            'client_id' => '00000000-0000-4000-8000-000000000000',
            'owner_note' => $note,
        ]), $this->availableIds());
        $badTest = $form->submit($this->post($key, ['test_id' => '999999', 'owner_note' => $note]), $this->availableIds());

        self::assertSame('error', $badClient['type']);
        self::assertSame('error', $badTest['type']);
        self::assertSame(0, $this->countInvitesWithNote($note));
    }

    private function submission(): OwnerInviteSubmission
    {
        return new OwnerInviteSubmission(
            $this->db,
            $this->clients,
            $this->invites,
            new FormOnce($this->session),
            self::APP_URL,
        );
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, mixed>
     */
    private function post(?string $key, array $overrides = []): array
    {
        $post = [
            'test_id' => (string) $this->testId('bdi'),
            'client_id' => '',
            'new_client_label' => '',
            'owner_note' => '',
        ];
        if ($key !== null) {
            $post['form_key'] = $key;
        }

        return array_merge($post, $overrides);
    }

    /** @return list<int> */
    private function availableIds(): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->select('SELECT id FROM tests WHERE is_active = 1'),
        );
    }

    /**
     * @param array{type: string, message: string, invite_url?: string} $flash
     * @return array<string, mixed>
     */
    private function track(array $flash): array
    {
        $url = $flash['invite_url'] ?? '';
        self::assertStringStartsWith(self::APP_URL . '/invite/', $url);
        $token = substr($url, strlen(self::APP_URL . '/invite/'));
        $invite = $this->db->selectOne('SELECT id, client_id FROM test_invites WHERE token_hash = ?', [hash('sha256', $token)]);
        self::assertIsArray($invite);
        $this->inviteIds[] = (string) $invite['id'];

        return $invite;
    }

    private function countInvitesWithNote(string $note): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM test_invites WHERE owner_note = ?', [$note])['n'];
    }

    private function clientCount(): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM therapist_clients')['n'];
    }

    private function testId(string $slug): int
    {
        return (int) $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug])['id'];
    }
}
