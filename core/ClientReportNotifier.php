<?php

declare(strict_types=1);

namespace PsyTest\Core;

use PsyTest\Core\Ai\Prompt;
use PsyTest\Core\Mail\MailerFactory;
use PsyTest\Core\Mail\MailerInterface;

/**
 * Одно короткое письмо клиенту: «разбор готов» (D-054, часть 2).
 *
 * Письмо отправляется только руками специалиста и только когда он уже
 * опубликовал версию разбора: автоматической отправки нет вовсе
 * (PRODUCT_RULES §4).
 *
 * В письме намеренно нет ни текста разбора, ни ссылки на результат, ни имени
 * и подписи клиента. Ссылка на результат — это bearer-токен: письмо живёт в
 * чужих почтовых серверах и пересылается дальше, поэтому отправлять ключ
 * доступа в нём нельзя (PRODUCT_RULES §11). Клиент открывает ту самую
 * страницу, которую получил после прохождения теста, — разбор уже там.
 *
 * Адрес берётся из карточки клиента и только оттуда: сессия сама по себе
 * никакого контакта не содержит.
 */
final class ClientReportNotifier
{
    /**
     * Не чаще одного письма в 10 минут на разбор.
     *
     * Лимит стоит не против злоупотребления (кабинет — один владелец), а
     * против двойного клика и второй вкладки: клиент не должен получить два
     * одинаковых письма подряд.
     */
    public const MIN_INTERVAL_MINUTES = 10;

    public function __construct(
        private readonly Database $db,
        private readonly MailerInterface $mailer,
    ) {
    }

    /** Обычная сборка для веб-запроса. Тесты подставляют свой отправитель. */
    public static function fromConfig(Database $db): self
    {
        /** @var object $config */
        $config = require dirname(__DIR__) . '/config.php';

        return new self($db, MailerFactory::fromConfig($config));
    }

    /**
     * Уведомить клиента этого кейса о готовом разборе.
     *
     * Возвращает false и ничего не отправляет, если разбор не опубликован, у
     * карточки нет адреса, письмо по этому разбору уже уходило в последние
     * десять минут или отправка не удалась.
     */
    public function notify(string $sessionId): bool
    {
        if (!Security::isValidUuid($sessionId)) {
            return false;
        }

        $target = $this->publishedReportWithClientEmail($sessionId);
        if ($target === null) {
            return false;
        }

        if ($target['notified_recently']) {
            return false;
        }

        try {
            $this->mailer->send($target['email'], self::subject(), self::body());
        } catch (\Throwable) {
            // Ни адреса, ни идентификаторов кейса: лог не место для
            // персональных данных (ENGINEERING_RULES §9).
            LoggerFactory::getLogger('mail')->error('client report notification delivery failed');

            return false;
        }

        $this->db->execute(
            'UPDATE ai_reports SET client_notified_at = NOW() WHERE id = :id',
            ['id' => $target['report_id']],
        );

        return true;
    }

    /** Есть ли что отправлять: опубликованный разбор и адрес в карточке. */
    public function canNotify(string $sessionId): bool
    {
        return Security::isValidUuid($sessionId) && $this->publishedReportWithClientEmail($sessionId) !== null;
    }

    /** Когда по этому кейсу в последний раз уходило письмо, если уходило. */
    public function lastNotifiedAt(string $sessionId): ?string
    {
        if (!Security::isValidUuid($sessionId)) {
            return null;
        }

        $row = $this->db->selectOne(
            'SELECT client_notified_at FROM ai_reports
             WHERE session_id = :session_id AND report_kind = :kind AND client_notified_at IS NOT NULL
             ORDER BY client_notified_at DESC LIMIT 1',
            ['session_id' => $sessionId, 'kind' => Prompt::KIND_CLEAR],
        );

        return $row === null ? null : (string) $row['client_notified_at'];
    }

    public static function subject(): string
    {
        return 'Ваш разбор готов';
    }

    /**
     * Текст письма.
     *
     * Он одинаков для всех и не содержит ни имени, ни подписи, ни ссылки: так
     * письмо, попавшее не туда, не рассказывает получателю ничего о клиенте.
     */
    public static function body(): string
    {
        return "Специалист подготовил разбор ваших результатов.\n\n"
            . "Откройте свою страницу результата, которую вы получили после прохождения теста — "
            . "разбор появился там.\n\n"
            . "Если письмо пришло по ошибке, просто проигнорируйте его.\n";
    }

    /**
     * Опубликованный понятный разбор кейса вместе с адресом его карточки.
     *
     * Всё соединяется одним запросом намеренно: письмо уходит только когда
     * одновременно верны публикация, therapist-режим кейса и заполненный
     * адрес. Разваленная на части проверка легко разошлась бы с этим.
     *
     * Окно повтора считает сама БД: `client_notified_at` пишется её же `NOW()`,
     * и сравнивать его с часами PHP нельзя — пояса у них расходятся.
     *
     * @return array{report_id: string, email: string, notified_recently: bool}|null
     */
    private function publishedReportWithClientEmail(string $sessionId): ?array
    {
        $row = $this->db->selectOne(
            'SELECT reports.id AS report_id, clients.email,
                    (reports.client_notified_at IS NOT NULL
                     AND reports.client_notified_at > NOW() - INTERVAL ' . self::MIN_INTERVAL_MINUTES . ' MINUTE)
                    AS notified_recently
             FROM ai_reports AS reports
             INNER JOIN test_sessions AS sessions ON sessions.id = reports.session_id
             INNER JOIN test_invites AS invites ON invites.claimed_session_id = sessions.id
             INNER JOIN therapist_clients AS clients ON clients.id = invites.client_id
             WHERE reports.session_id = :session_id
               AND reports.report_kind = :kind
               AND reports.published_revision_id IS NOT NULL
               AND sessions.retention_class = :retention_class
               AND clients.email IS NOT NULL
             ORDER BY reports.published_at DESC LIMIT 1',
            [
                'session_id' => $sessionId,
                'kind' => Prompt::KIND_CLEAR,
                'retention_class' => RetentionPolicy::THERAPIST_CASE,
            ],
        );

        if ($row === null) {
            return null;
        }

        return [
            'report_id' => (string) $row['report_id'],
            'email' => (string) $row['email'],
            'notified_recently' => (bool) $row['notified_recently'],
        ];
    }
}
