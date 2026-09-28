<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use Cleantalk\PHPAntiCrawler\Settings;
use Exception;
use PDO;
use RuntimeException;
use Throwable;

final class SyncManager
{
    private const SYNC_LOG_PATH = '/tmp/anticrawler_error_log';

    private static bool $syncLogWriteFailed = false;

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function manageSynchronization(string $apiKey): void
    {
        if (Settings::$syncByCron === true) {
            return;
        }

        $timeSinceLastSync = $this->timeSinceLastSyncRequest();

        // Skip synchronization if the last one happened just yet
        if ($timeSinceLastSync < Settings::$minSyncInterval) {
            return;
        }

        // Execute synchronization if we have too many unsynced requests or if too much time has passed
        if (
            $this->countRequests() > Settings::$maxRowsBeforeSync
            || $timeSinceLastSync > Settings::$maxSyncInterval
        ) {
            $this->syncData($apiKey);
        }
    }

    public function timeSinceLastSyncRequest(): int
    {
        $lastSyncUnixTime = (int)(
            $this->pdo
                ->query("SELECT v FROM kv WHERE k = 'last_export'")
                ->fetchColumn() ?? 0
        );
        return (time() - $lastSyncUnixTime);
    }

    public function countRequests(): int
    {
        if (Settings::$requestsBackend === 'keydb') {
            return KeyDBManager::countPendingRequests();
        }

        return (int)(
            $this->pdo
                ->query("SELECT COUNT(1) FROM requests WHERE sync_state = 'idle'")
                ->fetchColumn() ?? 0
        );
    }

    public function syncData(string $apiKey): void
    {
        $lock = $this->tryAcquireSyncLock();
        if ($lock === false) {
            return;
        }

        $syncId = getmypid() . '-' . str_replace('.', '', uniqid('', true));
        $startedAt = microtime(true);
        $stage = 'lock_acquired';
        $this->logSyncEvent($syncId, $stage, $startedAt);

        try {
            $stage = 'version_feedback';
            $this->logSyncEvent($syncId, $stage, $startedAt);
            $this->declareAppVersion();

            $stage = 'visitor_cleanup';
            $this->logSyncEvent($syncId, $stage, $startedAt);
            $this->cleanOldVisitorsData();

            if ($this->importRecentlyFailed() === false) {
                try {
                    $stage = 'list_import';
                    $this->logSyncEvent($syncId, $stage, $startedAt);
                    $this->updateListsAndAgents($apiKey);
                    $this->setLastImportDate();
                } catch (Exception $e) {
                    $this->logSyncEvent($syncId, 'list_import_failed', $startedAt, [
                        'error_class' => get_class($e),
                        'error' => substr($e->getMessage(), 0, 500),
                    ]);
                    $this->setLastImportFailDate();
                    $lastImportTs = (int)(
                        $this->pdo
                            ->query("SELECT v FROM kv WHERE k = 'last_import'")
                            ->fetchColumn() ?? 0
                    );
                    error_log('AntiCrawler failed to update filtering lists. Last import date: ' . date("Y-m-d H:i:s", $lastImportTs));
                }
            }

            $stage = 'request_export';
            $this->logSyncEvent($syncId, $stage, $startedAt);
            $this->uploadRequestsToDB($apiKey, $syncId, $startedAt);

            $stage = 'export_timestamp';
            $this->logSyncEvent($syncId, $stage, $startedAt);
            $this->setLastExportDate();
            $this->logSyncEvent($syncId, 'completed', $startedAt);
        } catch (Throwable $e) {
            $this->logSyncEvent($syncId, 'failed', $startedAt, [
                'stage' => $stage,
                'error_class' => get_class($e),
                'error' => substr($e->getMessage(), 0, 500),
            ]);
            throw $e;
        } finally {
            try {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function logSyncEvent(string $syncId, string $event, float $startedAt, array $details = []): void
    {
        $entry = array_merge([
            'time_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'sync_id' => $syncId,
            'pid' => getmypid(),
            'database' => basename(Settings::$dbPath),
            'backend' => Settings::$requestsBackend,
            'event' => $event,
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'peak_memory_mb' => (int)ceil(memory_get_peak_usage(true) / 1048576),
        ], $details);

        $line = json_encode($entry, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line !== false && @file_put_contents(self::SYNC_LOG_PATH, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            if (self::$syncLogWriteFailed === false) {
                error_log('AntiCrawler could not write synchronization diagnostics to ' . self::SYNC_LOG_PATH);
                self::$syncLogWriteFailed = true;
            }
        }
    }

    /** @return resource|false */
    private function tryAcquireSyncLock()
    {
        // Keep this file in place: unlinking it can let workers lock different inodes.
        $lock = @fopen(Settings::$dbPath . '.sync.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Unable to open AntiCrawler synchronization lock file');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }

        return $lock;
    }

    private function declareAppVersion(): void
    {
        $payload = json_encode([
            'auth_key' => Settings::$apiKey,
            'feedback' => '0:' . Settings::VERSION,
        ]);

        $ch = curl_init('https://moderate.cleantalk.org/api3.0/send_feedback');

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);
    }

    private function cleanOldVisitorsData(): void
    {
        if (Settings::$requestsBackend === 'keydb') {
            return;
        }

        $threshold = time() - Settings::$visitorForgetAfter;
        $this->pdo->exec("DELETE FROM visitors WHERE last_seen < {$threshold}");
    }

    private function uploadRequestsToDB(string $apiKey, string $syncId, float $startedAt): void
    {
        if (Settings::$requestsBackend === 'keydb') {
            $this->logSyncEvent($syncId, 'keydb_upload', $startedAt);
            KeyDBManager::uploadRequestsToDB($apiKey);
            return;
        }

        $this->logSyncEvent($syncId, 'batch_prepare', $startedAt);
        $rows = $this->prepareRequestsForUpload();
        $this->logSyncEvent($syncId, 'batch_prepared', $startedAt, ['aggregated_rows' => count($rows)]);

        $data = [];
        foreach($rows as $row) {
            $data[] = [
                $row['ip'],
                $row['total_requests'],
                $row['total_requests'] - $row['total_blocked'],
                $row['last_request'],
                $row['request_status'],
                $row['ua_name'],
                $row['ua_id'],
                ['fu' => $row['first_visited_url'], 'lu' => $row['last_visited_url']],
            ];
        }

        $this->logSyncEvent($syncId, 'batch_upload', $startedAt, ['aggregated_rows' => count($data)]);
        if (LogsSender::sendDataQuery($apiKey, $data) === false) {
            throw new Exception('failed to upload request logs');
        }

        $this->pdo->exec("UPDATE requests SET sync_state = 'sent' WHERE sync_state = 'sending'");
        $this->logSyncEvent($syncId, 'batch_uploaded', $startedAt, ['aggregated_rows' => count($data)]);
    }

    private function prepareRequestsForUpload(): array
    {
        $this->pdo->beginTransaction();
            // A previous upload may have reached the server before its worker died.
            // Drop that uncertain batch instead of risking a duplicate upload.
            $this->pdo->exec("DELETE FROM requests WHERE sync_state IN ('sent', 'sending')");
            $this->pdo->exec("UPDATE requests SET sync_state = 'sending' WHERE sync_state = 'idle'");
            $stmt = $this->pdo->query(<<<SQL
                WITH ranked AS (
                    SELECT
                        ip,
                        fingerprint,
                        ua_name,
                        ua_id,
                        request_status,
                        blocked,
                        timestamp_unixtime,
                        url,
                        ROW_NUMBER() OVER (
                            PARTITION BY fingerprint, request_status
                            ORDER BY timestamp_unixtime ASC
                        ) AS rn_first,
                        ROW_NUMBER() OVER (
                            PARTITION BY fingerprint, request_status
                            ORDER BY timestamp_unixtime DESC
                        ) AS rn_last
                    FROM requests
                    WHERE sync_state = 'sending'
                )
                SELECT
                    ip,
                    fingerprint,
                    COUNT(*) AS total_requests,
                    SUM(blocked) AS total_blocked,
                    MAX(timestamp_unixtime) AS last_request,
                    MAX(CASE WHEN rn_first = 1 THEN url END) AS first_visited_url,
                    MAX(CASE WHEN rn_last  = 1 THEN url END) AS last_visited_url,
                    ua_name,
                    ua_id,
                    request_status
                FROM ranked
                GROUP BY fingerprint, request_status;
            SQL);
            $rows = $stmt->fetchAll();
        $this->pdo->commit();

        return $rows;
    }

    private function setLastExportDate()
    {
        $this->pdo->exec("UPDATE kv SET v = " . time() . " WHERE k = 'last_export'");
    }

    private function setLastImportDate()
    {
        $this->pdo->exec("UPDATE kv SET v = " . time() . " WHERE k = 'last_import'");
    }

    private function setLastImportFailDate()
    {
        $this->pdo->exec("UPDATE kv SET v = " . time() . " WHERE k = 'last_import_fail_date'");
    }

    private function importRecentlyFailed(): bool
    {
        $lastImportFailTs = (int)(
            $this->pdo
                ->query("SELECT v FROM kv WHERE k = 'last_import_fail_date'")
                ->fetchColumn() ?? 0
        );

        return (time() - $lastImportFailTs) < 30;
    }

    public function updateListsAndAgents(string $apiKey)
    {
        $url = 'https://api.cleantalk.org/?method_name=2s_blacklists_db&version=3_1&auth_key=' . $apiKey;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['accept: application/json'],
            CURLOPT_ENCODING       => '', // enables gzip/deflate
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            throw new Exception('curl error: ' . curl_error($ch));
        }
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            throw new Exception("HTTP error: status $code");
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $rows = $data['data'] ?? [];
        $userAgents = $data['data_user_agents'] ?? [];
        if (!is_array($rows) || !is_array($userAgents)) {
            throw new Exception('Unexpected payload shape');
        }

        try {
            $this->pdo->beginTransaction();
                $this->pdo->exec('DELETE FROM lists');
                $this->pdo->exec('DELETE FROM user_agents');

                $stmt = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO lists (ip, is_personal_list, is_whitelist) VALUES (?, ?, ?)'
                );

                foreach ($rows as $record) {
                    if (!is_array($record) || count($record) != 4) {
                        continue;
                    }
                    $stmt->execute([inet_pton(long2ip((int)($record[0]))), (int)$record[3], (int)$record[2]]);
                }

                $stmt = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO user_agents (ua_id, ua_name, is_whitelist) VALUES (?, ?, ?)'
                );
                foreach ($userAgents as $agent) {
                    if (!is_array($agent) || count($agent) != 3) {
                        continue;
                    }
                    $agent[1] = str_replace('\\', '', $agent[1]);
                    $stmt->execute([$agent[0], $agent[1], $agent[2]]);
                }
            $this->pdo->commit();
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function syncByCron(string $apiKey): void
    {
        $this->syncData($apiKey);
    }
}
