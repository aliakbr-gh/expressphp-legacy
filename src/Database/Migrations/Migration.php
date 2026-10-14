<?php

declare(strict_types=1);

namespace ExpressPHP\Database\Migrations;

use PDO;

abstract class Migration
{
    /**
     * Applies the schema change.
     */
    abstract public function up(PDO $database): void;

    /**
     * Reverses the schema change made by up().
     */
    abstract public function down(PDO $database): void;
}
