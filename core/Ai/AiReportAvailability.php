<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\Database;

/**
 * Единое правило: можно ли предложить и заказать новый ИИ-разбор (07.WP10, D-056).
 *
 * Разбор предлагается, когда одновременно:
 * - общий выключатель ИИ-разборов включён ({@see AiSettings});
 * - владелец включил разбор для этой методики ({@see AiTestSettings});
 * - для сочетания «методика + режим + вид» опубликован промпт.
 *
 * Правило одно для всех мест: страница результата гостя, кабинет посетителя,
 * карточка кейса в кабинете специалиста и все обработчики заказа. Оно
 * касается только НОВЫХ заказов: уже готовые разборы и опубликованный
 * специалистом текст остаются видны и после выключения.
 */
final class AiReportAvailability
{
    public const REASON_TEST_OFF = 'ИИ-разбор для этой методики выключен';
    public const REASON_NO_PROMPT = 'для этой методики и режима разбор пока не открыт';

    public function __construct(
        private readonly AiSettings $settings,
        private readonly AiTestSettings $tests,
        private readonly PromptRegistry $registry,
    ) {
    }

    public static function forDatabase(Database $db): self
    {
        return new self(new AiSettings($db), new AiTestSettings($db), PromptRegistry::default($db));
    }

    public function canOffer(string $test, string $mode, string $kind): bool
    {
        return $this->refusal($test, $mode, $kind) === null;
    }

    /**
     * Причина отказа по-русски или null, если заказ возможен.
     *
     * Причина не содержит данных о клиенте: только состояние настроек.
     */
    public function refusal(string $test, string $mode, string $kind): ?string
    {
        if (!$this->settings->isAiEnabled()) {
            return AiClient::DISABLED_REASON;
        }
        if (!$this->tests->isReportEnabled($test)) {
            return self::REASON_TEST_OFF;
        }
        if ($this->registry->published($test, $mode, $kind) === null) {
            return self::REASON_NO_PROMPT;
        }

        return null;
    }

    /**
     * Виды разбора, которые сейчас можно заказать для методики и режима.
     *
     * @return list<string>
     */
    public function offeredKinds(string $test, string $mode): array
    {
        return array_values(array_filter(
            [Prompt::KIND_CLEAR, Prompt::KIND_PROFESSIONAL],
            fn (string $kind): bool => $this->canOffer($test, $mode, $kind),
        ));
    }

    /** Опубликованный промпт для заказа, только если заказ разрешён правилом. */
    public function promptFor(string $test, string $mode, string $kind): ?Prompt
    {
        return $this->canOffer($test, $mode, $kind) ? $this->registry->published($test, $mode, $kind) : null;
    }
}
