<?php

namespace Cleantalk\PHPAntiCrawler;

class Settings
{
    /** @var string */
    public const VERSION = 'phpanticrawler-1.0.37';

    /** @var string */
    public static $dbPath = __DIR__ . '/anticrawler.sqlite';

    /** @var string */
    public static $apiKey = '';

    /** @var int */
    public static $minSyncInterval = 60 * 10; // Ten minutes

    /** @var int */
    public static $maxSyncInterval = 60 * 60; // One hour

    /** @var int */
    public static $visitorForgetAfter = 60 * 60 * 24 * 30; // One month

    /** @var int */
    public static $pendingRequestsMaxLength = 100000;

    /** @var int */
    public static $maxRowsBeforeSync = 20000;

    /** @var bool */
    public static $syncByCron = false;

    /** @var string */
    public static $requestsBackend = 'sqlite';

    /** @var string */
    public static $keyDbHost = '127.0.0.1';

    /** @var int */
    public static $keyDbPort = 6379;

    /** @var float */
    public static $keyDbTimeout = 1.5;

    /** @var string */
    public static $keyDbPassword = '';

    /** @var int */
    public static $keyDbDatabase = 0;

    /** @var string */
    public static $keyDbPrefix = 'anticrawler';

    /**
     * Configures library settings. This method is called from the CleanTalkAntiCrawler constructor.
     * Array values override config values.
     *
     * @param $options List of options
     *
     * Example:
     * $ac = new CleanTalkAntiCrawler([
     *     'max_sync_interval' => 60 * 10,
     *     'max_rows_before_sync' => 10000,
     * ]);
     */
    public static function configure(array $options): void
    {
        if (!empty($options)) {
            self::$dbPath             = $options['db_path']              ?? self::$dbPath;
            self::$apiKey             = $options['api_key']              ?? self::$apiKey;
            self::$minSyncInterval    = $options['min_sync_interval']    ?? self::$minSyncInterval;
            self::$maxSyncInterval    = $options['max_sync_interval']    ?? self::$maxSyncInterval;
            self::$visitorForgetAfter = $options['visitor_forget_after'] ?? self::$visitorForgetAfter;
            self::$pendingRequestsMaxLength = $options['pending_requests_max_length']
                ?? self::$pendingRequestsMaxLength;
            self::$maxRowsBeforeSync  = $options['max_rows_before_sync'] ?? self::$maxRowsBeforeSync;
            self::$syncByCron         = $options['sync_by_cron']         ?? self::$syncByCron;
            self::$requestsBackend    = $options['requests_backend']     ?? self::$requestsBackend;
            self::$keyDbHost          = $options['keydb_host']           ?? self::$keyDbHost;
            self::$keyDbPort          = $options['keydb_port']           ?? self::$keyDbPort;
            self::$keyDbTimeout       = $options['keydb_timeout']        ?? self::$keyDbTimeout;
            self::$keyDbPassword      = $options['keydb_password']       ?? self::$keyDbPassword;
            self::$keyDbDatabase      = $options['keydb_database']       ?? self::$keyDbDatabase;
            self::$keyDbPrefix        = $options['keydb_prefix']         ?? self::$keyDbPrefix;
        }
    }
}
