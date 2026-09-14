<?php

declare(strict_types=1);

namespace PsyTest\Core;

/**
 * Explicit lifecycle operations for clinical cases belonging to the owner.
 *
 * This service deliberately accepts a resolved session ID for mutations. A
 * public result token can only be used for the initial owner lookup and never
 * moves through redirects, audit details or list URLs.
 */
final class TherapistCaseService
{
    public function __construct(
        private readonly Database $db,
        private readonly SessionLifecycleService $lifecycle,
        private readonly TestInviteService $invites,
        private readonly TherapistClientService $clients,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function lookupByResultToken(string $token): ?array
    {
        return $this->db->selectOne(
            'SELECT sessions.id, sessions.status, sessions.retention_class, sessions.created_at, sessions.expires_at,
                    tests.name AS test_name, tests.slug AS test_slug
             FROM test_sessions AS sessions
             INNER JOIN tests ON tests.id = sessions.test_id
             WHERE sessions.session_token = :token AND sessions.status <> :deleted_status',
            ['token' => $token, 'deleted_status' => 'deleted'],
        );
    }

    public function assignCompletedSession(string $sessionId): bool
    {
        $this->db->beginTransaction();
        try {
            $updated = $this->db->update(
                'test_sessions',
                ['retention_class' => RetentionPolicy::THERAPIST_CASE],
                'id = ? AND status = ? AND retention_class = ?',
                [$sessionId, 'completed', RetentionPolicy::ANONYMOUS],
            );

            if ($updated === 0) {
                $existing = $this->db->selectOne(
                    'SELECT retention_class FROM test_sessions WHERE id = :id AND status = :status',
                    ['id' => $sessionId, 'status' => 'completed'],
                );
                if (($existing['retention_class'] ?? null) !== RetentionPolicy::THERAPIST_CASE) {
                    $this->db->rollback();

                    return false;
                }
            } else {
                $this->writeOwnerAuditEvent('therapist_case_assigned');
            }

            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    /**
     * Привязывает уже пройденную сессию к карточке клиента.
     *
     * Сессия могла состояться до всякого приглашения — по обычной публичной
     * ссылке. Такой кейс виден специалисту только через разовый поиск по
     * токену результата, поэтому здесь он один раз получает погашенное
     * приглашение: после этого он открывается в `/admin/invited-case/` и
     * попадает в историю карточки клиента наравне с назначенными.
     *
     * Ограничения намеренно узкие. Привязывается только завершённая сессия,
     * только один раз (`claimed_session_id` уникален) и только не удалённая.
     * Сессия из кабинета посетителя (`account`) не привязывается вовсе: она
     * принадлежит человеку, который её сохранил, а не специалисту
     * (PRODUCT_RULES §11). В парном Лазарусе привязывается ровно та сессия,
     * чей токен ввели: партнёрская остаётся чужой.
     *
     * @param string|null $clientId существующая карточка либо NULL, и тогда
     *                              карточка создаётся из `$newClientLabel`.
     */
    public function attachToClient(
        string $sessionId,
        ?string $clientId,
        string $note,
        string $newClientLabel = '',
    ): bool {
        $session = $this->db->selectOne(
            'SELECT id, test_id, status, retention_class FROM test_sessions WHERE id = :id',
            ['id' => $sessionId],
        );
        if (
            $session === null
            || $session['status'] !== 'completed'
            || !in_array($session['retention_class'], [RetentionPolicy::ANONYMOUS, RetentionPolicy::THERAPIST_CASE], true)
        ) {
            return false;
        }

        $alreadyBound = $this->db->selectOne(
            'SELECT id FROM test_invites WHERE claimed_session_id = :session_id',
            ['session_id' => $sessionId],
        );
        if ($alreadyBound !== null) {
            return false;
        }

        if ($clientId !== null && !$this->clients->exists($clientId)) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            if ($clientId === null) {
                $clientId = $this->clients->create($newClientLabel, '');
            }

            $this->invites->bindExistingSession($sessionId, (int) $session['test_id'], $clientId, $note);
            $this->db->update(
                'test_sessions',
                ['retention_class' => RetentionPolicy::THERAPIST_CASE],
                'id = ? AND status = ?',
                [$sessionId, 'completed'],
            );
            $this->writeOwnerAuditEvent('therapist_case_attached');
            $this->db->commit();

            return true;
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    public function deleteAssignedCase(string $sessionId): bool
    {
        $case = $this->db->selectOne(
            'SELECT id FROM test_sessions WHERE id = :id AND retention_class = :retention_class',
            ['id' => $sessionId, 'retention_class' => RetentionPolicy::THERAPIST_CASE],
        );
        if ($case === null || !$this->lifecycle->deleteSessionAndArtifacts($sessionId)) {
            return false;
        }

        // This deliberately survives the clinical session deletion. It proves
        // that an owner action happened without retaining a token, session ID,
        // test name, IP address, user agent or answers.
        $this->writeOwnerAuditEvent('therapist_case_deleted');

        return true;
    }

    private function writeOwnerAuditEvent(string $action): void
    {
        $this->db->insert('activity_log', [
            'session_id' => null,
            'test_id' => null,
            'action' => $action,
            'details' => json_encode(['actor' => 'owner'], JSON_THROW_ON_ERROR),
        ]);
    }
}
