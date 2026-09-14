<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiCompletion;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiReportRevisionService;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\ClientReportNotifier;
use PsyTest\Core\Database;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionLifecycleService;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TestInviteService;
use PsyTest\Core\TherapistClientService;
use PsyTest\Tests\Support\FailingMailer;
use PsyTest\Tests\Support\RecordingMailer;

/**
 * Письмо «разбор готов» (D-054, пакет 07.K5b).
 *
 * Проверяется не «ушло ли письмо», а ровно три обещания: оно уходит только по
 * решению специалиста и только после публикации, оно не выносит наружу ни
 * текста разбора, ни ключа доступа к результату, и недоступный SMTP не
 * превращается в ошибку кабинета.
 *
 * Внешний провайдер не вызывается: готовый отчёт пишется напрямую.
 */
#[Group('database')]
final class ClientReportNotifierTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private AiReportRepository $reports;
    private AiReportRevisionService $revisions;
    private TestInviteService $invites;
    private TherapistClientService $clients;
    private RecordingMailer $mailer;
    private ClientReportNotifier $notifier;
    private string $storagePath;

    /** @var list<string> */
    private array $clientIds = [];

    /** @var list<string> */
    private array $sessionIds = [];

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->reports = new AiReportRepository($this->db);
        $this->revisions = new AiReportRevisionService($this->db);
        $this->invites = new TestInviteService($this->db, $this->sessions);
        $this->storagePath = sys_get_temp_dir() . '/psytest-notify-' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0700, true);
        $this->clients = new TherapistClientService(
            $this->db,
            new SessionLifecycleService($this->db, new RetentionPolicy(180), $this->storagePath),
        );
        $this->mailer = new RecordingMailer();
        $this->notifier = new ClientReportNotifier($this->db, $this->mailer);
    }

    protected function tearDown(): void
    {
        foreach ($this->clientIds as $clientId) {
            $this->db->delete('therapist_clients', 'id = ?', [$clientId]);
        }
        foreach ($this->sessionIds as $sessionId) {
            $this->db->delete('test_sessions', 'id = ?', [$sessionId]);
        }
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    public function testPublishedReportAndAStoredEmailProduceOneShortLetterWithoutTheReportOrAnyKey(): void
    {
        $case = $this->caseWithPublishedReport('client@example.test', 'Разбор: выраженная тревога, рекомендации по шагам.');

        self::assertTrue($this->notifier->canNotify($case['session_id']));
        self::assertTrue($this->notifier->notify($case['session_id']));
        self::assertCount(1, $this->mailer->sent);

        $letter = $this->mailer->sent[0];
        self::assertSame('client@example.test', $letter['to']);
        self::assertSame('Ваш разбор готов', $letter['subject']);
        self::assertStringContainsString('Специалист подготовил разбор ваших результатов', $letter['body']);
        self::assertStringContainsString('Откройте свою страницу результата', $letter['body']);
        self::assertStringContainsString('просто проигнорируйте его', $letter['body']);

        // Ничего, что раскрывает клиента, содержание разбора или доступ к нему.
        self::assertStringNotContainsString('выраженная тревога', $letter['body']);
        self::assertStringNotContainsString('Клиент с адресом', $letter['body']);
        self::assertStringNotContainsString($case['session_token'], $letter['body']);
        self::assertStringNotContainsString('/result/', $letter['body']);
        self::assertStringNotContainsString('http', $letter['body']);

        $notified = $this->db->selectOne(
            'SELECT client_notified_at FROM ai_reports WHERE id = ?',
            [$case['report_id']],
        );
        self::assertNotNull($notified);
        self::assertNotNull($notified['client_notified_at']);
        self::assertSame((string) $notified['client_notified_at'], $this->notifier->lastNotifiedAt($case['session_id']));
    }

    public function testAnUnpublishedReportSendsNothingEvenWhenTheCardHasAnEmail(): void
    {
        $case = $this->caseWithPublishedReport('waiting@example.test', 'Одобренный текст.');
        $this->revisions->unpublish($case['report_id']);

        self::assertFalse($this->notifier->canNotify($case['session_id']));
        self::assertFalse($this->notifier->notify($case['session_id']));
        self::assertSame([], $this->mailer->sent);
    }

    public function testAPublishedReportWithoutAnEmailInTheCardSendsNothing(): void
    {
        $case = $this->caseWithPublishedReport('', 'Одобренный текст.');

        self::assertFalse($this->notifier->canNotify($case['session_id']));
        self::assertFalse($this->notifier->notify($case['session_id']));
        self::assertSame([], $this->mailer->sent);
    }

    public function testASecondNotificationWithinTenMinutesIsRefusedAndAllowedAgainAfterThem(): void
    {
        $case = $this->caseWithPublishedReport('repeat@example.test', 'Одобренный текст.');

        self::assertTrue($this->notifier->notify($case['session_id']));
        self::assertFalse($this->notifier->notify($case['session_id']), 'Двойной клик не должен слать второе письмо.');
        self::assertCount(1, $this->mailer->sent);

        // Сдвигаем отметку за границу окна: прошлое уведомление больше не мешает.
        $this->db->execute(
            'UPDATE ai_reports SET client_notified_at = NOW() - INTERVAL 11 MINUTE WHERE id = :id',
            ['id' => $case['report_id']],
        );
        self::assertTrue($this->notifier->notify($case['session_id']));
        self::assertCount(2, $this->mailer->sent);
    }

    public function testAnUnavailableMailServerIsAFalseAndALogLineInsteadOfAnException(): void
    {
        $case = $this->caseWithPublishedReport('unreachable@example.test', 'Одобренный текст.');
        $failing = new ClientReportNotifier($this->db, new FailingMailer());

        self::assertFalse($failing->notify($case['session_id']));

        // Неудачная отправка не занимает окно: специалист может повторить сразу.
        $row = $this->db->selectOne('SELECT client_notified_at FROM ai_reports WHERE id = ?', [$case['report_id']]);
        self::assertNotNull($row);
        self::assertNull($row['client_notified_at']);
        self::assertTrue($this->notifier->notify($case['session_id']));
    }

    public function testDeletingTheClientOrTheCaseLeavesNothingToNotifyAndDoesNotFail(): void
    {
        $case = $this->caseWithPublishedReport('gone@example.test', 'Одобренный текст.');
        self::assertTrue($this->clients->delete($case['client_id']));

        self::assertFalse($this->notifier->canNotify($case['session_id']));
        self::assertFalse($this->notifier->notify($case['session_id']));
        self::assertNull($this->notifier->lastNotifiedAt($case['session_id']));
        self::assertSame([], $this->mailer->sent);

        // Несуществующая и некорректная сессия ведут себя так же — молча.
        self::assertFalse($this->notifier->notify('not-a-uuid'));
        self::assertSame([], $this->mailer->sent);
    }

    /**
     * Кейс клиента с опубликованным понятным разбором.
     *
     * @return array{client_id: string, session_id: string, session_token: string, report_id: string}
     */
    private function caseWithPublishedReport(string $email, string $reportText): array
    {
        $clientId = $this->clients->create('Клиент с адресом', 'Заметка специалиста', $email);
        $this->clientIds[] = $clientId;

        $invite = $this->invites->create($this->testId('bdi'), 'Назначение', $clientId);
        $claim = $this->invites->claim($invite['token']);
        self::assertNotNull($claim);
        $sessionId = (string) $claim['session']['id'];
        $this->sessionIds[] = $sessionId;
        $this->sessions->completeSession($sessionId, ['total_score' => 7]);

        $prompt = new Prompt('bdi', 'individual', Prompt::KIND_CLEAR, 1, Prompt::STATUS_PUBLISHED, 'Текст промпта.', false, 'fixture');
        $job = $this->reports->request($sessionId, 'bdi', 'individual', Prompt::KIND_CLEAR, $prompt, ['scales' => []]);
        $reportId = (string) $job['id'];
        $this->reports->markReady($reportId, new AiCompletion($reportText, 'fixture/requested', 'fixture/served', 1, 2));

        $latest = $this->revisions->latest($reportId);
        self::assertNotNull($latest);
        self::assertTrue($this->revisions->publish($reportId, (string) $latest['id']));

        $session = $this->db->selectOne('SELECT session_token FROM test_sessions WHERE id = ?', [$sessionId]);
        self::assertNotNull($session);

        return [
            'client_id' => $clientId,
            'session_id' => $sessionId,
            'session_token' => (string) $session['session_token'],
            'report_id' => $reportId,
        ];
    }

    private function testId(string $slug): int
    {
        $row = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [$slug]);
        self::assertNotNull($row);

        return (int) $row['id'];
    }
}
