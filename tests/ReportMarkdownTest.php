<?php

declare(strict_types=1);

namespace PsyTest\Tests;

use PHPUnit\Framework\TestCase;
use PsyTest\Core\ReportMarkdown;

/**
 * Разметка ИИ-разбора.
 *
 * Текст приходит от внешней модели и попадёт на страницу, поэтому главное, что
 * здесь стережётся, — невозможность внести чужую разметку. Всё остальное
 * (заголовки, таблицы, списки) проверяется во вторую очередь.
 */
final class ReportMarkdownTest extends TestCase
{
    public function testScriptTagNeverSurvivesAsMarkup(): void
    {
        $html = ReportMarkdown::toHtml('Текст <script>alert(1)</script> дальше');

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html, 'Тег должен остаться видимым текстом, а не исчезнуть молча.');
    }

    /** @return list<array{0: string}> */
    public static function hostileInputProvider(): array
    {
        return [
            ['<img src=x onerror=alert(1)>'],
            ['<iframe src="https://example.com"></iframe>'],
            ['<a href="javascript:alert(1)">клик</a>'],
            ['<div onclick="alert(1)">текст</div>'],
            ['<svg/onload=alert(1)>'],
            ['<style>body{display:none}</style>'],
            ['<!-- <script>alert(1)</script> -->'],
            ['<object data="x"></object>'],
            ['<form action="/admin/case/delete"><button>ок</button></form>'],
        ];
    }

    /**
     * Теги, которые рендерер имеет право создавать. Всё остальное в выводе —
     * ошибка, независимо от того, как оно туда попало.
     *
     * @var list<string>
     */
    private const ALLOWED_TAGS = [
        'p', 'br', 'h2', 'h3', 'h4', 'h5', 'ul', 'ol', 'li', 'strong', 'em',
        'code', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'div',
    ];

    /**
     * Разметка вывода: только сами теги, без текста между ними.
     *
     * @return list<string>
     */
    private function tagsOf(string $html): array
    {
        preg_match_all('/<[^>]*>/', $html, $matches);

        return $matches[0];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileInputProvider')]
    public function testHostileInputProducesOnlyOurOwnTags(string $input): void
    {
        // Существенно именно это: опасные слова могут остаться в тексте
        // (и это правильно — читатель должен видеть, что прислала модель),
        // но разметкой они стать не могут.
        $html = ReportMarkdown::toHtml($input);

        foreach ($this->tagsOf($html) as $tag) {
            self::assertSame(
                1,
                preg_match('/^<\/?([a-z0-9]+)/i', $tag, $m),
                "Непонятная конструкция в выводе: {$tag}",
            );
            self::assertContains(strtolower($m[1]), self::ALLOWED_TAGS, "Чужой тег в выводе: {$tag}");

            // Ни один наш тег не несёт ни ссылок, ни обработчиков событий.
            self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $tag, "Обработчик события в теге: {$tag}");
            self::assertStringNotContainsString('href', $tag);
            self::assertStringNotContainsString('src', $tag);
            self::assertStringNotContainsString('javascript:', $tag);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileInputProvider')]
    public function testHostileInputStaysVisibleAsTextInsteadOfDisappearing(string $input): void
    {
        // Молча выбрасывать кусок ответа модели нельзя: читатель не должен
        // получить документ, из которого что-то пропало без следа.
        $html = ReportMarkdown::toHtml($input);

        self::assertStringContainsString('&lt;', $html);
        self::assertStringNotContainsString('<' . ltrim(explode(' ', trim($input, '<'))[0], '/'), $html);
    }

    public function testMarkdownLinksAreNotTurnedIntoLinks(): void
    {
        // Ссылок нет намеренно: модель не должна уводить читателя со страницы.
        $html = ReportMarkdown::toHtml('Смотрите [здесь](https://example.com/x) подробнее');

        self::assertStringNotContainsString('<a', $html);
        self::assertStringNotContainsString('href', $html);
        self::assertStringContainsString('здесь', $html);
    }

    public function testQuotesAndAmpersandsSurviveAsText(): void
    {
        $html = ReportMarkdown::toHtml('Показатель «тревога» вырос & закрепился');

        self::assertStringContainsString('«тревога»', $html);
        self::assertStringContainsString('&amp;', $html);
    }

    public function testHeadingsBecomeSubheadingsNotPageTitles(): void
    {
        // h1 принадлежит странице результата, отчёт начинается уровнем ниже.
        $html = ReportMarkdown::toHtml("# Заключение\n\n## Профиль");

        self::assertStringContainsString('<h2>Заключение</h2>', $html);
        self::assertStringContainsString('<h3>Профиль</h3>', $html);
        self::assertStringNotContainsString('<h1>', $html);
    }

    public function testTableRendersWithoutTheSeparatorRow(): void
    {
        $markdown = "| Домен | Оценка |\n|---|---|\n| Общение | 8 |\n| Финансы | 4 |";

        $html = ReportMarkdown::toHtml($markdown);

        self::assertStringContainsString('<th>Домен</th>', $html);
        self::assertStringContainsString('<td>Общение</td>', $html);
        self::assertStringContainsString('<td>4</td>', $html);
        self::assertStringNotContainsString('---', $html, 'Строка-разделитель не является данными.');
        self::assertSame(2, substr_count($html, '<tr>') - 1, 'Две строки данных плюс заголовок.');
    }

    public function testWideTableCanScrollInsteadOfBreakingTheLayout(): void
    {
        $html = ReportMarkdown::toHtml("| a | b |\n|---|---|\n| 1 | 2 |");

        self::assertStringContainsString('report-table-scroll', $html);
    }

    public function testListsAndEmphasisRender(): void
    {
        $html = ReportMarkdown::toHtml("- первый\n- второй\n\n1. шаг\n2. шаг\n\n**важно** и *мягко*");

        self::assertStringContainsString('<ul><li>первый</li><li>второй</li></ul>', $html);
        self::assertStringContainsString('<ol><li>шаг</li><li>шаг</li></ol>', $html);
        self::assertStringContainsString('<strong>важно</strong>', $html);
        self::assertStringContainsString('<em>мягко</em>', $html);
    }

    public function testParagraphsAreSeparatedByBlankLines(): void
    {
        $html = ReportMarkdown::toHtml("Первый абзац.\nЕго продолжение.\n\nВторой абзац.");

        self::assertSame(2, substr_count($html, '<p>'));
        self::assertStringContainsString('Первый абзац.<br>Его продолжение.', $html);
    }

    public function testEmptyInputProducesNothingRatherThanEmptyTags(): void
    {
        self::assertSame('', ReportMarkdown::toHtml(''));
        self::assertSame('', ReportMarkdown::toHtml("\n\n   \n"));
    }

    public function testRealReportFromTheModelRendersWithoutMarkupLeaks(): void
    {
        $report = (string) file_get_contents(__DIR__ . '/fixtures/ai-report-sample.md');
        self::assertNotSame('', $report, 'Предусловие: фикстура настоящего отчёта на месте.');

        $html = ReportMarkdown::toHtml($report);

        self::assertStringContainsString('<h2>', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringContainsString('<ul>', $html);

        foreach ($this->tagsOf($html) as $tag) {
            preg_match('/^<\/?([a-z0-9]+)/i', $tag, $m);
            self::assertContains(strtolower($m[1] ?? ''), self::ALLOWED_TAGS, "Чужой тег в выводе: {$tag}");
        }
    }
}
