<?php

declare(strict_types=1);

namespace PsyTest\Modules;

/**
 * Растровая версия профильного графика для печати (07.WP7b).
 *
 * DomPDF не исполняет JavaScript, поэтому график, который в браузере рисует
 * скрипт модуля, в PDF попадает картинкой. Модуль владеет своей геометрией:
 * секция `TYPE_PROFILE_CHART` объявляет в данных ключ `pdf_image_renderer` —
 * имя класса, реализующего этот контракт. Общий рендерер PDF
 * (`core/ResultSectionRenderer.php`) знает только контракт и ничего не знает
 * о конкретной методике.
 *
 * Реализация обязана быть детерминированной и не должна ничего писать на
 * диск: картинка живёт только внутри документа.
 */
interface ProfileChartImageRenderer
{
    /**
     * Готов ли рендерер в этом окружении (расширения, ассеты).
     */
    public static function isAvailable(): bool;

    /**
     * PNG-байты графика по данным секции.
     *
     * @param array<string, mixed> $data Данные секции `TYPE_PROFILE_CHART`.
     *
     * @throws \RuntimeException Если нарисовать не удалось — вызывающий
     *         откатывается к статической HTML-подмене.
     */
    public function renderPng(array $data): string;
}
