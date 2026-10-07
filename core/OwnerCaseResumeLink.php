<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Перевыпуск ссылки для продолжения прохождения из карточки кейса (07.K12).
 *
 * Отдельный класс, как у `OwnerCaseNoteUpdate`, чтобы одноразовый ключ формы
 * и выдача ссылки проверялись тестом без HTTP и `exit`.
 *
 * - Токен приглашения хранится только хэшем, поэтому прежнюю ссылку показать
 *   нельзя: действие заменяет токен. Старая ссылка перестаёт работать, сессия
 *   и ответы остаются.
 * - Повтор: одноразовый ключ формы. Второй POST того же ключа токен не
 *   меняет и возвращает итог первого запроса — ту же ссылку.
 * - Токена нет в тексте сообщения: он лежит отдельным полем `invite_url`,
 *   которое страница показывает один раз и вместе с флешем стирает.
 */
final class OwnerCaseResumeLink
{
    public const FORM = 'owner_case_resume_link';

    public function __construct(
        private readonly TestInviteService $invites,
        private readonly FormOnce $once,
        private readonly string $appUrl,
    ) {
    }

    public function issueKey(): string
    {
        return $this->once->issue(self::FORM);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{type: string, message: string, invite_url?: string}
     */
    public function submit(string $sessionId, array $post): array
    {
        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->perform($sessionId),
            'Форма устарела или уже была обработана. Ссылка не выдана: обновите страницу и повторите.',
            'Ссылка уже выдаётся. Если вы её не увидели, обновите страницу и выдайте новую.',
        );

        $flash = ['type' => $outcome['result']['type'], 'message' => $outcome['result']['message']];
        if (isset($outcome['result']['invite_url'])) {
            $flash['invite_url'] = $outcome['result']['invite_url'];
        }

        return $flash;
    }

    /** @return array<string, string> */
    private function perform(string $sessionId): array
    {
        $token = $this->invites->reissueResumeLink($sessionId);
        if ($token === null) {
            return ['type' => 'error', 'message' => 'Ссылка не выдана: прохождение завершено, удалено, лежит в корзине или срок его хранения вышел.'];
        }

        return [
            'type' => 'success',
            'message' => 'Ссылка для продолжения готова. Скопируйте её сейчас: повторно она не показывается. Прежняя ссылка клиента больше не работает.',
            'invite_url' => $this->appUrl . '/invite/' . $token,
        ];
    }
}
