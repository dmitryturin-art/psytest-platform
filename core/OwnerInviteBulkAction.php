<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Архив, корзина и окончательное удаление приглашений из кабинета (07.K8).
 *
 * Один класс на все пять действий, чтобы выбор строк, одноразовый ключ формы
 * и тексты ответов проверялись тестом без HTTP и `exit`.
 *
 * - Выбор: либо одна строка (`invite_id` — кнопка в строке таблицы), либо
 *   отмеченные галочками (`invite_ids[]`). Кнопка строки главнее галочек, иначе
 *   ранее отмеченные строки попали бы под действие, о котором речь не шла.
 * - Повтор: одноразовый ключ формы (`FormOnce`). Второй POST двойного клика
 *   действие не повторяет и получает сообщение первого.
 * - Подтверждение «в корзину» и «удалить сейчас» проверяет контроллер до
 *   вызова `submit()`: ключ при этом не расходуется.
 */
final class OwnerInviteBulkAction
{
    public const FORM = 'owner_invite_bulk';

    public const ARCHIVE = 'archive';
    public const UNARCHIVE = 'unarchive';
    public const TRASH = 'trash';
    public const RESTORE = 'restore';
    public const PURGE = 'purge';

    public const ACTIONS = [self::ARCHIVE, self::UNARCHIVE, self::TRASH, self::RESTORE, self::PURGE];

    /** Действия, которые не выполняются без явного подтверждения. */
    public const CONFIRMED_ACTIONS = [self::TRASH, self::PURGE];

    /** Сколько строк можно обработать за один запрос. */
    public const MAX_IDS = 200;

    public function __construct(
        private readonly TestInviteService $invites,
        private readonly TherapistCaseService $cases,
        private readonly FormOnce $once,
    ) {
    }

    public function issueKey(): string
    {
        return $this->once->issue(self::FORM);
    }

    /**
     * Строки, к которым относится запрос.
     *
     * @param array<string, mixed> $post
     * @return list<string>
     */
    public static function selectedIds(array $post): array
    {
        $single = $post['invite_id'] ?? null;
        if (is_string($single) && $single !== '') {
            return Security::isValidUuid($single) ? [strtolower($single)] : [];
        }

        $many = $post['invite_ids'] ?? [];
        if (!is_array($many)) {
            return [];
        }
        $ids = [];
        foreach ($many as $id) {
            if (is_string($id) && Security::isValidUuid($id)) {
                $ids[strtolower($id)] = true;
            }
        }

        return array_slice(array_keys($ids), 0, self::MAX_IDS);
    }

    /**
     * Адрес возврата: только внутренние страницы кабинета, где есть список
     * приглашений или карточка кейса/клиента. Всё остальное — `/admin`.
     */
    public static function safeReturn(mixed $value): string
    {
        if (!is_string($value)) {
            return '/admin';
        }
        $uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
        $allowed = [
            '#\A/admin(\?[A-Za-z0-9_%=&.~+\-]{0,400})?\z#',
            '#\A/admin/clients(\?[A-Za-z0-9_%=&.~+\-]{0,400})?\z#',
            '#\A/admin/(invited-case|clients)/' . $uuid . '(\?[A-Za-z0-9_%=&.~+\-]{0,400})?\z#',
        ];
        foreach ($allowed as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return $value;
            }
        }

        return '/admin';
    }

    /**
     * Выполнить действие и вернуть сообщение для кабинета.
     *
     * Подтверждение (`confirm_delete` для окончательного удаления) проверяется
     * здесь, но до захвата ключа: без галочки действие не выполняется и форму
     * можно отправить повторно.
     *
     * @param array<string, mixed> $post
     * @return array{type: string, message: string}
     */
    public function submit(string $action, array $post): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown invite action');
        }
        $ids = self::selectedIds($post);
        if ($ids === []) {
            return ['type' => 'error', 'message' => 'Ничего не выбрано: отметьте приглашения в списке.'];
        }
        if ($action === self::PURGE && ($post['confirm_delete'] ?? null) !== 'delete') {
            return ['type' => 'error', 'message' => 'Удаление не выполнено: подтвердите, что понимаете, что оно необратимо.'];
        }

        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->perform($action, $ids),
            'Форма устарела или уже была обработана. Ничего не изменено: обновите страницу и повторите действие.',
            'Это действие уже выполняется или выполнено. Обновите страницу и проверьте список приглашений.',
        );

        return ['type' => $outcome['result']['type'], 'message' => $outcome['result']['message']];
    }

    /**
     * @param list<string> $ids
     * @return array{type: string, message: string}
     */
    private function perform(string $action, array $ids): array
    {
        if ($action === self::PURGE) {
            $purged = 0;
            foreach ($ids as $id) {
                if ($this->cases->purgeTrashed($id) === TherapistCaseService::PURGE_DONE) {
                    ++$purged;
                }
            }

            return $purged > 0
                ? ['type' => 'success', 'message' => 'Окончательно удалено: ' . self::count($purged) . '. Кейс, ответы, разборы и выгрузки стёрты без возможности восстановления.']
                : ['type' => 'error', 'message' => 'Ничего не удалено: окончательно удалить можно только приглашение из корзины.'];
        }

        $changed = match ($action) {
            self::ARCHIVE => $this->invites->archive($ids),
            self::UNARCHIVE => $this->invites->unarchive($ids),
            self::TRASH => $this->invites->trash($ids),
            default => $this->invites->restore($ids),
        };
        if ($changed === 0) {
            return ['type' => 'error', 'message' => match ($action) {
                self::ARCHIVE, self::TRASH => 'Ничего не перемещено: ожидающее приглашение сначала отзовите, а уже перемещённые строки пропускаются.',
                self::UNARCHIVE => 'Ничего не изменено: выбранных приглашений нет в архиве.',
                default => 'Ничего не восстановлено: выбранных приглашений нет в корзине, либо их карточка клиента сама в корзине — сначала восстановите карточку.',
            }];
        }

        return ['type' => 'success', 'message' => match ($action) {
            self::ARCHIVE => 'В архив перемещено ' . self::count($changed) . '. Клиентские ссылки продолжают работать.',
            self::UNARCHIVE => 'Возвращено из архива: ' . self::count($changed) . '.',
            self::TRASH => 'В корзину перемещено ' . self::count($changed) . '. Окончательное удаление через '
                . TestInviteService::TRASH_RETENTION_DAYS . ' дней, до этого их можно восстановить.',
            default => 'Восстановлено: ' . self::count($changed) . '.',
        }];
    }

    /** «1 приглашение», «3 приглашения», «5 приглашений». */
    public static function count(int $n): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;
        $word = match (true) {
            $mod100 >= 11 && $mod100 <= 14 => 'приглашений',
            $mod10 === 1 => 'приглашение',
            $mod10 >= 2 && $mod10 <= 4 => 'приглашения',
            default => 'приглашений',
        };

        return $n . ' ' . $word;
    }
}
