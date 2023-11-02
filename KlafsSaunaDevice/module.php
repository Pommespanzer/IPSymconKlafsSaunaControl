<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class KlafsSaunaDevice extends IPSModule
{

    use KlafsSauna\StubsCommonLib;
    use KlafsSaunaLocalLib;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonContruct(__DIR__);
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyBoolean('log_no_parent', true);

        $this->RegisterPropertyString('GUID', ''); // sauna id
        $this->RegisterPropertyString('Name', ''); // sauna name
        $this->RegisterPropertyString('PIN', '');  // PIN code (optional)

        $this->RegisterPropertyInteger('Type', 0);      // product type: sauna, sanarium, infrared
        //$this->RegisterPropertyInteger('Remaining', 0); // remaining bath time
        $this->RegisterPropertyInteger('BathingHours', 0);
        $this->RegisterPropertyInteger('BathingMinutes', 0);
        $this->RegisterPropertyInteger('SelectedHour', 0);   // 0 - 23, vorwahl
        $this->RegisterPropertyInteger('SelectedMinute', 0); // 0 - 59, vorwahl

        $this->RegisterPropertyFloat('CurrentHumidity', 0);
        $this->RegisterPropertyFloat('CurrentTemperature', 0);

        $this->RegisterPropertyBoolean('IsConnected', false);
        $this->RegisterPropertyBoolean('IsPoweredOn', false);
        $this->RegisterPropertyBoolean('IsReadyForUse', false);
        $this->RegisterPropertyBoolean('Power', false); // on off

        // Last error message, normally something about reed door
        $this->RegisterPropertyString('LastError', '');
        $this->RegisterPropertyString('StatusMessage', '');
        $this->RegisterPropertyInteger('StatusCode', 0);

        $this->RegisterPropertyBoolean('InfraredActive', false);
        $this->RegisterPropertyBoolean('SanariumActive', false);
        $this->RegisterPropertyBoolean('SaunaActive', false);

        $this->RegisterPropertyInteger('SelectedHumidityLevel', 0);       // 0 - 10
        $this->RegisterPropertyInteger('SelectedInfraredLevel', 0);       // 1 - 3
        $this->RegisterPropertyInteger('SelectedInfraredTemperature', 0); // guess 41 - 43
        $this->RegisterPropertyInteger('SelectedSanariumTemperature', 0); // max 75
        $this->RegisterPropertyInteger('SelectedSaunaTemperature', 0);    // max 100

        $this->RegisterPropertyInteger('UpdateInterval', 30);
        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        //$this->RequireParent('{F4718E9B-550D-A56B-9A66-BEFB5703EDCC}');
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    private function MaintainStateVariable($ident, $use, $vpos)
    {
        $definitions = [
            'Type'  => [
                'desc'    => 'product type',
                'vartype' => VARIABLETYPE_BOOLEAN,
                'varprof' => 'KlafsSauna.Type',
            ],
            'Power' => [
                'desc'    => 'power state',
                'vartype' => VARIABLETYPE_BOOLEAN,
                'varprof' => 'KlafsSauna.Power',
                //'varprof' => '~Switch',
            ],
        ];

        if (isset($definitions[$ident])) {
            $this->MaintainVariable($ident, $this->Translate($definitions[$ident]['desc']), $definitions[$ident]['vartype'], $definitions[$ident]['varprof'], $vpos, $use);
        }
    }

    private function CheckModuleConfiguration()
    {
        $result = [];

        $guid = $this->ReadPropertyString('GUID');
        if (empty($guid)) {
            $this->SendDebug(__FUNCTION__, '"GUID" is empty', 0);
            $result[] = $this->Translate('A sauna ID (GUID) is required');
        }
        $type = $this->ReadPropertyInteger('Type');
        if (empty($type)) {
            $this->SendDebug(__FUNCTION__, '"Type" is empty', 0);
            $result[] = $this->Translate('A sauna type is required');
        }

        return $result;
    }

    private function CheckModuleUpdate(array $oldInfo, array $newInfo)
    {
        $result = [];

        return $result;
    }


    public function ApplyChanges()
    {
        //Never delete this line!
        parent::ApplyChanges();

        $this->MaintainReferences();

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('UpdateData', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $vPos = 1; // variable position

        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_BOOLEAN, 'KlafsSauna.Power', $vPos++, true);
        $this->MaintainVariable('Mode', $this->Translate('Mode'), VARIABLETYPE_INTEGER, 'KlafsSauna.Mode', $vPos++, true);
        $this->MaintainVariable('IsConnected', $this->Translate('Is connected'), VARIABLETYPE_BOOLEAN, 'KlafsSauna.YesNo', $vPos++, true);
        $this->MaintainVariable('IsPoweredOn', $this->Translate('Powered on'), VARIABLETYPE_BOOLEAN, 'KlafsSauna.YesNo', $vPos++, true);
        $this->MaintainVariable('ReadyForUse', $this->Translate('Ready for use'), VARIABLETYPE_BOOLEAN, 'KlafsSauna.YesNo', $vPos++, true);

        //$this->MaintainVariable('RemainingHours', $this->Translate('Remaining hours'), VARIABLETYPE_INTEGER, 'KlafsSauna.Hour', $vPos++, true);
        //$this->MaintainVariable('RemainingMinutes', $this->Translate('Remaining minutes'), VARIABLETYPE_INTEGER, 'KlafsSauna.Minute', $vPos++, true);

        $this->MaintainVariable('BathingHours', $this->Translate('Bathing hour'), VARIABLETYPE_INTEGER, 'KlafsSauna.Hour', $vPos++, true);       // hour of bathing end
        $this->MaintainVariable('BathingMinutes', $this->Translate('Bathing minute'), VARIABLETYPE_INTEGER, 'KlafsSauna.Minute', $vPos++, true); // minute of bathing end

        $this->MaintainVariable('SelectedHour', $this->Translate('Selected hour'), VARIABLETYPE_INTEGER, 'KlafsSauna.Hour', $vPos++, true);       // hour of bathing start
        $this->MaintainVariable('SelectedMinute', $this->Translate('Selected minute'), VARIABLETYPE_INTEGER, 'KlafsSauna.Minute', $vPos++, true); // minute of bathing start

        $this->MaintainVariable('CurrentTemperature', $this->Translate('Current temperature'), VARIABLETYPE_FLOAT, 'KlafsSauna.Temperature', $vPos++, true);
        $this->MaintainVariable('CurrentHumidity', $this->Translate('Current humidity'), VARIABLETYPE_FLOAT, 'KlafsSauna.Humidity', $vPos++, true);

        $this->MaintainAction("Power", true);
        $this->MaintainAction("Mode", true);

        // create variables based on type
        $type = $this->ReadPropertyInteger('Type');
        switch ($type) {
            case self::$KLAFS_TYPE_SAUNA:
                $this->MaintainVariable('SaunaSelectedTemperature', $this->Translate('Selected Temperature'), VARIABLETYPE_INTEGER, 'KlafsSauna.SaunaTemperature', $vPos++, true);

                $this->MaintainAction('SaunaSelectedTemperature', true);

                // unregister other variables if available
                $this->UnregisterVariable('SanariumSelectedTemperature');
                $this->UnregisterVariable('SanariumSelectedHumidity');
                $this->UnregisterVariable('InfraredSelectedTemperature');
                break;
            case self::$KLAFS_TYPE_SANARIUM:
                $this->MaintainVariable('SaunaSelectedTemperature', $this->Translate('Selected Sauna Temperature'), VARIABLETYPE_INTEGER, 'KlafsSauna.SaunaTemperature', $vPos++, true);
                $this->MaintainVariable('SanariumSelectedTemperature', $this->Translate('Selected Sanarium Temperature'), VARIABLETYPE_INTEGER, 'KlafsSauna.SanariumTemperature', $vPos++, true);
                $this->MaintainVariable('SanariumSelectedHumidity', $this->Translate('Selected Humidity Level'), VARIABLETYPE_INTEGER, 'KlafsSauna.SanariumHumidity', $vPos++, true);

                $this->MaintainAction('SaunaSelectedTemperature', true);
                $this->MaintainAction('SanariumSelectedTemperature', true);
                $this->MaintainAction('SanariumSelectedHumidity', true);

                $this->UnregisterVariable('InfraredSelectedTemperature');
                break;
            case self::$KLAFS_TYPE_INFRARED:
                $this->MaintainVariable('InfraredSelectedTemperature', $this->Translate('Selected Infrared Temperature'), VARIABLETYPE_INTEGER, 'KlafsSauna.SanariumTemperature', $vPos++, true);
                $this->MaintainAction('InfraredSelectedTemperature', true);

                $this->UnregisterVariable('SaunaSelectedTemperature');
                $this->UnregisterVariable('SanariumSelectedTemperature');
                $this->UnregisterVariable('SanariumSelectedHumidity');
                break;
        }


        $this->MaintainVariable('StatusMessage', $this->Translate('Status message'), VARIABLETYPE_STRING, '', $vPos++, true);
        $this->MaintainVariable('LastErrorMessage', $this->Translate('Last error message'), VARIABLETYPE_STRING, '', $vPos++, true);

        $this->MaintainStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Klafs Sauna');

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
                    'type'    => 'Select',
                    'enabled' => true,
                    'name'    => 'Type',
                    'caption' => 'Sauna Type',
                    'options' => $this->SaunaTypeOptions(),
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'enabled' => true,
                    'name'    => 'PIN',
                    'caption' => 'PIN Code',
                ],
            ],
            'caption' => 'Basic configuration',
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'Update interval',
            'items'   => [
                [
                    'name'    => 'UpdateInterval',
                    'type'    => 'NumberSpinner',
                    'minimum' => 5,
                    'suffix'  => 'seconds',
                    'caption' => 'Update interval',
                ],
            ],
        ];

        return $formElements;
    }

    public function GetFormActions()
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
            'caption' => 'Update data',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");',
        ];

        $formActions[] = [
            'type'    => 'Button',
            'caption' => 'Send changes to sauna',
            'onClick' => 'IPS_RequestAction(' . $this->InstanceID . ', "PostConfigChange", "");',
        ];

        $formActions[] = [
            'type'     => 'ExpansionPanel',
            'caption'  => 'Expert area',
            'expanded' => false,
            'items'    => [
                $this->GetInstallVarProfilesFormItem(),
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    public function Send()
    {
        $this->SendDataToParent(json_encode([ 'DataID' => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}' ]));
    }

    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString);
        IPS_LogMessage('Device RECV', utf8_decode($data->Buffer));
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->SetUpdateInterval();
        }
    }

    public function SetUpdateInterval(int $sec = null)
    {
        if (is_null($sec)) {
            $sec = $this->ReadPropertyInteger('UpdateInterval');
        }
        $msec = $sec * 1000;
        $this->MaintainTimer('UpdateData', $msec);
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $result         = false;
        $updateOnServer = false;
        switch ($ident) {
            case 'Power':
                if ($value) {
                    $result = $this->PowerOn();
                } else {
                    $result = $this->PowerOff();
                }
                break;
            case 'Mode':
                $result = $this->SelectMode($value);
                if ($result && !$this->IsPoweredOn()) {
                    $updateOnServer = true;
                }
                break;
            case 'SaunaSelectedTemperature':
            case 'SanariumSelectedTemperature':
            case 'InfraredSelectedTemperature':
                $result = $this->SelectTemperature($ident, $value);
                if ($result) {
                    $updateOnServer = true;
                }
                break;
            case 'SanariumSelectedHumidity':
                $result = $this->SelectHumidity($value);
                if ($result) {
                    $updateOnServer = true;
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident "' . $ident . '"', 0);
        }

        if ($result) {
            $this->SetValue($ident, $value);
        }

        if ($updateOnServer) {
            $this->SendDebug(__FUNCTION__, 'Post config change "' . $ident . '"', 0);
            $this->PostConfigChange();
        }
    }

    private function LocalRequestAction($ident, $value)
    {
        $result = true;
        switch ($ident) {
            case 'UpdateData':
                $this->UpdateSaunaData();
                break;
            case 'PostConfigChange':
                $this->PostConfigChange();
                break;
            default:
                $result = false;
                break;
        }
        return $result;
    }

    /**
     * Get update for sauna from KLAFs backend
     *
     * @return array|void
     */
    private function UpdateSaunaData()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return;
        }

        $this->SetUpdateInterval();

        $guid     = $this->ReadPropertyString('GUID');
        $SendData = [
            'DataID'   => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}',
            'CallerID' => $this->InstanceID,
            'Function' => 'UpdateSauna',
            'ObjectID' => $guid,
            'AsJson'   => true,
        ];
        $result   = $this->SendDataToParent(json_encode($SendData));
        $data     = json_decode($result, true);

        $this->SendDebug(__FUNCTION__, ' => data=' . print_r($data, true), 0);

        $changedData = $this->getOrSetChangedData($data);

        return $changedData;
    }

    /**
     * Updates sauna with new data
     *
     * @return bool
     */
    private function PostConfigChange()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return;
        }

        $data = $this->getOrSetChangedData();

        $this->SendDebug(__FUNCTION__, ' => send data=' . print_r($data, true), 0);

        $SendData = [
            'DataID'   => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}',
            'CallerID' => $this->InstanceID,
            'Function' => 'PostConfigChange',
            'ObjectID' => $data,
            'AsJson'   => true,
        ];
        $result   = $this->SendDataToParent(json_encode($SendData));
        $data     = json_decode($result, true);

        if (isset($data['error'])) {
            $this->SendDebug(__FUNCTION__, ' => error=' . print_r($data, true), 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return false;
        }

        $this->SendDebug(__FUNCTION__, ' => received data=' . print_r($data, true), 0);

        return true;
    }

    public function SendUpdate()
    {
        return $this->PostConfigChange();
    }

    /**
     * Power on sauna
     *
     * @return false|mixed
     */
    public function PowerOn()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return;
        }

        $guid = $this->ReadPropertyString('GUID');
        $pin  = $this->ReadPropertyString('PIN');
        $data = [
            'saunaData'   => [
                'guid' => $guid,
                'pin'  => $pin,
            ],
            'changedData' => $this->getOrSetChangedData(),
        ];
        $this->SendDebug(__FUNCTION__, ' => send data=' . print_r($data, true), 0);

        $SendData = [
            'DataID'   => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}',
            'CallerID' => $this->InstanceID,
            'Function' => 'PowerOn',
            'ObjectID' => $data,
            'AsJson'   => true,
        ];
        $result   = $this->SendDataToParent(json_encode($SendData));
        $data     = json_decode($result, true);

        if (isset($data['error'])) {
            $this->SendDebug(__FUNCTION__, ' => error=' . print_r($data, true), 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return false;
        }

        $this->SendDebug(__FUNCTION__, ' => received data=' . print_r($data, true), 0);

        if (isset($data['isPoweredOn'])) {
            return $data['isPoweredOn'];
        } else {
            if (isset($data['lastErrorMessage'])) {
                $this->SetValue('LastErrorMessage', $data['lastErrorMessage']);
            } else {
                //$this->SetValue('LastErrorMessage', $this->Translate('The sauna could not be turned on.'));
            }
        }

        return false;
    }

    /**
     * Power off sauna
     *
     * @return false|mixed
     */
    public function PowerOff()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent/gateway', 0);
            $log_no_parent = $this->ReadPropertyBoolean('log_no_parent');
            if ($log_no_parent) {
                $this->LogMessage($this->Translate('Instance has no active gateway'), KL_WARNING);
            }
            return;
        }

        $guid     = $this->ReadPropertyString('GUID');
        $SendData = [
            'DataID'   => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}',
            'CallerID' => $this->InstanceID,
            'Function' => 'PowerOff',
            'ObjectID' => $guid,
            'AsJson'   => true,
        ];
        $result   = $this->SendDataToParent(json_encode($SendData));
        $data     = json_decode($result, true);

        $this->SendDebug(__FUNCTION__, ' => received data=' . print_r($data, true), 0);

        $this->UpdateSaunaData();

        if (isset($data['isPoweredOn'])) {
            return $data['isPoweredOn'];
        } else {
            if (isset($data['lastErrorMessage'])) {
                $this->SetValue('LastErrorMessage', $data['lastErrorMessage']);
            } else {
                //$this->SetValue('LastErrorMessage', $this->Translate('The sauna could not be turned off.'));
            }
        }

        return false;
    }

    private function SelectTemperature($ident, $value)
    {
        switch ($ident) {
            case 'SaunaSelectedTemperature':
                if ($value < 10 || $value > 100) {
                    return false;
                }
                break;
            case 'SanariumSelectedTemperature':
                if ($value < 40 || $value > 75) {
                    return false;
                }
                break;
            case 'InfraredSelectedTemperature':
                if ($value < 20 || $value > 40) {
                    return false;
                }
                break;
        }

        return true;
    }

    /**
     * @param $value
     *
     * @return bool
     */
    public function SetSaunaTemperature($value)
    {
        if ($this->SelectTemperature('SaunaSelectedTemperature', $value)) {
            return $this->SetValue('SaunaSelectedTemperature', $value);
            //$this->PostConfigChange();
        }

        return false;
    }

    /**
     * @param $value
     *
     * @return bool
     */
    public function SetSanariumTemperature($value)
    {
        if ($this->SelectTemperature('SanariumSelectedTemperature', $value)) {
            return $this->SetValue('SanariumSelectedTemperature', $value);
            //$this->PostConfigChange();
        }

        return false;
    }

    /**
     * @param $value
     *
     * @return bool
     */
    public function SetInfraredTemperature($value)
    {
        if ($this->SelectTemperature('InfraredSelectedTemperature', $value)) {
            return $this->SetValue('InfraredSelectedTemperature', $value);
            //$this->PostConfigChange();
        }

        return false;
    }

    private function SelectHumidity($value)
    {
        if ($value < 0 || $value > 10) {
            return false;
        }

        return true;
    }

    public function SetSanariumHumidity($value)
    {
        if ($this->SelectHumidity($value)) {
            return $this->SetValue('SanariumSelectedHumidity', $value);
        }

        return false;
    }

    private function SelectMode($value)
    {
        $type = $this->ReadPropertyInteger('Type');

        if ($this->IsPoweredOn()) {
            return false;
        }

        switch ($value) {
            case self::$KLAFS_MODE_SAUNA:
                if (in_array($type, [ self::$KLAFS_TYPE_SAUNA, self::$KLAFS_TYPE_SANARIUM ])) {
                    return true;
                }
                break;
            case self::$KLAFS_MODE_SANARIUM:
                if (in_array($type, [ self::$KLAFS_TYPE_SANARIUM ])) {
                    return true;
                }
                break;
            case self::$KLAFS_MODE_INFRARED:
                if (in_array($type, [ self::$KLAFS_TYPE_INFRARED ])) {
                    return true;
                }
                break;
        }

        return false;
    }

    public function SetMode($value)
    {
        if ($this->SelectMode($value)) {
            $this->SetValue('Mode', $value);
        }
    }

    public function SetStartingTime(int $hour, int $minute)
    {
        if ($hour >= 0 && $hour <= 23) {
            $this->SetValue('SelectedHour', $hour);
        }
        if ($minute >= 0 && $minute <= 59) {
            $this->SetValue('SelectedMinute', $minute);
        }
    }

    public function SetBathingTime(int $hour, int $minute)
    {
        if ($hour >= 0 && $hour <= 23) {
            $this->SetValue('BathingHours', $hour);
        }
        if ($minute >= 0 && $minute <= 59) {
            $this->SetValue('BathingMinutes', $minute);
        }
    }

    /**
     * @return bool
     */
    public function IsPoweredOn()
    {
        $isPower     = $this->GetValue('Power');
        $isPoweredOn = $this->ReadPropertyBoolean('IsPoweredOn');

        return $isPower || $isPoweredOn;
    }

    public function IsConnected()
    {
        return $this->GetValue('IsConnected');
    }

    public function IsReadyForUse()
    {
        return $this->GetValue('ReadyForUse');
    }

    private function getOrSetChangedData(array $setData = [])
    {
        $isSet   = !empty($setData);
        $mapping = [
            'isPoweredOn'                 => [ 'Power', 'IsPoweredOn' ],
            'isConnected'                 => [ 'IsConnected' ],
            'isReadyForUse'               => [ 'ReadyForUse' ],
            'currentTemperature'          => [ 'CurrentTemperature' ],
            'currentHumidity'             => [ 'CurrentHumidity' ],
            'selectedHour'                => [ 'SelectedHour' ],
            'selectedMinute'              => [ 'SelectedMinute' ],
            'bathingHours'                => [ 'BathingHours' ],
            'bathingMinutes'              => [ 'BathingMinutes' ],
            'statusMessage'               => [ 'StatusMessage' ],
            'selectedSaunaTemperature'    => [ 'SaunaSelectedTemperature' ],
            'selectedSanariumTemperature' => [ 'SanariumSelectedTemperature' ],
            'selectedHumLevel'            => [ 'SanariumSelectedHumidity' ],
            'selectedIrTemperature'       => [ 'InfraredSelectedTemperature' ],
        ];
        $result  = [];

        foreach ($mapping as $key => $variableName) {
            if (is_array($variableName)) {
                foreach ($variableName as $variable) {
                    if ($isSet) {
                        @$this->SetValue($variable, $setData[$key]);

                        if ($variable === 'IsPoweredOn') {
                            $this->SetValue('Power', $setData[$key]);
                        }
                    } else {
                        $result[$key] = @$this->GetValue($variable);
                    }
                }
            } else {
                if ($isSet) {
                    @$this->SetValue($variableName, $setData[$key]);
                } else {
                    $result[$key] = @$this->GetValue($variable);
                }
            }
        }

        // get correct changedData based on choosen Mode. Check with the sauna type if the mode is possible
        $type = $this->ReadPropertyInteger('Type');
        $mode = $this->GetValue('Mode');
        if ($isSet) {
            if ($setData['saunaSelected']) {
                $this->SetValue('Mode', self::$KLAFS_MODE_SAUNA);
            }
            if ($setData['sanariumSelected']) {
                $this->SetValue('Mode', self::$KLAFS_MODE_SANARIUM);
            }
            if ($setData['irSelected']) {
                $this->SetValue('Mode', self::$KLAFS_MODE_INFRARED);
            }
        } else {
            switch ($mode) {
                case self::$KLAFS_MODE_SAUNA:
                    if (in_array($type, [ self::$KLAFS_TYPE_SAUNA, self::$KLAFS_TYPE_SANARIUM ])) {
                        $result['saunaSelected']    = true;
                        $result['sanariumSelected'] = false;
                        $result['irSelected']       = false;
                        $result['selectedIrLevel']  = 0;
                    }
                    break;
                case self::$KLAFS_MODE_SANARIUM:
                    if (in_array($type, [ self::$KLAFS_TYPE_SANARIUM ])) {
                        $result['sanariumSelected'] = true;
                        $result['saunaSelected']    = false;
                        $result['irSelected']       = false;
                        $result['selectedIrLevel']  = 0;
                    }
                    break;
                case self::$KLAFS_TYPE_INFRARED:
                    if (in_array($type, [ self::$KLAFS_TYPE_INFRARED ])) {
                        $result['irSelected']       = true;
                        $result['saunaSelected']    = false;
                        $result['sanariumSelected'] = false;
                        $result['selectedIrLevel']  = 1;
                    }
                    break;
            }
        }

        $result['saunaId']                  = $this->ReadPropertyString('GUID');
        $result['currentTemperatureStatus'] = 0;
        $result['currentHumidityStatus']    = 0;
        $result['statusCode']               = 0;
        $result['showBathingHour']          = false;

        return $result;
    }

}