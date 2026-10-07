<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Правка заметки специалиста в шапке карточки кейса (04.D3).
 *
 * Отдельный класс, как у `OwnerInviteClientAttach`, чтобы проверка ввода и
 * одноразовый ключ формы проверялись тестом без HTTP и `exit`.
 *
 * - Ввод: строка до `OwnerInviteSubmission::NOTE_MAX_LENGTH` символов после
 *   обрезки пробелов — та же граница, что у формы приглашения. Пустая строка
 *   стирает заметку.
 * - Повтор: одноразовый ключ формы (`FormOnce`). При ошибке ввода ключ
 *   возвращается, и исправленную форму можно отправить ещё раз.
 * - Кейс в корзине — только чтение: заметка не меняется.
 */
final class OwnerCaseNoteUpdate
{
    public const FORM = 'owner_case_note';

    public function __construct(
        private readonly TestInviteService $invites,
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
    public function submit(string $sessionId, array $post): array
    {
        $note = $post['owner_note'] ?? null;
        if (!is_string($note) || mb_strlen(trim($note)) > OwnerInviteSubmission::NOTE_MAX_LENGTH) {
            return [
                'type' => 'error',
                'message' => 'Заметка не сохранена: сократите её до ' . OwnerInviteSubmission::NOTE_MAX_LENGTH . ' символов.',
            ];
        }

        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->perform($sessionId, $note),
            'Форма устарела или уже была обработана. Заметка не изменена: обновите страницу и сохраните ещё раз.',
            'Заметка уже сохраняется. Обновите страницу и проверьте её текст.',
        );

        return ['type' => $outcome['result']['type'], 'message' => $outcome['result']['message']];
    }

    /** @return array{type: string, message: string} */
    private function perform(string $sessionId, string $note): array
    {
        return match ($this->invites->updateNote($sessionId, $note)) {
            TestInviteService::NOTE_SAVED => ['type' => 'success', 'message' => trim($note) === '' ? 'Заметка удалена.' : 'Заметка сохранена.'],
            TestInviteService::NOTE_UNCHANGED => ['type' => 'success', 'message' => 'Заметка не изменилась.'],
            default => ['type' => 'error', 'message' => 'Заметка не сохранена: кейс в корзине или уже удалён.'],
        };
    }
}
