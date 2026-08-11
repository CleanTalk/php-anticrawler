<?php

namespace Cleantalk\PHPAntiCrawler\Tests;

use Cleantalk\PHPAntiCrawler\LogsSender;
use PHPUnit\Framework\TestCase;

final class LogsSenderTest extends TestCase
{
    public function testMalformedUtf8IsSubstitutedWithoutDroppingBatch(): void
    {
        $malicious = "\xC0\xA7\xC0\xA2%2527%2522\\'\\\"";
        $data = [
            ['127.0.0.1', 1, 1, 123, 'PASS', $malicious, 0, ['fu' => $malicious, 'lu' => '/safe']],
            ['127.0.0.2', 1, 0, 124, 'FAIL', 'ordinary agent', 0, ['fu' => '/one', 'lu' => '/two']],
        ];

        $serialized = LogsSender::serializeData($data);
        $decoded = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);
        $postBody = http_build_query(['data' => $serialized]);
        parse_str($postBody, $parsedPostBody);

        $this->assertCount(count($data), $decoded);
        $this->assertStringContainsString('%2527%2522', $decoded[0][5]);
        $this->assertStringContainsString("\\'\\\"", $decoded[0][5]);
        $this->assertSame(4, substr_count($decoded[0][5], "\u{FFFD}"));
        $this->assertStringNotContainsString('\\/', $serialized);
        $this->assertStringNotContainsString('data=0', $postBody);
        $this->assertSame($serialized, $parsedPostBody['data']);
        $this->assertIsArray(json_decode($parsedPostBody['data'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testValidUnicodeIsPreserved(): void
    {
        $userAgent = 'Браузер/猫';
        $url = '/путь/猫?ключ=значение';
        $data = [['127.0.0.1', 1, 1, 123, 'PASS', $userAgent, 0, ['fu' => $url, 'lu' => $url]]];

        $serialized = LogsSender::serializeData($data);
        $decoded = json_decode($serialized, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($userAgent, $decoded[0][5]);
        $this->assertSame($url, $decoded[0][7]['fu']);
        $this->assertStringNotContainsString("\u{FFFD}", $serialized);
        $this->assertStringNotContainsString('\\/', $serialized);
    }
}
