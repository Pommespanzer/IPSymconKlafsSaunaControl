<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class KlafsSaunaConfigurator extends IPSModule
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

        $this->RegisterPropertyInteger('ImportCategoryID', 0);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));
        $this->RegisterAttributeString('DataCache', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->ConnectParent('{F4718E9B-550D-A56B-9A66-BEFB5703EDCC}');

        $this->RegisterTimer('UpdateData', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "UpdateData", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function Destroy()
    {
        //Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [ 'ImportCategoryID' ];
        $this->MaintainReferences($propertyNames);

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

        $this->SetupDataCache(24 * 60 * 60);

        $this->MaintainStatus(IS_ACTIVE);
    }

    private function getConfiguratorValues()
    {
        $entries = [];

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return $entries;
        }

        if ($this->HasActiveParent() == false) {
            $this->SendDebug(__FUNCTION__, 'has no active parent', 0);
            return $entries;
        }

        // Get all saunas from KLAFS backend
        $categoryId = $this->ReadPropertyInteger('ImportCategoryID');
        $dataCache  = $this->ReadDataCache();
        if (isset($dataCache['data']['saunas'])) {
            $saunas = $dataCache['data']['saunas'];
            $this->SendDebug(__FUNCTION__, 'saunas (from cache)=' . print_r($saunas, true), 0);
        } else {
            $sendData = [
                'DataID'   => '{2F696B68-5663-D109-1E2F-2772B3D0A9C0}', // an KlafsSaunaIO module.json -> implemented
                'CallerID' => $this->InstanceID,
                'Function' => 'GetSaunas',
                'AsJson'   => true,
            ];
            $data     = $this->SendDataToParent(json_encode($sendData)); // ParentModule::ForwardData()
            $saunas   = @json_decode($data, true);

            $this->SendDebug(__FUNCTION__, 'saunas=' . print_r($saunas, true), 0);

            if (is_array($saunas)) {
                $dataCache['data']['saunas'] = $saunas;
            }
            $this->WriteDataCache($dataCache, time());
        }

        $deviceGuid  = '{1352C505-3C65-587D-B88D-15DA23EA54D5}'; // KlafsSaunaDevice
        $instanceIds = IPS_GetInstanceListByModuleID($deviceGuid);

        if (is_array($saunas)) {
            foreach ($saunas as $sauna) {
                $this->SendDebug(__FUNCTION__, 'sauna=' . print_r($sauna, true), 0);

                $saunaGuid = $sauna['guid'];
                $saunaName = $sauna['name'];

                $myInstanceId   = 0;
                $myInstanceName = '';
                foreach ($instanceIds as $instanceId) {
                    if ($saunaGuid === IPS_GetProperty($instanceId, 'GUID')) {
                        $this->SendDebug(__FUNCTION__, 'sauna found: ' . IPS_GetName($instanceId) . ' (' . $instanceId . ')', 0);

                        $myInstanceId   = $instanceId;
                        $myInstanceName = IPS_GetName($instanceId);
                        break;
                    }

                    // check if instance has same IO
                    if ($instanceId && IPS_GetInstance($instanceId)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                        continue;
                    }
                }

                $entries[] = [
                    'instanceID'   => $myInstanceId,
                    'instanceName' => $myInstanceName,
                    'GUID'         => $saunaGuid,
                    'name'         => $saunaName,
                    'create'       => [
                        'moduleID'      => $deviceGuid,
                        'location'      => $this->GetConfiguratorLocation($categoryId),
                        'info'          => $saunaName,
                        'configuration' => [
                            'GUID' => $saunaGuid,
                            'Type' => self::$KLAFS_TYPE_UNKNOWN,
                        ],
                    ]
                ];
            }
        }

        foreach ($instanceIds as $instanceId) {
            $found = false;
            foreach ($entries as $entry) {
                if ($entry['instanceID'] === $instanceId) {
                    $found = true;
                    break;
                }
            }

            if ($found) {
                continue;
            }

            if (IPS_GetInstance($instanceId)['ConnectionID'] != IPS_GetInstance($this->InstanceID)['ConnectionID']) {
                continue;
            }

            $instanceName = IPS_GetName($instanceId);
            $saunaGuid    = IPS_GetProperty($instanceId, 'GUID');
            $saunaName    = IPS_GetProperty($instanceId, 'Name');

            $entry = [
                'instanceID'   => $instanceId,
                'instanceName' => $instanceName,
                'GUID'         => $saunaGuid,
                'Name'         => $saunaName,
            ];

            $entries[] = $entry;
            $this->SendDebug(__FUNCTION__, 'missing entry=' . print_r($entry, true), 0);
        }

        return $entries;
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('Klafs Sauna Configurator');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'SelectCategory',
            'name'    => 'ImportCategoryID',
            'caption' => 'Category for KLAFS saunas to be created'
        ];

        $entries        = $this->getConfiguratorValues();
        $formElements[] = [
            'name'              => 'Klafs Sauna Configurator',
            'type'              => 'Configurator',
            'rowCount'          => count($entries),
            'add'               => false,
            'delete'            => false,
            'sort'              => [
                'column'    => 'name',
                'direction' => 'ascending'
            ],
            'columns'           => [
                [
                    'caption' => 'GUID',
                    'name'    => 'GUID',
                    'width'   => '200px',
                    'visible' => false
                ],
                [
                    'caption' => 'Name',
                    'name'    => 'name',
                    'width'   => 'auto'
                ],
            ],
            'values'            => $entries,
            'discoveryInterval' => 60 * 60 * 24,
        ];
        $formElements[] = $this->GetRefreshDataCacheFormAction();

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }
        switch ($ident) {
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
    }

}