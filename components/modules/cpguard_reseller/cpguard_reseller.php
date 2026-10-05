<?php

require_once __DIR__ . '/lib/cpguard_reseller_api.php';

/** cPGuard license provisioning for a reseller's own Blesta installation. */
class CpguardReseller extends Module
{
    public function __construct()
    {
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('cpguard_reseller', null, __DIR__ . DS . 'language' . DS);
        $this->loadConfig(__DIR__ . DS . 'config.json');
    }

    public function getServiceName($service)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        return empty($fields->cpguard_service_id) ? $this->lang('name') : 'cPGuard #' . $fields->cpguard_service_id;
    }
    public function getPackageServiceName($package, ?array $vars = null)
    {
        return $this->lang('name');
    }

    public function manageModule($module, array &$vars)
    {
        // Blesta's manage controller creates a fresh module without setting its installed identity.
        $this->setModule($module);
        $balances = [];
        foreach (($module->rows ?? []) as $row) {
            try {
                $balances[$row->id] = $this->callApi($row, 'accountdetails');
            } catch (Exception $e) {
                $balances[$row->id] = ['connection_error' => $e->getMessage()];
            }
        }
        return $this->render('manage', ['module' => $module, 'balances' => $balances]);
    }

    public function manageAddRow(array &$vars)
    {
        return $this->render('account', ['vars' => (object)$vars, 'editing' => false]);
    }

    public function manageEditRow($module_row, array &$vars)
    {
        if (!$vars) {
            $vars = ['account_name' => $module_row->meta->account_name, 'api_key' => ''];
        }
        return $this->render('account', ['vars' => (object)$vars, 'editing' => true, 'row' => $module_row]);
    }

    public function addModuleRow(array &$vars)
    {
        if ($this->getModuleRows()) {
            $this->error(new RuntimeException($this->lang('error.single_account')));
            return;
        }
        return $this->accountMeta($vars);
    }

    private function accountMeta(array $vars)
    {
        try {
            $name = is_string($vars['account_name'] ?? null) ? trim($vars['account_name']) : '';
            $key = is_string($vars['api_key'] ?? null) ? trim($vars['api_key']) : '';
            if ($name === '') {
                throw new RuntimeException($this->lang('error.account_name'));
            }
            $row = (object)['meta' => (object)['api_key' => $key]];
            $details = $this->callApi($row, 'accountdetails');
            if (!is_array($details['credit'] ?? null) || !is_array($details['due'] ?? null)
                || !is_array($details['services'] ?? null)) {
                throw new RuntimeException($this->lang('error.account_response'));
            }
            return [
                ['key' => 'account_name', 'value' => $name, 'encrypted' => 0],
                ['key' => 'api_key', 'value' => $key, 'encrypted' => 1]
            ];
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function editModuleRow($module_row, array &$vars)
    {
        $save = $vars;
        if (is_string($save['api_key'] ?? null)) { $save['api_key'] = trim($save['api_key']); }
        if (!isset($save['api_key']) || $save['api_key'] === '') {
            $save['api_key'] = $module_row->meta->api_key;
        }
        return $this->accountMeta($save);
    }

    public function getPackageFields($vars = null)
    {
        $fields = new ModuleFields();
        $options = ['' => $this->lang('select_pricing')];
        try {
            // On initial module selection, Blesta renders the first account but does not yet send module_row.
            $selection = (object)(array)$vars;
            if (empty($selection->module_row)) {
                $rows = $this->getModuleRows();
                $selection->module_row = $rows[0]->id ?? null;
            }
            $row = $this->packageRow($selection);
            foreach ($this->availablePricing($row) as $id => $pricing) {
                $options[$id] = $pricing['package_name'] . ' — ' . $pricing['term'] . ' '
                    . $pricing['period'] . ' / ' . $pricing['price'] . ' ' . $pricing['currency']
                    . ' (#' . $id . ')';
            }
        } catch (Exception $e) {
            $fields->setHtml('<div class="alert alert-danger">' . $this->escape($e->getMessage()) . '</div>');
        }
        $meta = (array)($vars->meta ?? []);
        $label = $fields->label($this->lang('pricing'), 'cpguard_pricing_id');
        $label->attach($fields->fieldSelect('meta[cpguard_pricing_id]', $options,
            $meta['cpguard_pricing_id'] ?? '', ['id' => 'cpguard_pricing_id']));
        $label->attach($fields->tooltip($this->lang('pricing_help')));
        $fields->setField($label);
        return $fields;
    }

    public function addPackage(?array $vars = null)
    {
        try {
            $vars = $vars ?? [];
            // A remote service ID is scoped to one reseller. Do not distribute licenses across account groups.
            if (!empty($vars['module_group']) && $vars['module_group'] !== 'select') {
                throw new RuntimeException($this->lang('error.group'));
            }
            $row = $this->packageRow((object)$vars);
            $id = $vars['meta']['cpguard_pricing_id'] ?? '';
            $pricing = $this->findPricing($row, $id);
            $this->checkCycles($vars['pricing'] ?? [], $pricing);
            return [['key' => 'cpguard_pricing_id', 'value' => $pricing['id'], 'encrypted' => 0]];
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function editPackage($package, ?array $vars = null)
    {
        $vars = $vars ?? [];
        $vars['module_row'] = $vars['module_row'] ?? $package->module_row;
        $vars['module_group'] = $vars['module_group'] ?? $package->module_group ?? '';
        $vars['meta'] = $vars['meta'] ?? (array)$package->meta;
        // Remapping a sold package cannot replace the licenses attached to existing services.
        // Use Blesta's service package change to update those services explicitly.
        return $this->addPackage($vars);
    }

    public function getAdminAddFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $label = $fields->label($this->lang('remote_id'), 'cpguard_service_id');
        $label->attach($fields->fieldText('cpguard_service_id', $vars->cpguard_service_id ?? '',
            ['id' => 'cpguard_service_id']));
        $label->attach($fields->tooltip($this->lang('import_help')));
        $fields->setField($label);
        return $fields;
    }

    public function getClientAddFields($package, $vars = null)
    {
        return new ModuleFields();
    }

    public function getAdminEditFields($package, $vars = null)
    {
        $fields = new ModuleFields();
        $label = $fields->label($this->lang('recovery_id'), 'cpguard_recovery_service_id');
        $label->attach($fields->fieldText('cpguard_recovery_service_id', '', ['id' => 'cpguard_recovery_service_id']));
        $label->attach($fields->tooltip($this->lang('recovery_help')));
        $fields->setField($label);
        return $fields;
    }

    public function validateService($package, ?array $vars = null)
    {
        if (!in_array($vars['qty'] ?? 1, [1, '1'], true) || !in_array($vars['quantity'] ?? 1, [1, '1'], true)) {
            $this->error(new RuntimeException($this->lang('error.quantity')));
            return false;
        }
        return true;
    }

    public function addService($package, ?array $vars = null, $parent_package = null,
        $parent_service = null, $status = 'pending')
    {
        try {
            $vars = $vars ?? [];
            if (!$this->validateService($package, $vars)) { return; }
            $email = $this->clientEmail($vars['client_id'] ?? null);
            $row = $this->packageRow($package);
            $remoteId = $vars['cpguard_service_id'] ?? '';
            $storedIdentity = false;
            // Blesta 5.x passes service_id when activating a pending service, but does not merge its stored fields.
            if (!empty($vars['service_id'])) {
                if (!CpguardResellerApi::positiveId($vars['service_id'])) {
                    throw new RuntimeException($this->lang('error.recovery'));
                }
                Loader::loadModels($this, ['Services']);
                $pending = $this->Services->get($vars['service_id']);
                if (!$pending || $pending->status !== 'pending'
                    || (string)$pending->client_id !== (string)($vars['client_id'] ?? '')
                    || (string)$pending->module_row_id !== (string)$row->id) {
                    throw new RuntimeException($this->lang('error.recovery'));
                }
                $this->serviceRow($pending);
                $pendingFields = $this->serviceFieldsToObject($pending->fields);
                if (!empty($pendingFields->cpguard_service_id)) {
                    $remoteId = $pendingFields->cpguard_service_id;
                    $storedIdentity = true;
                }
            }
            if ($remoteId !== '') {
                if (!$storedIdentity && !$this->staffAccess()) {
                    throw new RuntimeException($this->lang('error.staff_import'));
                }
                $license = $this->license($row, $remoteId);
                if ((string)$license['pricing_id'] !== (string)$package->meta->cpguard_pricing_id
                    || $license['status'] !== 'active') {
                    throw new RuntimeException($this->lang('error.import'));
                }
                $license['invite_link'] = '';
                return $this->licenseFields($license, $email, $row->id);
            }
            if (($vars['use_module'] ?? 'false') !== 'true') {
                return $this->fields(['cpguard_client_email' => $email]);
            }
            $pricing = $this->findPricing($row, $package->meta->cpguard_pricing_id ?? '');
            $this->checkCycles($package->pricing ?? [], $pricing);
            $response = $this->callApi($row, 'addlicense', [
                'pricing_id' => $pricing['id'], 'quantity' => 1, 'client_email' => $email
            ]);
            $license = CpguardResellerApi::createdLicense($response, $pricing['id']);
            return $this->licenseFields($license, $email, $row->id);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function editService($package, $service, ?array $vars = null,
        $parent_package = null, $parent_service = null)
    {
        try {
            $row = $this->serviceRow($service);
            if (!$this->validateService($package, $vars ?? [])) { return; }
            if (isset($vars['module_row_id']) && (string)$vars['module_row_id'] !== (string)$service->module_row_id) {
                throw new RuntimeException($this->lang('error.row_change'));
            }
            $existing = $this->serviceFieldsToObject($service->fields);
            if (!empty($vars['cpguard_recovery_service_id'])) {
                if (!$this->staffAccess()) {
                    throw new RuntimeException($this->lang('error.staff_import'));
                }
                if (!empty($existing->cpguard_service_id)
                    || !in_array($service->status, ['pending', 'in_review'], true)) {
                    throw new RuntimeException($this->lang('error.recovery'));
                }
                $license = $this->license($row, $vars['cpguard_recovery_service_id']);
                if ((string)$license['pricing_id'] !== (string)$package->meta->cpguard_pricing_id
                    || $license['status'] !== 'active') {
                    throw new RuntimeException($this->lang('error.import'));
                }
                return $this->licenseFields($license, $this->clientEmail($service->client_id), $row->id);
            }
            // License identity and buyer email are immutable here. A package change uses changeServicePackage.
            return $this->fields((array)$existing);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->lifecycle($service, 'suspendlicense', 'suspended');
    }

    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->lifecycle($service, 'unsuspendlicense', 'active');
    }

    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        if (empty($fields->cpguard_service_id)) {
            // Cancel an unprovisioned pending service locally.
            return [];
        }
        return $this->lifecycle($service, 'cancellicense', 'canceled');
    }

    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        // OPSSHIELD renews active licenses itself. Blesta invoices the retail customer independently.
        return null;
    }

    public function changeServicePackage($package_from, $package_to, $service,
        $parent_package = null, $parent_service = null)
    {
        try {
            $fields = $this->serviceFieldsToObject($service->fields);
            $row = $this->serviceRow($service);
            $targetRow = $this->packageRow($package_to);
            if ((string)$row->id !== (string)$targetRow->id) {
                throw new RuntimeException($this->lang('error.row_change'));
            }
            $current = $this->license($row, $fields->cpguard_service_id ?? '');
            if (($current['status'] ?? '') !== 'active' || $service->status !== 'active') {
                throw new RuntimeException($this->lang('error.active'));
            }
            $pricing = $this->findPricing($row, $package_to->meta->cpguard_pricing_id ?? '');
            $this->checkCycles($package_to->pricing ?? [], $pricing);
            if ((string)$current['pricing_id'] === (string)$pricing['id']) {
                return $this->fields((array)$fields);
            }
            $email = $fields->cpguard_client_email ?? $this->clientEmail($service->client_id);
            $response = $this->callApi($row, 'changepackage', [
                'service_id' => $fields->cpguard_service_id,
                'pricing_id' => $pricing['id'], 'client_email' => $email
            ]);
            $license = CpguardResellerApi::createdLicense($response, $pricing['id']);
            $newFields = array_column($this->licenseFields($license, $email, $row->id), 'value', 'key');
            $newFields['cpguard_key_changed'] = 'true';
            return $this->fields($newFields);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    public function getAdminTabs($package) { return ['tabLicense' => $this->lang('license_tab')]; }
    public function getClientTabs($package) { return ['tabClientLicense' => $this->lang('license_tab')]; }

    public function tabLicense($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        if (!$this->staffAccess()) {
            $this->error(new RuntimeException($this->lang('error.action')));
            return '';
        }
        return $this->licenseTab($service, $post ?? [], false);
    }

    public function tabClientLicense($package, $service, ?array $get = null, ?array $post = null, ?array $files = null)
    {
        return $this->licenseTab($service, $post ?? [], true);
    }

    public function getAdminServiceInfo($service, $package)
    {
        return $this->render('service_info', ['fields' => $this->serviceFieldsToObject($service->fields)]);
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->getAdminServiceInfo($service, $package);
    }

    private function licenseTab($service, array $post, $client)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $details = [];
        $error = '';
        $notice = '';
        $invite = CpguardResellerApi::invitationUrl($fields->cpguard_invite_link ?? '');
        if (empty($fields->cpguard_service_id)) {
            return $this->render('license', compact('fields', 'details', 'error', 'notice', 'invite', 'client', 'service'));
        }
        try {
            $row = $this->serviceRow($service);
            $details = $this->license($row, $fields->cpguard_service_id ?? '');
            // The enclosing Blesta controllers authenticate service ownership and validate POST CSRF tokens.
            // IDs/email from the POST are deliberately ignored.
            if (!empty($post['cpguard_action'])) {
                if ($service->status !== 'active' || $details['status'] !== 'active') {
                    throw new RuntimeException($this->lang('error.active'));
                }
                if ($post['cpguard_action'] === 'reissue' && ($details['reissue'] ?? null) === false) {
                    $response = $this->callApi($row, 'reissuelicense', ['service_id' => $fields->cpguard_service_id]);
                    CpguardResellerApi::requireSuccess($response, $fields->cpguard_service_id);
                    $notice = $this->lang('reissued');
                    $details = $this->license($row, $fields->cpguard_service_id);
                } elseif ($post['cpguard_action'] === 'invitation' && !$client) {
                    $response = $this->callApi($row, 'invitationlink', ['client_email' => $fields->cpguard_client_email]);
                    if (!array_key_exists('invite_link', $response)
                        || ($response['invite_link'] !== null && !is_string($response['invite_link']))) {
                        throw new RuntimeException($this->lang('error.invitation'));
                    }
                    $invite = CpguardResellerApi::invitationUrl($response['invite_link']);
                    if (!empty($response['invite_link']) && $invite === '') {
                        throw new RuntimeException($this->lang('error.invitation'));
                    }
                    Loader::loadModels($this, ['Services']);
                    $this->Services->editField($service->id,
                        ['key' => 'cpguard_invite_link', 'value' => $invite, 'encrypted' => 'true']);
                    if ($this->Services->errors()) {
                        throw new RuntimeException($this->lang('error.invitation_save'));
                    }
                    $notice = $invite === '' ? $this->lang('registered') : $this->lang('invitation_ready');
                } else {
                    throw new RuntimeException($this->lang('error.action'));
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
            if (!empty($post)) { $this->error($e); }
        }
        return $this->render('license', compact('fields', 'details', 'error', 'notice', 'invite', 'client', 'service'));
    }

    private function lifecycle($service, $action, $targetStatus)
    {
        try {
            $fields = $this->serviceFieldsToObject($service->fields);
            $row = $this->serviceRow($service);
            $license = $this->license($row, $fields->cpguard_service_id ?? '');
            if ($license['status'] !== $targetStatus) {
                $response = $this->callApi($row, $action, ['service_id' => $fields->cpguard_service_id]);
                CpguardResellerApi::requireSuccess($response, $fields->cpguard_service_id);
            }
            $values = (array)$fields;
            $values['cpguard_remote_status'] = $targetStatus;
            return $this->fields($values);
        } catch (Exception $e) {
            $this->error($e);
        }
    }

    private function packageRow($package)
    {
        if (!empty($package->module_group) && $package->module_group !== 'select') {
            throw new RuntimeException($this->lang('error.group'));
        }
        if (!CpguardResellerApi::positiveId($package->module_row ?? null)) {
            throw new RuntimeException($this->lang('error.row'));
        }
        $row = $this->getModuleRow($package->module_row);
        if (!$row || empty($row->meta->api_key)) {
            throw new RuntimeException($this->lang('error.row'));
        }
        return $row;
    }

    private function serviceRow($service)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        if (!CpguardResellerApi::positiveId($service->module_row_id ?? null)) {
            throw new RuntimeException($this->lang('error.row'));
        }
        if (!empty($fields->cpguard_account_row_id)
            && (string)$fields->cpguard_account_row_id !== (string)$service->module_row_id) {
            throw new RuntimeException($this->lang('error.row_change'));
        }
        $row = $this->getModuleRow($service->module_row_id);
        if (!$row || empty($row->meta->api_key)) {
            throw new RuntimeException($this->lang('error.row'));
        }
        return $row;
    }

    private function availablePricing($row)
    {
        $packages = $this->callApi($row, 'getpackages');
        $pricing = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !CpguardResellerApi::positiveId($package['id'] ?? null)
                || !is_string($package['name'] ?? null) || !is_array($package['pricing'] ?? null)) {
                throw new RuntimeException($this->lang('error.packages'));
            }
            foreach ($package['pricing'] as $price) {
                if (!is_array($price) || !CpguardResellerApi::positiveId($price['id'] ?? null)
                    || !CpguardResellerApi::sameCycle($price['term'] ?? null, $price['period'] ?? null,
                        $price['term'] ?? null, $price['period'] ?? null)
                    || !is_string($price['currency'] ?? null) || !preg_match('/^[A-Z]{3}$/D', $price['currency'])
                    || !is_numeric($price['price'] ?? null) || (float)$price['price'] < 0) {
                    throw new RuntimeException($this->lang('error.packages'));
                }
                $price['package_name'] = $package['name'];
                $pricing[$price['id']] = $price;
            }
        }
        return $pricing;
    }

    private function findPricing($row, $id)
    {
        $prices = $this->availablePricing($row);
        if (!CpguardResellerApi::positiveId($id) || !isset($prices[$id])) {
            throw new RuntimeException($this->lang('error.pricing'));
        }
        return $prices[$id];
    }

    private function checkCycles($localPricing, array $remotePricing)
    {
        if (!$localPricing) { throw new RuntimeException($this->lang('error.cycle')); }
        foreach ($localPricing as $local) {
            $local = (array)$local;
            if (!CpguardResellerApi::sameCycle($local['term'] ?? '', $local['period'] ?? '',
                $remotePricing['term'], $remotePricing['period'])) {
                throw new RuntimeException($this->lang('error.cycle'));
            }
        }
    }

    private function clientEmail($clientId)
    {
        if (!CpguardResellerApi::positiveId($clientId)) {
            throw new RuntimeException($this->lang('error.email'));
        }
        Loader::loadModels($this, ['Clients']);
        $client = $this->Clients->get($clientId);
        if (!$client || !filter_var($client->email ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException($this->lang('error.email'));
        }
        return $client->email;
    }

    private function license($row, $id)
    {
        if (!CpguardResellerApi::positiveId($id)) {
            throw new RuntimeException($this->lang('error.remote_id'));
        }
        $license = $this->callApi($row, 'getlicense', ['service_id' => $id]);
        CpguardResellerApi::validateLicense($license);
        if ((string)$license['service_id'] !== (string)$id) {
            throw new RuntimeException($this->lang('error.license_response'));
        }
        return $license;
    }

    private function licenseFields(array $license, $email, $rowId)
    {
        return $this->fields([
            'cpguard_service_id' => $license['service_id'],
            'cpguard_license_key' => $license['license_key'],
            'cpguard_pricing_id' => $license['pricing_id'],
            'cpguard_remote_status' => $license['status'],
            'cpguard_client_email' => $email,
            'cpguard_account_row_id' => $rowId,
            'cpguard_invite_link' => CpguardResellerApi::invitationUrl($license['invite_link'] ?? ''),
            'cpguard_apply_command' => 'cpgcli license --key ' . escapeshellarg($license['license_key']),
            'cpguard_key_changed' => 'false'
        ]);
    }

    private function fields(array $values)
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!in_array($key, ['cpguard_service_id', 'cpguard_license_key', 'cpguard_pricing_id',
                'cpguard_remote_status', 'cpguard_client_email', 'cpguard_account_row_id',
                'cpguard_invite_link', 'cpguard_apply_command', 'cpguard_key_changed'], true)) { continue; }
            $result[] = [
                'key' => $key, 'value' => $value,
                'encrypted' => in_array($key, ['cpguard_license_key', 'cpguard_invite_link', 'cpguard_apply_command'], true) ? 1 : 0
            ];
        }
        return $result;
    }

    /** Override only in offline contract tests; credentials and raw responses are never logged. */
    protected function callApi($row, $action, array $data = [])
    {
        $api = $this->apiClient($row);
        $this->logApi($action,
            json_encode(['action' => $action, 'service_id' => $data['service_id'] ?? null]), 'input', true);
        try {
            $response = $api->request($action, $data);
            $this->logApi($action, json_encode(['received' => true]), 'output', true);
            return $response;
        } catch (Exception $e) {
            $this->logApi($action, $e->getMessage(), 'output', false);
            throw $e;
        }
    }

    protected function apiClient($row)
    {
        return new CpguardResellerApi($row->meta->api_key);
    }

    private function logApi($action, $data, $direction, $success)
    {
        try {
            $this->log(CpguardResellerApi::ENDPOINT . $action, $data, $direction, $success);
        } catch (Exception $e) {
            // A logging failure must not discard a successful purchase response and trigger a duplicate purchase.
        }
    }

    protected function staffAccess()
    {
        Loader::loadComponents($this, ['Session']);
        return (bool)$this->Session->read('blesta_staff_id');
    }

    private function render($name, array $data)
    {
        $this->view = new View($name, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'cpguard_reseller' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);
        foreach ($data as $key => $value) { $this->view->set($key, $value); }
        return $this->view->fetch();
    }

    private function error(Exception $e)
    {
        $this->Input->setErrors(['cpguard' => ['api' => $e->getMessage()]]);
    }
    private function lang($key) { return Language::_('CpguardReseller.' . $key, true); }
    private function escape($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}
