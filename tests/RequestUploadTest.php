<?php

namespace Cleantalk\PHPAntiCrawler\Tests;

use Cleantalk\PHPAntiCrawler\SQLiteManager;
use Cleantalk\PHPAntiCrawler\SyncManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RequestUploadTest extends TestCase
{
    public function testInterruptedBatchIsDiscardedWhileNewRequestsArePrepared(): void
    {
        $path = sys_get_temp_dir() . '/anticrawler-upload-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $pdo = SQLiteManager::initDb($path);
            $insert = $pdo->prepare(
                'INSERT INTO requests (id, fingerprint, ip, blocked, timestamp_unixtime, url, request_status, sync_state) '
                . 'VALUES (?, ?, ?, 0, 1, ?, ?, ?)'
            );
            foreach (['sent', 'sending', 'idle'] as $state) {
                $insert->execute([$state, $state, '127.0.0.1', '/', 'PASS', $state]);
            }

            $prepare = new ReflectionMethod(SyncManager::class, 'prepareRequestsForUpload');
            $manager = new SyncManager($pdo);
            $rows = $prepare->invoke($manager);

            $this->assertCount(1, $rows);
            $this->assertSame('/', $rows[0]['first_visited_url']);
            $this->assertSame(
                [['idle', 'sending']],
                $pdo->query('SELECT id, sync_state FROM requests')->fetchAll(\PDO::FETCH_NUM)
            );

            // A worker that dies here leaves the batch marked "sending".
            $this->assertSame([], $prepare->invoke($manager));
            $this->assertSame(0, (int)$pdo->query('SELECT COUNT(*) FROM requests')->fetchColumn());

            $insert->execute(['new', 'new', '127.0.0.2', '/new', 'PASS', 'idle']);
            $this->assertCount(1, $prepare->invoke($manager));
        } finally {
            unset($pdo);
            SQLiteManager::deleteDb($path);
        }
    }
}
