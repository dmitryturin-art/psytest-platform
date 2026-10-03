<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\FormOnce;
use PsyTest\Core\OwnerClientSubmission;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\TherapistClientService;

/**
 * Форма «Новая карточка» на странице «Клиенты» (07.K6b).
 *
 * Второй POST с тем же ключом формы — второй клик: вторая карточка не
 * создаётся, ответ ведёт в ту же карточку.
 */
#[Group('database')]
final class OwnerClientSubmissionTest extends TestCase
{
    private Database $db;
    private TherapistClientService $clients;
    private string $storagePath;

    /** @var array<string, mixed> */
    private array $session = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->storagePath = sys_get_temp_dir() . '/psytest-owner-client-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->clients = new TherapistClientService(
            $this->db,
            new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath),
        );
        $this->session = [];
    }

    protected function tearDown(): void
    {
        $this->db->execute("DELETE FROM therapist_clients WHERE label LIKE 'K6b карточка %'");
        @rmdir($this->storagePath);
    }

    public function testDoubleSubmitWithTheSameKeyCreatesOneCard(): void
    {
        $label = $this->label();
        $post = ['form_key' => $this->submission()->issueKey(), 'label' => $label, 'note' => 'Заметка', 'email' => ''];

        $first = $this->submission()->submit($post);
        $second = $this->submission()->submit($post);

        self::assertSame('success', $first['type']);
        self::assertStringStartsWith('/admin/clients/', $first['redirect']);
        self::assertSame($first, $second, 'Второй клик ведёт в ту же карточку.');
        self::assertSame(1, $this->cardCount($label));

        // Новая отрисовка формы — новый ключ: вторая карточка с той же подписью возможна.
        $again = $this->submission()->submit(['form_key' => $this->submission()->issueKey()] + $post);
        self::assertSame('success', $again['type']);
        self::assertNotSame($first['redirect'], $again['redirect']);
        self::assertSame(2, $this->cardCount($label));
    }

    public function testPostWithoutOrWithAnUnknownKeyCreatesNothing(): void
    {
        $label = $this->label();
        $this->submission()->issueKey();

        $missing = $this->submission()->submit(['label' => $label, 'note' => '', 'email' => '']);
        $unknown = $this->submission()->submit(['form_key' => str_repeat('ef', 16), 'label' => $label, 'note' => '', 'email' => '']);

        self::assertSame('error', $missing['type']);
        self::assertSame('error', $unknown['type']);
        self::assertSame('/admin/clients', $missing['redirect']);
        self::assertSame(0, $this->cardCount($label));
    }

    public function testInvalidInputKeepsTheKeyUsable(): void
    {
        $label = $this->label();
        $key = $this->submission()->issueKey();

        foreach ([['label' => '  '], ['email' => 'не адрес'], ['note' => str_repeat('я', TherapistClientService::NOTE_MAX_LENGTH + 1)]] as $bad) {
            $flash = $this->submission()->submit($bad + ['form_key' => $key, 'label' => $label, 'note' => '', 'email' => '']);
            self::assertSame('error', $flash['type']);
            self::assertSame('/admin/clients', $flash['redirect']);
        }
        self::assertSame(0, $this->cardCount($label));

        $fixed = $this->submission()->submit(['form_key' => $key, 'label' => $label, 'note' => '', 'email' => '']);
        self::assertSame('success', $fixed['type'], 'Ошибка ввода ключ не сжигает.');
        self::assertSame(1, $this->cardCount($label));
    }

    private function submission(): OwnerClientSubmission
    {
        return new OwnerClientSubmission($this->clients, new FormOnce($this->session));
    }

    private function label(): string
    {
        return 'K6b карточка ' . bin2hex(random_bytes(4));
    }

    private function cardCount(string $label): int
    {
        return (int) $this->db->selectOne('SELECT COUNT(*) AS n FROM therapist_clients WHERE label = ?', [$label])['n'];
    }
}
