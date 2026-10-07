<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\InviteFilter;
use PsyTest\Core\OwnerInviteBulkAction;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Маршруты и разметка корзины карточек клиентов (07.K11). */
final class OwnerClientTrashContractTest extends TestCase
{
    private const ANNA = '22222222-2222-4222-8222-222222222222';
    private const BORIS = '33333333-3333-4333-8333-333333333333';

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__);
    }

    private function twig(): Environment
    {
        $twig = new Environment(new FilesystemLoader($this->root . '/templates'), ['cache' => false]);
        \PsyTest\Core\TemplateFunctions::register($twig);

        return $twig;
    }

    /** @param list<array<string, mixed>> $clients */
    private function listHtml(array $clients, string $view): string
    {
        return $this->twig()->render('owner-clients.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'clients' => $clients, 'client_view' => $view, 'active_count' => 2, 'trashed_count' => 1,
            'trash_days' => 30, 'return_url' => '/admin/clients' . ($view === 'trash' ? '?view=trash' : ''),
            'trash_form_key' => 'trash-key', 'client_form_key' => 'create-key',
            'invite_tests' => [['id' => 1, 'slug' => 'bdi', 'name' => 'BDI']], 'filter_test' => '', 'filter_query' => '',
        ]);
    }

    /** @return array<string, mixed> */
    private function client(string $id, string $label, ?string $trashedAt = null): array
    {
        return [
            'id' => $id, 'label' => $label, 'note' => null, 'created_at' => '2026-09-01 10:00:00',
            'assignment_count' => 3, 'completed_count' => 1, 'trashed_at' => $trashedAt,
            'purge_at' => $trashedAt === null ? null : '2026-11-06 10:00:00',
        ];
    }

    public function testRoutesArePostsAndTheControllerChecksTheOwner(): void
    {
        $routes = (string) file_get_contents($this->root . '/public/index.php');
        foreach (['trash' => 'trashClient', 'restore' => 'restoreClient', 'purge' => 'purgeClient', 'delete' => 'deleteClient'] as $path => $method) {
            self::assertStringContainsString("\$router->post('/admin/clients/{clientId}/{$path}', [OwnerController::class, '{$method}'])", $routes);
            self::assertStringNotContainsString("\$router->get('/admin/clients/{clientId}/{$path}'", $routes);
        }

        $controller = (string) file_get_contents($this->root . '/controllers/OwnerController.php');
        self::assertMatchesRegularExpression('/private function clientTrashAction\(string \$action, string \$clientId\): void\s*\{\s*if \(!\$this->requireOwner\(\)\)/', $controller);
        self::assertMatchesRegularExpression('/public function deleteClient\(string \$clientId\): void\s*\{\s*if \(!\$this->requireOwner\(\)\)/', $controller);
        self::assertStringContainsString("OwnerInviteBulkAction::safeReturn(\$post['return'] ?? null)", $controller);
        // Правка и новое назначение отклоняются на сервере, пока карточка в корзине.
        self::assertSame(2, substr_count($controller, '$this->requireActiveClient($clientId);'));
        self::assertStringContainsString("in_array(\$action, OwnerClientTrashAction::CONFIRMED_ACTIONS, true)", $controller);
    }

    public function testSafeReturnKeepsTheClientsListAndItsTrashTab(): void
    {
        foreach (['/admin/clients', '/admin/clients?view=trash', '/admin/clients?view=trash&q=anna'] as $ok) {
            self::assertSame($ok, OwnerInviteBulkAction::safeReturn($ok));
        }
        self::assertSame('/admin', OwnerInviteBulkAction::safeReturn('/admin/clients/../prompts'));
        self::assertSame('/admin', OwnerInviteBulkAction::safeReturn('//evil.example/admin/clients'));
    }

    public function testWorkingListHasTabsCountsMenuDialogAndTheSharedForm(): void
    {
        $html = $this->listHtml([$this->client(self::ANNA, 'Анна К.'), $this->client(self::BORIS, 'Борис Л.')], 'active');

        self::assertMatchesRegularExpression('#<nav class="view-tabs" aria-label="Вид списка">\s*<a class="view-tabs__tab" href="/admin/clients" aria-current="page">Рабочие <span class="view-tabs__count">2</span></a>\s*<a class="view-tabs__tab" href="/admin/clients\?view=trash">Корзина <span class="view-tabs__count">1</span></a>#', $html);
        self::assertStringNotContainsString('>Архив', $html);
        self::assertSame(2, substr_count($html, 'class="row-menu"'));
        self::assertStringContainsString('formaction="/admin/clients/' . self::ANNA . '/trash" data-client-confirm="trash" data-client-label="Анна К." data-assignments="3"', $html);
        self::assertStringNotContainsString('/restore"', $html);
        self::assertStringNotContainsString('/purge"', $html);
        self::assertStringContainsString('<use href="#i-trash">', $html);

        // Общая форма: POST, CSRF, одноразовый ключ, возврат, поля подтверждения.
        self::assertSame(1, substr_count($html, 'id="client-trash-form"'));
        self::assertStringContainsString('<form method="post" action="/admin/clients" id="client-trash-form" data-client-trash-form>', $html);
        self::assertStringContainsString('name="csrf_token" value="synthetic-csrf"', $html);
        self::assertStringContainsString('name="form_key" value="trash-key"', $html);
        self::assertStringContainsString('name="return" value="/admin/clients" data-return', $html);
        self::assertStringContainsString('name="confirmed" value="" data-confirmed', $html);
        self::assertStringContainsString('name="confirm_delete" value="" data-confirm-delete', $html);

        // Диалог один, тексты последствий и чекбокс необратимости.
        self::assertSame(1, substr_count($html, 'id="owner-client-dialog"'));
        self::assertStringContainsString('Карточка и её {assignments} переместятся в корзину. Через 30 дней всё будет удалено окончательно: ответы, результаты, разборы, выгрузки. До этого карточку можно восстановить.', $html);
        self::assertStringContainsString('Я понимаю, что удаление необратимо.', $html);
        self::assertStringContainsString('js/owner-client-trash.js', $html);
        self::assertStringContainsString('Новая карточка', $html);
    }

    public function testTrashTabShowsPurgeDateRestoreAndPurgeButNoCreateForm(): void
    {
        $html = $this->listHtml([$this->client(self::ANNA, 'Анна К.', '2026-10-07 10:00:00')], 'trash');

        self::assertStringContainsString('href="/admin/clients?view=trash" aria-current="page">Корзина', $html);
        self::assertStringContainsString('будет удалена 06.11.2026', $html);
        self::assertStringContainsString('formaction="/admin/clients/' . self::ANNA . '/restore"', $html);
        self::assertStringContainsString('formaction="/admin/clients/' . self::ANNA . '/purge" data-client-confirm="purge"', $html);
        self::assertStringNotContainsString('/trash" data-client-confirm', $html);
        self::assertStringNotContainsString('Новая карточка', $html);
        self::assertStringContainsString('name="return" value="/admin/clients?view=trash"', $html);
        self::assertStringContainsString('<input type="hidden" name="view" value="trash">', $html);
    }

    private function cardHtml(bool $trashed): string
    {
        $client = [
            'id' => self::ANNA, 'label' => 'Анна К.', 'note' => null, 'email' => null,
            'trashed_at' => $trashed ? '2026-10-07 10:00:00' : null,
            'purge_at' => $trashed ? '2026-11-06 10:00:00' : null,
        ];
        $assignment = [
            'id' => 'inv-1', 'status' => 'claimed', 'display_status' => 'completed', 'claimed_session_id' => '11111111-1111-4111-8111-111111111111',
            'test_name' => 'BDI', 'test_slug' => 'bdi', 'created_at' => '2026-09-27 10:00:00', 'owner_note' => null, 'completed_at' => '2026-09-28 10:00:00',
            'client_id' => self::ANNA, 'client_label' => 'Анна К.', 'archived_at' => null, 'trashed_at' => $trashed ? '2026-10-07 10:00:00' : null, 'purge_at' => null,
        ];

        return $this->twig()->render('owner-client.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'client' => $client, 'assignments' => [$assignment], 'history' => [], 'assignment_view' => 'active', 'assignment_test' => '',
            'active_count' => $trashed ? 0 : 1, 'archived_count' => 0, 'trashed_count' => $trashed ? 1 : 0, 'trash_days' => 30,
            'bulk_form_key' => 'bulk-key', 'attach_form_key' => 'attach-key', 'invite_form_key' => 'invite-key', 'trash_form_key' => 'trash-key',
            'return_url' => '/admin/clients/' . self::ANNA, 'clients' => [], 'invite_tests' => [['id' => 1, 'slug' => 'bdi', 'name' => 'BDI']],
            'client_trashed' => $trashed, 'card_assignment_count' => 1,
        ]);
    }

    public function testWorkingCardHasOneQuietTrashButtonInsteadOfTheOldDeleteForm(): void
    {
        $html = $this->cardHtml(false);

        self::assertStringContainsString('<h2 id="owner-client-trash-title">Корзина</h2>', $html);
        self::assertStringContainsString('class="btn btn-danger" form="client-trash-form" formaction="/admin/clients/' . self::ANNA . '/trash" data-client-confirm="trash" data-client-label="Анна К." data-assignments="1"', $html);
        self::assertStringNotContainsString('Удаление клиента', $html);
        self::assertStringNotContainsString('/admin/clients/' . self::ANNA . '/delete', $html);
        self::assertStringNotContainsString('owner-trash-banner', $html);
        // Привычные формы на месте.
        self::assertStringContainsString('/admin/clients/' . self::ANNA . '/update', $html);
        self::assertStringContainsString('/admin/clients/' . self::ANNA . '/invites/create', $html);
        self::assertStringContainsString('id="owner-attach-dialog"', $html);
    }

    public function testTrashedCardShowsTheBannerAndHidesEveryWritingControl(): void
    {
        $html = $this->cardHtml(true);

        self::assertStringContainsString('owner-notice owner-notice--error owner-trash-banner', $html);
        self::assertStringContainsString('Карточка в корзине, будет удалена 06.11.2026.', $html);
        self::assertStringContainsString('form="client-trash-form" formaction="/admin/clients/' . self::ANNA . '/restore">Восстановить', $html);
        self::assertStringContainsString('formaction="/admin/clients/' . self::ANNA . '/purge" data-client-confirm="purge"', $html);

        // Только чтение: ни правки, ни нового назначения, ни привязки, ни меню строки, ни кнопки «В корзину».
        self::assertStringNotContainsString('/update"', $html);
        self::assertStringNotContainsString('/invites/create"', $html);
        self::assertStringNotContainsString('id="owner-attach-dialog"', $html);
        self::assertStringNotContainsString('id="owner-invite-dialog"', $html);
        self::assertStringNotContainsString('data-row-menu', $html);
        self::assertStringNotContainsString('/trash" data-client-confirm', $html);
        self::assertStringNotContainsString('class="view-tabs"', $html);
        self::assertStringContainsString('href="/admin/invited-case/11111111-1111-4111-8111-111111111111">Открыть кейс', $html);
    }

    public function testNoJavaScriptConfirmationPageCarriesTheOneTimeKeyAndTheCheckbox(): void
    {
        $render = fn (string $action): string => $this->twig()->render('owner-client-confirm.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf', 'action' => $action,
            'client' => ['id' => self::ANNA, 'label' => 'Анна К.'], 'assignments_total' => 3,
            'return_url' => '/admin/clients', 'trash_form_key' => 'trash-key', 'trash_days' => 30,
        ]);

        $trash = $render('trash');
        self::assertStringContainsString('<form method="post" action="/admin/clients/' . self::ANNA . '/trash"', $trash);
        self::assertStringContainsString('name="form_key" value="trash-key"', $trash);
        self::assertStringContainsString('name="confirmed" value="1"', $trash);
        self::assertStringNotContainsString('confirm_delete', $trash);
        self::assertStringContainsString('Через 30 дней всё будет удалено окончательно', $trash);

        $purge = $render('purge');
        self::assertStringContainsString('action="/admin/clients/' . self::ANNA . '/purge"', $purge);
        self::assertStringContainsString('<input type="checkbox" name="confirm_delete" value="delete" required>', $purge);
        self::assertStringContainsString('без возможности восстановления', $purge);
    }

    public function testClientSelectsAndFiltersReadOnlyWorkingCards(): void
    {
        $service = (string) file_get_contents($this->root . '/core/TherapistClientService.php');
        self::assertStringContainsString("\$trashed ? 'clients.trashed_at IS NOT NULL' : 'clients.trashed_at IS NULL'", $service);

        // Выпадающие списки получают listForOwner() без корзины; проверка клиента — isActive().
        $controller = (string) file_get_contents($this->root . '/controllers/OwnerController.php');
        self::assertDoesNotMatchRegularExpression('/listForOwner\([^)]*true\)/', $controller);
        foreach (['core/OwnerInviteSubmission.php', 'core/OwnerInviteClientAttach.php', 'core/TherapistCaseService.php'] as $file) {
            self::assertStringContainsString('->isActive(', (string) file_get_contents($this->root . '/' . $file), $file);
        }
        $invites = (string) file_get_contents($this->root . '/core/TestInviteService.php');
        self::assertSame(2, substr_count($invites, "SELECT id FROM therapist_clients WHERE id = :id AND trashed_at IS NULL"));

        // Фильтр приглашений предлагает клиентов тем же рабочим списком.
        self::assertSame(
            InviteFilter::STATUS_TRASH,
            InviteFilter::fromQuery(['status' => 'trash'], ['bdi'])->status,
        );
    }

    public function testCleanupScriptPurgesTheClientTrashAfterTheInvites(): void
    {
        $script = (string) file_get_contents($this->root . '/bin/cleanup-sessions.php');
        self::assertStringContainsString('$clients->purgeTrash($trashThreshold)', $script);
        self::assertLessThan(
            strpos($script, '$clients->purgeTrash('),
            strpos($script, '$cases->purgeTrash('),
        );
        self::assertStringContainsString("'clients_purged' => \$clientTrash['clients']", $script);
        self::assertStringContainsString("'clients_purge_failed' => \$clientTrash['failed']", $script);
    }
}
