<?php

declare(strict_types=1);

namespace PsyTest\Modules;

/**
 * Модуль умеет пересчитать по сохранённым ответам только ту часть результата,
 * реестр которой может расширяться после прохождения (05.S4a).
 *
 * Базовый расчёт, достоверность и интерпретация при этом не трогаются:
 * результат считается один раз при завершении и дальше неизменен, кроме
 * явно объявленного модулем блока.
 */
interface RefreshesAdditionalScores
{
    /**
     * Вернуть результаты с актуальным блоком дополнительных шкал.
     *
     * Если блок уже соответствует текущему реестру или ответов нет,
     * возвращается ровно переданный массив.
     *
     * @param array<string, mixed>     $results Сохранённые `calculated_results`.
     * @param array<int|string, mixed> $answers Сохранённые ответы сессии.
     *
     * @return array<string, mixed>
     */
    public function refreshAdditionalScores(array $results, array $answers): array;
}
