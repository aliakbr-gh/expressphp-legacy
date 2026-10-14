<?php

declare(strict_types=1);

use ExpressPHP\Database\Migrations\Migration;

// Brings databases created before database-backed sessions up to date. Fresh installations already get
// user_sessions from the consolidated migration, so this one does nothing there. The old
// users.session_version column is left in place on existing databases; it is no longer read or written.
return new class extends Migration {
    /**
     * Creates the user_sessions table if it does not exist.
     */
    public function up(PDO $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS user_sessions (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'user_id BIGINT UNSIGNED NOT NULL, ' .
            'token_hash CHAR(64) NOT NULL, ' .
            'expires_at TIMESTAMP NOT NULL, ' .
            'absolute_expires_at TIMESTAMP NOT NULL, ' .
            'last_used_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'ip_address VARCHAR(45) NULL, ' .
            'user_agent VARCHAR(500) NULL, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'UNIQUE KEY uq_user_sessions_user_id (user_id), ' .
            'UNIQUE KEY uq_user_sessions_token_hash (token_hash), ' .
            'INDEX idx_user_sessions_expires_at (expires_at), ' .
            'CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * Drops the user_sessions table.
     */
    public function down(PDO $database): void
    {
        // Sessions are temporary sign-in state; dropping them only signs everyone out.
        $database->exec('DROP TABLE IF EXISTS user_sessions');
    }
};
