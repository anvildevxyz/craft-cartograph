<?php

declare(strict_types=1);

namespace anvildevxyz\cartograph\migrations;

use Craft;
use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $db = Craft::$app->getDb();
        $driver = strtolower((string) $db->getDriverName());
        $version = (string) $db->getServerVersion();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            if (version_compare($version, '8.0.17', '<')) {
                throw new \RuntimeException(
                    "Cartograph requires MySQL ≥ 8.0.17 (proximity queries use JSON_EXTRACT on object values); detected {$driver} {$version}.",
                );
            }

            return true;
        }

        if (in_array($driver, ['pgsql', 'postgres', 'postgresql'], true)) {
            if (version_compare($version, '13', '<')) {
                throw new \RuntimeException(
                    "Cartograph requires PostgreSQL ≥ 13 (proximity queries use jsonb operators); detected {$driver} {$version}.",
                );
            }

            return true;
        }

        throw new \RuntimeException(
            "Cartograph supports MySQL ≥ 8.0.17 and PostgreSQL ≥ 13; detected unsupported driver: {$driver}.",
        );
    }

    public function safeDown(): bool
    {
        return true;
    }
}
