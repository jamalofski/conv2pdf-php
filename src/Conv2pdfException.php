<?php

declare(strict_types=1);

namespace Conv2pdf;

/**
 * Erreur renvoyée par le client conv2pdf : erreur API (4xx/5xx) ou réseau.
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
     * Code HTTP de la réponse (ex. 401, 415, 422, 429). 0 en cas d'erreur réseau.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Code d'erreur stable renvoyé par l'API (ex. 'missing_bearer_token',
     * 'pdf_scanned_needs_ocr', 'quota_exceeded'), ou null si indisponible.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }
}
