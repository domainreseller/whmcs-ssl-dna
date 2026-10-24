<?php
/**
 * DNA SSL - turns gateway DTOs into the plain shapes both billing modules use.
 *
 * Everything the Swagger file leaves open (status strings, the DCV detail format, the
 * unit of a price period) is interpreted here and nowhere else, so a gateway surprise
 * is a one-file fix.
 *
 * Kept PHP 7.4 compatible.
 */

namespace DomainNameApi\Ssl;

class Normalizer
{
    /**
     * Module DCV method => gateway authenticationType.
     *
     * Probed on OTE (2026-10-24): the gateway accepts EMAIL, HTTP, CNAME and FILE
     * (case-insensitive) and rejects DNS, HTTPS and TXT. There is no HTTPS file
     * validation, so a module asking for https gets the HTTP file method.
     */
    const DCV_TO_API = [
        'email' => 'EMAIL',
        'http'  => 'HTTP',
        'https' => 'HTTP',
        'dns'   => 'CNAME',
    ];

    /** DCV methods a client can pick (https is folded into http, see above). */
    const DCV_METHODS = ['email', 'http', 'dns'];

    /**
     * Web server types offered to the client; the key is what the gateway accepts.
     * Only "Apache" and "IIS" pass its validation by name (OTE, 2026-10-24); Apache is
     * the CA's catch-all for every non-Microsoft server (nginx, LiteSpeed, cPanel...).
     */
    const WEB_SERVERS = [
        'Apache' => 'Apache, nginx, LiteSpeed, cPanel, Plesk and others',
        'IIS'    => 'Microsoft IIS',
    ];

    const DEFAULT_WEB_SERVER = 'Apache';

    // ------------------------------------------------------------------
    // Catalogue
    // ------------------------------------------------------------------

    /**
     * One ProductSslListDto as a catalogue row.
     *
     * @return array{code:string,name:string,vendor:string,type:string,wildcard:bool,
     *               multi_domain:bool,included_sans:int,max_sans:int,warranty:float,
     *               issue_minutes:int,reissue_free:bool,requires_csr:bool,currency:string,
     *               prices:array,min_cost:float}|null null when the row has no code
     */
    public static function product(array $dto, string $priceGroup = ''): ?array
    {
        $code = trim((string) ($dto['productCode'] ?? ''));
        if ($code === '') return null;

        $name   = trim((string) ($dto['name'] ?? '')) ?: $code;
        $prices = self::prices((array) ($dto['prices'] ?? []), $priceGroup);
        $multi  = (bool) ($dto['isMultiDomain'] ?? false);

        return [
            'code'          => $code,
            'name'          => $name,
            'vendor'        => trim((string) ($dto['vendorName'] ?? '')),
            'type'          => self::validationType((string) ($dto['validationType'] ?? ''), $name),
            'wildcard'      => (bool) ($dto['isWildcard'] ?? false),
            'multi_domain'  => $multi,
            // The SAN counts include the common name (a single-domain certificate
            // reports 1/1), and wildcard-only packages report a meaningless 1/100: only
            // multi-domain packages take additional names.
            'included_sans' => $multi ? max(0, (int) ($dto['defaultSanCount'] ?? 0) - 1) : 0,
            'max_sans'      => $multi ? max(0, (int) ($dto['maxSanCount'] ?? 0) - 1) : 0,
            'warranty'      => (float) ($dto['warrantyAmount'] ?? 0),
            'issue_minutes' => max(0, (int) ($dto['issueTimeMinutes'] ?? 0)),
            'reissue_free'  => (bool) ($dto['isReissueFree'] ?? false),
            'requires_csr'  => (bool) ($dto['requiresCsr'] ?? true),
            'currency'      => $prices['currency'],
            'prices'        => $prices['terms'],
            'min_cost'      => $prices['terms'] ? min(array_column($prices['terms'], 'cost')) : 0.0,
        ];
    }

    /**
     * The whole catalogue, keyed by product code.
     *
     * The gateway does not keep codes unique: on OTE "412" names two different Comodo
     * packages and "503" is listed twice. initialize() takes only the code, so the
     * gateway can only ever sell one of them; the first row is kept so the module
     * offers exactly one product per code.
     *
     * @param array[] $dtos ProductSslListDto rows
     * @return array<string,array> rows from product(), with prices
     */
    public static function catalogue(array $dtos, string $priceGroup = ''): array
    {
        $out = [];
        foreach ($dtos as $dto) {
            if (!is_array($dto)) continue;
            $p = self::product($dto, $priceGroup);
            if ($p && $p['prices'] && !isset($out[$p['code']])) $out[$p['code']] = $p;
        }

        return $out;
    }

    /**
     * The registration prices of a product, cheapest per term.
     *
     * The gateway lists every price group (Reseller, Premium, Platinum, VIP); the
     * reseller pays the one named by resellerGroupName in /deposit/accounts/me. That
     * group is used when given and present, otherwise the first group with prices.
     *
     * @return array{currency:string,terms:array<int,array{months:int,cost:float}>}
     */
    public static function prices(array $groups, string $priceGroup = ''): array
    {
        $byMonths = [];
        $currency = '';

        $wanted = strtolower(trim($priceGroup));
        if ($wanted !== '') {
            foreach ($groups as $group) {
                if (is_array($group) && strtolower(trim((string) ($group['priceGroup'] ?? ''))) === $wanted && !empty($group['create'])) {
                    $groups = [$group];
                    break;
                }
            }
        }

        foreach ($groups as $group) {
            if (!is_array($group) || empty($group['create']) || !is_array($group['create'])) continue;

            foreach ($group['create'] as $row) {
                if (!is_array($row)) continue;
                $months = self::periodToMonths((int) ($row['period'] ?? 0));
                $cost   = (float) ($row['price'] ?? 0);
                if ($months <= 0 || $cost <= 0) continue;

                if (!isset($byMonths[$months]) || $cost < $byMonths[$months]) $byMonths[$months] = $cost;
                if ($currency === '' && !empty($row['currency'])) $currency = strtoupper(trim((string) $row['currency']));
            }

            if ($byMonths) break;
        }

        ksort($byMonths);

        $terms = [];
        foreach ($byMonths as $months => $cost) $terms[] = ['months' => (int) $months, 'cost' => round($cost, 2)];

        return ['currency' => $currency, 'terms' => $terms];
    }

    /**
     * Price periods are years (OTE lists period 1 for every package); a value above 10
     * can only be a month count, so both encodings land on months.
     */
    public static function periodToMonths(int $period): int
    {
        if ($period <= 0) return 0;

        return $period <= 10 ? $period * 12 : $period;
    }

    public static function validationType(string $raw, string $name = ''): string
    {
        $raw = strtoupper(trim($raw));
        if (in_array($raw, ['DV', 'OV', 'EV'], true)) return $raw;
        if (strpos($raw, 'EXTENDED') !== false) return 'EV';
        if (strpos($raw, 'ORGANI') !== false)   return 'OV';
        if (strpos($raw, 'DOMAIN') !== false)   return 'DV';

        $name = strtolower($name);
        if (strpos($name, 'extended') !== false || preg_match('/\bev\b/', $name)) return 'EV';
        if (strpos($name, 'organi') !== false || preg_match('/\bov\b/', $name))   return 'OV';

        return 'DV';
    }

    // ------------------------------------------------------------------
    // Status
    // ------------------------------------------------------------------

    /**
     * One of: awaiting_configuration, processing, active, revoked, expired.
     *
     * The boolean flags of CertificateStatusDto / CertificateDetailDto are trusted over
     * the free-text status string, which the Swagger file does not enumerate. Seen on
     * OTE: "WaitingForInstall" with isConfigured=false right after initialize.
     */
    public static function status(array $dto): string
    {
        $text = strtolower(trim((string) ($dto['status'] ?? '')));

        if (self::matches($text, ['revok', 'cancel', 'reject', 'refund'])) return 'revoked';
        if (self::matches($text, ['expire']))                             return 'expired';

        if (!empty($dto['isIssued'])) {
            $until = (string) ($dto['certificateExpiresAt'] ?? ($dto['certificate']['validUntil'] ?? ''));
            $ts    = $until !== '' ? strtotime($until) : false;

            return ($ts && $ts < time()) ? 'expired' : 'active';
        }

        if (self::matches($text, ['issued', 'active', 'complete'])) return 'active';
        if (array_key_exists('isConfigured', $dto) && !$dto['isConfigured']) return 'awaiting_configuration';

        return 'processing';
    }

    private static function matches(string $text, array $needles): bool
    {
        foreach ($needles as $n) {
            if ($text !== '' && strpos($text, $n) !== false) return true;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Domain control validation
    // ------------------------------------------------------------------

    public static function dcvToApi(string $method): string
    {
        $method = strtolower(trim($method));

        return self::DCV_TO_API[$method] ?? 'EMAIL';
    }

    public static function dcvFromApi(string $authType): string
    {
        $authType = strtoupper(trim($authType));
        if ($authType === 'EMAIL') return 'email';
        if (strpos($authType, 'DNS') !== false || strpos($authType, 'CNAME') !== false || strpos($authType, 'TXT') !== false) return 'dns';
        if (strpos($authType, 'HTTP') !== false || strpos($authType, 'FILE') !== false) return 'http';

        return 'email';
    }

    /**
     * What the client has to publish for DCV, from the free-form dcvDetails string.
     *
     * The gateway sends a Sectigo-style object (seen on OTE 2026-10-24):
     *   {"ApprovalMethod":"EMAIL|HTTPCSRHASH|CNAMECSRHASH","ApprovalEmail":..,
     *    "MD5":..,"SHA1":..,"UniqueValue":..}
     * The file / CNAME the CA checks are derived from the CSR hashes. The MD5/SHA1 it
     * returns are not always real hashes (GUIDs on OTE), so with the CSR at hand they
     * are computed locally from its DER form.
     *
     * Other shapes are still accepted: JSON with any reasonable key names, "host TYPE
     * value" DNS lines, a bare e-mail and plain text. Whatever cannot be parsed is kept
     * in 'raw' so it is still shown verbatim.
     *
     * @return array{method:string,file:array,dns:array,email:string,raw:string}
     */
    public static function dcv(string $details, string $method = '', string $csr = '', string $domain = ''): array
    {
        $out     = ['method' => $method !== '' ? self::dcvFromApi($method) : '', 'file' => [], 'dns' => [], 'email' => '', 'raw' => ''];
        $details = trim($details);
        if ($details === '') return $out;

        $json = json_decode($details, true);
        if (is_array($json) && isset($json['ApprovalMethod'])) {
            return self::sectigoDcv($json, $csr, $domain) + $out;
        }
        if (is_array($json)) {
            $flat = self::flatten($json);

            $filename = self::pick($flat, ['filename', 'filepath', 'path', 'httpfilename', 'authfilename']);
            $content  = self::pick($flat, ['filecontent', 'content', 'contents', 'httpfilecontent', 'authfilecontent']);
            $link     = self::pick($flat, ['link', 'url', 'fileurl', 'httpurl']);
            $host     = self::pick($flat, ['host', 'name', 'cnamename', 'dnsname', 'record', 'hostname']);
            $value    = self::pick($flat, ['value', 'target', 'point', 'cnamevalue', 'dnsvalue', 'data']);
            $type     = self::pick($flat, ['type', 'recordtype', 'dnstype']);
            $email    = self::pick($flat, ['email', 'approveremail', 'approvermail', 'mail']);

            if ($filename !== '' || $content !== '') $out['file'] = ['filename' => $filename, 'content' => $content, 'link' => $link];
            if ($host !== '' && $value !== '') $out['dns'] = ['host' => $host, 'type' => strtoupper($type ?: 'CNAME'), 'value' => $value];
            if ($email !== '') $out['email'] = $email;

            if (!$out['file'] && !$out['dns'] && $out['email'] === '') $out['raw'] = $details;

            return $out;
        }

        if (preg_match('/^(\S+)\s+(?:IN\s+)?(CNAME|TXT)\s+(\S+)/mi', $details, $m)) {
            $out['dns'] = ['host' => $m[1], 'type' => strtoupper($m[2]), 'value' => trim($m[3], '"')];
            return $out;
        }

        if (filter_var($details, FILTER_VALIDATE_EMAIL)) {
            $out['email'] = $details;
            return $out;
        }

        $out['raw'] = $details;

        return $out;
    }

    /** The ApprovalMethod/MD5/UniqueValue object as file / CNAME / e-mail instructions. */
    private static function sectigoDcv(array $j, string $csr, string $domain): array
    {
        $kind   = strtoupper((string) $j['ApprovalMethod']);
        $method = strpos($kind, 'CNAME') !== false ? 'dns' : (strpos($kind, 'HTTP') !== false ? 'http' : 'email');
        $out    = ['method' => $method, 'file' => [], 'dns' => [], 'email' => '', 'raw' => ''];
        $unique = trim((string) ($j['UniqueValue'] ?? ''));

        if ($method === 'email') {
            $out['email'] = trim((string) ($j['ApprovalEmail'] ?? ''));
            return $out;
        }

        $hashes = self::csrHashes($csr);
        $md5    = $hashes['md5'] ?: (preg_match('/^[0-9a-f]{32}$/i', (string) ($j['MD5'] ?? '')) ? strtolower($j['MD5']) : '');
        $sha256 = $hashes['sha256'];

        if ($md5 === '' || $sha256 === '') {
            $out['raw'] = json_encode($j, JSON_UNESCAPED_SLASHES);
            return $out;
        }

        $base = preg_replace('/^\*\./', '', self::domain($domain));

        if ($method === 'http') {
            $path = '/.well-known/pki-validation/' . strtoupper($md5) . '.txt';
            $out['file'] = [
                'filename' => $path,
                'content'  => strtoupper($sha256) . "\nsectigo.com" . ($unique !== '' ? "\n" . $unique : ''),
                'link'     => $base !== '' ? 'http://' . $base . $path : '',
            ];
        } else {
            $out['dns'] = [
                'host'  => '_' . $md5 . ($base !== '' ? '.' . $base : ''),
                'type'  => 'CNAME',
                'value' => substr($sha256, 0, 32) . '.' . substr($sha256, 32, 32) . ($unique !== '' ? '.' . strtolower($unique) : '') . '.sectigo.com',
            ];
        }

        return $out;
    }

    /** md5 / sha256 (lower-case hex) of a PEM CSR's DER body; empty strings when unparsable. */
    public static function csrHashes(string $csr): array
    {
        if (!preg_match('/-----BEGIN (?:NEW )?CERTIFICATE REQUEST-----(.+?)-----END (?:NEW )?CERTIFICATE REQUEST-----/s', $csr, $m)) {
            return ['md5' => '', 'sha256' => ''];
        }
        $der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false || $der === '') return ['md5' => '', 'sha256' => ''];

        return ['md5' => md5($der), 'sha256' => hash('sha256', $der)];
    }

    /** Lower-cased leaf keys of a nested array (first occurrence wins). */
    private static function flatten(array $data, array &$out = []): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::flatten($value, $out);
            } elseif (is_scalar($value)) {
                $k = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $key));
                if ($k !== '' && !isset($out[$k])) $out[$k] = trim((string) $value);
            }
        }

        return $out;
    }

    private static function pick(array $flat, array $keys): string
    {
        foreach ($keys as $k) {
            if (isset($flat[$k]) && $flat[$k] !== '') return $flat[$k];
        }

        return '';
    }

    // ------------------------------------------------------------------
    // Misc
    // ------------------------------------------------------------------

    /** Lower case, no trailing dot, punycode for IDNs - the form the CA signs. */
    public static function domain(string $domain): string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));

        if ($domain !== '' && function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7f]/', $domain)) {
            $ascii = idn_to_ascii($domain, 0, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') return $ascii;
        }

        return $domain;
    }

    /** One SAN per line/comma, validated and de-duplicated, CN excluded. */
    public static function sanList(string $raw, string $commonName = '', int $limit = 0): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $entry) {
            $d = self::domain($entry);
            if ($d === '' || $d === $commonName || in_array($d, $out, true)) continue;
            if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $d)) continue;
            $out[] = $d;
        }

        return $limit > 0 ? array_slice($out, 0, $limit) : $out;
    }

    /**
     * Public key size and signature algorithm of a PEM certificate or CSR.
     *
     * Prefers the issued certificate (it is what is actually deployed), falls back to the
     * CSR. Returns ['bits' => 0, 'signature' => ''] for anything unreadable, or when the
     * openssl extension is missing.
     *
     * @return array{bits:int,signature:string}
     */
    public static function keyInfo(string $certificate, string $csr = ''): array
    {
        $out = ['bits' => 0, 'signature' => ''];
        if (!function_exists('openssl_pkey_get_details')) return $out;

        $certificate = trim($certificate);
        if ($certificate !== '' && strpos($certificate, 'BEGIN CERTIFICATE') !== false) {
            $key = @openssl_pkey_get_public($certificate);
            if ($key) {
                $details     = openssl_pkey_get_details($key);
                $out['bits'] = (int) ($details['bits'] ?? 0);
            }
            $parsed = @openssl_x509_parse($certificate);
            if (is_array($parsed)) $out['signature'] = (string) ($parsed['signatureTypeSN'] ?? '');
            if ($out['bits'] > 0) return $out;
        }

        $csr = trim($csr);
        if ($csr !== '' && strpos($csr, 'CERTIFICATE REQUEST') !== false) {
            $key = @openssl_csr_get_public_key($csr);
            if ($key) {
                $details     = openssl_pkey_get_details($key);
                $out['bits'] = (int) ($details['bits'] ?? 0);
            }
        }

        return $out;
    }

    /** The PEM bundle pieces from certificate-content: [certificate, ca-bundle]. */
    public static function pem(array $content): array
    {
        $crt = trim((string) ($content['certificate'] ?? ''));
        $ca  = [];
        foreach (['intermediateCertificate1', 'intermediateCertificate2', 'rootCertificate'] as $k) {
            $v = trim((string) ($content[$k] ?? ''));
            if ($v !== '') $ca[] = $v;
        }

        return [$crt, implode("\n", $ca)];
    }
}
