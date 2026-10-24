<?php
/**
 * DNA SSL - shared client for the Domain Name API SSL gateway.
 *
 * One method per gateway operation, named after the Swagger path
 * (https://api.domainresellerapi.com/swagger/v1/swagger.json). Every method returns the
 * decoded JSON body as an array or throws ApiException; there is no false/error-string
 * channel to forget to check.
 *
 * Kept PHP 7.4 compatible (see ApiException.php).
 */

namespace DomainNameApi\Ssl;

require_once __DIR__ . '/ApiException.php';

class Client
{
    const URL_PROD = 'https://api.domainresellerapi.com/api/v1';
    const URL_OTE  = 'https://ote.domainresellerapi.com/api/v1';

    const VERSION = '1.0.0';

    /** Request and response keys whose values never reach a log line. */
    const SECRET_KEYS = ['privateKey', 'privatekey', 'private_key', 'X-API-KEY', 'apiKey', 'api_key'];

    /** @var string */
    private $resellerId;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var int */
    private $timeout;

    /** @var callable|null fn(string $action, array $request, mixed $response): void */
    private $logger;

    /** @var callable|null fn(string $method, string $url, array $headers, ?string $body): array{0:int,1:string} */
    private $transport;

    public function __construct(string $resellerId, string $apiKey, bool $testMode = false, int $timeout = 30)
    {
        $this->resellerId = trim($resellerId);
        $this->apiKey     = trim($apiKey);
        $this->baseUrl    = $testMode ? self::URL_OTE : self::URL_PROD;
        $this->timeout    = max(5, $timeout);
    }

    /** Receives every call, with secrets already masked. */
    public function setLogger(?callable $logger): void
    {
        $this->logger = $logger;
    }

    /** Test seam: replaces the cURL transport. */
    public function setTransport(?callable $transport): void
    {
        $this->transport = $transport;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * GET /products/ssls - every certificate product, all pages.
     *
     * @param array $filter FilterText, Currency, ValidationType, IsWildcard, IsMultiDomain, VendorName
     * @return array[] ProductSslListDto rows
     */
    public function products(array $filter = []): array
    {
        $items = [];
        $skip  = 0;
        $page  = 100;

        // Hard stop well above any real catalogue so a gateway that ignores SkipCount
        // cannot keep us looping.
        for ($guard = 0; $guard < 50; $guard++) {
            $query = array_filter($filter, function ($v) { return $v !== null && $v !== ''; })
                + ['SkipCount' => $skip, 'MaxResultCount' => $page];

            $result = $this->request('GET', '/products/ssls', $query);
            $rows   = isset($result['items']) && is_array($result['items']) ? $result['items'] : [];
            $total  = isset($result['totalCount']) ? (int) $result['totalCount'] : count($rows);

            foreach ($rows as $row) {
                if (is_array($row)) $items[] = $row;
            }

            $skip += count($rows);
            if (!$rows || $skip >= $total) break;
        }

        return $items;
    }

    /**
     * GET /deposit/accounts/me - resellerName, resellerGroupName (the price group the
     * reseller pays), balances.
     */
    public function account(): array
    {
        return $this->request('GET', '/deposit/accounts/me');
    }

    /** The reseller's price group name ("Reseller", "Premium", ...), '' when unknown. */
    public function priceGroup(): string
    {
        try {
            return trim((string) ($this->account()['resellerGroupName'] ?? ''));
        } catch (ApiException $e) {
            return '';
        }
    }

    // ------------------------------------------------------------------
    // CSR helpers
    // ------------------------------------------------------------------

    /** POST /ssls/generate-csr - returns ['csr' => ..., 'privateKey' => ...]. */
    public function generateCsr(array $input): array
    {
        return $this->request('POST', '/ssls/generate-csr', [], [
            'domainName'       => (string) ($input['domainName'] ?? ''),
            'country'          => (string) ($input['country'] ?? ''),
            'state'            => (string) ($input['state'] ?? ''),
            'locality'         => (string) ($input['locality'] ?? ''),
            'organization'     => (string) ($input['organization'] ?? ''),
            'organizationUnit' => (string) ($input['organizationUnit'] ?? ''),
            'alternativeNames' => array_values((array) ($input['alternativeNames'] ?? [])),
        ]);
    }

    /** POST /ssls/check-csr - decodes a CSR (domainName, isWildcard, isSan, ...). */
    public function checkCsr(string $csr): array
    {
        return $this->request('POST', '/ssls/check-csr', [], ['csr' => trim($csr)]);
    }

    // ------------------------------------------------------------------
    // Certificate lifecycle
    // ------------------------------------------------------------------

    /** POST /ssls/initialize - opens the order. Returns SslOperationResponse. */
    public function initialize(string $packageCode, string $domainName): array
    {
        return $this->operation('POST', '/ssls/initialize', [], [
            'packageCode' => $packageCode,
            'domainName'  => $domainName,
        ]);
    }

    /**
     * PUT /ssls/configure - submits CSR, DCV choice and contact. Returns SslOperationResponse.
     *
     * @param array $input domainName, csr, privateKey?, approverMail, authenticationType,
     *                     webServerType, sanList?, wildcardList?, contact{...}
     */
    public function configure(array $input): array
    {
        return $this->operation('PUT', '/ssls/configure', [], $input);
    }

    /** PUT /ssls/check-status - CertificateStatusDto (status, isConfigured, isIssued, ...). */
    public function checkStatus(string $domainName): array
    {
        return $this->request('PUT', '/ssls/check-status', [], ['domainName' => $domainName]);
    }

    /** GET /ssls/certificate - CertificateDetailDto. */
    public function certificate(string $domainName): array
    {
        return $this->request('GET', '/ssls/certificate', ['DomainName' => $domainName]);
    }

    /** GET /ssls/certificate-content - PEM bodies (certificate, intermediates, root). */
    public function certificateContent(string $domainName): array
    {
        return $this->request('GET', '/ssls/certificate-content', ['DomainName' => $domainName]);
    }

    /** POST /ssls/reissue - Returns SslOperationResponse. */
    public function reissue(string $domainName, string $csr = '', string $approverMail = '', string $privateKey = ''): array
    {
        $body = ['domainName' => $domainName];
        if ($csr !== '')          $body['csr']          = trim($csr);
        if ($approverMail !== '') $body['approverMail'] = $approverMail;
        if ($privateKey !== '')   $body['privateKey']   = $privateKey;

        return $this->operation('POST', '/ssls/reissue', [], $body);
    }

    /** GET /ssls/approvers - the e-mail addresses allowed for e-mail DCV. */
    public function approvers(string $domainName, string $packageCode): array
    {
        $result = $this->request('GET', '/ssls/approvers', [
            'DomainName'  => $domainName,
            'PackageCode' => $packageCode,
        ]);

        $mails = isset($result['mails']) && is_array($result['mails']) ? $result['mails'] : [];

        return array_values(array_filter(array_map('strval', $mails), function ($m) {
            return $m !== '';
        }));
    }

    /** DELETE /ssls/revoke - Returns SslOperationResponse. */
    public function revoke(string $domainName): array
    {
        return $this->operation('DELETE', '/ssls/revoke', ['DomainName' => $domainName]);
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /** Like request(), but an SslOperationResponse with success=false is an error too. */
    private function operation(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $result = $this->request($method, $path, $query, $body);

        if (array_key_exists('success', $result) && !$result['success']) {
            $message = trim((string) ($result['message'] ?? ''));
            throw new ApiException($message !== '' ? $message : 'The SSL gateway rejected the request.', 200, 'OperationFailed');
        }

        return $result;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        if ($this->resellerId === '' || $this->apiKey === '') {
            throw new ApiException('Reseller ID and API Key are required.');
        }

        $url = $this->baseUrl . $path;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers = [
            'Accept: application/json',
            'X-API-KEY: ' . $this->apiKey,
            '__reseller: ' . $this->resellerId,
            'User-Agent: DNA-SSL/' . self::VERSION . ' (+https://www.domainnameapi.com)',
        ];

        $payload = null;
        if ($body !== null) {
            $payload   = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        $action = $method . ' ' . $path;

        try {
            list($status, $raw) = $this->transport
                ? call_user_func($this->transport, $method, $url, $headers, $payload)
                : $this->curl($method, $url, $headers, $payload);
        } catch (ApiException $e) {
            $this->log($action, $query, $body, $e->getMessage());
            throw $e;
        }

        $decoded = $raw === '' ? [] : json_decode($raw, true);
        $this->log($action, $query, $body, is_array($decoded) ? $decoded : $raw);

        if ($status < 200 || $status >= 300) {
            throw $this->httpError((int) $status, is_array($decoded) ? $decoded : [], (string) $raw);
        }

        if ($raw !== '' && !is_array($decoded)) {
            throw new ApiException('The SSL gateway returned a response that is not JSON.', (int) $status, 'InvalidResponse');
        }

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array{0:int,1:string} */
    private function curl(string $method, string $url, array $headers, ?string $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new ApiException('The PHP cURL extension is required.');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $errno) {
            throw new ApiException('Could not reach the SSL gateway: ' . ($error !== '' ? $error : 'cURL error ' . $errno), 0, 'Transport');
        }

        return [$status, (string) $raw];
    }

    private function httpError(int $status, array $decoded, string $raw): ApiException
    {
        $error   = isset($decoded['error']) && is_array($decoded['error']) ? $decoded['error'] : [];
        $message = trim((string) ($error['message'] ?? ''));
        $details = trim((string) ($error['details'] ?? ''));
        $code    = (string) ($error['code'] ?? '');

        if (!empty($error['validationErrors']) && is_array($error['validationErrors'])) {
            $parts = [];
            foreach ($error['validationErrors'] as $v) {
                if (is_array($v) && !empty($v['message'])) $parts[] = (string) $v['message'];
            }
            if ($parts) $details = trim($details . ' ' . implode(' ', $parts));
        }

        if ($message === '') {
            switch ($status) {
                case 401: $message = 'The SSL gateway rejected the Reseller ID / API Key.'; break;
                case 403: $message = 'This reseller account is not allowed to use the SSL gateway.'; break;
                case 404: $message = 'The SSL gateway could not find the requested certificate.'; break;
                default:  $message = 'The SSL gateway answered HTTP ' . $status . '.';
            }
        }

        if ($details !== '' && strpos($message, $details) === false) $message .= ' ' . $details;

        return new ApiException($message, $status, $code, $details);
    }

    private function log(string $action, array $query, ?array $body, $response): void
    {
        if (!$this->logger) return;

        $request = ['url' => $this->baseUrl, 'query' => $query];
        if ($body !== null) $request['body'] = $body;

        try {
            call_user_func($this->logger, $action, self::mask($request), is_array($response) ? self::mask($response) : $response);
        } catch (\Throwable $e) {
            // A broken logger must never break provisioning.
        }
    }

    /** Recursively replaces secret values with a fixed marker. */
    public static function mask(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::mask($value);
            } elseif (is_string($key) && in_array($key, self::SECRET_KEYS, true) && $value !== null && $value !== '') {
                $data[$key] = '***';
            }
        }

        return $data;
    }
}
