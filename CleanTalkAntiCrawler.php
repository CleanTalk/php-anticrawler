<?php
declare(strict_types=1);

namespace Cleantalk\PHPAntiCrawler;

use Cleantalk\PHPAntiCrawler\RequestDto;
use Cleantalk\PHPAntiCrawler\ResultDto;
use Cleantalk\PHPAntiCrawler\Settings;
use Cleantalk\PHPAntiCrawler\SQLiteManager;
use Cleantalk\PHPAntiCrawler\SyncManager;
use Exception;
use PDO;

final class CleanTalkAntiCrawler
{
    public const COOKIE   = 'js_anticrawler_passed';
    public const ONE_DAY  = 60 * 60 * 24;

    private PDO $pdo;

    public function __construct(array $options = [])
    {
        Settings::configure($options);

        $this->pdo = SQLiteManager::initDb(Settings::$dbPath);
    }

    public function badVisitor(): bool
    {
        if (self::isTestIp()) {
            return true;
        }

        $request = RequestDto::fromArray([
            'id' => bin2hex(random_bytes(16)),
            'fingerprint' => self::fingerprint(),
            'ip' => self::ip(),
            'ua_name' => self::ua(),
            'url' => self::url(),
            'ua_id' => $this->getUaId(self::ua()),
            'access_language' => $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            'access_encoding' => $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '',
        ]);

        // Unauthorized check
        if ($this->apiKeyActive(Settings::$apiKey) === false) {
            $checkResult = $this->checkByCookie($request);
            return ($checkResult->goodRequest === false);
        }

        (new SyncManager($this->pdo))->manageSynchronization(Settings::$apiKey);

        // Authorized check
        $checkResult = $this->fullCheck($request);
        $this->storeRequest($request, $checkResult);

        return ($checkResult->goodRequest === false);
    }

    private function storeRequest(RequestDto $request, ResultDto $result): void
    {
        $stmt = $this->pdo->prepare("SELECT ua_id FROM user_agents WHERE ua_name LIKE :ua LIMIT 1");
        $stmt->execute([':ua' => '%' . $request->uaName . '%']);
        $ua = $stmt->fetch();
        $uaId = !empty($ua) ? (int)$ua['ua_id'] : 0;

        $stmt = $this->pdo->prepare(<<<SQL
            INSERT INTO requests
            (id, fingerprint, ip, blocked, timestamp_unixtime, ua_name, ua_id, url, request_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $request->id,
            $request->fingerprint,
            $request->ip,
            $result->goodRequest ? 0 : 1,
            time(),
            $request->uaName,
            $uaId,
            $request->url,
            $result->status,
        ]);
    }

    private function fullCheck(RequestDto $request): ResultDto
    {
        if (($firstCheck = $this->checkByLists($request))->status !== ResultDto::STATUS_UNDEFINED) {
            return $firstCheck;
        }

        if (($secondCheck = $this->checkByUserAgents($request))->status !== ResultDto::STATUS_UNDEFINED) {
            return $secondCheck;
        }

        return $thirdCheck = $this->checkByCookie($request);
    }

    private function checkByLists(RequestDto $request): ResultDto
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT ip, is_personal_list, is_whitelist
            FROM lists
            WHERE ip = :ip
            ORDER BY is_personal_list DESC, is_whitelist DESC
            LIMIT 1
        SQL);
        $stmt->bindValue(':ip', inet_pton($request->ip), PDO::PARAM_LOB);
        $stmt->execute();
        $row = $stmt->fetch();

        if (empty($row)) {
            return ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_UNDEFINED]);
        }
        if ($row['is_whitelist'] == 1) {
            return ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_PERSONAL_LIST_MATCH]);
        }
        if ($row['is_whitelist'] == 0) {
            return ResultDto::fromArray(['good_request' => 0, 'status' => ResultDto::STATUS_DB_MATCH]);
        }
        throw new Exception('Unexpected data found in lists table: ' . json_encode($row));
    }

    private function checkByUserAgents(RequestDto $request): ResultDto
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT is_whitelist FROM user_agents
            WHERE ua_id = :id
            ORDER BY is_whitelist DESC LIMIT 1
        SQL);
        $stmt->execute([':id' => $request->uaId]);
        $row = $stmt->fetch();

        if (empty($row)) {
            return ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_UNDEFINED]);
        }
        if ($row['is_whitelist'] == 1) {
            return ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_BOT_PROTECTION]);
        }
        if ($row['is_whitelist'] == 0) {
            return ResultDto::fromArray(['good_request' => 0, 'status' => ResultDto::STATUS_BOT_PROTECTION]);
        }
        throw new Exception('Unexpected data found in user_agents table: ' . json_encode($row));
    }

    private function checkByCookie(RequestDto $request): ResultDto
    {
        $isFirstVisit = $this->saveVisitor($request); // returns `true` if INSERT happened and `false` otherwise
        if ($isFirstVisit) {
            return ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_DB_MATCH]);
        }

        $this->updateLastSeen($request);
        return self::cookieFound()
            ? ResultDto::fromArray(['good_request' => 1, 'status' => ResultDto::STATUS_BOT_PROTECTION])
            : ResultDto::fromArray(['good_request' => 0, 'status' => ResultDto::STATUS_BOT_PROTECTION]);
    }

    private function saveVisitor(RequestDto $request): bool
    {
        $now = time();

        $stmt = $this->pdo->prepare('
            INSERT INTO visitors (fingerprint, ip, ua, created_at, last_seen)
            VALUES (:fp, :ip, :ua, :c, :l)
            ON CONFLICT(fingerprint) DO NOTHING
        ');
        $stmt->execute([
            ':fp' => $request->fingerprint,
            ':ip' => $request->ip,
            ':ua' => $request->uaName,
            ':c'  => $now,
            ':l'  => $now,
        ]);

        // rowCount() will be 1 if insert succeeded (first time), 0 if ignored (already existed)
        return $stmt->rowCount() === 1;
    }

    private function updateLastSeen(RequestDto $request): void
    {
        $now = time();

        $stmt = $this->pdo->prepare('UPDATE visitors SET last_seen = :l WHERE fingerprint = :fp');
        $stmt->execute([
            ':fp' => $request->fingerprint,
            ':l'  => $now,
        ]);
    }

    private static function cookieFound(): bool
    {
        return isset($_COOKIE[self::COOKIE]) && $_COOKIE[self::COOKIE] == 1;
    }

    public function showAccessDeniedScreen(int $status = 403): void
    {
        http_response_code($status);
        header('content-type: text/html; charset=utf-8');

        $html = file_get_contents(__DIR__ . '/cleantalk-anticrawler.html');
        $html = str_replace(':IP:', htmlspecialchars(self::ip(), ENT_QUOTES, 'UTF-8'), $html);

        echo $html;
        exit;
    }

    private static function ip(): string
    {
        if (self::isTestIp()) {
            return '10.10.10.10';
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private static function ua(): string
    {
        return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024);
    }

    private static function url(): string
    {
        $scheme = (
            (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        ) ? 'https' : 'http';

        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'];
        $uri  = $_SERVER['REQUEST_URI'];

        return "{$scheme}://{$host}{$uri}";
    }

    private static function isTestIp(): bool
    {
        return ($_REQUEST['sfw_test_ip'] ?? '') === '10.10.10.10';
    }

    private function getUaId(string $ua): int
    {
        $stmt = $this->pdo->prepare(<<<SQL
            SELECT ua_id, ua_name, is_whitelist
            FROM user_agents
            ORDER BY is_whitelist DESC, ua_id ASC
        SQL);
        $stmt->execute();
        $userAgents = $stmt->fetchAll();

        foreach ($userAgents as $agent) {
            $regex = $agent['ua_name'];

            if (preg_match($regex, $ua, $matches) === 1) {
                return (int)$agent['ua_id'];
            }
        }

        return 0;
    }

    private static function fingerprint(): string
    {
        $userIp         = self::ip();
        $userAgent      = self::ua();
        $acceptLanguage = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        $acceptEncoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';

        return sha1(implode('|', [$userIp, $userAgent, $acceptLanguage, $acceptEncoding]));
    }

    private function apiKeyActive(string $apiKey = ''): bool
    {
        if (empty($apiKey)) {
            return false;
        }

        $lastKeyCheckUnixTime = (int)(
            $this->pdo
                ->query("SELECT v FROM kv WHERE k = 'last_key_check';")
                ->fetchColumn() ?? 0
        );
        $checkStillValid = (time() - $lastKeyCheckUnixTime < self::ONE_DAY);
        if ($checkStillValid) {
            return true;
        }

        $url = 'https://api.cleantalk.org/?method_name=notice_paid_till&auth_key=' . urlencode($apiKey);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            curl_close($ch);
            return false;
        }
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($status !== 200) {
            return false;
        }

        $data = json_decode($response, true);
        $keyActive = !empty($data['data']['moderate']);

        if ($keyActive) {
            $this->pdo->exec("UPDATE kv SET v = " . time() . " WHERE k = 'last_key_check';");
        }

        return $keyActive;
    }
}
