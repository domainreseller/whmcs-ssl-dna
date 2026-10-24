<?php
/**
 * DNA SSL - shared client for the Domain Name API SSL gateway.
 *
 * Kept PHP 7.4 compatible: the same files ship inside the WHMCS module (WHMCS 8 still
 * runs on 7.4) and the WISECP 5 module. Each module loads its own copy with
 * require_once; the two never run in one process.
 */

namespace DomainNameApi\Ssl;

/**
 * A failed gateway call: transport error, HTTP error, or an operation the gateway
 * answered with success=false. The message is safe to show to a client; secrets never
 * reach it.
 */
class ApiException extends \RuntimeException
{
    /** @var int HTTP status, 0 when the request never got an answer */
    private $httpStatus;

    /** @var string Gateway error code (ABP "error.code"), empty when none */
    private $errorCode;

    /** @var string Gateway error details, empty when none */
    private $details;

    public function __construct(string $message, int $httpStatus = 0, string $errorCode = '', string $details = '')
    {
        parent::__construct($message, $httpStatus);
        $this->httpStatus = $httpStatus;
        $this->errorCode  = $errorCode;
        $this->details    = $details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function details(): string
    {
        return $this->details;
    }

    /**
     * True when the gateway said the certificate does not exist (as opposed to
     * failing). It answers "Certificate not found" with HTTP 403 and code Ssl:10026,
     * not with a 404.
     */
    public function isNotFound(): bool
    {
        return $this->httpStatus === 404 || $this->errorCode === 'Ssl:10026';
    }

    /** True when the package does not offer this operation (revoke: Ssl:10003). */
    public function isUnsupported(): bool
    {
        return $this->errorCode === 'Ssl:10003';
    }
}
