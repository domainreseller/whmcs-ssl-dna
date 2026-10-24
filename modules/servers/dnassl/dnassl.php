<?php
/**
 * DNA SSL - Domain Name API SSL certificates for WHMCS.
 *
 * Provisioning module without a server: the Domain Name API credentials are entered on
 * the product's Module Settings tab (Reseller ID, API Key, Test Mode). No server record
 * or server group is used.
 *
 * Flow: the order is opened at activation (CreateAccount -> /ssls/initialize); the
 * client then submits CSR, validation method and contact from the client area
 * (-> /ssls/configure); status, DCV instructions and the issued certificate are read
 * back from the gateway on every visit.
 *
 * The gateway identifies a certificate by its domain, so the domain the order was
 * opened for is stored with the service and used for every later call.
 *
 * Target: PHP 7.4+, WHMCS 8.0 and later.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use DomainNameApi\Ssl\ApiException;
use DomainNameApi\Ssl\Client;
use DomainNameApi\Ssl\Normalizer;

require_once __DIR__ . '/lib/DnaSsl/Client.php';
require_once __DIR__ . '/lib/DnaSsl/Normalizer.php';
require_once __DIR__ . '/lib/Store.php';

// ---------------------------------------------------------------------------
// Module definition
// ---------------------------------------------------------------------------

function dnassl_MetaData()
{
    return [
        'DisplayName'    => 'DNA SSL Certificates',
        'APIVersion'     => '1.1',
        'RequiresServer' => false,
    ];
}

/**
 * Product settings. Bound to configoption1..N by position: only ever append.
 *
 *   1  Reseller ID
 *   2  API Key
 *   3  Test Mode (yes/no: OTE test gateway instead of live)
 *   4  Certificate package (gateway productCode)
 *   5  Revoke on termination (yes/no)
 */
function dnassl_ConfigOptions()
{
    return [
        'Reseller ID' => [
            'Type'        => 'text',
            'Size'        => '40',
            'SimpleMode'  => true,
            'Description' => dnassl_t('cfg_reseller_desc'),
        ],
        // Note: browsers may autofill the admin's saved WHMCS login into this pair of
        // fields; the description asks to check it.
        'API Key' => [
            'Type'        => 'password',
            'Size'        => '40',
            'SimpleMode'  => true,
            'Description' => dnassl_t('cfg_key_desc'),
        ],
        'Test Mode' => [
            'Type'        => 'yesno',
            'SimpleMode'  => true,
            'Description' => dnassl_t('cfg_test_desc'),
        ],
        'Certificate' => [
            'Type'        => 'dropdown',
            'Loader'      => 'dnassl_PackageLoader',
            'SimpleMode'  => true,
            'Description' => dnassl_t('cfg_package_desc'),
        ],
        'Revoke on Termination' => [
            'Type'        => 'yesno',
            'SimpleMode'  => true,
            'Description' => dnassl_t('cfg_revoke_desc'),
        ],
    ];
}

/** Fills the Certificate dropdown from the gateway catalogue. */
function dnassl_PackageLoader(array $params)
{
    $params = dnassl_withProductCredentials($params);
    if (trim((string) ($params['configoption1'] ?? '')) === '' || trim((string) ($params['configoption2'] ?? '')) === '') {
        throw new \Exception(dnassl_t('cfg_need_credentials'));
    }
    $options = [];
    try {
        $api = dnassl_api($params);
        $dtos = $api->products();
    } catch (ApiException $e) {
        throw new \Exception(dnassl_credentialHint($params, $e));
    }

    try {
        foreach (Normalizer::catalogue($dtos, $api->priceGroup()) as $p) {
            $price = $p['prices'] ? sprintf(' - %.2f %s', $p['prices'][0]['cost'], $p['currency']) : '';
            $options[$p['code']] = sprintf('%s [%s%s%s]%s',
                $p['name'],
                $p['type'],
                $p['wildcard'] ? ', Wildcard' : '',
                $p['multi_domain'] ? ', SAN ' . $p['max_sans'] : '',
                $price
            );
        }
    } catch (ApiException $e) {
        throw new \Exception('Domain Name API: ' . $e->getMessage());
    }

    asort($options);

    return $options;
}

/**
 * A readable reason when the gateway refuses the credentials: the most common slip is a
 * test key with Test Mode off (or the other way round), so say that when it is the case.
 */
function dnassl_credentialHint(array $params, ApiException $e): string
{
    if (!in_array($e->httpStatus(), [401, 403], true)) return 'Domain Name API: ' . $e->getMessage();

    $test  = ($params['configoption3'] ?? '') === 'on';
    $other = Client::environmentFor(trim((string) $params['configoption1']), trim((string) $params['configoption2']), dnassl_transport());

    if ($other === 'test' && !$test) return dnassl_t('err_key_is_test');
    if ($other === 'live' && $test)  return dnassl_t('err_key_is_live');

    return dnassl_t('err_credentials');
}

/**
 * The loader runs on the product edit page, where WHMCS may not hand over the saved
 * module settings; read them from the product being edited when they are missing.
 */
function dnassl_withProductCredentials(array $params): array
{
    if (!empty($params['configoption1']) && !empty($params['configoption2'])) return $params;

    $pid = (int) ($params['pid'] ?? $params['packageid'] ?? ($_REQUEST['id'] ?? 0));
    if ($pid <= 0 || !class_exists('\\WHMCS\\Database\\Capsule')) return $params;

    $product = \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->where('servertype', 'dnassl')
        ->first(['configoption1', 'configoption2', 'configoption3']);
    if ($product) {
        foreach (['configoption1', 'configoption2', 'configoption3'] as $k) {
            $params[$k] = (string) ($params[$k] ?? '') ?: (string) $product->$k;
        }
    }

    return $params;
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------

function dnassl_CreateAccount(array $params)
{
    $serviceId = (int) $params['serviceid'];
    $row       = DnaSsl_Store::get($serviceId);
    if (!empty($row['certificate_id'])) return 'success';

    $package = trim((string) $params['configoption4']);
    $domain  = Normalizer::domain((string) $params['domain']);

    if ($package === '') return dnassl_t('err_no_package');
    if ($domain === '')  return dnassl_t('err_no_domain');

    try {
        $result = dnassl_api($params)->initialize($package, $domain);
    } catch (ApiException $e) {
        return dnassl_fail('CreateAccount', $params, $e);
    }

    DnaSsl_Store::save($serviceId, [
        'certificate_id' => (string) ($result['certificateId'] ?? '') ?: $domain,
        'domain'         => $domain,
        'package'        => $package,
        'status'         => 'awaiting_configuration',
        'configured'     => false,
    ]);

    return 'success';
}

/**
 * Renewal is a new order for the same domain. When the client configured the previous
 * one, the same CSR and validation choice are submitted again so the renewal needs no
 * action from them; otherwise they configure it like a new order.
 */
function dnassl_Renew(array $params)
{
    $serviceId = (int) $params['serviceid'];
    $row       = DnaSsl_Store::get($serviceId);
    $domain    = (string) ($row['domain'] ?? '') ?: Normalizer::domain((string) $params['domain']);
    $package   = (string) ($row['package'] ?? '') ?: trim((string) $params['configoption4']);

    if ($domain === '' || $package === '') return dnassl_t('err_no_domain');

    $api = dnassl_api($params);

    try {
        $result = $api->initialize($package, $domain);
    } catch (ApiException $e) {
        return dnassl_fail('Renew', $params, $e);
    }

    DnaSsl_Store::save($serviceId, [
        'certificate_id' => (string) ($result['certificateId'] ?? '') ?: $domain,
        'domain'         => $domain,
        'package'        => $package,
        'status'         => 'awaiting_configuration',
        'configured'     => false,
    ]);

    if (trim((string) ($row['csr'] ?? '')) === '') return 'success';

    try {
        $api->configure(dnassl_configureInput($params, $row));
    } catch (ApiException $e) {
        // The order exists; the client can still configure it by hand.
        dnassl_fail('Renew (configure)', $params, $e);
        return 'success';
    }

    DnaSsl_Store::save($serviceId, ['configured' => true, 'status' => 'processing']);

    return 'success';
}

function dnassl_SuspendAccount(array $params)
{
    return 'success';
}

function dnassl_UnsuspendAccount(array $params)
{
    return 'success';
}

function dnassl_TerminateAccount(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (empty($row['certificate_id']) || $params['configoption5'] !== 'on') return 'success';

    // Termination must not stall on a package that cannot be revoked (Ssl:10003).
    return dnassl_revoke($params, true);
}

// ---------------------------------------------------------------------------
// Admin area
// ---------------------------------------------------------------------------

function dnassl_AdminCustomButtonArray()
{
    return [
        'Refresh Status'     => 'refresh',
        'Revoke Certificate' => 'revoke',
    ];
}

function dnassl_refresh(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (empty($row['domain'])) return dnassl_t('err_no_order');

    try {
        $status = dnassl_api($params)->checkStatus($row['domain']);
    } catch (ApiException $e) {
        return dnassl_fail('refresh', $params, $e);
    }

    DnaSsl_Store::save((int) $params['serviceid'], [
        'status'     => Normalizer::status($status),
        'configured' => !empty($status['isConfigured']) || !empty($row['configured']),
    ]);

    return 'success';
}

function dnassl_revoke(array $params, bool $lenient = false)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (empty($row['domain'])) return dnassl_t('err_no_order');

    try {
        dnassl_api($params)->revoke($row['domain']);
    } catch (ApiException $e) {
        if ($lenient && $e->isUnsupported()) {
            dnassl_fail('revoke (skipped, package cannot be revoked)', $params, $e);
            return 'success';
        }
        if (!$e->isNotFound()) return dnassl_fail('revoke', $params, $e);
    }

    DnaSsl_Store::save((int) $params['serviceid'], ['status' => 'revoked']);

    return 'success';
}

function dnassl_AdminServicesTabFields(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (!$row) return [dnassl_t('certificate') => dnassl_t('no_order_yet')];

    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

    return [
        dnassl_t('certificate_id') => $e($row['certificate_id']),
        dnassl_t('domain')         => $e($row['domain']),
        dnassl_t('package')        => $e($row['package']),
        dnassl_t('status')         => $e(dnassl_t('status_' . ($row['status'] ?: 'processing'))),
        dnassl_t('dcv_method')     => $e(strtoupper((string) $row['dcv_method']) ?: '-'),
        dnassl_t('approver')       => $e($row['approver'] ?: '-'),
    ];
}

// ---------------------------------------------------------------------------
// Client area
// ---------------------------------------------------------------------------

function dnassl_ClientAreaAllowedFunctions()
{
    return ['configure', 'reissue', 'downloadCertificate', 'downloadPrivateKey', 'refreshStatus'];
}

function dnassl_ClientArea(array $params)
{
    $serviceId = (int) $params['serviceid'];
    $row       = DnaSsl_Store::get($serviceId);
    $vars      = [
        'L'           => dnassl_lang(),
        'serviceid'   => $serviceId,
        'row'         => $row,
        'status'      => $row ? ($row['status'] ?: 'processing') : 'none',
        'error'       => '',
        'issuedAt'    => '',
        'validUntil'  => '',
        'dcv'         => ['file' => [], 'dns' => [], 'email' => '', 'raw' => ''],
        'approverShown' => (string) ($row['approver'] ?? ''),
        'approvers'   => [],
        'webServers'  => Normalizer::WEB_SERVERS,
        'client'      => $params['clientsdetails'],
        'hasKey'      => !empty($row['private_key']),
        'maxSans'     => 0,
        'token'       => function_exists('generate_token') ? generate_token('plain') : '',
    ];

    if (!$row || empty($row['domain'])) {
        return ['templatefile' => 'templates/clientarea', 'vars' => dnassl_tplVars($vars)];
    }

    $api = dnassl_api($params);

    try {
        $detail          = $api->certificate($row['domain']);
        $vars['issuedAt']   = substr((string) ($detail['certificate']['issuedAt'] ?? ''), 0, 10);
        $vars['validUntil'] = substr((string) ($detail['certificate']['validUntil'] ?? ($detail['subscription']['expiresAt'] ?? '')), 0, 10);
        $vars['status']  = Normalizer::status($detail);
        $conf            = (array) ($detail['configuration'] ?? []);
        $vars['dcv']     = Normalizer::dcv((string) ($conf['dcvDetails'] ?? ''), (string) ($conf['authenticationType'] ?? ''), (string) ($row['csr'] ?? ''), $row['domain']);
        if ($vars['dcv']['email'] !== '') $vars['approverShown'] = $vars['dcv']['email'];

        if ($vars['status'] !== ($row['status'] ?? '')) {
            DnaSsl_Store::save($serviceId, ['status' => $vars['status']]);
        }
    } catch (ApiException $e) {
        $vars['error'] = $e->getMessage();
    }

    if (in_array($vars['status'], ['awaiting_configuration', 'active'], true)) {
        try {
            $vars['approvers'] = $api->approvers($row['domain'], (string) $row['package']);
        } catch (ApiException $e) {
            // The form falls back to the standard admin@/webmaster@... addresses.
        }
        if (!$vars['approvers']) {
            foreach (['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster'] as $box) {
                $vars['approvers'][] = $box . '@' . preg_replace('/^\*\./', '', $row['domain']);
            }
        }
    }

    $vars['maxSans'] = dnassl_maxSans($params, (string) $row['package']);

    return ['templatefile' => 'templates/clientarea', 'vars' => dnassl_tplVars($vars)];
}

/**
 * Template variables, prefixed: the product details page already defines $status,
 * $domain, $client, $serviceid, $token... and a module template that reuses those names
 * overwrites them (or trips over WHMCS's types for them).
 */
function dnassl_tplVars(array $vars): array
{
    $out = [];
    foreach ($vars as $k => $v) $out['dna' . ucfirst($k)] = $v;

    return $out;
}

/** Client submits CSR (or asks us to generate one), DCV method and approver. */
function dnassl_configure(array $params)
{
    $serviceId = (int) $params['serviceid'];
    $row       = DnaSsl_Store::get($serviceId);
    if (empty($row['domain'])) return dnassl_t('err_no_order');
    if (!dnassl_checkToken()) return dnassl_t('err_token');

    $api    = dnassl_api($params);
    $domain = $row['domain'];
    $csr    = trim((string) ($_POST['csr'] ?? ''));
    $key    = '';

    if (!empty($_POST['generate_csr'])) {
        try {
            $generated = $api->generateCsr([
                'domainName'       => $domain,
                'country'          => strtoupper(substr(trim((string) ($_POST['csr_country'] ?? $params['clientsdetails']['countrycode'])), 0, 2)),
                'state'            => trim((string) ($_POST['csr_state'] ?? $params['clientsdetails']['state'])),
                'locality'         => trim((string) ($_POST['csr_city'] ?? $params['clientsdetails']['city'])),
                'organization'     => trim((string) ($_POST['csr_org'] ?? '')) ?: trim($params['clientsdetails']['companyname'] ?: $params['clientsdetails']['fullname']),
                'organizationUnit' => trim((string) ($_POST['csr_unit'] ?? '')) ?: 'IT',
                'alternativeNames' => Normalizer::sanList((string) ($_POST['san_list'] ?? ''), $domain),
            ]);
        } catch (ApiException $e) {
            return dnassl_fail('generateCsr', $params, $e);
        }
        $csr = trim((string) ($generated['csr'] ?? ''));
        $key = trim((string) ($generated['privateKey'] ?? ''));
    }

    if ($csr === '' || strpos($csr, 'BEGIN CERTIFICATE REQUEST') === false) return dnassl_t('err_csr');

    // The CSR's common name must be the ordered domain; catch it here, with a message
    // the client understands, instead of letting the CA reject it later.
    try {
        $decoded = $api->checkCsr($csr);
        $cn      = Normalizer::domain((string) ($decoded['domainName'] ?? ''));
        if ($cn !== '' && $cn !== $domain) {
            return sprintf(dnassl_t('err_csr_cn'), $cn, $domain);
        }
    } catch (ApiException $e) {
        return dnassl_fail('checkCsr', $params, $e);
    }

    $method = strtolower((string) ($_POST['dcv_method'] ?? 'email'));
    if (!in_array($method, Normalizer::DCV_METHODS, true)) $method = 'email';

    $server = (string) ($_POST['web_server'] ?? Normalizer::DEFAULT_WEB_SERVER);
    if (!isset(Normalizer::WEB_SERVERS[$server])) $server = Normalizer::DEFAULT_WEB_SERVER;

    $fields = [
        'csr'        => $csr,
        'dcv_method' => $method,
        'approver'   => dnassl_approver($domain, (string) ($_POST['approver'] ?? ''), dnassl_allowedApprovers($api, $row)),
        'web_server' => $server,
        'san_list'   => implode(',', Normalizer::sanList((string) ($_POST['san_list'] ?? ''), $domain, dnassl_maxSans($params, (string) $row['package']))),
        // A pasted CSR comes with the client's own key; one we kept from an earlier
        // generated CSR would no longer match it.
        'private_key' => $key !== '' ? encrypt($key) : null,
    ];

    try {
        $api->configure(dnassl_configureInput($params, $fields + $row));
    } catch (ApiException $e) {
        return dnassl_fail('configure', $params, $e);
    }

    $fields['configured'] = true;
    $fields['status']     = 'processing';

    DnaSsl_Store::save($serviceId, $fields);

    return 'success';
}

function dnassl_reissue(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (empty($row['domain'])) return dnassl_t('err_no_order');
    if (!dnassl_checkToken()) return dnassl_t('err_token');

    $csr = trim((string) ($_POST['csr'] ?? ''));
    if ($csr !== '' && strpos($csr, 'BEGIN CERTIFICATE REQUEST') === false) return dnassl_t('err_csr');

    $api      = dnassl_api($params);
    $approver = dnassl_approver($row['domain'], (string) ($_POST['approver'] ?? $row['approver']), dnassl_allowedApprovers($api, $row));

    try {
        $api->reissue($row['domain'], $csr, $approver);
    } catch (ApiException $e) {
        return dnassl_fail('reissue', $params, $e);
    }

    $update = ['status' => 'processing', 'approver' => $approver];
    if ($csr !== '') {
        // A new CSR means the key we generated earlier no longer matches.
        $update['csr']         = $csr;
        $update['private_key'] = null;
    }
    DnaSsl_Store::save((int) $params['serviceid'], $update);

    return 'success';
}

function dnassl_refreshStatus(array $params)
{
    return dnassl_refresh($params);
}

function dnassl_downloadCertificate(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    if (empty($row['domain'])) return dnassl_t('err_no_order');

    try {
        list($crt, $ca) = Normalizer::pem(dnassl_api($params)->certificateContent($row['domain']));
    } catch (ApiException $e) {
        return dnassl_fail('downloadCertificate', $params, $e);
    }
    if ($crt === '') return dnassl_t('err_not_issued');

    $name = preg_replace('/[^a-z0-9.-]/', '_', str_replace('*', 'wildcard', $row['domain']));
    $body = $crt . "\n" . ($ca !== '' ? $ca . "\n" : '');

    dnassl_send($name . '.crt', $body, 'application/x-pem-file');
}

function dnassl_downloadPrivateKey(array $params)
{
    $row = DnaSsl_Store::get((int) $params['serviceid']);
    $key = DnaSsl_Store::privateKey($row);
    if ($key === '') return dnassl_t('err_no_key');

    $name = preg_replace('/[^a-z0-9.-]/', '_', str_replace('*', 'wildcard', (string) $row['domain']));

    dnassl_send($name . '.key', $key . "\n", 'application/x-pem-file');
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function dnassl_api(array $params): Client
{
    $reseller  = trim((string) ($params['configoption1'] ?? ''));
    $secret    = trim((string) ($params['configoption2'] ?? ''));
    $transport = dnassl_transport();

    $client = new Client($reseller, $secret, ($params['configoption3'] ?? '') === 'on');
    if ($transport) $client->setTransport($transport);

    $client->setLogger(function ($action, $request, $response) use ($secret) {
        if (function_exists('logModuleCall')) {
            logModuleCall('dnassl', $action, $request, $response, '', $secret !== '' ? [$secret] : []);
        }
    });

    return $client;
}

/** Test seam for tests/smoke-whmcs.php; never set inside WHMCS. */
function dnassl_transport(): ?callable
{
    return isset($GLOBALS['dnassl_test_transport']) && is_callable($GLOBALS['dnassl_test_transport'])
        ? $GLOBALS['dnassl_test_transport'] : null;
}

function dnassl_configureInput(array $params, array $row): array
{
    $c     = $params['clientsdetails'];
    $phone = preg_replace('/\D/', '', (string) ($c['phonenumber'] ?? ''));
    $cc    = preg_replace('/\D/', '', (string) ($c['phonecc'] ?? ''));

    $input = [
        'domainName'         => $row['domain'],
        'csr'                => $row['csr'],
        'approverMail'       => $row['approver'] ?: dnassl_approver($row['domain'], ''),
        'authenticationType' => Normalizer::dcvToApi((string) $row['dcv_method']),
        'webServerType'      => isset(Normalizer::WEB_SERVERS[(string) $row['web_server']]) ? (string) $row['web_server'] : Normalizer::DEFAULT_WEB_SERVER,
        'contact'            => [
            'firstName'        => (string) $c['firstname'],
            'lastName'         => (string) $c['lastname'],
            'companyName'      => (string) ($c['companyname'] ?: $c['fullname']),
            'eMail'            => (string) $c['email'],
            'address'          => trim($c['address1'] . ' ' . $c['address2']),
            'phoneCountryCode' => $cc,
            'phone'            => $local = ($cc !== '' && strpos($phone, $cc) === 0 ? substr($phone, strlen($cc)) : $phone),
            // Required in practice although Swagger says nullable: without them the
            // gateway answers configure with HTTP 500 (OTE, 2026-10-24).
            'faxCountryCode'   => $cc,
            'fax'              => $local,
            'country'          => strtoupper((string) $c['countrycode']),
            'city'             => (string) $c['city'],
            'state'            => (string) $c['state'],
            'postalCode'       => (string) $c['postcode'],
        ],
    ];

    $privateKey = DnaSsl_Store::privateKey($row);
    if ($privateKey !== '') $input['privateKey'] = $privateKey;
    if (!empty($row['san_list'])) $input['sanList'] = (string) $row['san_list'];

    return $input;
}

/**
 * The approver address to submit: one the gateway lists for the domain, or a mailbox on
 * the domain itself. Anything else (an address the client typed or injected) falls back
 * to admin@ - DCV e-mail must never be steerable to a third-party inbox.
 */
function dnassl_approver(string $domain, string $choice, array $allowed = []): string
{
    $base   = preg_replace('/^\*\./', '', $domain);
    $choice = strtolower(trim($choice));

    if (in_array($choice, ['admin', 'administrator', 'hostmaster', 'postmaster', 'webmaster'], true)) $choice .= '@' . $base;
    if ($choice !== '' && in_array($choice, array_map('strtolower', $allowed), true)) return $choice;
    if (filter_var($choice, FILTER_VALIDATE_EMAIL) && substr($choice, -strlen('@' . $base)) === '@' . $base) return $choice;

    return 'admin@' . $base;
}

function dnassl_allowedApprovers(Client $api, array $row): array
{
    try {
        return $api->approvers((string) $row['domain'], (string) $row['package']);
    } catch (ApiException $e) {
        return [];
    }
}

/** SAN capacity of a package; 0 when it is a single-domain certificate. */
function dnassl_maxSans(array $params, string $package): int
{
    static $cache = [];
    if ($package === '') return 0;
    if (isset($cache[$package])) return $cache[$package];

    try {
        $catalogue = Normalizer::catalogue(dnassl_api($params)->products());
        if (isset($catalogue[$package])) return $cache[$package] = $catalogue[$package]['max_sans'];
    } catch (ApiException $e) {
        // Unknown capacity: let the gateway enforce it.
    }

    return $cache[$package] = 0;
}

function dnassl_checkToken(): bool
{
    if (!function_exists('check_token')) return true;

    try {
        check_token('WHMCS.default');
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function dnassl_fail(string $action, array $params, ApiException $e): string
{
    if (function_exists('logActivity')) {
        logActivity(sprintf('dnassl: %s failed for service #%d (%s): %s',
            $action, (int) $params['serviceid'], (string) $params['domain'], $e->getMessage()));
    }

    return $e->getMessage();
}

function dnassl_send(string $filename, string $body, string $type): void
{
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

/** Language strings in the client's language, English as fallback. */
function dnassl_lang(): array
{
    static $lang = null;
    if ($lang !== null) return $lang;

    $lang     = include __DIR__ . '/lang/english.php';
    $selected = '';
    if (isset($_SESSION['Language'])) $selected = (string) $_SESSION['Language'];
    elseif (isset($GLOBALS['CONFIG']['Language'])) $selected = (string) $GLOBALS['CONFIG']['Language'];

    $selected = strtolower(preg_replace('/[^A-Za-z_-]/', '', $selected));
    if ($selected !== '' && $selected !== 'english' && is_file(__DIR__ . '/lang/' . $selected . '.php')) {
        $lang = array_merge($lang, (array) include __DIR__ . '/lang/' . $selected . '.php');
    }

    return $lang;
}

function dnassl_t(string $key): string
{
    $lang = dnassl_lang();

    return (string) ($lang[$key] ?? $key);
}
