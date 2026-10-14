<?php

declare(strict_types=1);

use ExpressPHP\Database\Migrations\Migration;

// Login now returns a stateless JWT, so the user_sessions table from the earlier session design is no longer
// used. Fresh installations never create it, so this does nothing there.
return new class extends Migration {
    /**
     * Drops the unused user_sessions table, which only held temporary sign-in state.
     */
    public function up(PDO $database): void
    {
        $database->exec('DROP TABLE IF EXISTS user_sessions');
    }

    /**
     * Does nothing: the sessions table is not used by any code, so it is not recreated.
     */
    public function down(PDO $database): void
    {
    }
};
