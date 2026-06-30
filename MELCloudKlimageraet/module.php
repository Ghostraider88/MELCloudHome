<?php

declare(strict_types=1);

/**
 * MELCloud Klimagerät (Device)
 *
 * Repräsentiert ein einzelnes Mitsubishi-Klimagerät (ATA). Erhält den Status
 * vom MELCloud-Connection-Splitter (ReceiveData) und schickt Steuerbefehle
 * über den Splitter an die Cloud (SendDataToParent).
 */
class MELCloudKlimageraet extends IPSModuleStrict
{
    private const RX_TO_PARENT = '{7D0C324F-EF82-4716-A8A0-00006378D27F}';

    // int <-> API-String Zuordnungen
    private const MODE_MAP   = [0 => 'Automatic', 1 => 'Heat', 2 => 'Cool', 3 => 'Dry', 4 => 'Fan'];
    private const FAN_MAP    = [0 => 'Auto', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five'];
    private const VANE_V_MAP = [0 => 'Auto', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 7 => 'Swing'];
    private const VANE_H_MAP = [0 => 'Auto', 1 => 'Left', 2 => 'LeftCentre', 3 => 'Centre', 4 => 'RightCentre', 5 => 'Right', 7 => 'Swing'];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('UnitID', '');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Steuerbare Variablen
        $this->MaintainVariable('Power', $this->Translate('Power'), VARIABLETYPE_BOOLEAN, $this->switchPresentation(), 1, true);
        $this->MaintainVariable('Mode', $this->Translate('Mode'), VARIABLETYPE_INTEGER, $this->modePresentation(), 2, true);
        $this->MaintainVariable('SetTemperature', $this->Translate('Target temperature'), VARIABLETYPE_FLOAT, $this->temperaturePresentation(), 3, true);
        $this->MaintainVariable('FanSpeed', $this->Translate('Fan speed'), VARIABLETYPE_INTEGER, $this->fanSpeedPresentation(), 4, true);
        $this->MaintainVariable('VaneVertical', $this->Translate('Vane vertical'), VARIABLETYPE_INTEGER, $this->vaneVerticalPresentation(), 5, true);
        $this->MaintainVariable('VaneHorizontal', $this->Translate('Vane horizontal'), VARIABLETYPE_INTEGER, $this->vaneHorizontalPresentation(), 6, true);

        // Reine Anzeige-Variablen
        $this->MaintainVariable('RoomTemperature', $this->Translate('Room temperature'), VARIABLETYPE_FLOAT, '~Temperature', 7, true);
        $this->MaintainVariable('OperatingStatus', $this->Translate('Operating status'), VARIABLETYPE_INTEGER, $this->statusPresentation(), 8, true);

        // Alte, instanzübergreifende Custom-Profile entfernen (Legacy, durch Presentations ersetzt)
        $this->removeLegacyProfiles();
        $this->MaintainVariable('Connected', $this->Translate('Connected'), VARIABLETYPE_BOOLEAN, '~Switch', 9, true);
        $this->MaintainVariable('Error', $this->Translate('Error'), VARIABLETYPE_BOOLEAN, '~Alert', 10, true);
        $this->MaintainVariable('WiFiSignal', $this->Translate('WiFi signal'), VARIABLETYPE_INTEGER, 'MELCloud.RSSI', 11, true);
        $this->MaintainVariable('EnergyConsumed', $this->Translate('Energy consumed'), VARIABLETYPE_FLOAT, '~Electricity', 12, true);

        // Aktionen für steuerbare Variablen aktivieren
        foreach (['Power', 'Mode', 'SetTemperature', 'FanSpeed', 'VaneVertical', 'VaneHorizontal'] as $ident) {
            $this->EnableAction($ident);
        }

        // Filter auf DataID – UnitID-Prüfung erfolgt in ReceiveData
        $unitID = $this->ReadPropertyString('UnitID');
        if ($unitID !== '') {
            $this->SetReceiveDataFilter('.*2FD07B1C-5822-48B2-B394-0000776DF537.*');
            $this->SetStatus(102);
            $this->triggerImmediateRefresh();
        } else {
            $this->SetReceiveDataFilter('(?!)'); // nichts empfangen, solange unkonfiguriert
            $this->SetStatus(104);
        }
    }

    /**
     * Stößt direkt nach dem Anlegen/Speichern einen sofortigen Status-Poll am
     * Connection-Splitter an, damit die Werte nicht erst auf den nächsten
     * regulären Polling-Zyklus (bis zu 60s) warten müssen.
     */
    private function triggerImmediateRefresh(): void
    {
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || !IPS_InstanceExists($parentID)) {
            return;
        }
        try {
            MELC_UpdateStatus($parentID);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Sofort-Refresh fehlgeschlagen: ' . $e->getMessage(), 0);
        }
    }

    /* -------------------------------------------------------------------------
     * Datenempfang vom Splitter
     * ---------------------------------------------------------------------- */

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug('ReceiveData', 'Empfangen (100 Zeichen): ' . substr($JSONString, 0, 100), 0);
        $outer = json_decode($JSONString, true);
        if (!isset($outer['Buffer']) || !is_string($outer['Buffer'])) {
            $this->SendDebug('ReceiveData', 'Kein Buffer in Daten', 0);
            return '';
        }
        $buffer = json_decode(hex2bin($outer['Buffer']), true);
        if (!is_array($buffer)) {
            $this->SendDebug('ReceiveData', 'Buffer konnte nicht dekodiert werden', 0);
            return '';
        }
        $myID = $this->ReadPropertyString('UnitID');
        $bufID = (string) ($buffer['UnitID'] ?? '');
        $this->SendDebug('ReceiveData', 'Buffer-UnitID=' . $bufID . ' eigene=' . $myID, 0);
        if ($bufID !== $myID) {
            return '';
        }

        if (array_key_exists('Power', $buffer)) {
            $this->SetValue('Power', (bool) $buffer['Power']);
        }
        if (array_key_exists('OperationMode', $buffer) && $buffer['OperationMode'] !== null) {
            $this->SetValue('Mode', $this->apiToInt(self::MODE_MAP, (string) $buffer['OperationMode'], 0));
        }
        if (array_key_exists('SetTemperature', $buffer) && is_numeric($buffer['SetTemperature'])) {
            $this->SetValue('SetTemperature', (float) $buffer['SetTemperature']);
        }
        if (array_key_exists('RoomTemperature', $buffer) && is_numeric($buffer['RoomTemperature'])) {
            $this->SetValue('RoomTemperature', (float) $buffer['RoomTemperature']);
        }
        if (array_key_exists('SetFanSpeed', $buffer) && $buffer['SetFanSpeed'] !== null) {
            $this->SetValue('FanSpeed', $this->apiToInt(self::FAN_MAP, (string) $buffer['SetFanSpeed'], 0));
        }
        if (array_key_exists('VaneVerticalDirection', $buffer) && $buffer['VaneVerticalDirection'] !== null) {
            $this->SetValue('VaneVertical', $this->apiToInt(self::VANE_V_MAP, (string) $buffer['VaneVerticalDirection'], 0));
        }
        if (array_key_exists('VaneHorizontalDirection', $buffer) && $buffer['VaneHorizontalDirection'] !== null) {
            $this->SetValue('VaneHorizontal', $this->apiToInt(self::VANE_H_MAP, (string) $buffer['VaneHorizontalDirection'], 0));
        }
        if (array_key_exists('IsInError', $buffer)) {
            $this->SetValue('Error', (bool) $buffer['IsInError']);
        }
        if (array_key_exists('Connected', $buffer)) {
            $this->SetValue('Connected', (bool) $buffer['Connected']);
        }
        if (array_key_exists('rssi', $buffer) && is_numeric($buffer['rssi'])) {
            $this->SetValue('WiFiSignal', (int) $buffer['rssi']);
        }
        if (array_key_exists('EnergyConsumed', $buffer) && is_numeric($buffer['EnergyConsumed'])) {
            $this->SetValue('EnergyConsumed', (float) $buffer['EnergyConsumed']);
        }

        // Betriebsstatus ableiten (nur bei vollständigem Statusdatensatz)
        if (array_key_exists('Power', $buffer)) {
            $this->SetValue('OperatingStatus', $this->deriveOperatingStatus($buffer));
        }

        return '';
    }

    /* -------------------------------------------------------------------------
     * Steuerung
     * ---------------------------------------------------------------------- */

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $this->SendDebug('RequestAction', $Ident . '=' . json_encode($Value), 0);

        switch ($Ident) {
            case 'Power':
                $this->control(['power' => (bool) $Value]);
                $this->SetValue('Power', (bool) $Value);
                break;

            case 'Mode':
                $api = self::MODE_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                $this->control(['operationMode' => $api]);
                $this->SetValue('Mode', (int) $Value);
                break;

            case 'SetTemperature':
                $temp = max(10.0, min(31.0, (float) $Value));
                $this->control(['setTemperature' => $temp]);
                $this->SetValue('SetTemperature', $temp);
                break;

            case 'FanSpeed':
                $api = self::FAN_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                $this->control(['setFanSpeed' => $api]);
                $this->SetValue('FanSpeed', (int) $Value);
                break;

            case 'VaneVertical':
                $api = self::VANE_V_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                $this->control(['vaneVerticalDirection' => $api]);
                $this->SetValue('VaneVertical', (int) $Value);
                break;

            case 'VaneHorizontal':
                $api = self::VANE_H_MAP[(int) $Value] ?? null;
                if ($api === null) {
                    return;
                }
                $this->control(['vaneHorizontalDirection' => $api]);
                $this->SetValue('VaneHorizontal', (int) $Value);
                break;

            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    /**
     * Schickt einen Steuerbefehl über den Splitter an die Cloud.
     *
     * @param array<string,mixed> $control
     */
    private function control(array $control): void
    {
        $unitID = $this->ReadPropertyString('UnitID');
        if ($unitID === '') {
            throw new Exception($this->Translate('Device is not configured.'));
        }

        $result = $this->SendDataToParent((string) json_encode([
            'DataID' => self::RX_TO_PARENT,
            'Buffer' => bin2hex((string) json_encode([
                'UnitID'  => $unitID,
                'Control' => $control
            ]))
        ]));

        $decoded = json_decode((string) $result, true);
        if (is_array($decoded) && isset($decoded['success']) && $decoded['success'] === false) {
            $this->LogMessage('MELCloud-Steuerung fehlgeschlagen: ' . ($decoded['error'] ?? 'unbekannt'), KL_ERROR);
        }
    }

    /* -------------------------------------------------------------------------
     * Helfer
     * ---------------------------------------------------------------------- */

    private function deriveOperatingStatus(array $buffer): int
    {
        if (!($buffer['Power'] ?? false)) {
            return 0; // Aus
        }
        if ($buffer['InStandbyMode'] ?? false) {
            return 1; // Leerlauf
        }
        switch ((string) ($buffer['OperationMode'] ?? '')) {
            case 'Heat':      return 2;
            case 'Cool':      return 3;
            case 'Dry':       return 4;
            case 'Fan':       return 5;
            case 'Automatic': return 6;
            default:          return 1;
        }
    }

    /**
     * @param array<int,string> $map
     */
    private function apiToInt(array $map, string $value, int $default): int
    {
        $key = array_search($value, $map, true);
        return $key === false ? $default : (int) $key;
    }

    private function switchPresentation(): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH];
    }

    private function temperaturePresentation(): array
    {
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_SLIDER,
            'MIN'          => 10,
            'MAX'          => 31,
            'STEP_SIZE'    => 0.5,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1
        ];
    }

    private function modePresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), 'Climate', -1],
            [1, $this->Translate('Heat'), 'Flame', 0xFF4500],
            [2, $this->Translate('Cool'), 'Snowflake', 0x1E90FF],
            [3, $this->Translate('Dry'), 'Drops', 0x00CED1],
            [4, $this->Translate('Fan'), 'Ventilation', -1]
        ]);
    }

    private function fanSpeedPresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), 'Ventilation', -1],
            [1, '1', '', -1],
            [2, '2', '', -1],
            [3, '3', '', -1],
            [4, '4', '', -1],
            [5, '5', '', -1]
        ]);
    }

    private function vaneVerticalPresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), '', -1],
            [1, '1', '', -1],
            [2, '2', '', -1],
            [3, '3', '', -1],
            [4, '4', '', -1],
            [5, '5', '', -1],
            [7, $this->Translate('Swing'), 'Move', -1]
        ]);
    }

    private function vaneHorizontalPresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), '', -1],
            [1, $this->Translate('Left'), '', -1],
            [2, $this->Translate('Left-Centre'), '', -1],
            [3, $this->Translate('Centre'), '', -1],
            [4, $this->Translate('Right-Centre'), '', -1],
            [5, $this->Translate('Right'), '', -1],
            [7, $this->Translate('Swing'), 'Move', -1]
        ]);
    }

    private function statusPresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Off'), '', -1],
            [1, $this->Translate('Idle'), '', -1],
            [2, $this->Translate('Heating'), 'Flame', 0xFF4500],
            [3, $this->Translate('Cooling'), 'Snowflake', 0x1E90FF],
            [4, $this->Translate('Drying'), 'Drops', 0x00CED1],
            [5, $this->Translate('Ventilating'), 'Ventilation', -1],
            [6, $this->Translate('Automatic'), 'Climate', -1]
        ]);
    }

    /**
     * Baut eine Enumeration-Presentation (ersetzt die alten Custom-Variablenprofile).
     *
     * Das OPTIONS-Listenformular des Editors (enumerationForm.php) erwartet je Zeile
     * IconActive (bool, ob das Icon überschrieben wird) und IconValue (Icon-Name) statt
     * eines einfachen "Icon"-Schlüssels – ohne diese Schlüssel meldet der Editor
     * "Undefined array key IconActive".
     *
     * @param array<int,array{0:int,1:string,2:string,3:int}> $options Je Eintrag: Value, Caption, Icon, Color
     */
    private function enumerationPresentation(array $options): array
    {
        $values = [];
        foreach ($options as $option) {
            $hasIcon = $option[2] !== '';
            $values[] = [
                'Value'      => $option[0],
                'Caption'    => $option[1],
                'IconActive' => $hasIcon,
                'IconValue'  => $hasIcon ? $option[2] : '',
                'Color'      => $option[3]
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($values)
        ];
    }

    /**
     * Entfernt die alten, instanzübergreifenden Custom-Variablenprofile aus Vorversionen.
     * Schlägt (abgefangen) fehl, solange noch eine andere, nicht aktualisierte Geräte-Instanz
     * das Profil referenziert – das Profil wird dann beim nächsten ApplyChanges erneut versucht.
     */
    private function removeLegacyProfiles(): void
    {
        foreach (['MELCloud.Mode', 'MELCloud.FanSpeed', 'MELCloud.VaneVertical', 'MELCloud.VaneHorizontal', 'MELCloud.Status', 'MELCloud.Temperature'] as $profile) {
            if (IPS_VariableProfileExists($profile)) {
                try {
                    IPS_DeleteVariableProfile($profile);
                } catch (Exception $e) {
                    // noch in Benutzung durch eine andere Instanz – beim nächsten Mal erneut versuchen
                }
            }
        }
    }
}
