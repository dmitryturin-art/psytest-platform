<?php

declare(strict_types=1);

namespace PsyTest\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use PsyTest\Core\Ai\AiClient;
use PsyTest\Core\Ai\AiProviderSettings;
use PsyTest\Core\Ai\AiReportContextBuilder;
use PsyTest\Core\Ai\AiReportGenerator;
use PsyTest\Core\Ai\AiReportRepository;
use PsyTest\Core\Ai\AiSettings;
use PsyTest\Core\Ai\AiTransport;
use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Ai\PromptRegistry;
use PsyTest\Core\Database;
use PsyTest\Core\ModuleLoader;
use PsyTest\Core\ResultPresenter;
use PsyTest\Core\SessionManager;
use PsyTest\Modules\Lazarus\LazarusModule;

/**
 * Промпты, отредактированные из кабинета (07.WP9).
 *
 * Уточнение владельца 26.08: файлы в `prompts/` остаются версионируемым
 * исходным состоянием в Git, правки владельца ложатся в БД поверх них, реестр
 * отдаёт версию из БД, если она есть, иначе файл. Здесь проверяется ровно это —
 * и то, что живое редактирование ничего не пишет в репозиторий.
 *
 * Внешний провайдер не вызывается: транспорт подменён.
 */
#[Group('database')]
final class PromptVersionsTest extends TestCase
{
    private const TEST = 'lazarus';
    private const MODE = 'individual';
    private const KIND = 'clear';

    private Database $db;
    private string $sessionId = '';

    protected function setUp(): void
    {
        $this->db = Database::getInstance();
        $this->cleanPromptTables();
    }

    protected function tearDown(): void
    {
        if ($this->sessionId !== '') {
            $this->db->delete('test_sessions', 'id = ?', [$this->sessionId]);
            $this->sessionId = '';
        }

        $this->cleanPromptTables();
    }

    private function cleanPromptTables(): void
    {
        $this->db->delete('prompt_versions', 'test = ?', [self::TEST]);
        $this->db->delete('prompt_publications', 'test = ?', [self::TEST]);
        $this->db->execute('DELETE FROM ai_settings');
    }

    private function registry(): PromptRegistry
    {
        // Новый экземпляр на каждое обращение: манифест кэшируется, а тест
        // как раз смотрит на поведение до и после публикации.
        return PromptRegistry::default($this->db);
    }

    // ------------------------------------------------------- версии и публикация

    public function testOwnerVersionGetsTheNextNumberAfterFilesAndPreviousOwnerEdits(): void
    {
        $fileVersions = (new PromptRegistry(dirname(__DIR__, 2) . '/prompts'))
            ->availableVersions(self::TEST, self::MODE, self::KIND);
        self::assertNotSame([], $fileVersions, 'Предусловие: у ключа есть файловые версии.');

        $first = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Правка владельца.', 'проба', false);
        self::assertSame(max($fileVersions) + 1, $first, 'Номер версии обязан быть сквозным.');

        $second = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Ещё правка.', null, false);
        self::assertSame($first + 1, $second);

        self::assertSame(
            array_merge($fileVersions, [$first, $second]),
            $this->registry()->availableVersions(self::TEST, self::MODE, self::KIND),
        );
    }

    public function testPublishingAnOwnerVersionSwitchesWhatTheRegistryServes(): void
    {
        $manifestVersion = $this->registry()->manifestVersion(self::TEST, self::MODE, self::KIND);
        $before = $this->registry()->published(self::TEST, self::MODE, self::KIND);
        self::assertInstanceOf(Prompt::class, $before);
        self::assertSame($manifestVersion, $before->version);

        $version = $this->registry()->createOwnerVersion(
            self::TEST,
            self::MODE,
            self::KIND,
            'Текст, написанный владельцем в кабинете.',
            'заметка',
            true,
        );

        // Сохранение — ещё не публикация: боевой поток не должен сдвинуться.
        self::assertSame($manifestVersion, $this->registry()->published(self::TEST, self::MODE, self::KIND)?->version);

        $this->registry()->publishVersion(self::TEST, self::MODE, self::KIND, $version);

        $after = $this->registry()->published(self::TEST, self::MODE, self::KIND);
        self::assertInstanceOf(Prompt::class, $after);
        self::assertSame($version, $after->version);
        self::assertSame('Текст, написанный владельцем в кабинете.', $after->text);
        self::assertTrue($after->allowsOwnerContext);
        self::assertTrue($after->isPublished());
    }

    public function testResetReturnsTheKeyToTheManifestVersion(): void
    {
        $manifestVersion = $this->registry()->manifestVersion(self::TEST, self::MODE, self::KIND);
        $version = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Временная правка.', null, false);
        $this->registry()->publishVersion(self::TEST, self::MODE, self::KIND, $version);
        self::assertSame($version, $this->registry()->published(self::TEST, self::MODE, self::KIND)?->version);

        $this->registry()->resetToManifest(self::TEST, self::MODE, self::KIND);

        self::assertNull($this->registry()->publishedOverride(self::TEST, self::MODE, self::KIND));
        self::assertSame($manifestVersion, $this->registry()->published(self::TEST, self::MODE, self::KIND)?->version);
    }

    public function testFileVersionStaysReachableAndTheRepositoryIsNeverWrittenTo(): void
    {
        $root = dirname(__DIR__, 2);
        $manifestVersion = (int) $this->registry()->manifestVersion(self::TEST, self::MODE, self::KIND);
        $fileText = (string) file_get_contents(
            sprintf('%s/prompts/%s/%s.%s.v%d.md', $root, self::TEST, self::MODE, self::KIND, $manifestVersion),
        );

        $version = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Совсем другой текст.', null, false);
        $this->registry()->publishVersion(self::TEST, self::MODE, self::KIND, $version);

        // Файловая версия по-прежнему доступна по своему номеру и не изменилась.
        $fileVersion = $this->registry()->version(self::TEST, self::MODE, self::KIND, $manifestVersion);
        self::assertInstanceOf(Prompt::class, $fileVersion);
        self::assertSame(trim($fileText), $fileVersion->text);

        $diff = [];
        exec('cd ' . escapeshellarg($root) . ' && git status --porcelain -- prompts 2>&1', $diff, $code);
        self::assertSame(0, $code, 'git status должен отработать.');
        self::assertSame([], $diff, 'Правки из кабинета не имеют права менять prompts/ в репозитории.');
    }

    public function testVersionCatalogMarksWhereEachVersionCameFrom(): void
    {
        $version = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Правка.', 'почему', false);
        $catalog = $this->registry()->versionCatalog(self::TEST, self::MODE, self::KIND);

        $sources = [];
        foreach ($catalog as $entry) {
            $sources[$entry['version']] = $entry['source'];
        }

        self::assertSame(PromptRegistry::SOURCE_OWNER, $sources[$version] ?? null);
        self::assertSame(PromptRegistry::SOURCE_FILE, $sources[1] ?? null);
    }

    public function testRegistryWithoutADatabaseStaysPurelyFileBased(): void
    {
        $fileOnly = new PromptRegistry(dirname(__DIR__, 2) . '/prompts');

        $version = $this->registry()->createOwnerVersion(self::TEST, self::MODE, self::KIND, 'Правка.', null, false);
        $this->registry()->publishVersion(self::TEST, self::MODE, self::KIND, $version);

        self::assertNull($fileOnly->publishedOverride(self::TEST, self::MODE, self::KIND));
        self::assertSame(
            $fileOnly->manifestVersion(self::TEST, self::MODE, self::KIND),
            $fileOnly->published(self::TEST, self::MODE, self::KIND)?->version,
        );
    }

    // --------------------------------------------------------------- выключатель

    public function testModelOverrideFromTheCabinetWinsOverTheEnvironment(): void
    {
        $config = require dirname(__DIR__, 2) . '/config.php';
        $settings = new AiSettings($this->db);

        self::assertSame('', $settings->modelOverride(), 'По умолчанию переопределения нет.');

        $settings->setModelOverride('owner/chosen-model');
        self::assertSame(
            'owner/chosen-model',
            AiProviderSettings::fromConfig($config, new AiSettings($this->db))->model,
        );

        // Пустое значение возвращает модель из окружения.
        (new AiSettings($this->db))->setModelOverride('');
        self::assertSame(
            AiProviderSettings::fromConfig($config)->model,
            AiProviderSettings::fromConfig($config, new AiSettings($this->db))->model,
        );
    }

    public function testDisabledAiFailsQueuedJobsWithTheOwnersReason(): void
    {
        (new AiSettings($this->db))->setAiEnabled(false);

        $sessions = new SessionManager($this->db);
        $reports = new AiReportRepository($this->db);
        $modules = (new ModuleLoader(null, $this->db))->discover();
        $contextBuilder = new AiReportContextBuilder($sessions, $modules);

        $this->createCompletedLazarusSession($sessions);

        $prompt = $this->registry()->published(self::TEST, self::MODE, self::KIND);
        self::assertInstanceOf(Prompt::class, $prompt);

        $context = $contextBuilder->build($this->sessionId, self::TEST, self::MODE);
        $job = $reports->request($this->sessionId, self::TEST, self::MODE, self::KIND, $prompt, $context);

        $generator = new AiReportGenerator(
            $reports,
            $contextBuilder,
            $this->registry(),
            new AiClient(
                new AiProviderSettings('https://provider.invalid/api/v1', 'fixture-key', 'fixture/model', 30),
                $this->failingTransport(),
                ownerSettings: new AiSettings($this->db),
            ),
        );

        $claimed = $reports->claimNext();
        self::assertIsArray($claimed);
        $generator->process($claimed);

        $processed = $reports->find((string) $job['id']);
        self::assertIsArray($processed);
        self::assertSame(AiReportRepository::STATUS_FAILED, $processed['status']);
        self::assertSame(AiClient::DISABLED_REASON, $processed['failure_reason']);
    }

    public function testDisabledAiRemovesTheOrderOfferFromTheResultPage(): void
    {
        $sessions = new SessionManager($this->db);
        $this->createCompletedLazarusSession($sessions);
        $session = $sessions->getSessionById($this->sessionId);
        self::assertIsArray($session);

        $presenter = new ResultPresenter($this->db, $sessions);

        $enabled = $presenter->reportViewData(self::TEST, $session);
        self::assertIsArray($enabled);
        self::assertFalse($enabled['ai_disabled'], 'По умолчанию разбор предлагается.');

        (new AiSettings($this->db))->setAiEnabled(false);

        $disabled = (new ResultPresenter($this->db, $sessions))->reportViewData(self::TEST, $session);
        self::assertIsArray($disabled);
        self::assertTrue($disabled['ai_disabled'], 'С выключенным ИИ страница не предлагает заказ.');
    }

    // ------------------------------------------------------------------ fixtures

    private function createCompletedLazarusSession(SessionManager $sessions): void
    {
        $test = $this->db->selectOne('SELECT id FROM tests WHERE slug = ?', [self::TEST]);
        self::assertIsArray($test, 'Предусловие: методика Лазаруса зарегистрирована.');

        $session = $sessions->createSession((int) $test['id']);
        $this->sessionId = (string) $session['id'];

        $module = new LazarusModule();
        $answers = ['gender' => 'female'];
        foreach ($module->getQuestions() as $index => $question) {
            $id = (int) $question['id'];
            $answers[$id . '_self'] = 4 + ($index % 7);
            $answers[$id . '_partner'] = 3 + ($index % 5);
        }

        $this->db->update(
            'test_sessions',
            [
                'status' => 'completed',
                'calculated_results' => json_encode($module->calculateResults($answers), JSON_UNESCAPED_UNICODE),
            ],
            'id = ?',
            [$this->sessionId],
        );
    }

    /**
     * Транспорт, который обязан остаться невызванным: выключенный разбор не
     * имеет права дойти до провайдера.
     */
    private function failingTransport(): AiTransport
    {
        $transport = $this->createStub(AiTransport::class);
        $transport->method('request')->willReturnCallback(
            static function (): array {
                throw new \LogicException('Провайдер не должен вызываться при выключенных разборах.');
            }
        );

        return $transport;
    }
}
