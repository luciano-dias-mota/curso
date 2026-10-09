<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $cfg = config('database');

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['host'],
            $cfg['port'],
            $cfg['database'],
            $cfg['charset']
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $cfg['username'],
                $cfg['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Mantém NOW()/TIMESTAMPDIFF do banco no mesmo relógio da aplicação.
            // Usamos o offset calculado pelo PHP para não depender das tabelas de fuso do MySQL/MariaDB.
            $timezoneName = (string) ($cfg['timezone'] ?? config('app.timezone', 'UTC'));
            $timezone = new DateTimeZone($timezoneName !== '' ? $timezoneName : 'UTC');
            $offset = (new DateTimeImmutable('now', $timezone))->format('P');
            self::$connection->exec('SET time_zone = ' . self::$connection->quote($offset));
        } catch (PDOException $e) {
            self::$connection = null;
            throw new RuntimeException('Falha na conexão com o banco de dados.', 0, $e);
        } catch (Throwable $e) {
            self::$connection = null;
            throw new RuntimeException('Falha ao configurar a conexão com o banco de dados.', 0, $e);
        }

        return self::$connection;
    }
}
