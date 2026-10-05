<?php

/** HTTPS client for the documented OPSSHIELD reseller API. No automatic retries. */
class CpguardResellerApi
{
    const ENDPOINT = 'https://manage.opsshield.com/plugin/reseller_api/cpguard/';
    private $apiKey;
    private $transport;

    public function __construct($apiKey, $transport = null)
    {
        if (!is_string($apiKey) || trim($apiKey) === '') {
            throw new InvalidArgumentException('A reseller API key is required.');
        }
        $this->apiKey = trim($apiKey);
        $this->transport = $transport;
    }

    public function request($action, array $data = [])
    {
        $allowed = ['accountdetails', 'getpackages', 'addlicense',
            'getlicense', 'invitationlink', 'reissuelicense', 'changepackage',
            'suspendlicense', 'unsuspendlicense', 'cancellicense'];
        if (!in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported reseller API action.');
        }
        $data['apikey'] = $this->apiKey;
        if ($this->transport !== null) {
            $result = call_user_func($this->transport, self::ENDPOINT . $action, $data);
        } else {
            $result = $this->send(self::ENDPOINT . $action, $data);
        }
        $body = json_decode($result['body'], true, 64);
        if ((int)$result['status'] !== 200 || !is_array($body) || array_key_exists('error', $body)) {
            // Do not expose raw upstream responses (they may contain credentials or invitation tokens).
            throw new RuntimeException('OPSSHIELD API request failed (HTTP ' . (int)$result['status']
                . '). Check the reseller account and reconcile any provisioning attempt before retrying.');
        }
        if ($action === 'accountdetails') {
            foreach (['credit', 'due', 'services'] as $field) {
                if (!is_array($body[$field] ?? null)
                    || count(array_filter($body[$field], 'is_numeric')) !== count($body[$field])) {
                    throw new RuntimeException('OPSSHIELD returned invalid account details.');
                }
            }
        }
        return $body;
    }

    private function send($url, array $data)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL is required by the cPGuard reseller module.');
        }
        $curl = curl_init($url);
        $body = '';
        $tooLarge = false;
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data, '', '&'),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_USERAGENT => 'cPGuardBlesta/1.0.0',
            // Bound memory usage if a proxy or upstream server returns an unexpectedly large body.
            CURLOPT_WRITEFUNCTION => function ($handle, $chunk) use (&$body, &$tooLarge) {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        $sent = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curl);
        curl_close($curl);
        if ($tooLarge) {
            throw new RuntimeException('OPSSHIELD response exceeded the size limit. Reconcile the remote action before retrying.');
        }
        if ($sent === false || $errno !== 0) {
            throw new RuntimeException('OPSSHIELD connection failed (cURL ' . $errno
                . '). The remote action may have completed. Check OPSSHIELD before retrying.');
        }
        return ['status' => $status, 'body' => $body];
    }

    public static function requireSuccess(array $response, $serviceId)
    {
        if (!self::positiveId($response['service_id'] ?? null) || ($response['status'] ?? null) !== true
            || (string)($response['service_id'] ?? '') !== (string)$serviceId) {
            throw new RuntimeException('OPSSHIELD did not confirm the license action.');
        }
    }

    public static function createdLicense(array $response, $pricingId)
    {
        $services = $response['services'] ?? [];
        if (!is_array($services) || count($services) !== 1 || !isset($services[0]) || !is_array($services[0])) {
            throw new RuntimeException('Expected exactly one created license. Reconcile the remote order before retrying.');
        }
        $license = $services[0];
        self::validateLicense($license);
        if ($license['status'] !== 'active' || (string)$license['pricing_id'] !== (string)$pricingId) {
            throw new RuntimeException('The created license response is incomplete. Reconcile the remote order before retrying.');
        }
        $license['invite_link'] = self::invitationUrl($response['invite_link'] ?? $response['Invite_link'] ?? '');
        return $license;
    }

    public static function positiveId($value)
    {
        return (is_string($value) || is_int($value)) && ctype_digit((string)$value) && (int)$value > 0;
    }

    public static function validateLicense(array $license)
    {
        if (!self::positiveId($license['service_id'] ?? null)
            || !self::positiveId($license['pricing_id'] ?? null)
            || !is_string($license['license_key'] ?? null) || trim($license['license_key']) === ''
            || strpos($license['license_key'], "\0") !== false
            || !in_array($license['status'] ?? null, ['active', 'suspended', 'canceled'], true)) {
            throw new RuntimeException('OPSSHIELD returned an invalid license. Reconcile any remote order before retrying.');
        }
        foreach (['package_name', 'date_renews'] as $field) {
            if (isset($license[$field]) && !is_string($license[$field])) {
                throw new RuntimeException('OPSSHIELD returned invalid license details.');
            }
        }
        foreach (['ips', 'domains'] as $field) {
            if (isset($license[$field]) && (!is_array($license[$field])
                || count(array_filter($license[$field], 'is_string')) !== count($license[$field]))) {
                throw new RuntimeException('OPSSHIELD returned invalid server bindings.');
            }
        }
        if (isset($license['reissue']) && !is_bool($license['reissue'])) {
            throw new RuntimeException('OPSSHIELD returned an invalid reissue state.');
        }
    }

    public static function invitationUrl($url)
    {
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f]/', $url) || strpos($url, '\\') !== false) {
            return '';
        }
        $parts = parse_url($url);
        return is_array($parts) && ($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') === 'manage.opsshield.com'
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === 443) ? $url : '';
    }

    /** Validate the upstream billing cycle independently of Blesta retail billing. */
    public static function validCycle($term, $period)
    {
        return self::positiveId($term) && (int)$term <= 10000
            && in_array($period, ['day', 'week', 'month', 'year'], true);
    }
}
