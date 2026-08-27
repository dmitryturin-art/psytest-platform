<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

/**
 * Модель из каталога провайдера — для выпадающего списка в кабинете.
 */
final class AiModel
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $contextLength,
        public readonly bool $isFree,
    ) {
    }
}
