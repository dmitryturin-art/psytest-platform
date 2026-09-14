<?php

declare(strict_types=1);

namespace PsyTest\Core;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;

/**
 * Owner-created, single-use invitations to any active supported test.
 *
 * The browser receives a random bearer token once. The database stores only
 * its SHA-256 hash; claim and session binding are one transaction so a second
 * open cannot create a second clinical case.
 */
final class TestInviteService
{
    public const TTL_DAYS = 14;

    public function __construct(
        private readonly Database $db,
        private readonly SessionManager $sessions,
    ) {
    }

    /** @return array{id: string, token: string, expires_at: string} */
    public function create(int $testId, string $ownerNote, ?string $clientId = null): array
    {
        $test = $this->db->selectOne(
            'SELECT id FROM tests WHERE id = :id AND is_active = 1',
            ['id' => $testId],
        );
        if ($test === null) {
            throw new \InvalidArgumentException('Test is unavailable for invitations');
        }
        if ($clientId !== null) {
            $client = $this->db->selectOne('SELECT id FROM therapist_clients WHERE id = :id', ['id' => $clientId]);
            if ($client === null) {
                throw new \InvalidArgumentException('Client card does not exist');
            }
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = new DateTimeImmutable('+' . self::TTL_DAYS . ' days');
        $id = Uuid::uuid4()->toString();
        $this->db->insert('test_invites', [
            'id' => $id,
            'test_id' => $testId,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', $token),
            'owner_note' => $ownerNote === '' ? null : $ownerNote,
            'status' => 'pending',
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
        ]);

        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt->format('Y-m-d H:i:s')];
    }

    /**
     * Записывает уже пройденную сессию как погашенное приглашение клиента.
     *
     * Ссылки здесь нет и быть не может: сессия состоялась без приглашения, а
     * строка нужна только чтобы кейс появился в карточке клиента и открывался
     * по `/admin/invited-case/`. Поэтому `token_hash` берётся от случайных
     * байтов, сам токен никуда не возвращается, а `expires_at` ставится в
     * прошлое — такое приглашение нельзя ни открыть, ни погасить повторно.
     *
     * Транзакцию открывает вызывающий код: привязка идёт вместе со сменой
     * режима хранения сессии и, при необходимости, созданием карточки.
     */
    public function bindExistingSession(string $sessionId, int $testId, ?string $clientId, string $note): string
    {
        $id = Uuid::uuid4()->toString();
        $this->db->insert('test_invites', [
            'id' => $id,
            'test_id' => $testId,
            'client_id' => $clientId,
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'owner_note' => $note === '' ? null : $note,
            'status' => 'claimed',
            'claimed_session_id' => $sessionId,
            'claimed_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s'),
        ]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function preview(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
            return null;
        }

        return $this->db->selectOne(
            "SELECT tests.name AS test_name
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             WHERE invites.token_hash = :token_hash
               AND invites.status = 'pending'
               AND invites.expires_at > NOW()
               AND tests.is_active = 1",
            ['token_hash' => hash('sha256', $token)],
        );
    }

    /**
     * Binds an invitation to exactly one newly created therapist case.
     *
     * @return array{session: array<string, mixed>, test: array<string, mixed>}|null
     */
    public function claim(string $token): ?array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/i', $token) !== 1) {
            return null;
        }

        $tokenHash = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $claimed = $this->db->execute(
                "UPDATE test_invites AS invites
                 INNER JOIN tests AS tests ON tests.id = invites.test_id
                 SET invites.status = 'claimed', invites.claimed_at = NOW()
                 WHERE invites.token_hash = :token_hash
                   AND invites.status = 'pending'
                   AND invites.claimed_session_id IS NULL
                   AND invites.expires_at > NOW()
                   AND tests.is_active = 1",
                ['token_hash' => $tokenHash],
            )->rowCount();

            if ($claimed !== 1) {
                $this->db->rollback();

                return null;
            }

            $invite = $this->db->selectOne(
                'SELECT invites.id, tests.id AS test_id, tests.slug, tests.name
                 FROM test_invites AS invites
                 INNER JOIN tests AS tests ON tests.id = invites.test_id
                 WHERE invites.token_hash = :token_hash AND invites.status = :status',
                ['token_hash' => $tokenHash, 'status' => 'claimed'],
            );
            if ($invite === null) {
                throw new \LogicException('Claimed invite disappeared');
            }

            $session = $this->sessions->createSession((int) $invite['test_id'], [
                'retention_class' => RetentionPolicy::THERAPIST_CASE,
            ]);
            $bound = $this->db->update(
                'test_invites',
                ['claimed_session_id' => $session['id']],
                'id = ? AND status = ? AND claimed_session_id IS NULL',
                [$invite['id'], 'claimed'],
            );
            if ($bound !== 1) {
                throw new \LogicException('Could not bind claimed invite');
            }

            $this->db->commit();

            return ['session' => $session, 'test' => $invite];
        } catch (\Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollback();
            }

            throw $exception;
        }
    }

    public function revoke(string $inviteId): bool
    {
        return $this->db->update(
            'test_invites',
            ['status' => 'revoked', 'revoked_at' => date('Y-m-d H:i:s')],
            'id = ? AND status = ?',
            [$inviteId, 'pending'],
        ) === 1;
    }

    /** @return list<array<string, mixed>> */
    public function recentForOwner(int $limit = 20): array
    {
        $invites = $this->db->select(
            "SELECT invites.id, invites.owner_note, invites.status, invites.created_at, invites.expires_at,
                    invites.claimed_at, invites.claimed_session_id, invites.client_id,
                    tests.name AS test_name, sessions.status AS session_status, sessions.completed_at,
                    clients.label AS client_label
             FROM test_invites AS invites
             INNER JOIN tests ON tests.id = invites.test_id
             LEFT JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             LEFT JOIN therapist_clients AS clients ON clients.id = invites.client_id
             ORDER BY invites.created_at DESC
            LIMIT " . max(1, min($limit, 50)),
        );

        return self::withDisplayStatus($invites);
    }

    /**
     * Adds the owner-facing state of each invitation row.
     *
     * A claimed invitation without a bound session is legacy data left by an
     * earlier deletion path: its case no longer exists, so it must never be
     * offered as a link to `/admin/invited-case/`.
     *
     * @param list<array<string, mixed>> $invites
     * @return list<array<string, mixed>>
     */
    public static function withDisplayStatus(array $invites): array
    {
        $now = new DateTimeImmutable();
        foreach ($invites as &$invite) {
            $invite['display_status'] = match ($invite['status']) {
                'claimed' => match (true) {
                    $invite['claimed_session_id'] === null, $invite['session_status'] === 'deleted' => 'result_deleted',
                    $invite['session_status'] === 'completed' => 'completed',
                    default => 'opened',
                },
                'revoked' => 'revoked',
                default => new DateTimeImmutable((string) $invite['expires_at']) <= $now ? 'expired' : 'pending',
            };
        }
        unset($invite);

        return $invites;
    }

    /** @return array<string, mixed>|null */
    public function claimedCaseForOwner(string $sessionId): ?array
    {
        $case = $this->db->selectOne(
            "SELECT sessions.id, sessions.status, sessions.created_at, sessions.completed_at,
                    sessions.answers, sessions.calculated_results, tests.name AS test_name, tests.slug AS test_slug,
                    invites.owner_note, invites.claimed_at, invites.client_id, clients.label AS client_label
             FROM test_invites AS invites
             INNER JOIN test_sessions AS sessions ON sessions.id = invites.claimed_session_id
             INNER JOIN tests ON tests.id = sessions.test_id
             LEFT JOIN therapist_clients AS clients ON clients.id = invites.client_id
             WHERE invites.claimed_session_id = :session_id
               AND sessions.status <> 'deleted'",
            ['session_id' => $sessionId],
        );
        if ($case === null) {
            return null;
        }

        $case['answers'] = json_decode((string) $case['answers'], true, 512, JSON_THROW_ON_ERROR);
        $case['calculated_results'] = json_decode((string) $case['calculated_results'], true, 512, JSON_THROW_ON_ERROR);

        return $case;
    }
}
