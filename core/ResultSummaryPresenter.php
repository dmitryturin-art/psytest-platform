<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Modules\ResultSection;

/**
 * Сводка «Коротко о профиле» рядом с графиком СМИЛ на странице результата (04.D5).
 *
 * Ничего не пересчитывается: значения берутся из той же выборки, что и
 * сводка карточки кейса (`InvitedCasePresenter::workspace`) — достоверность и
 * шкалы вне нормы 30–70T по границе легенды графика. Отличие только в словах:
 * респондент видит названия шкал по Собчик и уровень, без кодов, T-баллов и
 * клинических ярлыков MMPI — они остаются в таблицах ниже.
 */
final class ResultSummaryPresenter
{
    /**
     * Названия основных шкал СМИЛ по Собчик — те же, что в
     * `modules/smil/basic_scales_norms.json` до скобок; совпадение держит тест.
     *
     * @var array<int, string> Ключ — код шкалы на бланке (PHP хранит «1» как 1).
     */
    public const SMIL_PLAIN_NAMES = [
        '1' => 'Сверхконтроль',
        '2' => 'Пессимистичность',
        '3' => 'Эмоциональная лабильность',
        '4' => 'Импульсивность',
        '5' => 'Мужественность-женственность',
        '6' => 'Ригидность',
        '7' => 'Тревожность',
        '8' => 'Индивидуалистичность',
        '9' => 'Оптимистичность',
        '0' => 'Интроверсия',
    ];

    /**
     * @param list<ResultSection> $sections
     * @return array{
     *     validity: array{is_valid: bool, has_notes: bool}|null,
     *     above: list<array{name: string, level_name: string}>,
     *     below: list<array{name: string, level_name: string}>
     * }|null Null — у результата нет графика профиля.
     */
    public function profileBrief(array $sections): ?array
    {
        $profile = (new InvitedCasePresenter())->workspace($sections)['profile'];
        if ($profile === null) {
            return null;
        }

        $above = [];
        $below = [];
        foreach ($profile['outside'] as $scale) {
            $item = [
                'name' => self::SMIL_PLAIN_NAMES[(string) $scale['code']] ?? (string) $scale['name'],
                'level_name' => (string) $scale['level_name'],
            ];
            if ($scale['above']) {
                $above[] = $item;
            } else {
                $below[] = $item;
            }
        }

        $validity = $profile['validity'];

        return [
            'validity' => $validity === null ? null : [
                'is_valid' => (bool) $validity['is_valid'],
                'has_notes' => $validity['warnings'] !== [],
            ],
            'above' => $above,
            'below' => $below,
        ];
    }
}
