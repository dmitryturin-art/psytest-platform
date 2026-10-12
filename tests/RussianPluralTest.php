<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\RussianPlural;

final class RussianPluralTest extends TestCase
{
    /** @return list<array{int, string}> */
    public static function boundaries(): array
    {
        return [
            [0, 'вопросов'], [1, 'вопрос'], [2, 'вопроса'], [4, 'вопроса'], [5, 'вопросов'],
            [11, 'вопросов'], [12, 'вопросов'], [14, 'вопросов'], [20, 'вопросов'], [21, 'вопрос'],
            [22, 'вопроса'], [25, 'вопросов'], [101, 'вопрос'], [111, 'вопросов'], [112, 'вопросов'],
            [566, 'вопросов'],
        ];
    }

    #[DataProvider('boundaries')]
    public function testItChoosesTheRussianForm(int $number, string $expected): void
    {
        self::assertSame($expected, RussianPlural::choose($number, 'вопрос', 'вопроса', 'вопросов'));
    }
}
