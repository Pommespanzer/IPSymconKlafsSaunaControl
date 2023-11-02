<?php

declare(strict_types=1);

trait KlafsSaunaLocalLib
{

    public static $IS_UNAUTHORIZED = IS_EBASE + 10;
    public static $IS_SERVERERROR  = IS_EBASE + 11;
    public static $IS_HTTPERROR    = IS_EBASE + 12;
    public static $IS_INVALIDDATA  = IS_EBASE + 13;
    public static $IS_NOLOGIN      = IS_EBASE + 14;
    public static $IS_NODATA       = IS_EBASE + 15;

    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        $formStatus[] = [ 'code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (Not unauthorized)' ];
        $formStatus[] = [ 'code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (Server error)' ];
        $formStatus[] = [ 'code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (HTTP error)' ];
        $formStatus[] = [ 'code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (Invalid data)' ];
        $formStatus[] = [ 'code' => self::$IS_NOLOGIN, 'icon' => 'error', 'caption' => 'Instance is inactive (Not logged in)' ];
        $formStatus[] = [ 'code' => self::$IS_NODATA, 'icon' => 'error', 'caption' => 'Instance is inactive (No data)' ];

        return $formStatus;
    }

    public static $STATUS_INVALID   = 0;
    public static $STATUS_VALID     = 1;
    public static $STATUS_RETRYABLE = 2;
    public static $STATUS_LOCKED    = 3;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            case self::$IS_NODATA:
            case self::$IS_UNAUTHORIZED:
            case self::$IS_SERVERERROR:
            case self::$IS_HTTPERROR:
                $class = self::$STATUS_RETRYABLE;
                break;
            case self::$IS_INVALIDDATA:
                $class = self::$STATUS_LOCKED;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    private static $KLAFS_TYPE_UNKNOWN  = 0;
    private static $KLAFS_TYPE_SAUNA    = 1;
    private static $KLAFS_TYPE_SANARIUM = 2;
    private static $KLAFS_TYPE_INFRARED = 3;

    private static $KLAFS_MODE_SAUNA    = 1;
    private static $KLAFS_MODE_SANARIUM = 2;
    private static $KLAFS_MODE_INFRARED = 3;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        // Switch / Power On/Off
        $associations = [
            [ 'Wert' => false, 'Name' => $this->Translate('Off'), 'Farbe' => -1 ],
            [ 'Wert' => true, 'Name' => $this->Translate('On'), 'Farbe' => -1 ],
        ];
        $this->CreateVarProfile('KlafsSauna.Power', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 0, '', $associations, $reInstall);

        // Yes/No
        $associations = [
            [ 'Wert' => false, 'Name' => $this->Translate('No'), 'Farbe' => -1 ],
            [ 'Wert' => true, 'Name' => $this->Translate('Yes'), 'Farbe' => -1 ],
        ];
        $this->CreateVarProfile('KlafsSauna.YesNo', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            [ 'Wert' => self::$KLAFS_TYPE_UNKNOWN, 'Name' => $this->Translate('unknown'), 'Farbe' => -1 ],
            [ 'Wert' => self::$KLAFS_TYPE_SAUNA, 'Name' => $this->Translate('Sauna'), 'Farbe' => -1 ],
            [ 'Wert' => self::$KLAFS_TYPE_SANARIUM, 'Name' => $this->Translate('SANARIUM®'), 'Farbe' => -1 ],
            [ 'Wert' => self::$KLAFS_TYPE_INFRARED, 'Name' => $this->Translate('Infrared'), 'Farbe' => -1 ],
        ];
        $this->CreateVarProfile('KlafsSauna.Type', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        // Mode (active running mode)
        $associations = [
            [ 'Wert' => self::$KLAFS_MODE_SAUNA, 'Name' => $this->Translate('Sauna'), 'Farbe' => -1 ],
            [ 'Wert' => self::$KLAFS_MODE_SANARIUM, 'Name' => $this->Translate('SANARIUM®'), 'Farbe' => -1 ],
            [ 'Wert' => self::$KLAFS_MODE_INFRARED, 'Name' => $this->Translate('Infrared'), 'Farbe' => -1 ],
        ];
        $this->CreateVarProfile('KlafsSauna.Mode', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $this->CreateVarProfile('KlafsSauna.Temperature', VARIABLETYPE_FLOAT, '°C', 0, 0, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.Humidity', VARIABLETYPE_FLOAT, '%', 0, 100, 0, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.SaunaTemperature', VARIABLETYPE_INTEGER, '°C', 10, 100, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.SanariumTemperature', VARIABLETYPE_INTEGER, '°C', 40, 75, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.InfraredTemperature', VARIABLETYPE_INTEGER, '°C', 20, 40, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.InfraredLevel', VARIABLETYPE_INTEGER, '°C', 1, 3, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.SanariumHumidity', VARIABLETYPE_INTEGER, '', 0, 10, 1, 0, '', [], $reInstall);

        $this->CreateVarProfile('KlafsSauna.Hour', VARIABLETYPE_INTEGER, '', 0, 23, 1, 0, '', [], $reInstall);
        $this->CreateVarProfile('KlafsSauna.Minute', VARIABLETYPE_INTEGER, '', 0, 59, 1, 0, '', [], $reInstall);
    }

    /**
     * @return string[][]
     */
    private function SaunaTypeMapping()
    {
        return [
            self::$KLAFS_TYPE_SAUNA => [
                'caption' => 'Sauna',
            ],
            self::$KLAFS_TYPE_SANARIUM => [
                'caption' => 'Sauna & SANARIUM®',
            ],
            self::$KLAFS_TYPE_INFRARED => [
                'caption' => 'Infrared',
            ],
        ];
    }

    /**
     * @return array
     */
    private function SaunaTypeOptions()
    {
        $maps = $this->SaunaTypeMapping();
        $opts = [];
        foreach ($maps as $key => $data) {
            $opts[] = [
                'caption' => $data['caption'],
                'value'   => $key,
            ];
        }
        return $opts;
    }

}