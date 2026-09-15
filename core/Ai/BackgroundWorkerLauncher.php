<?php

declare(strict_types=1);

namespace PsyTest\Core\Ai;

use PsyTest\Core\LoggerFactory;

/**
 * Запуск обработчика очереди ИИ-разборов отдельным процессом.
 *
 * Схема «ответить браузеру и доработать в том же процессе» (ResponseFinisher)
 * держится только там, где есть `fastcgi_finish_request`. На боевом хостинге
 * PHP работает как `apache2handler` за nginx: этой функции нет, соединение
 * живёт до конца скрипта, и nginx закрывает его по 504 примерно через минуту —
 * задание при этом остаётся в `running` до получасового возврата зависших.
 *
 * Поэтому, если в окружении задан путь к CLI PHP (`AI_WORKER_PHP_BIN`),
 * веб-запрос только ставит задание и отсоединённым процессом запускает
 * `bin/generate-ai-reports.php`. Запрос отвечает сразу, а работа идёт вне его
 * жизненного цикла и переживает 504.
 *
 * Секреты в команду не попадают: скрипт читает `.env` сам, аргумент только один
 * — сколько заданий взять за прогон.
 */
final class BackgroundWorkerLauncher
{
    /**
     * Защита от шторма: одно нажатие — один запуск.
     *
     * Лишний воркер безвреден (`claimNext` атомарен), но десяток процессов на
     * двойном клике или на параллельных заказах хостингу не нужен.
     */
    public const THROTTLE_SECONDS = 10;

    public const LOG_FILE = 'ai-worker.log';

    /** @var (callable(string): void)|null */
    private $runner;

    /**
     * @param string $phpBinary Путь к CLI PHP; пустая строка означает «фоновый запуск не настроен».
     * @param (callable(string): void)|null $runner Подменяемый запуск команды; по умолчанию `exec`.
     */
    public function __construct(
        private readonly string $phpBinary,
        private readonly string $projectRoot,
        private readonly string $logPath,
        ?callable $runner = null,
    ) {
        $this->runner = $runner;
    }

    public static function fromConfig(object $config, ?callable $runner = null): self
    {
        // Конфиг — анонимный класс без интерфейса, поэтому вызовы динамические.
        return new self(
            trim((string) $config->aiWorkerPhpBin()),
            dirname(__DIR__, 2),
            (string) $config->logPath(),
            $runner,
        );
    }

    /**
     * Запустить обработчик очереди отдельным процессом.
     *
     * @return bool true — процесс запущен, веб-запросу доделывать нечего;
     *              false — фоновый запуск недоступен, и вызывающий код должен
     *              остаться на прежнем пути через ResponseFinisher.
     */
    public function launch(int $limit): bool
    {
        if ($this->phpBinary === '') {
            return false;
        }

        $runner = $this->runner ?? self::execRunner();
        if ($runner === null) {
            LoggerFactory::getLogger('ai')->warning('ai worker launch skipped: exec is not available');

            return false;
        }

        if (!$this->reserveSlot()) {
            // Воркер только что запущен этим же процессом или соседним
            // запросом: он возьмёт и это задание.
            return true;
        }

        $command = $this->command(max(1, $limit));

        try {
            $runner($command);
        } catch (\Throwable $e) {
            // В команде нет ни ключей, ни токенов, но в лог всё равно идёт
            // только класс ошибки: тело исключения — внешний текст.
            LoggerFactory::getLogger('ai')->error('ai worker launch failed: ' . $e::class);

            return false;
        }

        return true;
    }

    /**
     * Команда запуска.
     *
     * `nohup ... &` отвязывает процесс от веб-запроса: он переживает и уход
     * посетителя, и обрыв соединения по таймауту nginx. Вывод уходит в общий
     * лог воркера, потому что без перенаправления `nohup` пишет в файл рядом с
     * рабочим каталогом.
     */
    public function command(int $limit): string
    {
        $script = $this->projectRoot . '/bin/generate-ai-reports.php';
        $log = rtrim($this->logPath, '/') . '/' . self::LOG_FILE;

        return sprintf(
            'cd %s && nohup %s %s %s >> %s 2>&1 &',
            escapeshellarg($this->projectRoot),
            escapeshellarg($this->phpBinary),
            escapeshellarg($script),
            escapeshellarg('--limit=' . $limit),
            escapeshellarg($log),
        );
    }

    /** @return (callable(string): void)|null */
    private static function execRunner(): ?callable
    {
        if (!function_exists('exec')) {
            return null;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        if (in_array('exec', $disabled, true)) {
            return null;
        }

        return static function (string $command): void {
            exec($command);
        };
    }

    /**
     * Занять окно запуска: не чаще одного раза в THROTTLE_SECONDS.
     *
     * Состояние живёт в файле, а не в процессе: параллельные веб-запросы — это
     * разные процессы, и общая память им недоступна.
     */
    private function reserveSlot(): bool
    {
        $lock = $this->lockFile();
        if ($lock === null) {
            // Каталог недоступен — лучше запустить лишний процесс, чем не
            // запустить нужный.
            return true;
        }

        $modifiedAt = is_file($lock) ? (int) @filemtime($lock) : 0;
        if ($modifiedAt > 0 && (time() - $modifiedAt) < self::THROTTLE_SECONDS) {
            return false;
        }

        @touch($lock);

        return true;
    }

    private function lockFile(): ?string
    {
        $dir = $this->projectRoot . '/storage/cache';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        return $dir . '/ai-worker.lock';
    }
}
