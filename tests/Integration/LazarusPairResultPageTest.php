<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\ResultSectionRenderer;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\Lazarus\LazarusModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Подписи партнёров на странице результата пары (долг 00.D1-1).
 *
 * Дефект: `ResultPresenter::results()` подставлял текущую сессию как
 * `results_1` независимо от роли, поэтому у приглашённого партнёра его
 * собственные оценки были подписаны «Начавший опросник», а оценки начавшего —
 * «Приглашённый участник». Порядок обязан быть каноническим:
 * `pair_comparisons.session_1_id` — начавший, `session_2_id` — приглашённый.
 */
#[Group('database')]
final class LazarusPairResultPageTest extends TestCase
{
    private const FIRST_SELF = 8;
    private const SECOND_SELF = 6;

    private Database $db;
    private SessionManager $sessions;
    private LazarusModule $module;
    private string $firstId = '';
    private string $secondId = '';
    private string $comparisonId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->module = new LazarusModule();

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');
        $testId = (int) $test['id'];

        $this->firstId = $this->completedSession($testId, self: self::FIRST_SELF, partner: 6);
        $this->secondId = $this->completedSession($testId, self: self::SECOND_SELF, partner: 9);

        $first = $this->sessions->getSessionById($this->firstId);
        $second = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($first);
        self::assertIsArray($second);

        $record = $this->sessions->createPairComparison(
            $testId,
            $this->firstId,
            $this->secondId,
            $this->module->comparePairResults(
                $first['calculated_results'],
                $second['calculated_results'],
            ),
        );
        $this->comparisonId = (string) $record['id'];
    }

    protected function tearDown(): void
    {
        if ($this->comparisonId !== '') {
            $this->db->delete('pair_comparisons', 'id = ?', [$this->comparisonId]);
        }
        foreach ([$this->firstId, $this->secondId] as $id) {
            if ($id !== '') {
                $this->db->delete('test_sessions', 'id = ?', [$id]);
            }
        }
        $this->comparisonId = '';
        $this->firstId = '';
        $this->secondId = '';
    }

    public function testBothPartnersSeeTheSameCanonicalComparisonOrder(): void
    {
        $forFirst = $this->results($this->firstId)['pair_comparison'];
        $forSecond = $this->results($this->secondId)['pair_comparison'];

        // Начавший всегда p1, приглашённый всегда p2 — независимо от того,
        // чья страница собирается.
        self::assertSame(self::FIRST_SELF, $forFirst['items'][0]['p1_self']);
        self::assertSame(self::SECOND_SELF, $forFirst['items'][0]['p2_self']);
        self::assertSame(self::FIRST_SELF, $forSecond['items'][0]['p1_self']);
        self::assertSame(self::SECOND_SELF, $forSecond['items'][0]['p2_self']);

        self::assertSame(
            self::FIRST_SELF * 16,
            $forSecond['results_1']['total_self'],
            '«Начавший» на странице приглашённого указывает на результаты начавшего.',
        );
        self::assertSame(self::SECOND_SELF * 16, $forSecond['results_2']['total_self']);

        // Scoring и числа от порядка не зависят.
        self::assertSame($forFirst['items'], $forSecond['items']);
        self::assertSame($forFirst['overall_agreement'], $forSecond['overall_agreement']);
        self::assertSame($forFirst['summary'], $forSecond['summary']);
    }

    public function testViewerPositionMarksTheOwnColumnOnEachPage(): void
    {
        self::assertSame(1, $this->results($this->firstId)['pair_viewer_position']);
        self::assertSame(2, $this->results($this->secondId)['pair_viewer_position']);
    }

    public function testRenderedPageOfTheSecondPartnerLabelsTheStarterAsTheStarter(): void
    {
        $html = $this->renderPairBlock($this->secondId);

        // Карточка «Начавший опросник» несёт сумму начавшего, «Приглашённый» — свою.
        $starterCard = $this->cardMarkup($html, 'Начавший опросник');
        $invitedCard = $this->cardMarkup($html, 'Приглашённый участник');

        self::assertStringContainsString((string) (self::FIRST_SELF * 16), $starterCard);
        self::assertStringContainsString((string) (self::SECOND_SELF * 16), $invitedCard);

        // «Это вы» стоит у приглашённого — именно он смотрит страницу.
        self::assertStringNotContainsString('это вы', $starterCard);
        self::assertStringContainsString('это вы', $invitedCard);
    }

    public function testRenderedPageOfTheFirstPartnerKeepsTheStarterColumnAsItsOwn(): void
    {
        $html = $this->renderPairBlock($this->firstId);

        $starterCard = $this->cardMarkup($html, 'Начавший опросник');
        $invitedCard = $this->cardMarkup($html, 'Приглашённый участник');

        self::assertStringContainsString((string) (self::FIRST_SELF * 16), $starterCard);
        self::assertStringContainsString((string) (self::SECOND_SELF * 16), $invitedCard);
        self::assertStringContainsString('это вы', $starterCard);
        self::assertStringNotContainsString('это вы', $invitedCard);
    }

    public function testPdfOfTheSecondPartnerUsesTheSameCanonicalOrder(): void
    {
        $session = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($session);

        $printable = (new ResultPresenter($this->db, $this->sessions))
            ->pdfSections($session, $this->module);
        self::assertTrue($printable['includes_pair_comparison']);

        $html = $this->renderer()->renderToHtml($printable['sections']);
        self::assertStringContainsString('pair-comparison--pdf', $html);

        // Первая колонка компактной таблицы — оценка начавшего.
        preg_match_all(
            '#<td class="col-score">(\d+)</td>\s*<td class="col-score">(\d+)</td>#u',
            $html,
            $matches,
        );
        self::assertNotEmpty($matches[1]);
        self::assertSame((string) self::FIRST_SELF, $matches[1][0]);
        self::assertSame((string) self::SECOND_SELF, $matches[2][0]);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * @return array<string, mixed>
     */
    private function results(string $sessionId): array
    {
        $session = $this->sessions->getSessionById($sessionId);
        self::assertIsArray($session);

        return (new ResultPresenter($this->db, $this->sessions))->results($session, $this->module);
    }

    private function renderPairBlock(string $sessionId): string
    {
        $results = $this->results($sessionId);
        $sections = array_values(array_filter(
            $this->module->buildSections($results),
            static fn ($section): bool => $section->type === 'pair_comparison',
        ));
        self::assertCount(1, $sections);

        return $this->renderer()->renderToHtml($sections);
    }

    private function renderer(): ResultSectionRenderer
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return new ResultSectionRenderer(
            static fn (string $template, array $data): string => $twig->render($template . '.twig', $data),
        );
    }

    /**
     * Разметка одной карточки — от её подписи до конца `<article>`.
     */
    private function cardMarkup(string $html, string $label): string
    {
        $start = mb_strpos($html, $label);
        self::assertIsInt($start, 'Карточка «' . $label . '» отсутствует на странице.');
        $end = mb_strpos($html, '</article>', $start);
        self::assertIsInt($end);

        return mb_substr($html, $start, $end - $start);
    }

    private function completedSession(int $testId, int $self, int $partner): string
    {
        $session = $this->sessions->createSession($testId);
        $id = (string) $session['id'];

        $answers = [];
        foreach ($this->module->getQuestions() as $question) {
            $answers[(string) $question['id'] . '_self'] = $self;
            $answers[(string) $question['id'] . '_partner'] = $partner;
        }

        $this->db->update('test_sessions', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
            'calculated_results' => json_encode(
                $this->module->calculateResults($answers),
                JSON_UNESCAPED_UNICODE,
            ),
        ], 'id = ?', [$id]);

        return $id;
    }
}
