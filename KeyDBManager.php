<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use RuntimeException;

final class KeyDBManager
{
    public static function saveVisitor(RequestDto $request): bool
    {
        $redis = self::connectFromSettings();
        $payload = json_encode([
            'ip' => $request->ip,
            'ua' => $request->uaName,
            'created_at' => time(),
        ], JSON_UNESCAPED_SLASHES);

        $result = $redis->set(
            self::visitorKey($request->fingerprint),
            $payload === false ? '{}' : $payload,
            ['nx', 'ex' => self::visitorTtl()]
        );

        return $result === true;
    }

    public static function updateLastSeen(RequestDto $request): void
    {
        $redis = self::connectFromSettings();
        $key = self::visitorKey($request->fingerprint);

        if ((int)$redis->exists($key) !== 1) {
            return;
        }

        $redis->expire($key, self::visitorTtl());
    }

    public static function storeRequest(RequestDto $request, ResultDto $result): void
    {
        $payload = json_encode([
            'ip' => $request->ip,
            'fingerprint' => $request->fingerprint,
            'blocked' => $result->goodRequest ? 0 : 1,
            'timestamp_unixtime' => time(),
            'ua_name' => $request->uaName,
            'ua_id' => $request->uaId,
            'url' => $request->url,
            'request_status' => $result->status,
        ], JSON_UNESCAPED_SLASHES);

        self::connectFromSettings()->rPush(self::pendingKey(), $payload === false ? '{}' : $payload);
    }

    public static function countPendingRequests(): int
    {
        $redis = self::connectFromSettings();

        return (int)$redis->lLen(self::pendingKey()) + (int)$redis->lLen(self::processingKey());
    }

    public static function uploadRequestsToDB(string $apiKey): void
    {
        $data = self::getSyncPayloadRows();
        if ($data === []) {
            return;
        }

        try {
            if (LogsSender::sendDataQuery($apiKey, $data) === false) {
                throw new RuntimeException('failed to upload request logs');
            }
        } catch (\Throwable $e) {
            self::handleSyncFailure();
            throw $e;
        }

        self::markSynced();
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private static function getSyncPayloadRows(): array
    {
        $redis = self::connectFromSettings();
        $pendingKey = self::pendingKey();
        $processingKey = self::processingKey();

        if ((int)$redis->lLen($processingKey) === 0 && (int)$redis->exists($pendingKey) === 1) {
            $redis->rename($pendingKey, $processingKey);
        }

        $entries = $redis->lRange($processingKey, 0, -1);
        if ($entries === [] || $entries === false) {
            return [];
        }

        $aggregated = [];

        foreach ($entries as $entry) {
            $row = json_decode($entry, true);
            if (is_array($row) === false) {
                continue;
            }

            $fingerprint = (string)($row['fingerprint'] ?? '');
            $status = (string)($row['request_status'] ?? '');
            $groupKey = $fingerprint . "\x1f" . $status;
            $timestamp = (int)($row['timestamp_unixtime'] ?? 0);

            if (!isset($aggregated[$groupKey])) {
                $aggregated[$groupKey] = [
                    'ip' => (string)($row['ip'] ?? ''),
                    'total_requests' => 0,
                    'total_good' => 0,
                    'last_request' => $timestamp,
                    'request_status' => $status,
                    'ua_name' => (string)($row['ua_name'] ?? ''),
                    'ua_id' => (int)($row['ua_id'] ?? 0),
                    'first_visited_url' => (string)($row['url'] ?? ''),
                    'last_visited_url' => (string)($row['url'] ?? ''),
                ];
            }

            $aggregated[$groupKey]['total_requests']++;
            $aggregated[$groupKey]['total_good'] += ((int)($row['blocked'] ?? 0) === 0) ? 1 : 0;

            if ($timestamp >= $aggregated[$groupKey]['last_request']) {
                $aggregated[$groupKey]['last_request'] = $timestamp;
                $aggregated[$groupKey]['last_visited_url'] = (string)($row['url'] ?? '');
            }
        }

        $data = [];
        foreach ($aggregated as $row) {
            $data[] = [
                $row['ip'],
                $row['total_requests'],
                $row['total_good'],
                $row['last_request'],
                $row['request_status'],
                $row['ua_name'],
                $row['ua_id'],
                ['fu' => $row['first_visited_url'], 'lu' => $row['last_visited_url']],
            ];
        }

        return $data;
    }

    public static function handleSyncFailure(): void
    {
    }

    public static function markSynced(): void
    {
        self::connectFromSettings()->del(self::processingKey());
    }

    /**
     * @return \Redis
     */
    private static function connectFromSettings()
    {
        if (class_exists(\Redis::class) === false) {
            throw new RuntimeException(
                'keydb request storage requires the php redis extension (`ext-redis`)'
            );
        }

        $redis = new \Redis();
        $connected = $redis->connect(
            Settings::$keyDbHost,
            Settings::$keyDbPort,
            Settings::$keyDbTimeout
        );

        if ($connected === false) {
            throw new RuntimeException('failed to connect to keydb');
        }

        if (Settings::$keyDbPassword !== '' && $redis->auth(Settings::$keyDbPassword) === false) {
            throw new RuntimeException('keydb authentication failed');
        }

        if ($redis->select(Settings::$keyDbDatabase) === false) {
            throw new RuntimeException('failed to select keydb database');
        }

        return $redis;
    }

    private static function pendingKey(): string
    {
        return self::prefix() . ':requests:pending';
    }

    private static function processingKey(): string
    {
        return self::prefix() . ':requests:processing';
    }

    private static function prefix(): string
    {
        $normalizedPrefix = trim(Settings::$keyDbPrefix);

        return $normalizedPrefix === '' ? 'anticrawler' : $normalizedPrefix;
    }

    private static function visitorKey(string $fingerprint): string
    {
        return self::prefix() . ':visitors:' . $fingerprint;
    }

    private static function visitorTtl(): int
    {
        return max(1, (int)Settings::$visitorForgetAfter);
    }
}
