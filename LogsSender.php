<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

final class LogsSender
{
    /**
     * @param array<int, array<int, mixed>> $data
     */
    public static function sendDataQuery(string $apiKey, array $data): bool
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
}
