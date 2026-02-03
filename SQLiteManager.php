<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use PDO;
use PDOException;

final class SQLiteManager
{
    /**
     * Create filename.sqlite file
     *
     * @var $path Path to sqlite directory
     *
     * @return PDO
     */
    public static function initDb(string $path = ''): PDO
    {
        if (!$path) {
            $path = __DIR__ . '/' . 'anticrawler.sqlite';
        }

        try {
            if (is_file($path) === false) {
                return self::createDb($path);
            }

            $pdo = new PDO('sqlite:' . $path);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

            $pdo->exec("PRAGMA temp_store = MEMORY;");
            $pdo->exec("PRAGMA mmap_size = 268435456;"); // 256mb if available
            $pdo->exec("PRAGMA busy_timeout = 5000;");

            return $pdo;
        } catch (PDOException $e) {
            $errorMessage = 'SQLite error occurred in Anti-Crawler module: ' . $e->getMessage();
            error_log($errorMessage);
            exit;
        }
    }

    public static function createDb(string $path = ''): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $pdo->exec("PRAGMA journal_mode = WAL;");
        $pdo->exec("PRAGMA synchronous = NORMAL;");

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS visitors (
                fingerprint TEXT PRIMARY KEY,
                ip TEXT NOT NULL,
                ua TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                last_seen INTEGER NOT NULL
            );
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS requests (
                id TEXT PRIMARY KEY,
                fingerprint TEXT,
                ip TEXT NOT NULL,
                total INTEGER,
                blocked INTEGER,
                timestamp_unixtime INTEGER NOT NULL,
                ua_name TEXT,
                ua_id INTEGER,
                url TEXT,
                request_status TEXT NOT NULL,
                sync_state TEXT NOT NULL DEFAULT 'idle'
            );
        SQL);
        $pdo->exec('CREATE INDEX idx_requests_fp_status_time ON requests (fingerprint, request_status, timestamp_unixtime);');

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS lists (
                ip BLOB NOT NULL,
                is_personal_list INTEGER NOT NULL,
                is_whitelist INTEGER NOT NULL
            );
        SQL);
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_lists_ip_sort ON lists(ip, is_personal_list, is_whitelist);');

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS user_agents (
                ua_id INTEGER NOT NULL,
                ua_name TEXT NOT NULL,
                is_whitelist INTEGER NOT NULL
            );
        SQL);

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS kv (
                k TEXT PRIMARY KEY,
                v TEXT NOT NULL
            );
        SQL);
        $pdo->exec("INSERT OR IGNORE INTO kv(k, v) VALUES ('last_synchronization', '0');");
        $pdo->exec("INSERT OR IGNORE INTO kv(k, v) VALUES ('last_key_check', '0');");
        $pdo->exec("INSERT OR IGNORE INTO kv(k, v) VALUES ('sync_in_process', '0');");

        return $pdo;
    }


    public static function deleteDb(string $path = ''): void
    {
        if (!$path) {
            $path = __DIR__ . '/' . 'anticrawler.sqlite';
        }

        if (file_exists($path)) {
            unlink($path);
        }
        if (file_exists($path . '-shm')) {
            unlink($path . '-shm');
        }
        if (file_exists($path . '-wal')) {
            unlink($path . '-wal');
        }
    }
}
