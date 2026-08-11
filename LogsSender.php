<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use JsonException;
use RuntimeException;

final class LogsSender
{
    /**
     * @param array<int, array<int, mixed>> $data
     */
    public static function serializeData(array $data): string
    {
        try {
            return json_encode(
                $data,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new RuntimeException('Failed to serialize request logs: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<int, array<int, mixed>> $data
     */
    public static function sendDataQuery(string $apiKey, array $data): bool
    {
        $postFields = [
            'timestamp' => time(),
            'rows' => count($data),
            'data' => self::serializeData($data),
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
}
