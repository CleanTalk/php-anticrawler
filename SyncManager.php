<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use Cleantalk\PHPAntiCrawler\Settings;
use Exception;
use PDO;

final class SyncManager
{
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
                ->query("SELECT v FROM kv WHERE k = 'last_synchronization'")
                ->fetchColumn() ?? 0
        );
        return (time() - $lastSyncUnixTime);
    }

    public function countRequests(): int
    {
        return (int)(
            $this->pdo
                ->query("SELECT COUNT(1) FROM requests WHERE sync_state = 'idle'")
                ->fetchColumn() ?? 0
        );
    }

    public function syncData(string $apiKey): void
    {
        if (!$this->tryAcquireSyncLock()) {
            return;
        }
        try {
            $this->declareAppVersion();
            $this->cleanOldVisitorsData();
            $this->updateListsAndAgents($apiKey);
            $this->uploadRequestsToDB($apiKey);

            $this->setLastSyncDate();
        } finally {
            $this->removeSyncLock();
        }
    }

    private function tryAcquireSyncLock(): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE kv SET v = '1' WHERE k = 'sync_in_process' AND v = '0'"
        );
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    private function removeSyncLock(): void
    {
        $this->pdo->exec("UPDATE kv SET v = '0' WHERE k = 'sync_in_process'");
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
        $threshold = time() - Settings::$visitorForgetAfter;
        $this->pdo->exec("DELETE FROM visitors WHERE last_seen < {$threshold}");
    }

    private function uploadRequestsToDB(string $apiKey): void
    {
        $this->pdo->beginTransaction();
            $this->pdo->exec("DELETE FROM requests WHERE sync_state = 'sent'");
            $this->pdo->exec("UPDATE requests SET sync_state = 'sending'");
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

        try {
            $this->sendDataQuery($apiKey, $data);
        } catch (Exception $e) {
            $this->pdo->exec("UPDATE requests SET sync_state = 'idle' WHERE sync_state = 'sending'");
            throw $e;
        }

        $this->pdo->exec("UPDATE requests SET sync_state = 'sent' WHERE sync_state = 'sending'");
    }

    private function sendDataQuery(string $apiKey, array $data): bool
    {
        $postFields = [
            'timestamp' => time(),
            'rows' => count($data),
            'data' => json_encode($data, JSON_UNESCAPED_SLASHES),
        ];

        $url = 'https://api.cleantalk.org/?method_name=sfw_logs&auth_key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($postFields),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return false;
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ($status === 200);
    }

    private function setLastSyncDate()
    {
        $this->pdo->exec("UPDATE kv SET v = " . time() . " WHERE k = 'last_synchronization'");
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
