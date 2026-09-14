<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Mail\MailerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Добровольный кабинет посетителя: вход по одноразовой ссылке на email.
 *
 * Пароля нет, поэтому единственный credential — ссылка из письма: в базе
 * лежит только её SHA-256, срок жизни 15 минут, и погашается она одним
 * атомарным UPDATE, чтобы повторное открытие ничего не открыло.
 *
 * Результат НИКОГДА не попадает в кабинет сам: ни по совпадению email, ни по
 * cookie, ни по IP (PRODUCT_RULES §11, D-053). Привязка возможна только когда
 * посетитель одновременно держит в руках bearer-токен результата и находится
 * в своём кабинете.
 */
final class VisitorAccountService
{
    public const LOGIN_TOKEN_TTL_MINUTES = 15;
    public const RATE_LIMIT_WINDOW_MINUTES = 15;
    public const MAX_LOGIN_REQUESTS_PER_WINDOW = 3;
    /**
     * Потолок на всю платформу внутри того же окна.
     *
     * Лимит на адрес сам по себе останавливает только перебор одного ящика:
     * рассылку по тысяче чужих адресов он пропускает, потому что каждый из них
     * укладывается в свои три запроса. IP для этого не хранится (ER §9) —
     * общий счётчик стоит вместо него.
     */
    public const MAX_LOGIN_REQUESTS_GLOBAL_PER_WINDOW = 20;

    public function __construct(
        private readonly Database $db,
        private readonly MailerInterface $mailer,
        private readonly SessionLifecycleService $lifecycle,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Обычная сборка для веб-запроса.
     *
     * Тесты собирают сервис вручную с фейковым отправителем: проверки не ходят
     * в сеть и не отправляют писем.
     */
    public static function fromConfig(Database $db): self
    {
        /** @var object $config */
        $config = require dirname(__DIR__) . '/config.php';

        return new self(
            $db,
            Mail\MailerFactory::fromConfig($config),
            new SessionLifecycleService(
                $db,
                new RetentionPolicy($config->anonymousRetentionDays()),
                $config->pdfStoragePath(),
            ),
            $config->appUrl(),
        );
    }

    /**
     * Просит ссылку входа.
     *
     * Метод ничего не возвращает и не сообщает вызывающему, был ли адрес
     * известен, отвергнут проверкой или упёрся в лимит: контроллер обязан
     * показать один и тот же ответ, иначе форма входа стала бы способом
     * перебирать чужие адреса.
     */
    public function requestLogin(string $email): void
    {
        $email = self::normalizeEmail($email);
        if ($email === '' || !Security::isValidEmail($email)) {
            return;
        }
        $rateKey = self::rateKey($email);
        if ($this->recentRequestCount($rateKey) >= self::MAX_LOGIN_REQUESTS_PER_WINDOW) {
            return;
        }
        if ($this->recentGlobalRequestCount() >= self::MAX_LOGIN_REQUESTS_GLOBAL_PER_WINDOW) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        // Срок считает сама БД. Часы PHP и MySQL здесь могут стоять в разных
        // поясах, а пятнадцатиминутное окно такой сдвиг переживает плохо: срок,
        // посчитанный на стороне PHP, мог бы истечь ещё до отправки письма.
        $this->db->execute(
            'INSERT INTO visitor_login_tokens (id, email, rate_key, token_hash, expires_at)
             VALUES (:id, :email, :rate_key, :token_hash, NOW() + INTERVAL ' . self::LOGIN_TOKEN_TTL_MINUTES . ' MINUTE)',
            [
                'id' => Uuid::uuid4()->toString(),
                'email' => $email,
                'rate_key' => $rateKey,
                'token_hash' => hash('sha256', $token),
            ],
        );

        // Сбой отправки не меняет ответ формы: иначе ошибка SMTP отличала бы
        // известный адрес от неизвестного ровно так же, как это делало бы
        // честное сообщение «такого адреса нет». Токен при этом остаётся в
        // базе — он никому не известен и через 15 минут истекает сам.
        try {
            $this->mailer->send($email, self::loginSubject(), self::loginBody($this->appUrl, $token));
        } catch (\Throwable) {
            // В сообщение не попадают ни адрес, ни ссылка: лог не является
            // местом хранения credential и персональных данных (ER §9).
            LoggerFactory::getLogger('mail')->error('login mail delivery failed');
        }
    }

    public static function loginSubject(): string
    {
        return 'Вход в PsyTest';
    }

    public static function loginBody(string $appUrl, string $token): string
    {
        $link = rtrim($appUrl, '/') . '/account/login/' . $token;

        return "Ссылка для входа в личный кабинет PsyTest:\n\n"
            . $link . "\n\n"
            . "Откройте её и нажмите кнопку «Войти в кабинет».\n\n"
            . 'Ссылка действует ' . self::LOGIN_TOKEN_TTL_MINUTES . " минут и срабатывает один раз.\n\n"
            . "Если вы не запрашивали вход — просто проигнорируйте письмо.\n";
    }

    /**
     * Погашает ссылку входа и возвращает аккаунт.
     *
     * Гонка решается самим UPDATE: две одновременные попытки открыть одну
     * ссылку дают rowCount 1 и 0, поэтому вход происходит ровно один раз.
     *
     * @return array{id: string, email: string}|null
     */
    public function consumeLogin(string $token): ?array
    {
        if (!self::isLoginTokenFormat($token)) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $consumed = $this->db->execute(
                'UPDATE visitor_login_tokens
                 SET used_at = NOW()
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()',
                ['token_hash' => $tokenHash],
            )->rowCount();

            if ($consumed !== 1) {
                $this->db->rollback();

                return null;
            }

            $row = $this->db->selectOne(
                'SELECT email FROM visitor_login_tokens WHERE token_hash = :token_hash',
                ['token_hash' => $tokenHash],
            );
            if ($row === null) {
                throw new \LogicException('Consumed login token disappeared');
            }

            $email = (string) $row['email'];
            $account = $this->db->selectOne(
                'SELECT id, email FROM visitor_accounts WHERE email = :email',
                ['email' => $email],
            );
            if ($account === null) {
                $accountId = Uuid::uuid4()->toString();
                $this->db->insert('visitor_accounts', ['id' => $accountId, 'email' => $email]);
                $account = ['id' => $accountId, 'email' => $email];
            }

            // Отметка входа тоже ставится часами БД, как и сроки токенов.
            $this->db->execute(
                'UPDATE visitor_accounts SET last_login_at = NOW() WHERE id = :id',
                ['id' => $account['id']],
            );

            $this->db->commit();

            return ['id' => (string) $account['id'], 'email' => (string) $account['email']];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    /** @return array{id: string, email: string}|null */
    public function find(string $accountId): ?array
    {
        if (!Security::isValidUuid($accountId)) {
            return null;
        }

        $account = $this->db->selectOne(
            'SELECT id, email FROM visitor_accounts WHERE id = :id',
            ['id' => $accountId],
        );

        return $account === null
            ? null
            : ['id' => (string) $account['id'], 'email' => (string) $account['email']];
    }

    /**
     * Привязывает результат к кабинету.
     *
     * Условие намеренно двойное: нужен и вход в кабинет, и точный bearer-токен
     * именно этой сессии. Поэтому чужой результат нельзя присвоить, зная один
     * только его идентификатор, а свой — нельзя привязать «автоматически».
     * Кейс специалиста (`therapist_case`) в кабинет посетителя не переходит:
     * его режим хранения назначает владелец, а не респондент.
     */
    public function attach(string $accountId, string $sessionId, string $resultToken): bool
    {
        if (!Security::isValidUuid($accountId) || !Security::isValidUuid($sessionId)) {
            return false;
        }
        if ($this->find($accountId) === null) {
            return false;
        }

        $session = $this->db->selectOne(
            "SELECT id, session_token FROM test_sessions
             WHERE id = :id
               AND status = 'completed'
               AND retention_class = :retention_class
               AND account_id IS NULL",
            ['id' => $sessionId, 'retention_class' => RetentionPolicy::ANONYMOUS],
        );
        if ($session === null || !hash_equals((string) $session['session_token'], $resultToken)) {
            return false;
        }

        return $this->db->update(
            'test_sessions',
            ['account_id' => $accountId, 'retention_class' => RetentionPolicy::ACCOUNT],
            'id = ? AND account_id IS NULL',
            [$sessionId],
        ) === 1;
    }

    /**
     * Снимает связь.
     *
     * Сессия возвращается в анонимный класс, а её 180 дней по-прежнему идут от
     * `created_at`: отвязка не продлевает хранение и не удаляет результат —
     * ссылка на него остаётся рабочей у того, у кого она есть.
     */
    public function detach(string $accountId, string $sessionId): bool
    {
        if (!Security::isValidUuid($accountId) || !Security::isValidUuid($sessionId)) {
            return false;
        }

        return $this->db->update(
            'test_sessions',
            ['account_id' => null, 'retention_class' => RetentionPolicy::ANONYMOUS],
            'id = ? AND account_id = ?',
            [$sessionId, $accountId],
        ) === 1;
    }

    /**
     * История кабинета.
     *
     * `session_token` здесь не выбирается вовсе: в кабинете он не нужен, а
     * попав в HTML, стал бы вторым способом раздать доступ к результату
     * (PRODUCT_RULES §11).
     *
     * @return list<array<string, mixed>>
     */
    public function history(string $accountId): array
    {
        if (!Security::isValidUuid($accountId)) {
            return [];
        }

        return $this->db->select(
            "SELECT sessions.id, sessions.completed_at, sessions.created_at,
                    tests.name AS test_name, tests.slug AS test_slug
             FROM test_sessions AS sessions
             INNER JOIN tests ON tests.id = sessions.test_id
             WHERE sessions.account_id = :account_id AND sessions.status = 'completed'
             ORDER BY sessions.completed_at DESC, sessions.created_at DESC",
            ['account_id' => $accountId],
        );
    }

    /**
     * Сессия кабинета по владению, а не по bearer-токену.
     *
     * `expires_at` здесь не проверяется намеренно: 30-дневный срок ссылки —
     * свойство самой ссылки, а сохранённый в кабинете результат живёт, пока
     * посетитель его не отвяжет или не удалит аккаунт.
     *
     * @return array<string, mixed>|null
     */
    public function findSessionForAccount(string $accountId, string $sessionId): ?array
    {
        if (!Security::isValidUuid($accountId) || !Security::isValidUuid($sessionId)) {
            return null;
        }

        $session = $this->db->selectOne(
            "SELECT * FROM test_sessions
             WHERE id = :id AND account_id = :account_id AND status = 'completed'",
            ['id' => $sessionId, 'account_id' => $accountId],
        );
        if ($session === null) {
            return null;
        }

        $session['answers'] = $session['answers'] !== null && $session['answers'] !== ''
            ? json_decode((string) $session['answers'], true) : [];
        $session['calculated_results'] = $session['calculated_results'] !== null && $session['calculated_results'] !== ''
            ? json_decode((string) $session['calculated_results'], true) : [];
        $session['demographics'] = $session['demographics'] !== null && $session['demographics'] !== ''
            ? json_decode((string) $session['demographics'], true) : [];

        return $session;
    }

    /**
     * Удаляет аккаунт вместе со всем, что в нём сохранено.
     *
     * Сохранённый результат удаляется по-настоящему — тем же путём, что и кейс
     * специалиста: файлы сначала, строки одной транзакцией. Оставить их как
     * анонимные означало бы, что «удалить аккаунт» ничего не удаляет.
     */
    public function deleteAccount(string $accountId): bool
    {
        if (!Security::isValidUuid($accountId)) {
            return false;
        }
        $account = $this->find($accountId);
        if ($account === null) {
            return false;
        }

        $sessions = $this->db->select(
            'SELECT id FROM test_sessions WHERE account_id = :account_id',
            ['account_id' => $accountId],
        );

        $this->db->beginTransaction();
        try {
            foreach ($sessions as $session) {
                $this->lifecycle->deleteSessionAndArtifacts((string) $session['id']);
            }
            $this->db->delete('visitor_login_tokens', 'email = ?', [$account['email']]);
            $this->db->delete('visitor_accounts', 'id = ?', [$accountId]);
            $this->db->commit();
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }

        return true;
    }

    private function recentRequestCount(string $rateKey): int
    {
        // Окно тоже считает БД: `created_at` пишется её часами.
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS count FROM visitor_login_tokens
             WHERE rate_key = :rate_key AND created_at > NOW() - INTERVAL ' . self::RATE_LIMIT_WINDOW_MINUTES . ' MINUTE',
            ['rate_key' => $rateKey],
        );

        return (int) ($row['count'] ?? 0);
    }

    private function recentGlobalRequestCount(): int
    {
        $row = $this->db->selectOne(
            'SELECT COUNT(*) AS count FROM visitor_login_tokens
             WHERE created_at > NOW() - INTERVAL ' . self::RATE_LIMIT_WINDOW_MINUTES . ' MINUTE',
        );

        return (int) ($row['count'] ?? 0);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Канонический адрес для счётчика запросов.
     *
     * Отбрасывается только `+suffix`: точки в локальной части у большинства
     * провайдеров значимы, и «канонизировать» их означало бы считать письма
     * разных людей одним ящиком.
     */
    public static function rateKey(string $email): string
    {
        $email = self::normalizeEmail($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');

        return ($plus === false ? $local : substr($local, 0, $plus)) . substr($email, $at);
    }

    /**
     * Формат ссылки входа — 64 hex.
     *
     * Проверка вынесена сюда, чтобы страница подтверждения отсеивала мусор,
     * не обращаясь к базе и ничего не погашая.
     */
    public static function isLoginTokenFormat(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/i', $token) === 1;
    }
}
