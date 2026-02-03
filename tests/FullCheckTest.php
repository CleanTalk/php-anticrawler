<?php

namespace Cleantalk\PHPAntiCrawler\Tests;

use Cleantalk\PHPAntiCrawler\CleanTalkAntiCrawler;
use Cleantalk\PHPAntiCrawler\RequestDto;
use Cleantalk\PHPAntiCrawler\ResultDto;
use Cleantalk\PHPAntiCrawler\SQLiteManager;
use Cleantalk\PHPAntiCrawler\SyncManager;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

// If tests fail for no obvious reason, delete sqlite files and try again
class FullCheckTest extends TestCase {

    private const TEST_DB_PATH = __DIR__ . '/anticrawler-tests.sqlite';

    private PDO $pdo;

    private function getRequestsTestData(): array
    {
        return [
            'request1' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'personal_whitelisted', 'ip' => '10.10.10.10', 'ua_name' => 'useragent1']),
            'request2' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'personal_blacklisted', 'ip' => '10.10.10.11', 'ua_name' => 'useragent2']),
            'request3' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'common_whitelisted',   'ip' => '10.10.10.12', 'ua_name' => 'useragent3']),
            'request4' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'common_blacklisted',   'ip' => '10.10.10.13', 'ua_name' => 'useragent4']),
            'request5' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'ua_whitelisted',       'ip' => '10.10.10.14', 'ua_name' => 'Applebot', 'ua_id' => 17]),
            'request6' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'js_check_failed',      'ip' => '2001:db8::1', 'ua_name' => 'useragent6']),
            'request7' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'js_check_passed',      'ip' => '2001:db8::2', 'ua_name' => 'useragent7']),
            'request8' => RequestDto::fromArray(['id' => bin2hex(random_bytes(16)), 'fingerprint' => 'first_visit',          'ip' => '10.10.10.17', 'ua_name' => 'useragent8']),
        ];
    }

    private function prepareTestDatabase(): void
    {
        SQLiteManager::deleteDb(self::TEST_DB_PATH);
        $this->pdo = SQLiteManager::initDb(self::TEST_DB_PATH);

        // lists
        $listsData = [
            [inet_pton('10.10.10.10'), 1, 1],
            [inet_pton('10.10.10.11'), 1, 0],
            [inet_pton('10.10.10.12'), 0, 1],
            [inet_pton('10.10.10.13'), 0, 0],
        ];

        $stmt = $this->pdo->prepare('INSERT INTO lists (ip, is_personal_list, is_whitelist) VALUES (?, ?, ?)');
        foreach ($listsData as [$ip, $personal, $white]) {
            $stmt->bindValue(1, $ip,       PDO::PARAM_LOB);
            $stmt->bindValue(2, $personal, PDO::PARAM_INT);
            $stmt->bindValue(3, $white,    PDO::PARAM_INT);
            $stmt->execute();
        }

        // user_agents
        $uaData = [
            [17, '(Applebot)', 1],
            [42, '(CleanTalk)', 1],
            [133, '(GPTBot)', 1],
        ];
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO user_agents (ua_id, ua_name, is_whitelist)
            VALUES
            ({$uaData[0][0]}, '{$uaData[0][1]}', {$uaData[0][2]}),
            ({$uaData[1][0]}, '{$uaData[1][1]}', {$uaData[1][2]}),
            ({$uaData[2][0]}, '{$uaData[2][1]}', {$uaData[2][2]})
        SQL);
        $stmt->execute();

        // visitors
        $visitorsData = $this->getRequestsTestData();
        $now = time();
        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO visitors (fingerprint, ip, ua, created_at, last_seen)
            VALUES
            ('{$visitorsData['request1']->fingerprint}', '{$visitorsData['request1']->ip}', '{$visitorsData['request1']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request2']->fingerprint}', '{$visitorsData['request2']->ip}', '{$visitorsData['request2']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request3']->fingerprint}', '{$visitorsData['request3']->ip}', '{$visitorsData['request3']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request4']->fingerprint}', '{$visitorsData['request4']->ip}', '{$visitorsData['request4']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request5']->fingerprint}', '{$visitorsData['request5']->ip}', '{$visitorsData['request5']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request6']->fingerprint}', '{$visitorsData['request6']->ip}', '{$visitorsData['request6']->uaName}', {$now}, {$now}),
            ('{$visitorsData['request7']->fingerprint}', '{$visitorsData['request7']->ip}', '{$visitorsData['request7']->uaName}', {$now}, {$now})
        SQL);
        $stmt->execute();
    }

    public function testFullCheck(): void
    {
        $this->prepareTestDatabase();
        $visitorsData = $this->getRequestsTestData();
        $sut = new CleanTalkAntiCrawler(['db_path' => self::TEST_DB_PATH]);

        $method = new ReflectionMethod(CleanTalkAntiCrawler::class, 'fullCheck');
        $method->setAccessible(true);


        // Visitor 1: record found in personal whitelist
        $visitor1 = $visitorsData['request1'];
        $result1 = $method->invoke($sut, $visitor1);
        $this->assertSame(ResultDto::STATUS_PERSONAL_LIST_MATCH, $result1->status);
        $this->assertSame(true, $result1->goodRequest);

        // Visitor 2: record found in personal blacklist
        $visitor2 = $visitorsData['request2'];
        $result2 = $method->invoke($sut, $visitor2);
        $this->assertSame(ResultDto::STATUS_DB_MATCH, $result2->status);
        $this->assertSame(false, $result2->goodRequest);

        // Visitor 3: record found in common whitelist
        $visitor3 = $visitorsData['request3'];
        $result3 = $method->invoke($sut, $visitor3);
        $this->assertSame(ResultDto::STATUS_PERSONAL_LIST_MATCH, $result3->status);
        $this->assertSame(true, $result3->goodRequest);

        // Visitor 4: record found in common blacklist
        $visitor4 = $visitorsData['request4'];
        $result4 = $method->invoke($sut, $visitor4);
        $this->assertSame(ResultDto::STATUS_DB_MATCH, $result4->status);
        $this->assertSame(false, $result4->goodRequest);

        // Visitor 5: User-agent found in good agents list
        $visitor5 = $visitorsData['request5'];
        $result5 = $method->invoke($sut, $visitor5);
        $this->assertSame(ResultDto::STATUS_BOT_PROTECTION, $result5->status);
        $this->assertSame(true, $result5->goodRequest);

        // Visitor 6: JS check failed
        $visitor6 = $visitorsData['request6'];
        $result6 = $method->invoke($sut, $visitor6);
        $this->assertSame(ResultDto::STATUS_BOT_PROTECTION, $result6->status);
        $this->assertSame(false, $result6->goodRequest);

        // Visitor 7: JS check passed
        $_COOKIE[CleanTalkAntiCrawler::COOKIE] = 1;
        $visitor7 = $visitorsData['request7'];
        $result7 = $method->invoke($sut, $visitor7);
        $this->assertSame(ResultDto::STATUS_BOT_PROTECTION, $result7->status);
        $this->assertSame(true, $result7->goodRequest);

        // Visitor 8: first visit ever
        $visitor8 = $visitorsData['request8'];
        $result8 = $method->invoke($sut, $visitor8);
        $this->assertSame(ResultDto::STATUS_DB_MATCH, $result8->status);
        $this->assertSame(true, $result8->goodRequest);
    }

    public function testStoreRequest(): void
    {
        $this->prepareTestDatabase();
        $visitorsData = $this->getRequestsTestData();
        $sut = new CleanTalkAntiCrawler(['db_path' => self::TEST_DB_PATH]);

        $method = new ReflectionMethod(CleanTalkAntiCrawler::class, 'storeRequest');
        $method->setAccessible(true);

        $request = $visitorsData['request5'];
        $result = ResultDto::fromArray(['good_request' => true, 'status' => ResultDto::STATUS_BOT_PROTECTION]);
        $applebotId = 17;

        $method->invoke($sut, $request, $result);

        $stmt = $this->pdo->prepare('SELECT * FROM requests');
        $stmt->execute();
        $data = $stmt->fetch();

        $this->assertSame('10.10.10.14', $data['ip']);
        $this->assertSame(0, (int)$data['blocked']);
        $this->assertSame('Applebot', $data['ua_name']);
        $this->assertSame($applebotId, (int)$data['ua_id']);
        $this->assertSame('BOT_PROTECTION', $data['request_status']);
        $this->assertSame('idle', $data['sync_state']);
    }

    public function testRetrieveUaId(): void
    {
        $this->prepareTestDatabase();
        $sut = new CleanTalkAntiCrawler(['db_path' => self::TEST_DB_PATH]);

        $method = new ReflectionMethod(CleanTalkAntiCrawler::class, 'getUaId');
        $method->setAccessible(true);

        $uaName = 'CleanTalk';
        $uaId = $method->invoke($sut, $uaName);

        $wrongUaName = 'BadAgent';
        $wrongUaId = $method->invoke($sut, $wrongUaName);

        $this->assertSame(42, $uaId);
        $this->assertSame(0, $wrongUaId);
    }
}
