<?php

declare(strict_types=1);

namespace Conv2pdf;

/**
 * Error returned by the conv2pdf client: an API error (4xx/5xx) or a network error.
 *
 * Match on getErrorCode(), never on getMessage(): codes are part of the API contract,
 * messages are not. getPayload() carries the rest of the error body, getRetryAfter()
 * the delay the server asked for when the refusal is transient.
 */
class Conv2pdfException extends \RuntimeException
{
    private int $status;
    private ?string $errorCode;
    /** @var array<string, mixed> */
    private array $payload;
    private ?int $retryAfter;

    /**
     * @param int                  $status     HTTP status of the response, 0 on a network error.
     * @param string|null          $errorCode  Stable code from the JSON body (`error` field), null when there is none.
     * @param array<string, mixed> $payload    Whole decoded JSON body of the error response, [] when there is none.
     * @param int|null             $retryAfter Value of the Retry-After header in seconds, null when absent.
     */
    public function __construct(
        string $message,
        int $status = 0,
        ?string $errorCode = null,
        array $payload = [],
        ?int $retryAfter = null
    ) {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->payload = $payload;
        $this->retryAfter = $retryAfter;
    }

    /**
     * HTTP status of the response (e.g. 401, 413, 415, 422, 429, 503). 0 on a network error.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Stable error code returned by the API (e.g. 'invalid_api_key', 'unsupported_content',
     * 'file_too_large', 'quota_exceeded', 'rate_limited'), or null when unavailable: network
     * error, or a body that is not JSON.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * The whole error body, decoded. Besides `error`, the API describes the refusal there:
     *   - 413 file_too_large: `max_bytes`, `file`, `plan`, and `upgrade` (plan, price_eur_month,
     *     max_bytes, url) when a higher plan would accept the file;
     *   - 415 unsupported_content: `detected_type`; 415 unsupported_media_type: `received`, `accepted`;
     *   - 409 job_not_ready: `status` (pending / failed / rejected);
     *   - 429 quota_exceeded: `quota`, `used`, `soft_cap_limit`, `quota_period_end` (next reset,
     *     millisecond timestamp); 429 rate_limited: `retry_after`, `limit`;
     *   - 402 plan_limit_files: `max_allowed`.
     * Empty on a network error, or when the body is not JSON.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * Seconds to wait before retrying, from the Retry-After header. The API sets it on its
     * transient refusals: 429 rate_limited (per-minute rate exceeded) and 503 server_busy.
     * Null otherwise, including on 429 quota_exceeded, where the next reset is
     * `quota_period_end` in getPayload().
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * True when the server asked to retry later (see getRetryAfter()). Anything else is a
     * verdict on the request itself: retrying it unchanged gives the same answer.
     */
    public function isTransient(): bool
    {
        return $this->retryAfter !== null;
    }
}
