<?php

namespace TrafficCore;

/**
 * Raw PDO connection to the same `tds2` MySQL DB the Laravel `backend/`
 * app uses. Deliberately no ORM/reflection here (architecture decision —
 * see docs/ARCHITECTURE_PLAN.md: this core is meant to be easy to
 * ionCube-encode later, unlike the admin backend).
 */
class Db
{
    private static ?\PDO $instance = null;

    public static function instance(): \PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'tds2-mysql';
            $port = getenv('DB_PORT') ?: '3306';
            $name = getenv('DB_DATABASE') ?: 'tds2';
            $user = getenv('DB_USERNAME') ?: 'tds2';
            $pass = getenv('DB_PASSWORD') ?: 'secret';

            self::$instance = new \PDO(
                "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",
                $user,
                $pass,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }

        return self::$instance;
    }
}
