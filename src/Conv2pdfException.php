<?php

declare(strict_types=1);

namespace Conv2pdf;

/**
 * Error returned by the conv2pdf client: an API error (4xx/5xx) or a network error.
 */
class Conv2pdfException extends \RuntimeException
{
    private int $status;
    private ?string $errorCode;

    public function __construct(string $message, int $status = 0, ?string $errorCode = null)
    {
        parent::__construct($message, $status);
        $this->status = $status;
        $this->errorCode = $errorCode;
    }

    /**
     * HTTP status of the response (e.g. 401, 415, 422, 429). 0 on a network error.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Stable error code returned by the API (e.g. 'missing_bearer_token',
     * 'pdf_scanned_needs_ocr', 'quota_exceeded'), or null when unavailable.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
