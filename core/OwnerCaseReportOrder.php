<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderException;
use PsyTest\Core\Ai\AiReportAvailability;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;

/**
 * Формы «Заказать черновики» и «Заказать заново» в карточке кейса (07.K6b).
 *
 * Заказ запускает платный вызов внешнего ИИ. «Заказать заново» у готового
 * черновика сбрасывает его в очередь (`AiReportRepository::request` с
 * `allowExhaustedRetry`), поэтому двойное нажатие могло поставить второй
 * платный прогон после того, как первый уже отработал.
 *
 * Каждая форма заказа на странице получает свой одноразовый ключ
 * (`FormOnce`): повтор того же отправления заданий не ставит и не сбрасывает,
 * а получает ответ первого. Сознательный повторный заказ со свежей страницы
 * несёт новый ключ и работает как раньше.
 */
final class OwnerCaseReportOrder
{
    public const FORM = 'owner_case_reports';
    public const OWNER_CONTEXT_MAX_LENGTH = 4000;

    public function __construct(
        private readonly AiReportRepository $reports,
        private readonly PromptRegistry $registry,
        private readonly AiSettings $settings,
        private readonly AiReportContextBuilder $contextBuilder,
        private readonly FormOnce $once,
        private readonly AiReportAvailability $availability,
    ) {
    }

    /**
     * Ключи для всех форм заказа на одной отрисовке карточки.
     *
     * @return array{all: string, clear: string, professional: string}
     */
    public function issueKeys(): array
    {
        return [
            'all' => $this->once->issue(self::FORM),
            Prompt::KIND_CLEAR => $this->once->issue(self::FORM),
            Prompt::KIND_PROFESSIONAL => $this->once->issue(self::FORM),
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array{type: string, message: string, queued: int, replayed: bool}
     */
    public function submit(string $sessionId, string $slug, string $mode, array $post): array
    {
        $outcome = $this->once->run(
            self::FORM,
            $post['form_key'] ?? null,
            fn (): array => $this->order($sessionId, $slug, $mode, $post),
            'Форма устарела или уже была обработана. Черновики не заказаны: обновите страницу и закажите ещё раз.',
            'Этот заказ уже отправлен. Повторно черновики не заказаны: дождитесь результата на странице.',
        );
        $result = $outcome['result'];
        $fresh = $outcome['claim'] === FormOnce::FRESH;

        return [
            'type' => $result['type'] ?? 'error',
            'message' => $result['message'] ?? '',
            // Повтор не ставит заданий: запускать обработку ему нечего.
            'queued' => $fresh ? (int) ($result['queued'] ?? 0) : 0,
            'replayed' => $outcome['claim'] === FormOnce::REPLAY,
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @return array<string, string>
     */
    private function order(string $sessionId, string $slug, string $mode, array $post): array
    {
        if (($post['ai_consent'] ?? null) !== '1') {
            return self::error('Черновики не заказаны: нужно подтвердить передачу обезличенных результатов внешнему AI-сервису.');
        }

        $ownerContext = $post['owner_context'] ?? '';
        if (!is_string($ownerContext) || mb_strlen(trim($ownerContext)) > self::OWNER_CONTEXT_MAX_LENGTH) {
            return self::error('Черновики не заказаны: клинический контекст длиннее ' . self::OWNER_CONTEXT_MAX_LENGTH . ' символов.');
        }
        $ownerContext = trim($ownerContext);

        if (!$this->settings->isAiEnabled()) {
            return self::error('Черновики не заказаны: ' . AiClient::DISABLED_REASON . '.');
        }

        // Из карточки можно перезаказать один вид («Заказать заново» у готового
        // или неудавшегося черновика) либо оба сразу.
        $onlyKind = $post['kind'] ?? null;
        $kinds = in_array($onlyKind, [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL], true)
            ? [$onlyKind]
            : [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL];

        // Единое правило (07.WP10): заказ закрыт, если разбор методики выключен
        // или промпт не опубликован. Проверяется до сборки контекста — иначе
        // данные собирались бы для заказа, которого не будет.
        $offered = array_values(array_filter(
            $kinds,
            fn (string $kind): bool => $this->availability->canOffer($slug, $mode, $kind),
        ));
        if ($offered === []) {
            $reason = $this->availability->refusal($slug, $mode, $kinds[0]) ?? AiReportAvailability::REASON_NO_PROMPT;

            return self::error('Черновики не заказаны: ' . $reason . '.');
        }

        try {
            $context = $this->contextBuilder->build($sessionId, $slug, $mode);
        } catch (AiProviderException $e) {
            return self::error('Черновики не заказаны: ' . $e->getMessage());
        }

        $queued = 0;
        foreach ($offered as $kind) {
            $prompt = $this->registry->published($slug, $mode, $kind);
            if ($prompt === null) {
                continue;
            }

            // Клинический контекст пишет специалист и адресует специалисту: в
            // понятный клиентский разбор он не подмешивается (phase 07, WP3).
            $this->reports->request(
                $sessionId,
                $slug,
                $mode,
                $kind,
                $prompt,
                $context,
                $prompt->allowsOwnerContext && $ownerContext !== '' ? $ownerContext : null,
                // Кабинет — единственное место, откуда исчерпанное задание
                // можно заказать заново: это явное действие специалиста.
                true,
            );
            $queued++;
        }

        if ($queued === 0) {
            return self::error('Для этой методики и режима разбор пока не открыт.');
        }

        return [
            'type' => 'success',
            'message' => 'Черновики поставлены в очередь. Обычно это 2–5 минут — страница обновится сама.',
            'queued' => (string) $queued,
        ];
    }

    /** @return array<string, string> */
    private static function error(string $message): array
    {
        return ['type' => 'error', 'message' => $message];
    }
}
