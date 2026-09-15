<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\PromptFixtureContext;
use PsyTest\Core\Ai\SmilGlossaryCompactor;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Smil\SmilModule;

/**
 * Режим глоссария СМИЛ от настройки кабинета до нагрузки провайдера (07.G6).
 *
 * Владелец сравнивает разборы одного кейса на полном и компактном глоссарии,
 * поэтому важны две вещи: настройка действительно доезжает до боевого запроса,
 * и предпросмотр в кабинете сжимается тем же кодом, а не «примерно так же».
 */
#[Group('database')]
final class SmilGlossaryModeTest extends TestCase
{
    private Database $db;
    private SessionManager $sessions;
    private ModuleLoader $modules;
    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->sessions = new SessionManager($this->db);
        $this->modules = (new ModuleLoader(null, $this->db))->discover();
        $this->db->execute('DELETE FROM ai_settings');

        $test = $this->db->selectOne("SELECT id FROM tests WHERE slug = 'smil'");
        self::assertIsArray($test, 'Предусловие: СМИЛ зарегистрирован.');

        $session = $this->sessions->createSession((int) $test['id']);
        $this->sessionId = (string) $session['id'];

        $this->db->update(
            'test_sessions',
            [
                'status' => 'completed',
                'calculated_results' => json_encode($this->smilResults(), JSON_UNESCAPED_UNICODE),
            ],
            'id = ?',
            [$this->sessionId],
        );
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
            $this->sessionId = '';
        }

        $this->db->execute('DELETE FROM ai_settings');
    }

    /** @return array<string, mixed> */
    private function smilResults(): array
    {
        $module = new SmilModule();
        $answers = ['gender' => 'female'];
        foreach ($module->getQuestions() as $index => $question) {
            $answers[$question['id']] = $index % 3;
        }

        return $module->calculateResults($answers);
    }

    /** @return array<string, mixed> */
    private function liveContext(): array
    {
        return (new AiReportContextBuilder($this->sessions, $this->modules, new AiSettings($this->db)))
            ->build($this->sessionId, 'smil', 'individual');
    }

    public function testDefaultSettingKeepsTheFullGlossary(): void
    {
        // Ничего не настраивали — поведение ровно как до пакета.
        self::assertSame('full', (new AiSettings($this->db))->smilGlossaryMode());
        self::assertSame('full', $this->liveContext()['glossary_mode']);
    }

    public function testSettingIsStoredAndReadBackAndGarbageFallsBackToFull(): void
    {
        $settings = new AiSettings($this->db);

        $settings->setSmilGlossaryMode('compact');
        self::assertSame('compact', (new AiSettings($this->db))->smilGlossaryMode());

        $settings->setSmilGlossaryMode('full');
        self::assertSame('full', (new AiSettings($this->db))->smilGlossaryMode());

        // Значение мимо словаря не должно молча резать нагрузку.
        (new AiSettings($this->db))->setSmilGlossaryMode('полу-компактный');
        self::assertSame('full', (new AiSettings($this->db))->smilGlossaryMode());
    }

    public function testCompactSettingReachesTheLivePayload(): void
    {
        (new AiSettings($this->db))->setSmilGlossaryMode('compact');

        $context = $this->liveContext();

        self::assertSame('compact', $context['glossary_mode']);
        self::assertContains(SmilGlossaryCompactor::COMPACT_PRINCIPLE, $context['levels']['principles']);

        $short = 0;
        foreach ($context['additional_scales_glossary'] as $entry) {
            $short += array_keys($entry) === ['meaning'] ? 1 : 0;
        }
        self::assertGreaterThan(0, $short, 'В компактном режиме часть шкал обязана уйти одной строкой.');

        $full = (string) json_encode($this->fullLiveContext(), JSON_UNESCAPED_UNICODE);
        $compact = (string) json_encode($context, JSON_UNESCAPED_UNICODE);
        self::assertLessThan(mb_strlen($full), mb_strlen($compact));
    }

    /** @return array<string, mixed> */
    private function fullLiveContext(): array
    {
        (new AiSettings($this->db))->setSmilGlossaryMode('full');
        $context = $this->liveContext();
        (new AiSettings($this->db))->setSmilGlossaryMode('compact');

        return $context;
    }

    /**
     * Предпросмотр и боевая сборка сжимаются одним и тем же кодом.
     *
     * Ответы у них разные (в кабинете нет и не может быть реальной сессии),
     * поэтому сравниваются не сами нагрузки, а преобразование: компактная
     * нагрузка обязана совпасть с компактором, применённым к полной.
     */
    public function testPreviewAndLiveBuildApplyTheSameCompaction(): void
    {
        $module = $this->modules->getModule('smil');
        self::assertNotNull($module);
        $compactor = new SmilGlossaryCompactor(SmilGlossaryCompactor::MODE_COMPACT);

        (new AiSettings($this->db))->setSmilGlossaryMode('full');
        $liveFull = $this->liveContext();
        $previewFull = PromptFixtureContext::build($module, 'individual', new AiSettings($this->db));
        self::assertSame('full', $previewFull['glossary_mode']);

        (new AiSettings($this->db))->setSmilGlossaryMode('compact');
        self::assertSame($compactor->apply($liveFull), $this->liveContext());
        self::assertSame(
            $compactor->apply($previewFull),
            PromptFixtureContext::build($module, 'individual', new AiSettings($this->db)),
        );
    }
}
