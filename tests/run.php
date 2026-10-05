<?php
/** Offline API/module contract checks. These do not bootstrap a real Blesta installation. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) { throw new ErrorException($message, 0, $severity, $file, $line); }
});
define('DS', DIRECTORY_SEPARATOR);

class TestInput
{
    private $errors = [];
    public function setErrors($errors) { $this->errors = $errors; }
    public function errors() { return $this->errors; }
}
#[AllowDynamicProperties]
class Module
{
    public $Input;
    public $base_uri = '/admin/';
    public $rows = [];
    public $logs = [];
    public $module;
    public function loadConfig($path) { json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR); }
    public function getModuleRow($id = null) { return $this->rows[$id] ?? false; }
    public function getModuleRows($group = null) { return array_values($this->rows); }
    public function setModule($module) { $this->module = $module; }
    public function serviceFieldsToObject($fields)
    {
        $result = new stdClass();
        foreach ($fields as $field) { $result->{$field->key} = $field->value; }
        return $result;
    }
    public function log($url, $data, $direction, $success) { $this->logs[] = compact('url', 'data', 'direction', 'success'); }
}
class Language
{
    public static $lang;
    public static function loadLang($name, $unused, $path)
    {
        require $path . 'en_us/' . $name . '.php';
        self::$lang = $lang;
    }
    public static function _($key, $return = true) { return self::$lang[$key] ?? $key; }
}
class Loader
{
    public static function loadComponents($object, $components) { $object->Input = new TestInput(); }
    public static function loadModels($object, $models)
    {
        foreach ($models as $model) {
            if (!isset($object->$model)) { $object->$model = new TestModels(); }
        }
    }
    public static function loadHelpers($object, $helpers)
    {
        foreach ($helpers as $helper) { $object->view->$helper = new TestHelpers(); }
    }
}
class TestModels
{
    public $saved;
    public $service;
    public function get($id) { return $this->service ?? ($id ? (object)['email' => 'buyer@example.com'] : false); }
    public function editField($id, $data) { $this->saved = [$id, $data]; }
    public function errors() { return []; }
}
#[AllowDynamicProperties]
class View
{
    public $base_uri;
    private $name;
    private $data = [];
    public function __construct($name, $theme) { $this->name = $name; }
    public function setDefaultView($path) {}
    public function set($key, $value) { $this->data[$key] = $value; }
    public function _($key, $return = false)
    {
        $text = Language::_($key);
        if ($return) { return $text; }
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
    public function fetch()
    {
        extract($this->data);
        ob_start();
        try { include dirname(__DIR__) . '/components/modules/cpguard_reseller/views/default/' . $this->name . '.pdt'; return ob_get_clean(); }
        catch (Throwable $e) { ob_end_clean(); throw $e; }
    }
}
class TestHelpers
{
    public function safe($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
    public function __call($method, $args)
    {
        if ($method === 'footer') { throw new RuntimeException('Blesta 5.x Widget has no footer method'); }
        if ($method === 'fieldPassword' && (count($args) !== 2 || !is_array($args[1]))) {
            throw new RuntimeException('fieldPassword expects name and attributes only');
        }
        if ($method === 'create') { echo '<form method="post"><input name="_csrf_token" value="fixture">'; }
        if ($method === 'end') { echo '</form>'; }
        if ($method === 'fieldHidden') { echo '<input name="' . $this->safe($args[0]) . '" value="' . $this->safe($args[1]) . '">'; }
        if ($method === 'fieldSubmit') { echo '<button>' . $this->safe($args[1]) . '</button>'; }
    }
}
class ModuleFields
{
    public $options;
    public $html = '';
    public function label($text, $id) { return new TestField(); }
    public function setField($field) {}
    public function fieldSelect($name, $options, $value, $attributes) { $this->options = $options; return new TestField(); }
    public function tooltip($text) { return new TestField(); }
    public function setHtml($html) { $this->html = $html; }
}
class TestField { public function attach($field) {} }
require dirname(__DIR__) . '/components/modules/cpguard_reseller/cpguard_reseller.php';
class TestModule extends CpguardReseller
{
    public $isStaff = true;
    public $calls = [];
    public $handler;
    protected function staffAccess() { return $this->isStaff; }
    protected function callApi($row, $action, array $data = [])
    {
        $this->calls[] = [$row->id ?? null, $action, $data];
        return call_user_func($this->handler, $action, $data);
    }
}

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
}
function throws($callback, $message)
{
    try { $callback(); } catch (Exception $e) { check(true, $message); return; }
    throw new RuntimeException('FAIL: ' . $message);
}
function values($fields) { return array_column($fields ?? [], 'value', 'key'); }
function stored($fields) { return array_map(function ($field) { return (object)$field; }, $fields); }
function makeModule($handler)
{
    $module = new TestModule();
    $module->rows[1] = (object)['id' => 1, 'meta' => (object)['api_key' => 'fixture-key', 'account_name' => 'Test reseller']];
    $module->rows[2] = (object)['id' => 2, 'meta' => (object)['api_key' => 'other-key', 'account_name' => 'Other reseller']];
    $module->handler = $handler;
    return $module;
}

$pricing = ['id' => '558', 'term' => '1', 'period' => 'month', 'price' => '5.00', 'currency' => 'USD'];
$remote = ['service_id' => '501224', 'license_key' => '8rs-fixture', 'pricing_id' => '558',
    'status' => 'active', 'reissue' => false, 'ips' => ['192.0.2.1'], 'domains' => ['server.example.com']];
$package = (object)['module_row' => 1, 'module_group' => '', 'meta' => (object)['cpguard_pricing_id' => '558'],
    'pricing' => [(object)['term' => '1', 'period' => 'month']]];
$default = function ($action, $data) use ($pricing, $remote) {
    if ($action === 'getpackages') { return [['id' => 16, 'name' => 'Reseller Standard', 'pricing' => [$pricing, array_merge($pricing, ['id' => '559'])]]]; }
    if ($action === 'addlicense') { return ['services' => [$remote], 'Invite_link' => 'https://manage.opsshield.com/client/authorize/signup?sid=fixture']; }
    if ($action === 'getlicense') { return $remote; }
    if ($action === 'changepackage') { return ['services' => [array_merge($remote, ['service_id' => '501225', 'license_key' => '8rs-new-key', 'pricing_id' => $data['pricing_id']])]]; }
    if ($action === 'accountdetails') { return ['credit' => ['USD' => '10'], 'due' => ['USD' => '0'], 'services' => ['active' => 1]]; }
    if ($action === 'invitationlink') { return ['invite_link' => 'https://manage.opsshield.com/client/authorize/signup?sid=new']; }
    return ['status' => true, 'service_id' => $data['service_id']];
};

$apiCalls = [];
$api = new CpguardResellerApi('secret-fixture', function ($url, $data) use (&$apiCalls) {
    $apiCalls[] = [$url, $data];
    return ['status' => 200, 'body' => '{"status":false,"service_id":"1"}'];
});
$response = $api->request('suspendlicense', ['service_id' => 1, 'apikey' => 'override']);
check($apiCalls[0][1]['apikey'] === 'secret-fixture', 'API key cannot be overridden');
check(strpos($apiCalls[0][0], 'https://manage.opsshield.com/') === 0, 'Fixed HTTPS endpoint');
throws(function () use ($response) { CpguardResellerApi::requireSuccess($response, 1); }, 'False status is a failure');
throws(function () { CpguardResellerApi::requireSuccess(['status' => true, 'service_id' => 2], 1); }, 'Mismatched action response rejected');
throws(function () use ($api) { $api->request('deletelicense'); }, 'Remote delete is unavailable');
foreach ([['status' => 401, 'body' => '{"message":"secret-fixture"}'], ['status' => 200, 'body' => '<html>Maintenance</html>'], ['status' => 200, 'body' => '{"error":"failure"}']] as $failure) {
    $bad = new CpguardResellerApi('secret-fixture', function () use ($failure) { return $failure; });
    try { $bad->request('accountdetails'); check(false, 'Invalid response fails'); }
    catch (RuntimeException $e) { check(strpos($e->getMessage(), 'secret-fixture') === false, 'Failure does not expose raw response'); }
}
check(CpguardResellerApi::sameCycle(12, 'month', 1, 'year'), 'Year/month equivalence');
check(!CpguardResellerApi::sameCycle(30, 'day', 1, 'month'), 'Days are not months');
check(!CpguardResellerApi::sameCycle(0, 'month', 0, 'month'), 'Invalid cycles rejected');
check(CpguardResellerApi::invitationUrl('https://evil.example/signup') === '', 'Foreign invitation blocked');
check(CpguardResellerApi::invitationUrl('javascript:alert(1)') === '', 'Script URL blocked');
check(CpguardResellerApi::invitationUrl('https://manage.opsshield.com@evil.example/') === '', 'Misleading URL blocked');
check(CpguardResellerApi::invitationUrl('https://manage.opsshield.com:444/') === '', 'Unexpected port blocked');
throws(function () use ($remote) { CpguardResellerApi::createdLicense(['services' => [$remote, $remote]], 558); }, 'Multiple licenses rejected');
throws(function () use ($remote) { CpguardResellerApi::createdLicense(['services' => [$remote]], 559); }, 'Mismatched creation pricing rejected');

$module = makeModule($default);
$initialFields = $module->getPackageFields((object)['module_group' => 'select']);
check(isset($initialFields->options['558']) && $initialFields->html === '', 'Initial package fields use the account rendered by Blesta');
$vars = ['account_name' => 'Main', 'api_key' => 'test'];
$account = $module->addModuleRow($vars);
check($account[1]['encrypted'] === 1, 'API key stored encrypted');
$vars = ['account_name' => 'Rename', 'api_key' => ''];
check(values($module->editModuleRow($module->rows[1], $vars))['api_key'] === 'fixture-key', 'Blank edit retains key');
$pvars = ['module_row' => 1, 'meta' => ['cpguard_pricing_id' => '558'], 'pricing' => [['term' => '1', 'period' => 'month']]];
check(values($module->addPackage($pvars))['cpguard_pricing_id'] === '558', 'Explicit upstream pricing saved');
$pvars['module_group'] = 'select';
check(values($module->addPackage($pvars))['cpguard_pricing_id'] === '558', 'Native select placeholder means a directly selected account');
$pvars['pricing'][0]['period'] = 'year';
check($module->addPackage($pvars) === null && $module->Input->errors(), 'Mismatched package cycle rejected');
$module = makeModule($default);
$pending = $module->addService($package, ['client_id' => 10, 'use_module' => 'false']);
check(values($pending)['cpguard_client_email'] === 'buyer@example.com' && count($module->calls) === 0, 'Pending service does not provision');
$meta = $module->addService($package, ['client_id' => 10, 'use_module' => 'true', 'client_email' => 'attacker@example.com']);
check(values($meta)['cpguard_service_id'] === '501224', 'License provisioned');
check(values($meta)['cpguard_invite_link'] !== '', 'Capitalized Invite_link supported');
check($module->calls[1][2]['quantity'] === 1 && $module->calls[1][2]['client_email'] === 'buyer@example.com', 'Creation uses one license and authenticated client email');
check($meta[1]['encrypted'] === 1, 'License key encrypted');
$service = (object)['id' => 42, 'client_id' => 10, 'module_row_id' => 1, 'status' => 'active', 'fields' => stored($meta)];
$module->calls = [];
$linked = $module->addService($package, ['client_id' => 10, 'use_module' => 'true', 'cpguard_service_id' => '501224']);
check(values($linked)['cpguard_service_id'] === '501224' && count($module->calls) === 1 && $module->calls[0][1] === 'getlicense', 'Link existing license without duplicate creation');
$module->calls = [];
check($module->renewService($package, $service) === null && count($module->calls) === 0, 'Renewal makes no upstream purchase');
$suspended = $module->suspendService($package, $service);
check(values($suspended)['cpguard_remote_status'] === 'suspended', 'Confirmed suspension metadata returned');
$module = makeModule(function ($action, $data) use ($default) {
    return $action === 'suspendlicense' ? ['status' => false, 'service_id' => '501224'] : $default($action, $data);
});
check($module->suspendService($package, $service) === null && $module->Input->errors(), 'False suspension prevents local transition');
$module = makeModule(function ($action, $data) use ($default, $remote) {
    return $action === 'getlicense' ? array_merge($remote, ['status' => 'canceled']) : $default($action, $data);
});
check(values($module->cancelService($package, $service))['cpguard_remote_status'] === 'canceled' && count($module->calls) === 1, 'Repeated cancellation is idempotent');
$module = makeModule($default);
$target = clone $package;
$target->meta = (object)['cpguard_pricing_id' => '559'];
$changed = values($module->changeServicePackage($package, $target, $service));
check($changed['cpguard_service_id'] === '501225' && $changed['cpguard_license_key'] === '8rs-new-key', 'Package change stores replacement identity');
check($changed['cpguard_key_changed'] === 'true' && strpos($changed['cpguard_apply_command'], '8rs-new-key') !== false, 'Package change provides new-key instruction');
$target->module_row = 2;
$module = makeModule($default);
check($module->changeServicePackage($package, $target, $service) === null && count($module->calls) === 0, 'Cross-account package change blocked before API call');
$moved = clone $service;
$moved->module_row_id = 2;
check($module->suspendService($package, $moved) === null && count($module->calls) === 0, 'Cross-account service row mismatch blocked');
$module = makeModule($default);
check($module->addService($package, ['client_id' => 10, 'use_module' => 'true', 'qty' => 2]) === null && count($module->calls) === 0, 'Multi-license quantity blocked');
$module = makeModule($default);
check($module->addService($package, ['client_id' => 10, 'use_module' => 'true', 'qty' => '1.5']) === null && count($module->calls) === 0, 'Fractional quantity blocked');
$module = makeModule($default);
$pendingService = clone $service;
$pendingService->status = 'pending';
$pendingService->fields = stored($pending);
$recovered = $module->editService($package, $pendingService, ['cpguard_recovery_service_id' => '501224']);
check(values($recovered)['cpguard_service_id'] === '501224' && count($module->calls) === 1, 'Uncertain pending provisioning can be reconciled without purchase');
check($module->editService($package, $service, ['cpguard_recovery_service_id' => '501225']) === null, 'Recovery cannot replace an existing license');
$module = makeModule($default);
$module->isStaff = false;
check($module->addService($package, ['client_id' => 10, 'use_module' => 'true', 'cpguard_service_id' => '501224']) === null && count($module->calls) === 0, 'Client cannot import another license by posting a remote ID');
check($module->editService($package, $pendingService, ['cpguard_recovery_service_id' => '501224']) === null && count($module->calls) === 0, 'Client cannot use staff recovery linking');
$module = makeModule($default);
$module->isStaff = false;
$module->Services = new TestModels();
$pendingService->fields = stored($recovered);
$module->Services->service = $pendingService;
$activated = $module->addService($package, ['service_id' => 42, 'client_id' => 10, 'use_module' => 'true']);
check(values($activated)['cpguard_service_id'] === '501224' && count($module->calls) === 1 && $module->calls[0][1] === 'getlicense', 'Pending activation loads trusted stored identity without duplicate purchase');
$module = makeModule($default);
$html = $module->tabClientLicense($package, $service);
check(strpos($html, 'Reissue License') !== false && strpos($html, '_csrf_token') !== false, 'Client tab renders reissue POST form');
check(strpos($html, 'Refresh Invitation Link') === false, 'Staff invitation control absent for client');
$html = $module->tabLicense($package, $service, [], ['cpguard_action' => 'invitation', 'client_email' => 'attacker@example.com']);
$last = end($module->calls);
check($last[2]['client_email'] === 'buyer@example.com', 'Invitation ignores submitted email');
check($module->Services->saved[0] === 42 && $module->Services->saved[1]['encrypted'] === 'true', 'Refreshed invitation persists encrypted on correct service');
$module = makeModule($default);
$module->tabClientLicense($package, $service, [], ['cpguard_action' => 'invitation']);
check($module->Input->errors() && count($module->calls) === 1, 'Client cannot invoke staff invitation action');
$module = makeModule($default);
$module->tabClientLicense($package, $service, ['cpguard_action' => 'reissue']);
check(count($module->calls) === 1, 'GET cannot trigger reissue');
$module->calls = [];
$module->tabClientLicense($package, $service, [], ['cpguard_action' => 'reissue', 'service_id' => '999']);
check($module->calls[1][2]['service_id'] === '501224', 'Reissue ignores submitted service ID');
$module = makeModule(function ($action, $data) use ($default, $remote) {
    return $action === 'getlicense' ? array_merge($remote, ['license_key' => '<script>alert(1)</script>']) : $default($action, $data);
});
$html = $module->tabClientLicense($package, $service);
check(strpos($html, '<script>') === false && strpos($html, '&lt;script&gt;') !== false, 'License and command output HTML escaped');
$module = makeModule($default);
$html = $module->manageModule((object)['id' => 3, 'rows' => [$module->rows[1]]], $vars);
check(strpos($html, '10 USD') !== false && strpos($html, 'fixture-key') === false, 'Account view shows credit without API key');
check($module->module->id === 3, 'Manage view sets installed module identity before logging');
$html = $module->manageEditRow($module->rows[1], $vars);
check(strpos($html, 'fixture-key') === false, 'Saved API key not rendered in form');
class LoggingFailureModule extends CpguardReseller
{
    protected function apiClient($row)
    {
        return new CpguardResellerApi('fixture-key', function () {
            return ['status' => 200, 'body' => '{"credit":{},"due":{},"services":{}}'];
        });
    }
    public function log($url, $data = null, $direction = 'input', $success = false)
    {
        throw new RuntimeException('Log unavailable');
    }
    public function runRequest($row) { return $this->callApi($row, 'accountdetails'); }
}
$loggerFailure = new LoggingFailureModule();
check(isset($loggerFailure->runRequest($module->rows[1])['services']), 'Logging failure cannot discard successful API result');
// Regression checks for the public-release security review.
$module = makeModule($default);
check($module->getServiceName($service) === 'cPGuard #501224', 'Service labels do not expose a license key or email');
$vars = ['account_name' => ['invalid'], 'api_key' => 'fixture-key'];
check($module->addModuleRow($vars) === null, 'Array account name fails validation without a PHP warning');
$vars = ['account_name' => 'Test', 'api_key' => ['invalid']];
// Exercise the actual API constructor rather than the test transport override.
throws(function () { new CpguardResellerApi(['invalid']); }, 'Array API key rejected');
check(!CpguardResellerApi::positiveId(['501224']), 'Array service ID rejected');
check(!CpguardResellerApi::positiveId(true), 'Boolean service ID rejected');
check(!CpguardResellerApi::sameCycle([], 'month', 1, 'month'), 'Malformed cycle rejected');
check(!CpguardResellerApi::sameCycle(PHP_INT_MAX, 'year', 1, 'month'), 'Overflow-sized cycle rejected');
check(CpguardResellerApi::invitationUrl("https://manage.opsshield.com/\\@evil.example/") === '', 'Backslash invitation URL rejected');
check(CpguardResellerApi::invitationUrl("https://manage.opsshield.com/\npath") === '', 'Control characters in invitation URL rejected');
foreach ([['service_id' => []], ['pricing_id' => false], ['license_key' => "bad\0key"],
    ['status' => 'unknown'], ['ips' => [['nested']]], ['domains' => [false]],
    ['package_name' => []], ['date_renews' => []], ['reissue' => 'false']] as $invalid) {
    throws(function () use ($remote, $invalid) {
        CpguardResellerApi::validateLicense(array_merge($remote, $invalid));
    }, 'Malformed license field rejected');
}
$module = makeModule($default);
check($module->addService($package, ['client_id' => 10, 'qty' => []]) === null
    && count($module->calls) === 0, 'Array quantity blocked before API request');
$module->isStaff = false;
check($module->tabLicense($package, $service, [], ['cpguard_action' => 'invitation']) === ''
    && count($module->calls) === 0, 'Staff tab enforces staff authentication independently');
$module = makeModule($default);
$module->Services = new TestModels();
$wrongAccount = clone $pendingService;
$wrongAccount->module_row_id = 2;
$module->Services->service = $wrongAccount;
$wrongPackage = clone $package;
$wrongPackage->module_row = 2;
check($module->addService($wrongPackage, ['client_id' => 10, 'service_id' => 42, 'use_module' => 'true']) === null
    && count($module->calls) === 0, 'Pending recovery rejects persisted account mismatch');
$module = makeModule(function ($action, $data) use ($default) {
    if ($action === 'getpackages') { return [['id' => 1, 'name' => [], 'pricing' => []]]; }
    return $default($action, $data);
});
check($module->addPackage($pvars) === null && $module->Input->errors(), 'Malformed package data rejected');
$module = makeModule($default);
$extra = clone $service;
$extra->fields[] = (object)['key' => 'cpguard_unexpected', 'value' => 'discard'];
check(!isset(values($module->editService($package, $extra))['cpguard_unexpected']), 'Unknown service fields are not persisted');
$badAccount = new CpguardResellerApi('fixture-key', function () {
    return ['status' => 200, 'body' => '{"credit":{"USD":[]},"due":{},"services":{}}'];
});
throws(function () use ($badAccount) { $badAccount->request('accountdetails'); }, 'Nested account balance rejected');
$module = makeModule(function ($action, $data) use ($default) {
    return $action === 'invitationlink' ? ['invite_link' => false] : $default($action, $data);
});
$module->tabLicense($package, $service, [], ['cpguard_action' => 'invitation']);
check((bool)$module->Input->errors(), 'Malformed empty invitation is not treated as a registered customer');
$module = makeModule($default);
check($module->addService($package, ['client_id' => [], 'use_module' => 'true']) === null
    && count($module->calls) === 0, 'Malformed client ID rejected');
check($module->addService($package, ['client_id' => 10, 'service_id' => [42], 'use_module' => 'true']) === null
    && count($module->calls) === 0, 'Malformed pending service ID rejected');
$badRow = clone $package;
$badRow->module_row = [1];
check($module->addService($badRow, ['client_id' => 10, 'use_module' => 'true']) === null
    && count($module->calls) === 0, 'Malformed account ID rejected');
$module = makeModule(function ($action, $data) use ($default, $remote) {
    return $action === 'getlicense' ? array_merge($remote, ['status' => 'suspended']) : $default($action, $data);
});
check($module->addService($package, ['client_id' => 10, 'cpguard_service_id' => '501224', 'use_module' => 'true']) === null,
    'Import cannot activate a locally active service with a suspended remote license');
$module = makeModule($default);
$unprovisioned = clone $service;
$unprovisioned->status = 'pending';
$unprovisioned->fields = stored($pending);
$html = $module->tabLicense($package, $unprovisioned);
check(strpos($html, 'License details will appear after activation.') !== false
    && strpos($html, 'alert-danger') === false && count($module->calls) === 0,
    'Unprovisioned service shows a neutral state without an API request');
echo 'PASS: ' . $checks . " offline contract checks\n";
