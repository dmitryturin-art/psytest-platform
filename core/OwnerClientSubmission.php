<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Форма «Новая карточка» на странице «Клиенты» (07.K6b).
 *
 * Двойное нажатие «Создать карточку» создавало две одинаковые карточки.
 * Повтор с тем же одноразовым ключом (`FormOnce`) карточку не создаёт и ведёт
 * туда же, куда первый запрос, — в уже созданную карточку.
 */
final class OwnerClientSubmission
{
    public const FORM = 'owner_client_create';

    public function __construct(
        private readonly TherapistClientService $clients,
        private readonly FormOnce $once,
    ) {
    }

    /** Ключ для очередной отрисовки формы. */
    public function issueKey(): string
    {
        return $this->once->issue(self::FORM);
    }

    /**
     * @param array<string, mixed> $post
     * @return array{type: string, message: string, redirect: string}
     */
    public function submit(array $post): array
    {
        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->create($post),
            'Форма устарела или уже была обработана. Карточка не создана: обновите страницу и отправьте форму ещё раз.',
            'Эта форма уже отправлена. Вторая карточка не создана: проверьте список клиентов.',
        );
        $result = $outcome['result'];

        return [
            'type' => $result['type'] ?? 'error',
            'message' => $result['message'] ?? '',
            'redirect' => $result['redirect'] ?? '/admin/clients',
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function create(array $post): array
    {
        $label = $post['label'] ?? '';
        $note = $post['note'] ?? '';
        $email = $post['email'] ?? '';
        if (!TherapistClientService::isValidInput($label, $note, $email)) {
            return [
                'type' => 'error',
                'message' => 'Не удалось создать карточку: подпись обязательна (до 120 символов), заметка — до 1000 символов, email — корректный адрес или пусто.',
            ];
        }

        $clientId = $this->clients->create((string) $label, (string) $note, (string) $email);

        return [
            'type' => 'success',
            'message' => 'Карточка клиента создана.',
            'redirect' => '/admin/clients/' . $clientId,
        ];
    }
}
