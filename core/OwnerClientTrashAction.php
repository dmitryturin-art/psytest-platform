<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Корзина карточек клиентов из кабинета: в корзину, восстановить, удалить сейчас (07.K11).
 *
 * Отдельный класс, как у `OwnerInviteBulkAction`: выбор карточки, подтверждение,
 * одноразовый ключ формы и тексты ответов проверяются тестом без HTTP и `exit`.
 *
 * - Карточка одна, её идентификатор — в адресе запроса.
 * - Повтор: одноразовый ключ формы (`FormOnce`). Второй POST двойного клика
 *   действие не повторяет и получает сообщение первого.
 * - Подтверждение «в корзину» (`confirmed=1`) проверяет контроллер до `submit()`:
 *   ключ при этом не тратится. Для «удалить сейчас» нужна ещё и галочка
 *   `confirm_delete=delete`; без неё действие не выполняется.
 */
final class OwnerClientTrashAction
{
    public const FORM = 'owner_client_trash';

    public const TRASH = 'trash';
    public const RESTORE = 'restore';
    public const PURGE = 'purge';

    public const ACTIONS = [self::TRASH, self::RESTORE, self::PURGE];

    /** Действия, которые не выполняются без явного подтверждения. */
    public const CONFIRMED_ACTIONS = [self::TRASH, self::PURGE];

    public function __construct(
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
    public function submit(string $action, string $clientId, array $post): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown client action');
        }
        if (!Security::isValidUuid($clientId) || !$this->clients->exists($clientId)) {
            return ['type' => 'error', 'message' => 'Карточка не найдена: возможно, она уже удалена окончательно.'];
        }
        if ($action === self::PURGE && ($post['confirm_delete'] ?? null) !== 'delete') {
            return ['type' => 'error', 'message' => 'Удаление не выполнено: подтвердите, что понимаете, что оно необратимо.'];
        }

        $clientId = strtolower($clientId);
        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->perform($action, $clientId),
            'Форма устарела или уже была обработана. Ничего не изменено: обновите страницу и повторите действие.',
            'Это действие уже выполняется или выполнено. Обновите страницу и проверьте список клиентов.',
        );

        return ['type' => $outcome['result']['type'], 'message' => $outcome['result']['message']];
    }

    /** @return array{type: string, message: string} */
    private function perform(string $action, string $clientId): array
    {
        $days = TestInviteService::TRASH_RETENTION_DAYS;

        if ($action === self::TRASH) {
            $label = $this->clients->labelOf($clientId) ?? '';
            if ($this->clients->trash($clientId) !== TherapistClientService::TRASH_DONE) {
                return ['type' => 'error', 'message' => 'Карточка уже в корзине. Обновите страницу.'];
            }
            $hidden = $this->clients->assignmentsWithCard($clientId);

            return ['type' => 'success', 'message' => 'Карточка «' . $label . '» перемещена в корзину'
                . ($hidden > 0 ? ': ' . self::assignments($hidden) . ' скрыто' : '')
                . '. Через ' . $days . ' дней она будет удалена окончательно.'];
        }

        if ($action === self::RESTORE) {
            $returned = $this->clients->assignmentsWithCard($clientId);
            if ($this->clients->restore($clientId) !== TherapistClientService::RESTORE_DONE) {
                return ['type' => 'error', 'message' => 'Карточка не в корзине: восстанавливать нечего.'];
            }

            return ['type' => 'success', 'message' => $returned > 0
                ? 'Карточка восстановлена вместе с ' . self::assignmentsWith($returned) . '.'
                : 'Карточка восстановлена.'];
        }

        if ($this->clients->purgeTrashed($clientId) !== TherapistClientService::PURGE_DONE) {
            return ['type' => 'error', 'message' => 'Ничего не удалено: окончательно удалить можно только карточку из корзины.'];
        }

        return ['type' => 'success', 'message' => 'Карточка и все её данные удалены без возможности восстановления.'];
    }

    /** «1 назначение», «3 назначения», «5 назначений». */
    public static function assignments(int $n): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        $word = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => 'назначений',
            $mod10 === 1 => 'назначение',
            $mod10 >= 2 && $mod10 <= 4 => 'назначения',
            default => 'назначений',
        };

        return $n . ' ' . $word;
    }

    /** «1 назначением», «3 назначениями» — творительный падеж для «вместе с…». */
    private static function assignmentsWith(int $n): string
    {
        return $n . ' ' . ($n % 10 === 1 && $n % 100 !== 11 ? 'назначением' : 'назначениями');
    }
}
