<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Database;
use PsyTest\Core\InvitedCasePresenter;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\RetentionPolicy;
use PsyTest\Core\SessionManager;
use PsyTest\Core\TemplateFunctions;
use PsyTest\Modules\Lazarus\LazarusModule;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Карточка кейса специалиста для сессии из пары (07.K1b).
 *
 * Проверяется то, что владелец увидел как дефект: парный кейс выглядел
 * индивидуальным. Карточка обязана показать парное сравнение и обе анкеты,
 * при этом не выдать bearer-токен и не превратить вторую сессию в кейс.
 */
#[Group('database')]
final class OwnerPairCaseCardTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private LazarusModule $module;
    private string $firstId = '';
    private string $secondId = '';
    private string $soloId = '';
    private string $comparisonId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->module = new LazarusModule();

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'lazarus'");
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');
        $testId = (int) $test['id'];

        // Партнёр 1 — начавший опросник, партнёр 2 — приглашённый.
        $this->firstId = $this->completedSession($testId, self: 8, partner: 6);
        $this->secondId = $this->completedSession($testId, self: 6, partner: 9);
        $this->soloId = $this->completedSession($testId, self: 5, partner: 5);

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
        foreach ([$this->firstId, $this->secondId, $this->soloId] as $id) {
            if ($id !== '') {
                $this->db->delete('test_sessions', 'id = ?', [$id]);
            }
        }
        $this->comparisonId = '';
        $this->firstId = '';
        $this->secondId = '';
        $this->soloId = '';
    }

    public function testCaseOfTheFirstPartnerCarriesPairResultAndBothQuestionnaires(): void
    {
        $pair = $this->card($this->firstId);
        self::assertIsArray($pair);

        self::assertSame(1, $pair['position']);
        self::assertSame(2, $pair['partner_position']);
        self::assertStringContainsString('Партнёр 1', $pair['label']);
        self::assertStringContainsString('Партнёр 2', $pair['partner_label']);

        // Парный результат — тот же рендер, что у клиента: график и сравнение.
        $types = array_map(static fn ($section): string => $section->type, $pair['sections']);
        self::assertContains('pair_chart', $types);
        self::assertContains('pair_comparison', $types);
        // Приглашение партнёру принадлежит странице клиента, не кабинету.
        self::assertNotContains('pair_invite', $types);

        // Оба профиля пришли из одного расчёта модуля.
        $comparison = null;
        foreach ($pair['sections'] as $section) {
            if ($section->type === 'pair_comparison') {
                $comparison = $section->data['comparison'];
            }
        }
        self::assertIsArray($comparison);
        self::assertSame(8, $comparison['items'][0]['p1_self']);
        self::assertSame(6, $comparison['items'][0]['p2_self']);

        // Обе анкеты, в каноническом порядке, с пометкой этого кейса.
        self::assertCount(2, $pair['questionnaires']);
        [$sheet1, $sheet2] = $pair['questionnaires'];
        self::assertTrue($sheet1['is_case']);
        self::assertFalse($sheet2['is_case']);
        self::assertCount(16, $sheet1['rows']);
        self::assertCount(16, $sheet2['rows']);
        self::assertSame('8 из 10', $sheet1['rows'][0]['self_answer']);
        self::assertSame('6 из 10', $sheet2['rows'][0]['self_answer']);
    }

    public function testCaseOfTheSecondPartnerCarriesTheSamePairWithReversedLabels(): void
    {
        $pair = $this->card($this->secondId);
        self::assertIsArray($pair);

        self::assertSame(2, $pair['position']);
        self::assertSame(1, $pair['partner_position']);
        self::assertStringContainsString('Партнёр 2', $pair['label']);
        self::assertStringContainsString('Партнёр 1', $pair['partner_label']);

        // Порядок анкет остаётся каноническим, а «этот кейс» переезжает во вторую.
        [$sheet1, $sheet2] = $pair['questionnaires'];
        self::assertFalse($sheet1['is_case']);
        self::assertTrue($sheet2['is_case']);
        self::assertSame('8 из 10', $sheet1['rows'][0]['self_answer']);
        self::assertSame('6 из 10', $sheet2['rows'][0]['self_answer']);
    }

    public function testASingleSessionHasNoPairPartInTheCard(): void
    {
        self::assertNull($this->card($this->soloId));
    }

    public function testTheSecondSessionStaysOutsideTheCaseAndKeepsItsRetentionClass(): void
    {
        $before = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($before);

        $this->card($this->firstId);

        $after = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($after);
        self::assertSame($before['retention_class'], $after['retention_class']);
        self::assertSame(RetentionPolicy::ANONYMOUS, $after['retention_class']);
        self::assertNull($this->db->selectOne(
            'SELECT id FROM test_invites WHERE claimed_session_id = ?',
            [$this->secondId],
        ));
    }

    public function testTheOwnerCardRendersNeitherTheBearerTokenNorAClientResultLink(): void
    {
        $session = $this->sessions->getSessionById($this->secondId);
        self::assertIsArray($session);
        $partnerToken = (string) $session['session_token'];

        $html = $this->renderCard($this->firstId);

        self::assertStringContainsString('Парное прохождение', $html);
        self::assertStringContainsString('Партнёр 1 — начавший опросник', $html);
        self::assertStringContainsString('Партнёр 2 — приглашённый участник', $html);
        self::assertStringContainsString('pair-comparison-block', $html);
        self::assertStringContainsString('pair-chart-block', $html);

        self::assertStringNotContainsString('session_token', $html);
        self::assertStringNotContainsString('/result/', $html);
        self::assertStringNotContainsString($partnerToken, $html);
        self::assertStringNotContainsString($this->secondId, $html);
    }

    // ---------------------------------------------------------------- fixtures

    /**
     * Парная часть карточки — та же сборка, что делает `OwnerController`.
     *
     * @return array<string, mixed>|null
     */
    private function card(string $sessionId): ?array
    {
        $session = $this->sessions->getSessionById($sessionId);
        self::assertIsArray($session);

        $pair = (new ResultPresenter($this->db, $this->sessions))->pairViewData($session, $this->module);
        if ($pair === null) {
            return null;
        }

        $partner = $this->sessions->getSessionById($pair['partner_session_id']);
        self::assertIsArray($partner);

        return (new InvitedCasePresenter())->pair(
            $this->module,
            $pair,
            $session['answers'],
            $partner['answers'],
        );
    }

    private function renderCard(string $sessionId): string
    {
        $session = $this->sessions->getSessionById($sessionId);
        self::assertIsArray($session);

        $presenter = new InvitedCasePresenter();
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 2) . '/templates'), [
            'cache' => false,
            'strict_variables' => true,
        ]);
        TemplateFunctions::register($twig);

        return $twig->render('owner-invited-case.twig', [
            'appName' => 'PsyTest',
            'basePath' => '',
            'csrf_token' => 'synthetic-csrf-token',
            'flash' => null,
            'case' => [
                'id' => $sessionId,
                'test_name' => 'Опросник Лазаруса',
                'status' => 'completed',
                'claimed_at' => '2026-09-14 10:00:00',
                'completed_at' => '2026-09-14 10:20:00',
                'client_id' => null,
                'client_label' => null,
                'owner_note' => null,
                'answer_rows' => $presenter->answers($this->module, $session['answers']),
                'result_sections' => $presenter->resultSections($this->module, $session['calculated_results']),
                'pair' => $this->card($sessionId),
            ],
            'ai' => ['available' => false, 'has_jobs' => false, 'kinds' => [], 'owner_context_max' => 4000],
            'notify' => ['published' => false, 'has_email' => false, 'client_id' => null, 'last_at' => null],
        ]);
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
