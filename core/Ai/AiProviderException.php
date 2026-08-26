<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Отказ провайдера. Сообщение не должно нести полезную нагрузку запроса:
 * исключение может попасть в лог, а нагрузка — клиническая.
 */
final class AiProviderException extends \RuntimeException
{
}
