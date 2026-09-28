<?php

namespace Cleantalk\PHPAntiCrawler\Tests;

use Cleantalk\PHPAntiCrawler\Settings;
use Cleantalk\PHPAntiCrawler\SQLiteManager;
use Cleantalk\PHPAntiCrawler\SyncManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class SyncLockTest extends TestCase
{
    public function testFileLockExcludesConcurrentSyncAndCanBeReacquired(): void
    {
        $previousPath = Settings::$dbPath;
        $path = sys_get_temp_dir() . '/anticrawler-sync-lock-' . bin2hex(random_bytes(8)) . '.sqlite';
        Settings::$dbPath = $path;
        $lock = false;

        try {
            $pdo = SQLiteManager::initDb($path);
            // Existing databases may retain the obsolete flag from version 1.0.40.
            $pdo->exec("INSERT INTO kv(k, v) VALUES ('sync_in_process', '1')");
            $manager = new SyncManager($pdo);
            $acquire = new ReflectionMethod(SyncManager::class, 'tryAcquireSyncLock');

            $lock = $acquire->invoke($manager);
            $this->assertIsResource($lock);
            $this->assertFalse($acquire->invoke($manager));
            $manager->syncData('unused');
            $this->assertSame('1', $pdo->query("SELECT v FROM kv WHERE k = 'sync_in_process'")->fetchColumn());

            fclose($lock);
            $lock = false;
            $lock = $acquire->invoke($manager);
            $this->assertIsResource($lock);
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
            Settings::$dbPath = $previousPath;
            unset($pdo);
            SQLiteManager::deleteDb($path);
            @unlink($path . '.sync.lock');
        }
    }
}
