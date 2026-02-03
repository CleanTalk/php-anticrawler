<?php

namespace Cleantalk\PHPAntiCrawler;

class ResultDto
{
    /** @var bool */
    public $goodRequest;

    /** @var string */
    public $status;

    public const STATUS_DB_MATCH            = 'DB_MATCH';
    public const STATUS_FLOOD_PROTECTION    = 'FLOOD_PROTECTION';
    public const STATUS_BOT_PROTECTION      = 'BOT_PROTECTION';
    public const STATUS_PERSONAL_LIST_MATCH = 'PERSONAL_LIST_MATCH';
    public const STATUS_DENY_SFW            = 'DENY_SFW';
    public const STATUS_UNDEFINED           = ''; // Should not be sent to DB

    public function __construct(
        bool $goodRequest,
        string $status
    ) {
        $this->goodRequest = $goodRequest;
        $this->status = $status;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['good_request'],
            $data['status'],
        );
    }

    public function toArray(): array
    {
        return [
            'good_request' => $this->goodRequest,
            'status' => $this->status,
        ];
    }
}
