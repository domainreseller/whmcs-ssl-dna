<?php
/**
 * Smoke test for the WHMCS module against a fake gateway and a fake WHMCS runtime
 * (Capsule, encrypt/decrypt, logging).
 *
 *   tools/build-release-zip.sh whmcs WORKTREE /tmp/dnassl && php tests/smoke-whmcs.php /tmp/dnassl/DNASSL-WHMCS.zip
 */

namespace WHMCS\Database {
    class FakeQuery
    {
        public static $tables = [];
        private $table; private $where = [];
        public function __construct($table) { $this->table = $table; }
        public function where($col, $val) { $this->where[$col] = $val; return $this; }
        private function rows() {
            return array_filter(self::$tables[$this->table] ?? [], function ($r) {
                foreach ($this->where as $c => $v) if (($r[$c] ?? null) != $v) return false;
                return true;
            });
        }
        public function first() { $r = $this->rows(); return $r ? (object) reset($r) : null; }
        public function exists() { return (bool) $this->rows(); }
        public function insert(array $row) { self::$tables[$this->table][] = $row; return true; }
        public function update(array $f) { foreach (self::$tables[$this->table] as $i => $r) { $match = true; foreach ($this->where as $c => $v) if (($r[$c] ?? null) != $v) $match = false; if ($match) self::$tables[$this->table][$i] = array_merge($r, $f); } return 1; }
    }
    class FakeSchema
    {
        public static $created = [];
        public function hasTable($t) { return isset(self::$created[$t]); }
        public function create($t, $fn) { self::$created[$t] = true; FakeQuery::$tables[$t] = []; }
    }
    class Capsule
    {
        public static function schema() { return new FakeSchema(); }
        public static function table($t) { return new FakeQuery($t); }
    }
}

namespace WHMCS\Config {
    class Setting
    {
        public static $values = [];
        public static function getValue($k) { return self::$values[$k] ?? null; }
        public static function setValue($k, $v) { self::$values[$k] = $v; }
    }
}

namespace {
    define('WHMCS', true);

    $logs = [];
    function encrypt($s) { return 'enc:' . base64_encode($s); }
    function decrypt($s) { return strpos($s, 'enc:') === 0 ? base64_decode(substr($s, 4)) : ''; }
    function logModuleCall($m, $a, $req, $resp, $p = '', $replace = []) { global $logs; $logs[] = [$a, $req, $resp, $replace]; }
    function logActivity($m) { global $logs; $logs[] = ['activity', $m]; }
    function generate_token($t = 'plain') { return 'tok'; }
    function check_token($n) { if (($_POST['token'] ?? '') !== 'tok') throw new \Exception('bad token'); }

    $zip = $argv[1] ?? '';
    if (!is_file($zip)) { fwrite(STDERR, "usage: php tests/smoke-whmcs.php DNASSL-WHMCS.zip\n"); exit(2); }
    $dir = sys_get_temp_dir() . '/dnassl-smoke-' . getmypid();
    $z = new ZipArchive(); $z->open($zip); $z->extractTo($dir); $z->close();
    require $dir . '/modules/servers/dnassl/dnassl.php';

    $ok = 0; $fail = 0;
    function check($label, $cond, $extra = '') { global $ok, $fail; if ($cond) { $ok++; echo "  ok   $label\n"; } else { $fail++; echo "  FAIL $label $extra\n"; } }

    // ---- fake gateway ------------------------------------------------------
    $state = ['initialized' => 0, 'configured' => null, 'issued' => false, 'revoked' => 0, 'reissued' => null];
    $calls = [];
    $GLOBALS['dnassl_test_transport'] = function ($method, $url, $headers, $body) use (&$state, &$calls) {
        $path = parse_url($url, PHP_URL_PATH);
        $host = parse_url($url, PHP_URL_HOST);
        $calls[] = $method . ' ' . $host . $path;
        if ($path === '/api/v1/deposit/accounts/me') {
            return $host === 'ote.domainresellerapi.com' && in_array('X-API-KEY: TOPSECRETKEY', $headers, true)
                ? [200, json_encode(['resellerGroupName' => 'Reseller'])]
                : [401, 'Unauthorized! Invalid API Key.'];
        }
        // Everything else must go to the environment the key belongs to.
        if ($host !== 'ote.domainresellerapi.com' || !in_array('X-API-KEY: TOPSECRETKEY', $headers, true)) return [401, 'Unauthorized! Invalid API Key.'];
        $b = $body ? json_decode($body, true) : [];
        $j = function ($d, $s = 200) { return [$s, json_encode($d)]; };
        switch ($method . ' ' . $path) {
            case 'GET /api/v1/products/ssls':
                return $j(['totalCount' => 2, 'items' => [
                    ['productCode' => 'RAPID', 'name' => 'RapidSSL', 'validationType' => 'DV', 'isMultiDomain' => false, 'prices' => [['priceGroup' => 'D', 'create' => [['period' => 1, 'price' => 9.5, 'currency' => 'USD']]]]],
                    ['productCode' => 'UCC', 'name' => 'Multi Domain', 'validationType' => 'DV', 'isMultiDomain' => true, 'maxSanCount' => 3, 'prices' => [['priceGroup' => 'D', 'create' => [['period' => 1, 'price' => 40, 'currency' => 'USD']]]]],
                ]]);
            case 'POST /api/v1/ssls/initialize':
                $state['initialized']++;
                return $j(['success' => true, 'certificateId' => 'c0ffee-' . $state['initialized'], 'status' => 'Pending']);
            case 'POST /api/v1/ssls/check-csr':
                return $j(['domainName' => strpos($b['csr'], 'OTHER') !== false ? 'other.com' : 'shop.example.com']);
            case 'POST /api/v1/ssls/generate-csr':
                return $j(['csr' => "-----BEGIN CERTIFICATE REQUEST-----\nGEN\n-----END CERTIFICATE REQUEST-----", 'privateKey' => "-----BEGIN PRIVATE KEY-----\nKEY\n-----END PRIVATE KEY-----"]);
            case 'PUT /api/v1/ssls/configure':
                $state['configured'] = $b;
                return $j(['success' => true, 'certificateId' => 'c0ffee', 'status' => 'Processing']);
            case 'GET /api/v1/ssls/certificate':
                return $j([
                    'domainName' => 'shop.example.com', 'status' => $state['revoked'] ? 'Revoked' : ($state['issued'] ? 'Issued' : 'Pending'),
                    'isConfigured' => $state['configured'] !== null, 'isIssued' => $state['issued'],
                    'configuration' => ['authenticationType' => 'DNS', 'dcvDetails' => '{"dns":{"host":"_x.shop.example.com","type":"CNAME","value":"y.sectigo.com"}}'],
                    'certificate' => ['issuedAt' => '2026-10-24T00:00:00', 'validUntil' => $state['issued'] ? date('c', time() + 86400 * 199) : null],
                ]);
            case 'GET /api/v1/ssls/approvers':
                return $j(['mails' => ['admin@shop.example.com', 'webmaster@shop.example.com']]);
            case 'PUT /api/v1/ssls/check-status':
                return $j(['domainName' => 'shop.example.com', 'status' => 'Issued', 'isConfigured' => true, 'isIssued' => true, 'certificateExpiresAt' => date('c', time() + 86400)]);
            case 'GET /api/v1/ssls/certificate-content':
                return $j(['certificate' => '-----BEGIN CERTIFICATE-----LEAF', 'intermediateCertificate1' => '-----BEGIN CERTIFICATE-----INT']);
            case 'POST /api/v1/ssls/reissue':
                $state['reissued'] = $b;
                return $j(['success' => true]);
            case 'DELETE /api/v1/ssls/revoke':
                if (!empty($state['revokeUnsupported'])) return [403, json_encode(['error' => ['code' => 'Ssl:10003', 'message' => 'Package is not supported']])];
                $state['revoked']++;
                return $j(['success' => true]);
        }
        return [500, json_encode(['error' => ['message' => 'unexpected ' . $method . ' ' . $path]])];
    };

    $params = [
        'serviceid' => 7, 'domain' => 'Shop.Example.com',
        'configoption1' => 'reseller-uuid', 'configoption2' => 'TOPSECRETKEY', 'configoption3' => 'on', 'configoption4' => 'UCC', 'configoption5' => 'on',
        'clientsdetails' => ['firstname' => 'Ada', 'lastname' => 'Lovelace', 'fullname' => 'Ada Lovelace', 'companyname' => '', 'email' => 'ada@example.com',
            'address1' => 'Street 1', 'address2' => '', 'city' => 'Istanbul', 'state' => 'IST', 'postcode' => '34000', 'countrycode' => 'TR',
            'phonecc' => '90', 'phonenumber' => '+90.5321234567'],
    ];

    echo "== definition\n";
    check('metadata: no server needed', dnassl_MetaData()['RequiresServer'] === false);
    $co = array_keys(dnassl_ConfigOptions());
    check('settings order', $co === ['Reseller ID', 'API Key', 'Test Mode', 'Certificate', 'Revoke on Termination'], json_encode($co));
    try { dnassl_PackageLoader(['configoption1' => '', 'configoption2' => '']); check('loader asks for credentials', false); }
    catch (\Exception $e) { check('loader asks for credentials', $e->getMessage() === dnassl_t('cfg_need_credentials')); }
    try { dnassl_PackageLoader(['configoption1' => 'x', 'configoption2' => 'wrong', 'configoption3' => 'on']); check('loader rejects wrong key', false); }
    catch (\Exception $e) { check('loader rejects wrong key', $e->getMessage() === dnassl_t('err_credentials'), $e->getMessage()); }
    try { dnassl_PackageLoader(['configoption3' => ''] + $params); check('loader: test key with Test Mode off is explained', false); }
    catch (\Exception $e) { check('loader: test key with Test Mode off is explained', $e->getMessage() === dnassl_t('err_key_is_test'), $e->getMessage()); }
    check('API key is a password field', dnassl_ConfigOptions()['API Key']['Type'] === 'password');
    $opts = dnassl_PackageLoader($params);
    check('package loader', isset($opts['RAPID'], $opts['UCC']) && strpos($opts['UCC'], 'SAN 2') !== false, json_encode($opts));

    echo "== create\n";
    check('create', dnassl_CreateAccount($params) === 'success');
    $row = DnaSsl_Store::get(7);
    check('order stored, domain lower-cased', $row['certificate_id'] === 'c0ffee-1' && $row['domain'] === 'shop.example.com' && $row['status'] === 'awaiting_configuration');
    check('create idempotent', dnassl_CreateAccount($params) === 'success' && $state['initialized'] === 1);
    check('create needs domain', dnassl_CreateAccount(['serviceid' => 8, 'domain' => ''] + $params) === dnassl_t('err_no_domain'));

    echo "== client area\n";
    $ca = dnassl_ClientArea($params);
    check('client area awaiting', $ca['vars']['dnaStatus'] === 'awaiting_configuration' && $ca['vars']['dnaApprovers'][0] === 'admin@shop.example.com');
    check('client area max SANs (CN excluded)', $ca['vars']['dnaMaxSans'] === 2);

    echo "== configure\n";
    $_POST = ['token' => 'bad', 'csr' => 'x'];
    check('token enforced', dnassl_configure($params) === dnassl_t('err_token'));
    $_POST = ['token' => 'tok', 'csr' => 'not a csr'];
    check('csr validated', dnassl_configure($params) === dnassl_t('err_csr'));
    $_POST = ['token' => 'tok', 'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nOTHER\n-----END CERTIFICATE REQUEST-----"];
    check('csr CN mismatch caught', strpos(dnassl_configure($params), 'other.com') !== false);
    $_POST = ['token' => 'tok', 'generate_csr' => '1', 'csr_org' => 'ACME', 'dcv_method' => 'dns', 'web_server' => 'Nginx', 'approver' => 'webmaster@shop.example.com',
              'san_list' => "a.example.com\nb.example.com\nc.example.com\nd.example.com\nshop.example.com"];
    check('configure with generated CSR', dnassl_configure($params) === 'success');
    $c = $state['configured'];
    check('configure payload', $c['authenticationType'] === 'CNAME' && $c['webServerType'] === 'Apache' && $c['approverMail'] === 'webmaster@shop.example.com', json_encode($c));
    check('SANs capped to package and CN dropped', $c['sanList'] === 'a.example.com,b.example.com', $c['sanList'] ?? '');
    check('contact phone split', $c['contact']['phoneCountryCode'] === '90' && $c['contact']['phone'] === '5321234567' && $c['contact']['companyName'] === 'Ada Lovelace'
        && $c['contact']['faxCountryCode'] === '90' && $c['contact']['fax'] === '5321234567');
    check('generated key sent and stored encrypted', strpos($c['privateKey'], 'PRIVATE KEY') !== false && strpos(DnaSsl_Store::get(7)['private_key'], 'enc:') === 0);
    check('status processing', DnaSsl_Store::get(7)['status'] === 'processing' && DnaSsl_Store::get(7)['configured']);

    $ca = dnassl_ClientArea($params);
    check('dcv dns shown', $ca['vars']['dnaStatus'] === 'processing' && $ca['vars']['dnaDcv']['dns']['value'] === 'y.sectigo.com');

    echo "== issued\n";
    $state['issued'] = true;
    $_POST = ['token' => 'tok'];
    check('refresh', dnassl_refreshStatus($params) === 'success' && DnaSsl_Store::get(7)['status'] === 'active');
    $ca = dnassl_ClientArea($params);
    check('client area active, key available', $ca['vars']['dnaStatus'] === 'active' && $ca['vars']['dnaHasKey'] === true);
    check('admin fields', strpos(implode(' ', dnassl_AdminServicesTabFields($params)), 'c0ffee-1') !== false);

    $_POST = ['token' => 'tok', 'csr' => "-----BEGIN CERTIFICATE REQUEST-----\nNEW\n-----END CERTIFICATE REQUEST-----", 'approver' => 'evil@attacker.test'];
    check('reissue', dnassl_reissue($params) === 'success');
    check('foreign approver rejected', $state['reissued']['approverMail'] === 'admin@shop.example.com');
    check('new CSR drops stale key', empty(DnaSsl_Store::get(7)['private_key']));

    echo "== renew / terminate\n";
    check('renew reconfigures with stored CSR', dnassl_Renew($params) === 'success' && $state['initialized'] === 2 && DnaSsl_Store::get(7)['configured']);
    check('terminate revokes when enabled', dnassl_TerminateAccount($params) === 'success' && $state['revoked'] === 1);
    $state['revokeUnsupported'] = true;
    check('terminate survives unrevokable package', dnassl_TerminateAccount($params) === 'success');
    check('admin revoke reports unrevokable package', dnassl_revoke($params) === 'Package is not supported');
    $state['revokeUnsupported'] = false;
    check('terminate no-op when disabled', dnassl_TerminateAccount(['configoption5' => ''] + $params) === 'success' && $state['revoked'] === 1);

    echo "== logging\n";
    $flat = json_encode($logs);
    check('api key passed for redaction', (bool) array_filter($logs, function ($l) { return ($l[3] ?? null) === ['TOPSECRETKEY']; }));
    check('no private key in logs', strpos($flat, 'BEGIN PRIVATE KEY') === false);

    // cleanup
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? rmdir($f) : unlink($f);
    rmdir($dir);

    echo "\n$ok passed, $fail failed\n";
    exit($fail ? 1 : 0);
}
