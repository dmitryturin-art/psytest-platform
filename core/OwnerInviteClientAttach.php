<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Привязка приглашения к клиенту и смена клиента из кабинета (07.K9).
 *
 * Отдельный класс, как у `OwnerInviteBulkAction`, чтобы выбор клиента,
 * подтверждение смены и одноразовый ключ проверялись тестом без HTTP и `exit`.
 *
 * - Выбор: существующая карточка (`client_id` — UUID) или «Новый клиент…»
 *   (`__new__` и `new_client_label`): карточка и привязка — одна транзакция.
 * - Смена: если у приглашения уже есть другой клиент, нужна отметка
 *   `confirm_change=1`. Без неё ничего не меняется и ключ формы не тратится.
 * - Повтор: одноразовый ключ формы (`FormOnce`).
 */
final class OwnerInviteClientAttach
{
    public const FORM = 'owner_invite_attach';
    public const NEW_CLIENT = '__new__';

    public function __construct(
        private readonly TestInviteService $invites,
        private readonly TherapistClientService $clients,
        private readonly FormOnce $once,
    ) {
    }

    public function issueKey(): string
    {
        return $this->once->issue(self::FORM);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{type: string, message: string}
     */
    public function submit(array $post): array
    {
        $inviteId = $post['invite_id'] ?? null;
        if (!is_string($inviteId) || !Security::isValidUuid($inviteId)) {
            return self::error('Приглашение не найдено.');
        }
        $inviteId = strtolower($inviteId);
        $choice = $post['client_id'] ?? null;
        $isNew = $choice === self::NEW_CLIENT;
        if (!is_string($choice) || (!$isNew && !Security::isValidUuid($choice))) {
            return self::error('Выберите клиента из списка или создайте нового.');
        }
        $choice = strtolower($choice);
        $label = $isNew ? trim((string) ($post['new_client_label'] ?? '')) : '';
        if ($isNew && !TherapistClientService::isValidInput($label, '')) {
            return self::error('Укажите имя нового клиента (до ' . TherapistClientService::LABEL_MAX_LENGTH . ' символов). Карточка не создана.');
        }
        if (!$isNew && !$this->clients->exists($choice)) {
            return self::error('Такой карточки клиента нет. Обновите страницу и выберите клиента заново.');
        }

        $current = $this->invites->clientOfInvite($inviteId);
        if ($current === null) {
            return self::error('Приглашение не найдено: возможно, оно уже удалено.');
        }
        if (
            $current['client_id'] !== null
            && ($isNew || $current['client_id'] !== $choice)
            && ($post['confirm_change'] ?? null) !== '1'
        ) {
            return self::error('Клиент не изменён: подтвердите перенос кейса в другую карточку.');
        }

        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->perform($inviteId, $isNew ? null : $choice, $label, $current['claimed_session_id'] !== null),
            'Форма устарела или уже была обработана. Ничего не изменено: обновите страницу и повторите действие.',
            'Это действие уже выполняется или выполнено. Обновите страницу и проверьте клиента у приглашения.',
        );

        return ['type' => $outcome['result']['type'], 'message' => $outcome['result']['message']];
    }

    /** @return array{type: string, message: string} */
    private function perform(string $inviteId, ?string $clientId, string $label, bool $hasCase): array
    {
        $outcome = $this->invites->attachClient($inviteId, $clientId, $label);
        if ($outcome === TestInviteService::ATTACH_MISSING) {
            return self::error('Приглашение не найдено: возможно, оно уже удалено.');
        }
        if ($outcome === TestInviteService::ATTACH_REFUSED) {
            return self::error('Привязка не выполнена: проверьте выбранного клиента.');
        }

        $after = $this->invites->clientOfInvite($inviteId);
        $name = '«' . (string) ($after['client_label'] ?? '') . '»';

        return ['type' => 'success', 'message' => match ($outcome) {
            TestInviteService::ATTACH_UNCHANGED => 'Уже привязано к этому клиенту ' . $name . '.',
            TestInviteService::ATTACH_CHANGED => 'Клиент изменён на ' . $name . '. Результат и ссылка не изменились.',
            default => ($hasCase ? 'Кейс привязан' : 'Приглашение привязано') . ' к клиенту ' . $name . '.',
        }];
    }

    /** @return array{type: string, message: string} */
    private static function error(string $message): array
    {
        return ['type' => 'error', 'message' => $message];
    }
}
