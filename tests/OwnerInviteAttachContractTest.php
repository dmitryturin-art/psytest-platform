<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\OwnerInviteBulkAction;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/** Разметка и маршрут привязки приглашения к клиенту (07.K9). */
final class OwnerInviteAttachContractTest extends TestCase
{
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

    public function testRouteIsAPostAndTheControllerChecksTheOwner(): void
    {
        $routes = (string) file_get_contents($this->root . '/public/index.php');
        self::assertStringContainsString("\$router->post('/admin/invites/attach-client', [OwnerController::class, 'attachInviteClient'])", $routes);
        self::assertStringNotContainsString("\$router->get('/admin/invites/attach-client'", $routes);

        $controller = (string) file_get_contents($this->root . '/controllers/OwnerController.php');
        self::assertMatchesRegularExpression('/public function attachInviteClient\(\): void\s*\{\s*if \(!\$this->requireOwner\(\)\)/', $controller);
        self::assertStringContainsString("OwnerInviteBulkAction::safeReturn(\$post['return'] ?? null)", $controller);
    }

    public function testSafeReturnAllowsOnlyCabinetPages(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        foreach (['/admin', '/admin?status=trash', '/admin/invited-case/' . $uuid, '/admin/clients/' . $uuid] as $ok) {
            self::assertSame($ok, OwnerInviteBulkAction::safeReturn($ok));
        }
        foreach (['https://evil.example/admin', '//evil.example', '/admin/prompts', '/admin/invited-case/x', null, ['/admin']] as $bad) {
            self::assertSame('/admin', OwnerInviteBulkAction::safeReturn($bad));
        }
    }

    public function testListMenuOffersAttachOrChangeAndTheDialogCarriesTheOneTimeKey(): void
    {
        $row = static fn (string $id, ?string $clientId, ?string $label, array $extra = []): array => $extra + [
            'id' => $id, 'status' => 'claimed', 'display_status' => 'completed', 'claimed_session_id' => '11111111-1111-4111-8111-111111111111',
            'test_name' => 'BDI ' . $id, 'test_slug' => 'bdi', 'created_at' => '2026-09-27 10:00:00', 'owner_note' => null,
            'client_label' => $label, 'client_id' => $clientId, 'archived_at' => null, 'trashed_at' => null, 'purge_at' => null,
        ];
        $html = $this->twig()->render('owner-dashboard.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'invites' => [
                $row('loose-1', null, null),
                $row('owned-1', '22222222-2222-4222-8222-222222222222', 'Анна К.'),
                $row('trash-1', null, null, ['trashed_at' => '2026-09-30 10:00:00', 'purge_at' => '2026-10-30 10:00:00']),
            ],
            'invite_tests' => [['id' => 1, 'slug' => 'bdi', 'name' => 'BDI']],
            'clients' => [['id' => '22222222-2222-4222-8222-222222222222', 'label' => 'Анна К.']],
            'filter' => \PsyTest\Core\InviteFilter::fromQuery([], ['bdi']),
            'filter_url' => '/admin', 'invite_counts' => ['active' => 2, 'archived' => 0, 'trash' => 1],
            'invite_form_key' => 'k', 'bulk_form_key' => 'bulk-key', 'attach_form_key' => 'attach-key', 'trash_days' => 30,
        ]);

        // Строка без клиента — «Привязать», с клиентом — «Сменить», в корзине — ни того ни другого.
        self::assertMatchesRegularExpression('#formaction="/admin/invites/attach-client" name="invite_id" value="loose-1" data-attach data-client-id="" data-client-label=""[^>]*>.*?<span>Привязать к клиенту</span>#s', $html);
        self::assertMatchesRegularExpression('#formaction="/admin/invites/attach-client" name="invite_id" value="owned-1" data-attach data-client-id="22222222-2222-4222-8222-222222222222" data-client-label="Анна К."[^>]*>.*?<span>Сменить клиента</span>#s', $html);
        self::assertStringNotContainsString('name="invite_id" value="trash-1" data-attach', $html);
        self::assertStringContainsString('<use href="#i-client">', $html);

        // Один диалог: своя форма POST, CSRF, одноразовый ключ, возврат на тот же список.
        self::assertSame(1, substr_count($html, 'id="owner-attach-dialog"'));
        self::assertStringContainsString('<form method="post" action="/admin/invites/attach-client"', $html);
        self::assertStringContainsString('name="form_key" value="attach-key"', $html);
        self::assertStringContainsString('name="return" value="/admin" data-attach-return', $html);
        self::assertStringContainsString('<option value="__new__">Новый клиент…</option>', $html);
        self::assertStringContainsString('name="confirm_change" value="1" data-attach-confirm-input', $html);
        self::assertStringContainsString('js/owner-attach.js', $html);
    }

    public function testNoJavaScriptPageCarriesTheSameFormAndAskForConfirmationOnChange(): void
    {
        $render = fn (array $invite): string => $this->twig()->render('owner-invite-attach.twig', [
            'appName' => 'PsyTest', 'basePath' => '', 'csrf_token' => 'synthetic-csrf',
            'invite' => $invite, 'return_url' => '/admin/invited-case/11111111-1111-4111-8111-111111111111',
            'clients' => [['id' => '22222222-2222-4222-8222-222222222222', 'label' => 'Анна К.']],
            'attach_form_key' => 'attach-key',
        ]);

        $loose = $render(['id' => 'i1', 'client_id' => null, 'client_label' => null, 'claimed_session_id' => null]);
        self::assertStringContainsString('<h1 id="owner-attach-page-title">Привязать к клиенту</h1>', $loose);
        self::assertStringContainsString('name="invite_id" value="i1"', $loose);
        self::assertStringContainsString('name="form_key" value="attach-key"', $loose);
        self::assertStringContainsString('data-attach-confirm hidden', $loose);

        $owned = $render(['id' => 'i2', 'client_id' => '22222222-2222-4222-8222-222222222222', 'client_label' => 'Анна К.', 'claimed_session_id' => 's']);
        self::assertStringContainsString('<h1 id="owner-attach-page-title">Сменить клиента</h1>', $owned);
        self::assertStringNotContainsString('data-attach-confirm hidden', $owned);
        self::assertStringContainsString('data-attach-confirm-input required', $owned);
        self::assertStringContainsString('Кейс будет перенесён из карточки «<span data-attach-from>Анна К.</span>» в выбранную. Результат и ссылка не меняются.', $owned);
        self::assertStringContainsString('(сейчас)', $owned);
    }

    public function testCasePageOffersAttachOnlyOutsideTheTrash(): void
    {
        $case = (string) file_get_contents($this->root . '/templates/owner-invited-case.twig');
        self::assertStringContainsString('action="{{ basePath }}/admin/invites/attach-client"', $case);
        self::assertStringContainsString('Привязать к клиенту', $case);
        self::assertStringContainsString('Сменить', $case);
        // Кнопка и диалог выведены только когда кейс не в корзине.
        self::assertStringContainsString('{% if not isTrashed %}' . "\n" . '                <form method="post" action="{{ basePath }}/admin/invites/attach-client"', $case);
        self::assertStringContainsString("{% set isTrashed = trashed ?? false %}", $case);
        self::assertStringContainsString("{% if not isTrashed %}\n    {% include 'blocks/owner-attach-dialog.twig' with {attach_return: '/admin/invited-case/' ~ case.id, attach_form_key: attach_form_key ?? '', clients: clients ?? []} %}", $case);

        $controller = (string) file_get_contents($this->root . '/controllers/OwnerController.php');
        self::assertStringContainsString("'attach_form_key' => \$trashed ? null : \$this->inviteAttach()->issueKey()", $controller);
    }

    public function testOwnerAttachScriptIsLocalAndFallsBackToTheServerPage(): void
    {
        $js = (string) file_get_contents($this->root . '/public/js/owner-attach.js');
        self::assertStringContainsString("typeof dialog.showModal !== 'function'", $js);
        self::assertStringNotContainsString('fetch(', $js);
        self::assertStringNotContainsString('XMLHttpRequest', $js);
    }
}
