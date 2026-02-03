<?php

namespace Cleantalk\PHPAntiCrawler;

use Cleantalk\PHPAntiCrawler\ResultDto;
class RequestDto
{
    /** @var string */
    public $id;

    /** @var string */
    public $fingerprint;

    /** @var string */
    public $ip;

    /** @var string */
    public $uaName;

    /** @var int */
    public $uaId = 0;

    /** @var string */
    public $acceptLanguage = '';

    /** @var string */
    public $acceptEncoding = '';

    /** @var string */
    public $status = ResultDto::STATUS_UNDEFINED;

    /** @var string */
    public $url = '';

    public function __construct(
        string $id,
        string $fingerprint,
        string $ip,
        string $uaName,
        ?int $uaId = 0,
        ?string $url = '',
        ?string $status = '',
        ?string $acceptLanguage = '',
        ?string $acceptEncoding = ''
    ) {
        $this->id = $id;
        $this->fingerprint = $fingerprint;
        $this->ip = $ip;
        $this->uaName = $uaName;
        $this->uaId = $uaId;
        $this->url = $url;
        $this->status = $status;
        $this->acceptLanguage = $acceptLanguage;
        $this->acceptEncoding = $acceptEncoding;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['fingerprint'],
            $data['ip'],
            $data['ua_name'],
            $data['ua_id'] ?? null,
            $data['url'],
            isset($data['status']) ? (string)$data['status'] : '',
            isset($data['accept_language']) ? (string)$data['accept_language'] : '',
            isset($data['accept_encoding']) ? (string)$data['accept_encoding'] : '',
        );
    }

    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'fingerprint'     => $this->fingerprint,
            'ip'              => $this->ip,
            'ua_name'         => $this->uaName,
            'ua_id'           => $this->uaId,
            'url'             => $this->url,
            'status'          => $this->status,
            'accept_language' => $this->acceptLanguage,
            'accept_encoding' => $this->acceptEncoding,
        ];
    }
}
