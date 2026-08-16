<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Owner-approved public presentation for a known clinical safety signal.
 *
 * This class deliberately contains no contacts, URLs, country selection,
 * GeoIP/IP handling, AI calls, or severity disclosure.
 */
final class ClinicalSafetyNotice
{
    public const MESSAGE = 'Ваш ответ на этот пункт может означать, что сейчас вам особенно нужна поддержка. Если есть риск причинить себе вред или вы не уверены, что сможете оставаться в безопасности, пожалуйста, не оставайтесь один: свяжитесь с близким человеком и обратитесь в местную экстренную или кризисную службу.';

    /**
     * @param array<string, mixed> $results
     *
     * @return array{message: string}|null
     */
    public static function fromResults(array $results): ?array
    {
        $signals = $results['safety_signals'] ?? [];

        if (!is_array($signals)) {
            return null;
        }

        foreach ($signals as $signal) {
            if (is_array($signal) && ($signal['code'] ?? null) === ClinicalSafetySignal::BDI_ITEM_NINE) {
                return ['message' => self::MESSAGE];
            }
        }

        return null;
    }
}
