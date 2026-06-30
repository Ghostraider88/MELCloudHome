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

    // Verzögerung, mit der mehrere schnelle Soll-Temperatur-Änderungen (z. B. durch
    // wiederholtes Klicken auf den Schieber) zu einem einzelnen Steuerbefehl
    // zusammengefasst werden, statt jeden Zwischenwert sofort an die Cloud zu senden.
    private const TEMPERATURE_DEBOUNCE_MS = 1000;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('UnitID', '');
        $this->RegisterTimer('FlushSetTemperature', 0, 'MELA_FlushSetTemperature($_IPS[\'TARGET\']);');
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
        $this->MaintainVariable('RoomTemperature', $this->Translate('Room temperature'), VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_TEMPLATE_VALUE_PRESENTATION_ROOM_TEMPERATURE], 7, true);
        $this->MaintainVariable('OperatingStatus', $this->Translate('Operating status'), VARIABLETYPE_STRING, $this->statusPresentation(), 8, true);

        // Alte, instanzübergreifende Custom-Profile entfernen (Legacy, durch Presentations ersetzt)
        $this->removeLegacyProfiles();
        $this->MaintainVariable('Connected', $this->Translate('Connected'), VARIABLETYPE_BOOLEAN, ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH], 9, true);
        $this->MaintainVariable('Error', $this->Translate('Error'), VARIABLETYPE_BOOLEAN, $this->errorPresentation(), 10, true);
        $this->MaintainVariable('WiFiSignal', $this->Translate('WiFi signal'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_INPUT, 'SUFFIX' => ' dBm', 'DIGITS' => 0], 11, true);
        $this->MaintainVariable('EnergyConsumed', $this->Translate('Energy consumed'), VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_TEMPLATE_VALUE_PRESENTATION_ENERGY], 12, true);
        $this->MaintainVariable('OutdoorTemperature', $this->Translate('Outdoor temperature'), VARIABLETYPE_FLOAT, ['PRESENTATION' => VARIABLE_TEMPLATE_VALUE_PRESENTATION_ROOM_TEMPERATURE], 13, true);

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
        if (array_key_exists('OutdoorTemperature', $buffer) && is_numeric($buffer['OutdoorTemperature'])) {
            $this->SetValue('OutdoorTemperature', (float) $buffer['OutdoorTemperature']);
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
                $temp = max(16.0, min(31.0, (float) $Value));
                $this->SetValue('SetTemperature', $temp);
                $this->SetBuffer('PendingSetTemperature', (string) $temp);
                $this->SetTimerInterval('FlushSetTemperature', self::TEMPERATURE_DEBOUNCE_MS);
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
     * Timer-Callback (MELA_FlushSetTemperature): sendet den zuletzt gepufferten
     * Soll-Temperatur-Wert genau einmal an die Cloud, nachdem für
     * TEMPERATURE_DEBOUNCE_MS keine weitere Änderung mehr eingegangen ist.
     */
    public function FlushSetTemperature(): void
    {
        $this->SetTimerInterval('FlushSetTemperature', 0);

        $pending = $this->GetBuffer('PendingSetTemperature');
        if ($pending === '') {
            return;
        }
        $this->SetBuffer('PendingSetTemperature', '');
        $this->control(['setTemperature' => (float) $pending]);
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

    private function deriveOperatingStatus(array $buffer): string
    {
        if (!($buffer['Power'] ?? false)) {
            return 'off';
        }
        if ($buffer['InStandbyMode'] ?? false) {
            return 'idle';
        }
        switch ((string) ($buffer['OperationMode'] ?? '')) {
            case 'Heat':      return 'heating';
            case 'Cool':      return 'cooling';
            case 'Dry':       return 'drying';
            case 'Fan':       return 'fan';
            case 'Automatic': return 'automatic';
            default:          return 'idle';
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
            'TEMPLATE'     => VARIABLE_TEMPLATE_SLIDER_ROOM_TEMPERATURE,
            'MIN'          => 16,
            'MAX'          => 31,
            'STEP_SIZE'    => 0.5,
            'SUFFIX'       => ' °C',
            'DIGITS'       => 1
        ];
    }

    private function modePresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), 'arrows-rotate', -1],
            [1, $this->Translate('Heat'), 'heat', 0xFF4500],
            [2, $this->Translate('Cool'), 'snowflake', 0x1E90FF],
            [3, $this->Translate('Dry'), 'droplet', 0x00CED1],
            [4, $this->Translate('Fan'), 'fan', -1]
        ]);
    }

    private function fanSpeedPresentation(): array
    {
        return $this->enumerationPresentation([
            [0, $this->Translate('Automatic'), 'fan', -1],
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
            [7, $this->Translate('Swing'), '', -1]
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
            [7, $this->Translate('Swing'), '', -1]
        ]);
    }

    /**
     * Status ist eine reine Anzeige-Variable (kein EnableAction) – Aufzählung
     * (VARIABLE_PRESENTATION_ENUMERATION) ist dafür nicht zulässig ("Diese Darstellung
     * ist nur für Variablen mit einer Variablenaktion verfügbar"). Die Wertedarstellung
     * (VARIABLE_PRESENTATION_VALUE_PRESENTATION) erwartet außerdem Value-Einträge, deren
     * Typ exakt zum Variablentyp passt – daher String-Variable mit String-Values statt
     * Integer (per IPS_GetVariable-Dump einer funktionierenden Referenzvariable bestätigt).
     */
    private function statusPresentation(): array
    {
        return $this->valuePresentation([
            ['off', $this->Translate('Off'), '', -1],
            ['idle', $this->Translate('Idle'), '', -1],
            ['heating', $this->Translate('Heating'), 'heat', 0xFF4500],
            ['cooling', $this->Translate('Cooling'), 'snowflake', 0x1E90FF],
            ['drying', $this->Translate('Drying'), 'droplet', 0x00CED1],
            ['fan', $this->Translate('Ventilating'), 'fan', -1],
            ['automatic', $this->Translate('Automatic'), 'arrows-rotate', -1]
        ]);
    }

    /**
     * Eigene Wertedarstellung statt des Systemprofils ~Alert, dessen Beschriftung
     * ("OK"/"Alarm") nicht änderbar ist.
     */
    private function errorPresentation(): array
    {
        return $this->valuePresentation([
            [false, $this->Translate('No fault'), '', -1],
            [true, $this->Translate('Fault'), 'triangle-exclamation', 0xFF0000]
        ]);
    }

    /**
     * @param array<int,array{0:bool|string,1:string,2:string,3:int}> $options Je Eintrag: Value, Caption, Icon, Color
     */
    private function valuePresentation(array $options): array
    {
        $values = [];
        foreach ($options as $option) {
            $hasIcon  = $option[2] !== '';
            $hasColor = $option[3] !== -1;
            $values[] = [
                'Value'       => $option[0],
                'Caption'     => $option[1],
                'IconActive'  => $hasIcon,
                'IconValue'   => $hasIcon ? $option[2] : '',
                'ColorActive' => $hasColor,
                'ColorValue'  => $hasColor ? $option[3] : -1
            ];
        }
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            'OPTIONS'      => json_encode($values)
        ];
    }

    /**
     * Baut eine Enumeration-Presentation (ersetzt die alten Custom-Variablenprofile).
     * Für schaltbare Variablen, die als Auswahl-Buttons dargestellt werden.
     *
     * Das OPTIONS-Listenformular des Editors (enumerationForm.php) erwartet je Zeile
     * IconActive (bool, ob das Icon überschrieben wird) und IconValue (Icon-Name) statt
     * eines einfachen "Icon"-Schlüssels – ohne diese Schlüssel meldet der Editor
     * "Undefined array key IconActive". Damit Beschriftung UND Icon angezeigt werden
     * (statt nur Beschriftung), wird zusätzlich DISPLAY=2 (Caption and Icon) gesetzt;
     * LAYOUT=1 (Row) ergibt die segmentierte Button-Reihe.
     *
     * @param array<int,array{0:int,1:string,2:string,3:int}> $options Je Eintrag: Value, Caption, Icon, Color
     */
    private function enumerationPresentation(array $options): array
    {
        $values = $this->buildOptionValues($options);
        return [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'OPTIONS'      => json_encode($values),
            'LAYOUT'       => 1, // Row
            'DISPLAY'      => 2  // Caption and Icon
        ];
    }

    /**
     * @param array<int,array{0:int,1:string,2:string,3:int}> $options
     * @return array<int,array<string,mixed>>
     */
    private function buildOptionValues(array $options): array
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
        return $values;
    }

    /**
     * Entfernt die alten, instanzübergreifenden Custom-Variablenprofile aus Vorversionen.
     * Schlägt (abgefangen) fehl, solange noch eine andere, nicht aktualisierte Geräte-Instanz
     * das Profil referenziert – das Profil wird dann beim nächsten ApplyChanges erneut versucht.
     */
    private function removeLegacyProfiles(): void
    {
        foreach (['MELCloud.Mode', 'MELCloud.FanSpeed', 'MELCloud.VaneVertical', 'MELCloud.VaneHorizontal', 'MELCloud.Status', 'MELCloud.Temperature', 'MELCloud.RSSI'] as $profile) {
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
