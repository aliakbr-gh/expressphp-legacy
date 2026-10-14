<?php

declare(strict_types=1);

use ExpressPHP\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Creates the application tables and inserts the default roles, permissions and grants.
     */
    public function up(PDO $database): void
    {
        $database->exec(
            'CREATE TABLE IF NOT EXISTS roles (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'name VARCHAR(100) NOT NULL, ' .
            'slug VARCHAR(100) NOT NULL UNIQUE, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $database->exec(
            'CREATE TABLE IF NOT EXISTS permissions (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'name VARCHAR(120) NOT NULL, ' .
            'slug VARCHAR(120) NOT NULL UNIQUE, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $database->exec(
            'CREATE TABLE IF NOT EXISTS role_permissions (' .
            'role_id BIGINT UNSIGNED NOT NULL, ' .
            'permission_id BIGINT UNSIGNED NOT NULL, ' .
            'PRIMARY KEY (role_id, permission_id), ' .
            'CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE, ' .
            'CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $database->exec(
            'CREATE TABLE IF NOT EXISTS users (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'name VARCHAR(100) NOT NULL, ' .
            'username VARCHAR(50) NOT NULL UNIQUE, ' .
            'password VARCHAR(255) NOT NULL, ' .
            'is_active TINYINT(1) NOT NULL DEFAULT 1, ' .
            'role_id BIGINT UNSIGNED NOT NULL, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ' .
            'INDEX idx_users_role_id (role_id), ' .
            'CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $database->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'user_id BIGINT UNSIGNED NULL, ' .
            'user_name VARCHAR(100) NOT NULL, ' .
            'description TEXT NOT NULL, ' .
            'ip_address VARCHAR(45) NULL, ' .
            'user_agent VARCHAR(500) NULL, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ' .
            'INDEX idx_activity_logs_user_id (user_id), ' .
            'INDEX idx_activity_logs_created_at (created_at)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $database->exec(
            'CREATE TABLE IF NOT EXISTS email_logs (' .
            'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
            'user_id BIGINT UNSIGNED NULL, ' .
            'user_name VARCHAR(100) NOT NULL, ' .
            'mailer VARCHAR(20) NOT NULL, ' .
            'recipient VARCHAR(190) NOT NULL, ' .
            'subject VARCHAR(190) NOT NULL, ' .
            "status ENUM('sent', 'failed') NOT NULL, " .
            'error_message TEXT NULL, ' .
            'sent_at TIMESTAMP NULL, ' .
            'created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, ' .
            'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, ' .
            'INDEX idx_email_logs_user_id (user_id), ' .
            'INDEX idx_email_logs_status (status), ' .
            'INDEX idx_email_logs_created_at (created_at)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $this->seedRoles($database);
        $this->seedPermissions($database);
        $this->grantAdministrativePermissions($database);
    }

    /**
     * Drops the application tables in foreign-key order.
     */
    public function down(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS email_logs');
        $database->exec('DROP TABLE IF EXISTS activity_logs');
        $database->exec('DROP TABLE IF EXISTS role_permissions');
        $database->exec('DROP TABLE IF EXISTS users');
        $database->exec('DROP TABLE IF EXISTS permissions');
        $database->exec('DROP TABLE IF EXISTS roles');
    }

    /**
     * Inserts the super-admin, admin and user roles, or updates their names.
     */
    private function seedRoles(PDO $database): void
    {
        $statement = $database->prepare(
            'INSERT INTO roles (name, slug) VALUES (:name, :slug) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach ([
                     ['Super Admin', 'super-admin'],
                     ['Admin', 'admin'],
                     ['User', 'user'],
                 ] as [$name, $slug]) {
            $statement->execute(['name' => $name, 'slug' => $slug]);
        }
    }

    /**
     * Inserts the permissions used by the routes, or updates their names.
     */
    private function seedPermissions(PDO $database): void
    {
        $statement = $database->prepare(
            'INSERT INTO permissions (name, slug) VALUES (:name, :slug) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach ([
                     ['View roles', 'roles.view'],
                     ['Create roles', 'roles.create'],
                     ['Update roles', 'roles.update'],
                     ['Delete roles', 'roles.delete'],
                     ['Assign role permissions', 'roles.permissions.assign'],
                     ['View permissions', 'permissions.view'],
                     ['Create permissions', 'permissions.create'],
                     ['Update permissions', 'permissions.update'],
                     ['Delete permissions', 'permissions.delete'],
                     ['View users', 'users.view'],
                     ['Create users', 'users.create'],
                     ['Update users', 'users.update'],
                     ['Delete users', 'users.delete'],
                     ['Assign user roles', 'users.roles.assign'],
                     ['View activity logs', 'activity-logs.view'],
                     ['Create activity logs', 'activity-logs.create'],
                     ['View server logs', 'server-logs.view'],
                     ['View rate limits', 'rate-limits.view'],
                     ['Block IP addresses', 'rate-limits.block'],
                     ['Clear rate limits', 'rate-limits.clear'],
                     ['View email logs', 'emails.view'],
                     ['Send emails', 'emails.send'],
                     ['Upload files', 'files.upload'],
                     ['Download database backups', 'database-backups.download'],
                 ] as [$name, $slug]) {
            $statement->execute(['name' => $name, 'slug' => $slug]);
        }
    }

    /**
     * Grants every permission to the super-admin and admin roles, skipping existing grants.
     */
    private function grantAdministrativePermissions(PDO $database): void
    {
        $database->exec(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) ' .
            'SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions ' .
            "WHERE roles.slug IN ('super-admin', 'admin')"
        );
    }
};
