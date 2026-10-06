<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\InviteFilter;
use PsyTest\Core\OwnerInviteBulkAction;

/** Проверка фильтра списка приглашений (07.K8): значение из адреса, SQL только параметрами. */
final class InviteFilterTest extends TestCase
{
    private const SLUGS = ['smil', 'bdi', 'hads'];
    private const UUID = '11111111-2222-4333-8444-555555555555';

    public function testEmptyQueryIsTheDefaultWorkingView(): void
    {
        $filter = InviteFilter::fromQuery([], self::SLUGS);

        self::assertTrue($filter->isDefault());
        self::assertSame([], $filter->toQuery());
        self::assertSame('/admin', $filter->toUrl());
        [$where, $params] = $filter->toSql();
        self::assertStringContainsString('invites.trashed_at IS NULL AND invites.archived_at IS NULL', $where);
        self::assertSame([], $params);
    }

    public function testEveryFieldIsValidatedAndBadValuesFallBackToAll(): void
    {
        $filter = InviteFilter::fromQuery([
            'client' => "x'; DROP TABLE test_invites; --",
            'test' => 'unknown',
            'status' => 'everything',
            'q' => ['array'],
        ], self::SLUGS);

        self::assertTrue($filter->isDefault());

        $filter = InviteFilter::fromQuery(['client' => strtoupper(self::UUID), 'test' => 'bdi', 'status' => 'completed', 'q' => '  заметка  '], self::SLUGS);
        self::assertSame(self::UUID, $filter->clientId);
        self::assertSame('bdi', $filter->testSlug);
        self::assertSame('completed', $filter->status);
        self::assertSame('заметка', $filter->query);
        self::assertFalse($filter->isDefault());
    }

    public function testClientNoneAndTrashAndArchiveProduceTheirOwnConditions(): void
    {
        [$where] = InviteFilter::fromQuery(['client' => 'none'], self::SLUGS)->toSql();
        self::assertStringContainsString('invites.client_id IS NULL', $where);

        [$where] = InviteFilter::fromQuery(['status' => 'trash'], self::SLUGS)->toSql();
        self::assertStringContainsString('invites.trashed_at IS NOT NULL', $where);
        self::assertStringNotContainsString('archived_at IS NULL', $where);

        [$where] = InviteFilter::fromQuery(['status' => 'archived'], self::SLUGS)->toSql();
        self::assertStringContainsString('invites.trashed_at IS NULL AND invites.archived_at IS NOT NULL', $where);
    }

    public function testValuesNeverReachTheSqlTextOnlyParameters(): void
    {
        $filter = InviteFilter::fromQuery(['client' => self::UUID, 'test' => 'smil', 'q' => "o'brien 100%_"], self::SLUGS);
        [$where, $params] = $filter->toSql();

        self::assertStringNotContainsString('brien', $where);
        self::assertStringNotContainsString(self::UUID, $where);
        self::assertSame(self::UUID, $params['client_id']);
        self::assertSame('smil', $params['test_slug']);
        self::assertSame("%o'brien 100!%!_%", $params['q_note']);
        self::assertSame($params['q_note'], $params['q_label']);
    }

    public function testSearchIsTrimmedToEightyCharactersAndControlBytesAreDropped(): void
    {
        $filter = InviteFilter::fromQuery(['q' => str_repeat('я', 200) . "\x00"], self::SLUGS);
        self::assertSame(80, mb_strlen($filter->query));

        $filter = InviteFilter::fromQuery(['q' => "a\x00b\nc"], self::SLUGS);
        self::assertSame('a b c', $filter->query);
    }

    public function testUrlKeepsOnlyNonDefaultFieldsAndIsStable(): void
    {
        $filter = InviteFilter::fromQuery(['status' => 'trash', 'test' => 'hads', 'q' => 'а б'], self::SLUGS);

        self::assertSame(['test' => 'hads', 'status' => 'trash', 'q' => 'а б'], $filter->toQuery());
        self::assertSame('/admin?test=hads&status=trash&q=%D0%B0%20%D0%B1', $filter->toUrl());
        // Адрес возврата, собранный фильтром, проходит проверку кабинета.
        self::assertSame($filter->toUrl(), OwnerInviteBulkAction::safeReturn($filter->toUrl()));
    }

    public function testEveryStatusMapsToACondition(): void
    {
        foreach (InviteFilter::STATUSES as $status) {
            [$where] = InviteFilter::fromQuery(['status' => $status], self::SLUGS)->toSql();
            self::assertNotSame('', $where, $status);
        }
        [$where] = InviteFilter::fromQuery(['status' => 'pending'], self::SLUGS)->toSql();
        self::assertStringContainsString('expires_at > NOW()', $where);
        [$where] = InviteFilter::fromQuery(['status' => 'expired'], self::SLUGS)->toSql();
        self::assertStringContainsString('expires_at <= NOW()', $where);
    }

    public function testReturnAddressesAreLimitedToTheOwnerArea(): void
    {
        foreach (['https://evil.example/admin', '//evil.example', '/admin/../x', '/adminx', '/admin?a=<script>', "/admin\r\nSet-Cookie: a=b", '', null, 5] as $bad) {
            self::assertSame('/admin', OwnerInviteBulkAction::safeReturn($bad), var_export($bad, true));
        }
        self::assertSame('/admin?status=trash', OwnerInviteBulkAction::safeReturn('/admin?status=trash'));
        self::assertSame('/admin/invited-case/' . self::UUID, OwnerInviteBulkAction::safeReturn('/admin/invited-case/' . self::UUID));
        self::assertSame('/admin/clients/' . self::UUID . '?status=archived', OwnerInviteBulkAction::safeReturn('/admin/clients/' . self::UUID . '?status=archived'));
    }

    public function testSelectionPrefersTheRowButtonAndDropsForeignValues(): void
    {
        $other = '99999999-2222-4333-8444-555555555555';
        self::assertSame([self::UUID], OwnerInviteBulkAction::selectedIds(['invite_id' => strtoupper(self::UUID), 'invite_ids' => [$other]]));
        self::assertSame([self::UUID, $other], OwnerInviteBulkAction::selectedIds(['invite_ids' => [self::UUID, 'x', self::UUID, [1], $other]]));
        self::assertSame([], OwnerInviteBulkAction::selectedIds(['invite_id' => 'not-a-uuid', 'invite_ids' => [self::UUID]]));
        self::assertSame([], OwnerInviteBulkAction::selectedIds(['invite_ids' => 'oops']));
    }

    public function testRussianPluralForms(): void
    {
        self::assertSame('1 приглашение', OwnerInviteBulkAction::count(1));
        self::assertSame('3 приглашения', OwnerInviteBulkAction::count(3));
        self::assertSame('5 приглашений', OwnerInviteBulkAction::count(5));
        self::assertSame('11 приглашений', OwnerInviteBulkAction::count(11));
        self::assertSame('21 приглашение', OwnerInviteBulkAction::count(21));
        self::assertSame('112 приглашений', OwnerInviteBulkAction::count(112));
    }
}
