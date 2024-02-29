<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class KlafsSaunaIO extends IPSModule
{

    use KlafsSauna\StubsCommonLib;
    use KlafsSaunaLocalLib;

    private static $apiBaseUrl           = 'https://sauna-app-19.klafs.com';
    private static $apiBasePath          = 'SaunaApp';
    private static $allowedLoginAttempts = 1;
    private static $userAgent            = 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private static $cookieExpire  = 60 * 60 * 24 * 2;
    private static $semaphoreTime = 5 * 1000;

    private $SemaphoreID;

    /**
     * @param string $InstanceID
     */
    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonContruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        // form fields
        $this->RegisterPropertyBoolean('module_disable', false);
        $this->RegisterPropertyString('username', '');
        $this->RegisterPropertyString('password', '');

        // internal fields

        // KLAFS locks the user account after 3 login attempts.
        $this->RegisterAttributeInteger('LoginFailures', 0); // Lock auto login if value > 0

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ApiCallStats', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->SetBuffer('LastApiCall', 0);

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    private function CheckModuleConfiguration()
    {
        $result = [];

        $username = $this->ReadPropertyString('username');
        $password = $this->ReadPropertyString('password');

        if (empty($username)) {
            $this->SendDebug(__FUNCTION__, '"username" is needed', 0);
            $r[] = $this->Translate('Username must be specified');
        }
        if (empty($password)) {
            $this->SendDebug(__FUNCTION__, '"password" is needed', 0);
            $r[] = $this->Translate('Password must be specified');
        }

        return $result;
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $loginFailures = $this->ReadAttributeInteger('LoginFailures');
        if ($loginFailures > 0) {
            $this->SendDebug(__FUNCTION__, 'Login Failures: ' . $loginFailures, 0);
            $this->MaintainStatus(self::$IS_INVALIDDATA);
            return;
        }

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
        }
    }

    /**
     * This function is called by children modules via function SendDataToParent()
     *
     * @param $data
     *
     * @return array|false|string
     */
    public function ForwardData($data)
    {
        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        $data = json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'data=' . print_r($data, true), 0);

        $callerId = $data['CallerID'];
        $this->SendDebug(__FUNCTION__, 'caller=' . $callerId . '(' . IPS_GetName($callerId) . ')', 0);
        $_IPS['CallerID'] = $callerId;

        $objectId = null;
        if (isset($data['ObjectID'])) {
            $objectId = $data['ObjectID'];
        }

        $result = '';
        if (isset($data['Function'])) {
            switch ($data['Function']) {
                case 'GetSaunas':
                    $result = $this->GetSaunas();
                    break;
                case 'UpdateSauna':
                    $result = $this->UpdateSauna($objectId);
                    break;
                case 'PostConfigChange':
                    $result = $this->PostConfigChange($objectId);
                    break;
                case 'PowerOn':
                    $result = $this->PowerOn($objectId);
                    break;
                case 'PowerOff':
                    $result = $this->PowerOff($objectId);
                    break;
            }
        } else {
            $this->SendDebug(__FUNCTION__, 'unknown message-structure', 0);
        }

        $this->SendDebug(__FUNCTION__, 'result=' . print_r($result, true), 0);

        if (isset($data['AsJson']) && $data['AsJson']) {
            return json_encode($result);
        }

        return $result;
    }

    public function Send(string $Text)
    {
        $this->SendDataToChildren(json_encode([ 'DataID' => '{6156AE64-CA7A-49E1-AED4-35B31DA50CAF}', 'Buffer' => $Text ]));
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Klafs Sauna Control');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'Label',
                    'caption' => 'Log in with your KLAFS sauna app account: https://sauna-app.klafs.com',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Important: Please ensure you enter the correct credentials.\nKLAFS blocks the user account after 3 failed login attempts.\nReset your password if you forget it.',
                ],
                [
                    'name'    => 'username',
                    'type'    => 'ValidationTextBox',
                    'caption' => 'Username',
                ],
                [
                    'name'    => 'password',
                    'type'    => 'PasswordTextBox',
                    'caption' => 'Password',
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'This module disables automatic login after the first failed login attempt.\nYou then have to reactivate automatic login using the “Reset login attempts” button.',
                ],
            ],
            'caption' => 'Account data',
        ];

        return $formElements;
    }

    private function getFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Test access',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "TestAccess", "");',
        ];
        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Reset Login Failures',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ResetLoginFailures", "");',
        ];

        $formActions[] = [
            'type'     => 'ExpansionPanel',
            'caption'  => 'Expert area',
            'expanded' => false,
            'items'    => [
                $this->GetInstallVarProfilesFormItem(),
                [
                    'type'    => 'Button',
                    'label'   => 'Display token',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "DisplayToken", "");',
                ],
                [
                    'type'    => 'Button',
                    'label'   => 'Clear token',
                    'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "ClearToken", "");',
                ],
                $this->GetApiCallStatsFormItem(),
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;
        switch ($ident) {
            case 'ClearToken':
                $this->ClearToken();
                break;
            case 'DisplayToken':
                $this->DisplayToken();
                break;
            case 'TestAccess':
                $this->TestAccess();
                break;
            case 'ResetLoginFailures':
                $this->ResetLoginFailures();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

    /**
     * Test if login works. If not, lock login until ResetLoginFailures is called.
     *
     * @return void
     */
    private function TestAccess()
    {
        // if instance is inactive, dont offer this function
        if (in_array($this->GetStatus(), [ IS_INACTIVE ], true)) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            $this->PopupMessage($this->GetStatusText());

            return;
        }

        // check for login failures before we start testing the login
        $loginFailures = $this->ReadAttributeInteger('LoginFailures');
        if ($loginFailures > 0) {
            $debugMessage = 'Too many login failures: ' . $loginFailures;
            $popupMessage = [
                $debugMessage,
                '',
                'Please ensure logged in successfully into the app or sauna web app or reset your password.',
                'After 3 unsuccessful login attempts, klafs suspends your account.',
                'Click the button "RESET LOGIN FAILURES" after you have entered the correct login credentials to unlock the automatic login.',
            ];

            $this->SendDebug(__FUNCTION__, $debugMessage, 0);
            $this->PopupMessage(implode(PHP_EOL, $popupMessage));

            return;
        }

        // if username/password is wrong, inform the user
        $status = $this->GetStatus();
        if ($status === self::$IS_INVALIDDATA) {
            $this->SendDebug(__FUNCTION__, ' => statuscode=' . $status, 0);
            $popupMessage = [
                'Username/Password is incorrect!',
                '',
                'The login feature is locked until you have ensured that you have entered your correct login information.',
                'Please note that KLAFS will block your account after 3 failed login attempts.',
                'Click on the “RESET LOGIN FAILURES” button to reactivate the automatic login.',
            ];
            $this->PopupMessage(implode(PHP_EOL, $popupMessage));

            $this->MaintainStatus(self::$IS_INVALIDDATA);
            return false;
        }

        $saunas = $this->GetSaunas();

        $popupMessage = [
            'Login successful.',
            sprintf('Saunas found: %d', count($saunas)),
            print_r($saunas, true),
        ];

        $this->SendDebug(__FUNCTION__, 'txt=' . print_r($popupMessage, true), 0);
        $this->PopupMessage(implode(PHP_EOL, $popupMessage));
    }

    /**
     * Clear cookie
     *
     * @return void
     */
    private function ClearToken()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTime) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }
        $this->SetBuffer('AccessCookie', '');
        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    private function DisplayToken()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTime) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return false;
        }

        $accessCookie = $this->GetBuffer('AccessCookie');
        if (!empty($accessCookie)) {
            $cookieData = json_decode($accessCookie, true);
            $cookie     = $cookieData['cookie'];
            $expiration = $cookieData['expiration'];

            $popupMessage = [
                sprintf('Cookie: %s', $cookie),
                sprintf('Expires at: %s', date('Y-m-d H:i:s', $expiration)),
            ];

            $this->PopupMessage(implode(PHP_EOL, $popupMessage));
        } else {
            $this->PopupMessage('No cookie data available.');
        }
    }

    /**
     * @return void
     */
    private function ResetLoginFailures()
    {
        $this->WriteAttributeInteger('LoginFailures', 0);

        return true;
    }

    /**
     * Login to get access cookie
     *
     * @return false|void
     */
    private function GetAccessCookie()
    {
        $username     = $this->ReadPropertyString('username');
        $password     = $this->ReadPropertyString('password');
        $accessCookie = $this->GetBuffer('AccessCookie');

        if (!empty($accessCookie)) {
            $cookieData = json_decode($accessCookie, true);
            $cookie     = $cookieData['cookie'];
            $expiration = $cookieData['expiration'];

            if ($expiration > time()) { // expired
                return $cookie;
            }

            $this->SetBuffer('AccessCookie', '');
        }

        $data    = [
            'UserName'   => $username,
            'Password'   => $password,
            'RememberMe' => 'false',
        ];
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7r',
            'Accept-Encoding: gzip, deflate, br',
            'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
            self::$userAgent,
        ];

        $response = $this->CallHttpRequest('/Account/Login', 'POST', [], $headers, $data);
        $errors   = $this->ValidateResponse($response);
        if (!empty($errors)) {
            return;
        }

        $cookie = '';
        if (preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $response, $matches)) {
            foreach ($matches[1] as $item) {
                if (is_array($item)) {
                    $cookie = (string) $item[0];
                } else {
                    $cookie = (string) $item;
                }
            }

            if (!empty($cookie)) {
                $cookieExpire = time() + self::$cookieExpire;
                $cookieData   = json_encode([
                    'cookie'     => $cookie,
                    'expiration' => $cookieExpire,
                ]);

                $this->SetBuffer('AccessCookie', $cookieData);
            }
        }
        $this->SendDebug(__FUNCTION__, ' => cookie=' . print_r($cookie, true), 0);

        return $cookie;
    }

    /**
     * Performs the API call to klafs backend
     *
     * @param string $resource
     * @param string $method
     * @param array  $data
     * @param array  $params url params
     * @param array  $headers
     *
     * @return bool|string
     */
    private function CallApi(string $resource, string $method, $data = [], $params = [], $headers = [])
    {
        if (empty($headers)) {
            $cookie  = $this->GetAccessCookie();
            $headers = [
                'Content-Type: application/json',
                //'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                'Accept: application/json, text/javascript, */*; q=0.01',
                'Accept-Encoding: gzip, deflate, br',
                'Accept-Language: de-DE,de;q=0.9,en-US;q=0.8,en;q=0.7',
                self::$userAgent,
                sprintf('Cookie: %s', $cookie),
            ];
        }

        return $this->CallHttpRequest($resource, $method, $params, $headers, $data);
    }

    /**
     * @param string $resource
     * @param string $method
     * @param array  $params
     * @param array  $headers
     * @param mixed  $data
     *
     * @return bool|string
     */
    private function CallHttpRequest(string $resource, string $method, array $params = [], array $headers = [], $data = [])
    {
        $url   = self::$apiBaseUrl . $resource;
        $query = '';
        if (!empty($params)) {
            $queryParams = [];
            foreach ($params as $key => $value) {
                $queryParams[] = urlencode($key) . '=' . urlencode($value);
            }

            $query = implode('&', $queryParams);
            if (!empty($query)) {
                $url = $url . '?' . $query;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_VERBOSE, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (!empty($data) && is_array($data)) {
            $data = http_build_query($data);
        }

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                break;
        }

        $this->SendDebug(__FUNCTION__, 'http-' . $method . ': url=' . $url, 0);
        $this->SendDebug(__FUNCTION__, 'params=' . $query, 0);
        $this->SendDebug(__FUNCTION__, 'header=' . print_r($headers, true), 0);
        if (!empty($data)) {
            $this->SendDebug(__FUNCTION__, '    postdata=' . $data, 0);
        }

        $timeStart = microtime(true);

        $response  = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlError = $curlErrNo ? curl_error($ch) : '';
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        $duration = round(microtime(true) - $timeStart, 2);

        $this->SendDebug(__FUNCTION__, ' => errno=' . $curlErrNo . ', httpcode=' . $httpCode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => cerror=' . $curlError, 0);
        $this->SendDebug(__FUNCTION__, ' => cdata=' . $response, 0);

        $this->ApiCallsCollect($url, $curlError, $httpCode);

        return $response;
    }

    /**
     * Validate the KLAFS server response and set status if an error message is found
     *
     * @param $response
     *
     * @return bool
     */
    private function ValidateResponse($response)
    {
        $result   = [];
        $response = (string) $response;

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true); // Um Warnungen zu vermeiden, wenn das HTML nicht wohlgeformt ist
        $doc->loadHTML($response, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath  = new \DOMXPath($doc);
        $errors = $xpath->query('//div[@class="validation-summary-errors"]/ul/li');

        if ($errors->length > 0) {
            $this->SendDebug(__FUNCTION__, ' => errors=' . print_r($errors, true), 0);
        }

        if (preg_match('/"Success":true/', $response)) {
            preg_match('/ErrorMessage":"?(.*)"/', $response, $errorMessage);
            $errorMessage = $errorMessage[1] ?: 'Unknown error';

            $this->SendDebug(__FUNCTION__, ' => errors success false=' . print_r($errorMessage, true), 0);
        }

        foreach ($errors as $error) {
            $errorMessage = html_entity_decode($error->nodeValue);
            $result[]     = $errorMessage;
            $this->SendDebug(__FUNCTION__, ' => error=' . $errorMessage, 0);
        }

        return $result;
    }

    /**
     * Get a list of all saunas with names and GUIDs
     *
     * @return array
     */
    private function GetSaunas()
    {
        $response = $this->CallApi('/' . self::$apiBasePath . '/ChangeSettings', 'GET');
        $this->SendDebug(__FUNCTION__, print_r($response, true) . ' => GetSaunas', 0);

        $dom = new \DOMDocument();
        @$dom->loadHTML($response);
        $xpath = new \DOMXPath($dom);

        $rows   = $xpath->query('//tr[contains(@class, "iw-sauna-webgrid-row-style")]');
        $saunas = [];
        foreach ($rows as $row) {
            $saunaIdNode    = $xpath->query('//label[contains(@id, "lblsaunaId")]', $row);
            $saunaLabelNode = $xpath->query('//span[contains(@id, "lbldeviceName")]', $row);
            $saunaId        = $saunaIdNode->item(0)->nodeValue;
            $saunaLabel     = $saunaLabelNode->item(0)->nodeValue;
            $guid           = null;

            preg_match('/\w{8}-\w{4}-\w{4}-\w{4}-\w{12}/', $saunaId, $guid);
            if (is_array($guid)) {
                $guid = $guid[0];
            }

            $saunas[] = [
                'guid' => $guid,
                'name' => $saunaLabel,
            ];
        }

        $this->SendDebug(__FUNCTION__, print_r($saunas, true) . ' => GetSaunas', 0);

        return $saunas;
    }

    /**
     * @param string $saunaId
     *
     * @return array
     */
    private function UpdateSauna(string $saunaId)
    {
        $this->SendDebug(__FUNCTION__, 'update sauna=' . $saunaId, 0);

        $headers  = [];
        $jsonData = json_encode([ 'saunaId' => $saunaId ]);
        $response = $this->CallApi('/' . self::$apiBasePath . '/GetData?id=' . $saunaId, 'GET', $jsonData, [], $headers);

        // TODO for the future:
        // do a second request to get detailed information about the sauna to prefill information like type for the configurator later

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'sauna=' . print_r($data, true), 0);

        return $data;
    }

    /**
     *
     *
     * @param $data
     *
     * @return mixed
     */
    private function _PostConfigChange($data)
    {
        $changedData = [
            'changedData' => $data,
        ];

        $this->SendDebug(__FUNCTION__, 'post config change=' . print_r($changedData, true), 0);

        $jsonData = json_encode($changedData);
        $response = $this->CallApi('/' . self::$apiBasePath . '/PostConfigChange', 'POST', $jsonData);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'PostConfigChange response=' . print_r($data, true), 0);

        return $data;
    }

    /**
     * @param $data
     *
     * @return mixed
     */
    private function PostConfigChange($data)
    {
        $this->SendDebug(__FUNCTION__, 'PostConfigChange=' . print_r($data, true), 0);

        $this->SetMode($data);
        $this->ChangeTemperature($data);
        $this->ChangeHumidityLevel($data);
        $this->SetSelectedTime($data);
    }

    /**
     * @param array $data
     *
     * @return mixed|void
     */
    private function SetMode($data)
    {
        if ($data['saunaSelected']) {
            $mode = self::$KLAFS_MODE_SAUNA;
        } elseif ($data['sanariumSelected']) {
            $mode = self::$KLAFS_MODE_SANARIUM;
        } elseif ($data['irSelected']) {
            $mode = self::$KLAFS_MODE_INFRARED;
        } else {
            $this->SendDebug(__FUNCTION__, 'no mode selected => skip changing mode', 0);
            return;
        }

        $postData = [ 'id' => $data['saunaId'], 'selected_mode' => $mode ];
        $jsonData = json_encode($postData);
        $response = $this->CallApi('/' . self::$apiBasePath . '/SetMode', 'POST', $jsonData);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'SetMode response=' . print_r($data, true), 0);

        return $data;
    }

    /**
     * @param array $data
     *
     * @return mixed|void
     */
    private function ChangeTemperature($data)
    {
        if ($data['saunaSelected']) {
            $temperature = $data['selectedSaunaTemperature'];
        } elseif ($data['sanariumSelected']) {
            $temperature = $data['selectedSanariumTemperature'];
        } elseif ($data['irSelected']) {
            $temperature = $data['selectedIrTemperature'];
        } else {
            $this->SendDebug(__FUNCTION__, 'no mode selected => skip changing temperature', 0);
            return;
        }

        $postData = [ 'id' => $data['saunaId'], 'temperature' => $temperature ];
        $jsonData = json_encode($postData);
        $response = $this->CallApi('/' . self::$apiBasePath . '/ChangeTemperature', 'POST', $jsonData);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'ChangeTemperature response=' . print_r($data, true), 0);

        return $data;
    }

    /**
     * @param array $data
     *
     * @return mixed|void
     */
    private function ChangeHumidityLevel($data)
    {
        if (!$data['sanariumSelected']) {
            $this->SendDebug(__FUNCTION__, 'sanarium mode is not selected => skip changing humidity level', 0);
            return;
        }

        $postData = [ 'id' => $data['saunaId'], 'level' => $data['selectedHumLevel'] ];
        $jsonData = json_encode($postData);
        $response = $this->CallApi('/' . self::$apiBasePath . '/ChangeHumLevel', 'POST', $jsonData);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'ChangeHumidityLevel response=' . print_r($data, true), 0);

        return $data;
    }

    /**
     * @param array $data
     *
     * @return mixed|void
     */
    private function SetSelectedTime($data)
    {
        $hour = $data['selectedHour'];
        $min = $data['selectedMinute'];
        if ($hour <0 || $hour >23 || $min <0 || $min >59){
            $this->SendDebug(__FUNCTION__, 'invalid time format=' . $hour . ':' . $min . ' => skip set time', 0);
            return;
        }

        $postData = [ 'id' => $data['saunaId'], 'time_set' => true, 'hours' => $hour, 'minutes' => $min ];
        $jsonData = json_encode($postData);
        $response = $this->CallApi('/' . self::$apiBasePath . '/SetSelectedTime', 'POST', $jsonData);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'SetSelectedTime response=' . print_r($data, true), 0);

        return $data;
    }

    /**
     * @param $data
     *
     * @return string
     */
    private function PowerOn($data)
    {
        $this->SendDebug(__FUNCTION__, 'PowerOn=' . print_r($data, true), 0);

        $changedData = $data['changedData'];
        $saunaGuid   = $data['saunaData']['guid'];
        $saunaPin    = $data['saunaData']['pin'];
        if (empty($saunaGuid)) {
            $this->SendDebug(__FUNCTION__, 'no sauna guid given', 0);

            return $data['error'] = $this->Translate('No sauna GUID given.');
        }
        if (empty($saunaPin)) {
            $this->SendDebug(__FUNCTION__, 'no sauna pin given', 0);

            return $data['error'] = $this->Translate('No sauna PIN given.');
        }

        $this->SendDebug(__FUNCTION__, 'SaunaID=' . $saunaGuid, 0);
        $this->SendDebug(__FUNCTION__, 'PIN=' . $saunaPin, 0);

        // if sauna is not powered on already, send command with pin
        $poweredOn = (bool) $changedData['isPoweredOn'];
        if ($poweredOn) {
            $this->SendDebug(__FUNCTION__, 'Sauna is already powered on => skip powering on request', 0);
            return $changedData;
        }

        $postData = json_encode([
            'id'            => $saunaGuid,
            'pin'           => $saunaPin,
            'time_selected' => $changedData['timeSelected'],
            'sel_hour'      => $changedData['selectedHour'],
            'sel_min'       => $changedData['selectedMinute'],
        ]);

        $response = $this->CallApi('/' . self::$apiBasePath . '/StartCabin', 'POST', $postData);
        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $responseData = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, ' => StartCabin Send POST data=' . print_r($postData, true), 0);
        $this->SendDebug(__FUNCTION__, 'StartCabin response=' . print_r($responseData, true), 0);

        $errors = $this->ValidateResponse($response);
        if (!empty($errors)) {
            $data['lastErrorMessage'] = implode(' ', $errors);
            $data['isPoweredOn']      = false;
        } else {
            $data['isPoweredOn'] = true;
        }

        return $data;
    }

    /**
     * @param string $saunaId
     *
     * @return void
     */
    private function PowerOff(string $saunaId)
    {
        $data = json_encode([
            'changedData' => [
                'saunaId' => $saunaId,
            ],
        ]);

        $response = $this->CallApi('/' . self::$apiBasePath . '/StopCabin', 'POST', $data);

        list($header, $body) = explode("\r\n\r\n", $response, 2);
        $data = json_decode($body, true);

        $this->SendDebug(__FUNCTION__, 'StopCabin response=' . print_r($data, true), 0);

        return $data;
    }
}