<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Auth\SessionToken;
use ExpressPHP\Database\Model;

/**
 * Database-backed login sessions: one per user, with a sliding idle expiry and an absolute lifetime.
 * Only token hashes are stored. Expired rows are deleted when they are presented and at every login.
 */
final class UserSession extends Model
{
    protected string $table = 'user_sessions';
    protected array $fillable = [];
    protected array $hidden = ['token_hash'];
    protected array $dates = ['expires_at', 'absolute_expires_at', 'last_used_at', 'created_at'];

    /**
     * Starts a session for the user, replacing any session on another device.
     *
     * @return array{token: string, expires_at: int, absolute_expires_at: int}
     */
    public function start(
        int|string $userId,
        int        $idleSeconds,
        int        $lifetimeSeconds,
        ?string    $ipAddress,
        ?string    $userAgent,
    ): array {
        $this->deleteExpired();

        $token = SessionToken::generate();
        $now = time();
        $absoluteExpiresAt = $now + $lifetimeSeconds;
        $expiresAt = min($now + $idleSeconds, $absoluteExpiresAt);

        // The unique user_id key makes this replace the previous session in one statement.
        $this->run(
            'INSERT INTO user_sessions ' .
            '(user_id, token_hash, expires_at, absolute_expires_at, last_used_at, ip_address, user_agent, created_at) ' .
            'VALUES (:user_id, :token_hash, FROM_UNIXTIME(:expires_at), FROM_UNIXTIME(:absolute_expires_at), ' .
            'CURRENT_TIMESTAMP, :ip_address, :user_agent, CURRENT_TIMESTAMP) ' .
            'ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), expires_at = VALUES(expires_at), ' .
            'absolute_expires_at = VALUES(absolute_expires_at), last_used_at = VALUES(last_used_at), ' .
            'ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), created_at = VALUES(created_at)',
            [
                'user_id' => (int)$userId,
                'token_hash' => SessionToken::hash($token),
                'expires_at' => $expiresAt,
                'absolute_expires_at' => $absoluteExpiresAt,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent === null ? null : substr($userAgent, 0, 500),
            ],
        );

        return ['token' => $token, 'expires_at' => $expiresAt, 'absolute_expires_at' => $absoluteExpiresAt];
    }

    /**
     * Returns the active session for a token, or null. An expired session is deleted when presented.
     *
     * @return array{id: int, user_id: int, expires_at: int, absolute_expires_at: int, last_used_at: int}|null
     */
    public function findActive(#[\SensitiveParameter] string $token): ?array
    {
        if (!SessionToken::wellFormed($token)) {
            return null;
        }

        $session = $this->run(
            'SELECT id, user_id, UNIX_TIMESTAMP(expires_at) AS expires_at, ' .
            'UNIX_TIMESTAMP(absolute_expires_at) AS absolute_expires_at, UNIX_TIMESTAMP(last_used_at) AS last_used_at ' .
            'FROM user_sessions WHERE token_hash = :token_hash LIMIT 1',
            ['token_hash' => SessionToken::hash($token)],
        )->fetch();
        if ($session === false) {
            return null;
        }

        $session = array_map('intval', $session);
        $now = time();
        if ($session['expires_at'] <= $now || $session['absolute_expires_at'] <= $now) {
            $this->delete($session['id']);
            return null;
        }
        return $session;
    }

    /**
     * Slides the idle expiry forward, never past the absolute lifetime. Writes at most once per
     * $touchSeconds so ordinary API calls do not each cost a database write.
     */
    public function touch(array $session, int $idleSeconds, int $touchSeconds): void
    {
        $now = time();
        if ($now - $session['last_used_at'] < $touchSeconds) {
            return;
        }

        $this->run(
            'UPDATE user_sessions SET expires_at = FROM_UNIXTIME(:expires_at), last_used_at = CURRENT_TIMESTAMP ' .
            'WHERE id = :id',
            ['expires_at' => min($now + $idleSeconds, $session['absolute_expires_at']), 'id' => $session['id']],
        );
    }

    /**
     * Ends the user's session, for logout, password changes, deactivation, and role changes.
     */
    public function endForUser(int|string $userId): void
    {
        $this->run('DELETE FROM user_sessions WHERE user_id = :user_id', ['user_id' => (int)$userId]);
    }

    public function deleteExpired(): int
    {
        return $this->run(
            'DELETE FROM user_sessions WHERE expires_at <= CURRENT_TIMESTAMP OR absolute_expires_at <= CURRENT_TIMESTAMP'
        )->rowCount();
    }
}
