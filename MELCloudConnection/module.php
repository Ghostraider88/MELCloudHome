<?php

declare(strict_types=1);

/**
 * MELCloud Connection (Splitter)
 *
 * Hält die Verbindung zur MELCloud Home Cloud (OAuth 2.0 + PKCE), pollt den
 * Gerätestatus über /context und verteilt ihn an die Klimageräte-Instanzen.
 * Steuerbefehle der Kinder werden per ForwardData entgegengenommen und als
 * PUT /api/devices/{unit}/control an die Cloud gesendet.
 */
class MELCloudConnection extends IPSModule
{
    // OAuth / API Endpunkte (abgeleitet aus andrew-blake/melcloudhome)
    private const AUTH_BASE_URL    = 'https://auth.melcloudhome.com';
    private const API_BASE_URL     = 'https://mobile.bff.melcloudhome.com';
    private const OAUTH_CLIENT_ID  = 'homemobile';
    private const OAUTH_REDIRECT   = 'melcloudhome://';
    private const OAUTH_SCOPES     = 'openid profile email offline_access IdentityServerApi';
    private const USER_AGENT       = 'MonitorAndControl.App.Mobile/52 CFNetwork/3860.400.51 Darwin/25.3.0';

    // Datenschnittstelle zu den Kind-Instanzen
    private const TX_TO_CHILD = '{2FD07B1C-5822-48B2-B394-0000776DF537}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Email', '');
        $this->RegisterPropertyString('Password', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);   // Sekunden
        $this->RegisterPropertyInteger('EnergyInterval', 30);   // Minuten

        // Token werden als Attribute (nicht im Formular) gespeichert
        $this->RegisterAttributeString('AccessToken', '');
        $this->RegisterAttributeString('RefreshToken', '');
        $this->RegisterAttributeInteger('TokenExpiry', 0);

        $this->RegisterTimer('UpdateStatus', 0, 'MELC_UpdateStatus($_IPS[\'TARGET\']);');
        $this->RegisterTimer('UpdateEnergy', 0, 'MELC_UpdateEnergy($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(104); // inaktiv: Zugangsdaten fehlen
            $this->SetTimerInterval('UpdateStatus', 0);
            $this->SetTimerInterval('UpdateEnergy', 0);
            return;
        }

        $this->SetStatus(102); // aktiv

        $statusInterval = max(30, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        $energyInterval = max(5, $this->ReadPropertyInteger('EnergyInterval')) * 60 * 1000;
        $this->SetTimerInterval('UpdateStatus', $statusInterval);
        $this->SetTimerInterval('UpdateEnergy', $energyInterval);
    }

    /* -------------------------------------------------------------------------
     * Öffentliche Aktionen
     * ---------------------------------------------------------------------- */

    /**
     * Testet die Anmeldung und meldet das Ergebnis zurück (für Button im Formular).
     */
    public function TestLogin(): void
    {
        try {
            $token = $this->getAccessToken(true);
            if ($token === '') {
                echo $this->Translate('Login failed. Please check your credentials.');
                return;
            }
            $context = $this->fetchContext();
            $devices = $this->extractDevices($context);
            echo sprintf($this->Translate('Login successful. %d air conditioner(s) found.'), count($devices));
        } catch (Exception $e) {
            echo $this->Translate('Error') . ': ' . $e->getMessage();
        }
    }

    /**
     * Pollt den Gerätestatus und verteilt ihn an die Kinder.
     */
    public function UpdateStatus(): void
    {
        try {
            $context = $this->fetchContext();
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Fehler: ' . $e->getMessage(), 0);
            $this->SetStatus(201); // Verbindungsfehler
            return;
        }

        $devices = $this->extractDevices($context);
        if ($this->GetStatus() != 102) {
            $this->SetStatus(102);
        }

        foreach ($devices as $device) {
            $this->SendDataToChildren(json_encode([
                'DataID' => self::TX_TO_CHILD,
                'Buffer' => $device
            ]));
        }
    }

    /**
     * Pollt die Energiedaten je Gerät (seltener) und verteilt sie an die Kinder.
     */
    public function UpdateEnergy(): void
    {
        foreach ($this->getChildUnitIDs() as $unitID) {
            try {
                $energy = $this->fetchEnergy($unitID);
            } catch (Exception $e) {
                $this->SendDebug(__FUNCTION__, $unitID . ': ' . $e->getMessage(), 0);
                continue;
            }
            $this->SendDataToChildren(json_encode([
                'DataID' => self::TX_TO_CHILD,
                'Buffer' => [
                    'UnitID'         => $unitID,
                    'EnergyConsumed' => $energy
                ]
            ]));
        }
    }

    /* -------------------------------------------------------------------------
     * Datenfluss von den Kindern (Steuerbefehle)
     * ---------------------------------------------------------------------- */

    public function ForwardData($JSONString)
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || !isset($data['UnitID'], $data['Control'])) {
            return json_encode(['success' => false, 'error' => 'invalid request']);
        }

        try {
            $this->sendControl($data['UnitID'], $data['Control']);
            // Nach einer Steuerung zeitnah aktualisieren
            $this->UpdateStatus();
            return json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Control-Fehler: ' . $e->getMessage(), 0);
            return json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /* -------------------------------------------------------------------------
     * Konfigurationsformular inkl. Konfigurator-Liste
     * ---------------------------------------------------------------------- */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $values = [];
        try {
            if ($this->ReadPropertyString('Email') !== '' && $this->ReadPropertyString('Password') !== '') {
                $context = $this->fetchContext();
                $values  = $this->buildConfiguratorValues($this->extractDevices($context));
            }
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
        }

        foreach ($form['actions'] as &$action) {
            if (isset($action['name']) && $action['name'] === 'Configurator') {
                $action['values'] = $values;
            }
        }
        unset($action);

        return json_encode($form);
    }

    private function buildConfiguratorValues(array $devices): array
    {
        $deviceModuleID = '{73860314-C683-4067-B8BC-00005121318D}';
        $existing       = $this->getExistingDeviceInstances($deviceModuleID);

        $values = [];
        foreach ($devices as $device) {
            $unitID     = (string) $device['UnitID'];
            $instanceID = $existing[$unitID] ?? 0;

            $values[] = [
                'instanceID' => $instanceID,
                'UnitID'     => $unitID,
                'Name'       => $device['Name'] ?? $unitID,
                'create'     => [
                    'moduleID'      => $deviceModuleID,
                    'configuration' => [
                        'UnitID' => $unitID
                    ]
                ]
            ];
        }

        return $values;
    }

    /**
     * @return array<string,int> UnitID => InstanceID der bereits angelegten Geräte
     */
    private function getExistingDeviceInstances(string $deviceModuleID): array
    {
        $result = [];
        foreach (IPS_GetInstanceListByModuleID($deviceModuleID) as $instanceID) {
            if (IPS_GetInstance($instanceID)['ConnectionID'] !== $this->InstanceID) {
                continue;
            }
            $unitID = @IPS_GetProperty($instanceID, 'UnitID');
            if (is_string($unitID) && $unitID !== '') {
                $result[$unitID] = $instanceID;
            }
        }
        return $result;
    }

    /**
     * @return string[] UnitIDs der verbundenen Kind-Instanzen
     */
    private function getChildUnitIDs(): array
    {
        return array_keys($this->getExistingDeviceInstances('{73860314-C683-4067-B8BC-00005121318D}'));
    }

    /* -------------------------------------------------------------------------
     * MELCloud API
     * ---------------------------------------------------------------------- */

    /**
     * Liefert den vollständigen /context-Datensatz.
     */
    private function fetchContext(): array
    {
        $response = $this->apiRequest('GET', '/context');
        $data     = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Ungültige /context-Antwort');
        }
        return $data;
    }

    /**
     * Extrahiert die ATA-Klimageräte aus der /context-Antwort in ein normalisiertes Format.
     *
     * Die Cloud liefert Gebäude (buildings) mit Geräten. Die genaue Verschachtelung
     * kann variieren; daher wird rekursiv nach Geräten mit ATA-typischen Feldern gesucht.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractDevices(array $context): array
    {
        $devices = [];
        $this->collectAtaDevices($context, $devices);
        return $devices;
    }

    private function collectAtaDevices($node, array &$devices): void
    {
        if (!is_array($node)) {
            return;
        }

        // Ein ATA-Gerät erkennen wir an typischen Feldern
        $isDevice = $this->hasAnyKey($node, ['Power', 'power']) &&
                    $this->hasAnyKey($node, ['OperationMode', 'operationMode', 'SetTemperature', 'setTemperature']);

        if ($isDevice) {
            $normalized = $this->normalizeDevice($node);
            if ($normalized !== null) {
                $devices[] = $normalized;
                return;
            }
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectAtaDevices($value, $devices);
            }
        }
    }

    private function normalizeDevice(array $raw): ?array
    {
        $unitID = $this->pick($raw, ['unitId', 'unitID', 'id', 'deviceId', 'DeviceID']);
        if ($unitID === null) {
            return null;
        }

        return [
            'UnitID'                  => (string) $unitID,
            'Name'                    => $this->pick($raw, ['givenDisplayName', 'displayName', 'name', 'Name']) ?? (string) $unitID,
            'Power'                   => (bool) $this->pick($raw, ['Power', 'power']),
            'OperationMode'           => $this->pick($raw, ['OperationMode', 'operationMode']),
            'SetTemperature'          => $this->pick($raw, ['SetTemperature', 'setTemperature']),
            'RoomTemperature'         => $this->pick($raw, ['RoomTemperature', 'roomTemperature']),
            'SetFanSpeed'             => $this->pick($raw, ['SetFanSpeed', 'setFanSpeed']),
            'VaneVerticalDirection'   => $this->pick($raw, ['VaneVerticalDirection', 'vaneVerticalDirection']),
            'VaneHorizontalDirection' => $this->pick($raw, ['VaneHorizontalDirection', 'vaneHorizontalDirection']),
            'InStandbyMode'           => (bool) $this->pick($raw, ['InStandbyMode', 'inStandbyMode']),
            'IsInError'               => (bool) $this->pick($raw, ['IsInError', 'isInError']),
            'rssi'                    => $this->pick($raw, ['rssi', 'Rssi', 'RSSI']),
            'Connected'               => $this->pick($raw, ['rssi', 'Rssi', 'RSSI']) !== null
        ];
    }

    /**
     * Sendet einen Steuerbefehl an ein Gerät.
     *
     * @param array<string,mixed> $control Teilmenge der steuerbaren Felder.
     */
    private function sendControl(string $unitID, array $control): void
    {
        // Vollständiger Body, nicht gesetzte Felder bleiben null (analog HA-Modul)
        $body = [
            'power'                       => null,
            'operationMode'               => null,
            'setFanSpeed'                 => null,
            'vaneHorizontalDirection'     => null,
            'vaneVerticalDirection'       => null,
            'setTemperature'              => null,
            'temperatureIncrementOverride' => null,
            'inStandbyMode'               => null
        ];
        foreach ($control as $key => $value) {
            if (array_key_exists($key, $body)) {
                $body[$key] = $value;
            }
        }

        $this->apiRequest('PUT', '/api/devices/' . rawurlencode($unitID) . '/control', $body);
    }

    /**
     * Holt den (kumulierten) Energieverbrauch eines Geräts in kWh.
     */
    private function fetchEnergy(string $unitID): float
    {
        $response = $this->apiRequest('GET', '/telemetry/telemetry/energy/' . rawurlencode($unitID));
        $data     = json_decode($response, true);

        // Antwortstruktur: measureData -> values -> [{ value }]
        $sum = 0.0;
        if (isset($data['measureData']) && is_array($data['measureData'])) {
            foreach ($data['measureData'] as $measure) {
                if (isset($measure['values']) && is_array($measure['values'])) {
                    foreach ($measure['values'] as $entry) {
                        if (isset($entry['value']) && is_numeric($entry['value'])) {
                            $sum += (float) $entry['value'];
                        }
                    }
                }
            }
        }
        return $sum;
    }

    /**
     * Führt einen authentifizierten API-Request aus und liefert den Body zurück.
     */
    private function apiRequest(string $method, string $path, ?array $body = null): string
    {
        $token = $this->getAccessToken();
        if ($token === '') {
            throw new Exception('Keine gültige Anmeldung');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'User-Agent: ' . self::USER_AGENT
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        [$status, $response] = $this->httpRequest($method, self::API_BASE_URL . $path, $headers, $body === null ? null : json_encode($body));

        if ($status === 401) {
            // Token erneuern und einmal wiederholen
            $token = $this->getAccessToken(true);
            $headers[0] = 'Authorization: Bearer ' . $token;
            [$status, $response] = $this->httpRequest($method, self::API_BASE_URL . $path, $headers, $body === null ? null : json_encode($body));
        }

        if ($status < 200 || $status >= 300) {
            throw new Exception(sprintf('HTTP %d bei %s %s', $status, $method, $path));
        }

        return $response;
    }

    /* -------------------------------------------------------------------------
     * OAuth 2.0 Authorization Code + PKCE
     * ---------------------------------------------------------------------- */

    /**
     * Liefert ein gültiges Access-Token (refresht/loggt bei Bedarf neu ein).
     */
    private function getAccessToken(bool $forceRefresh = false): string
    {
        $now    = time();
        $access = $this->ReadAttributeString('AccessToken');
        $expiry = $this->ReadAttributeInteger('TokenExpiry');

        if (!$forceRefresh && $access !== '' && $expiry > $now + 60) {
            return $access;
        }

        // 1. Versuch: Refresh-Token verwenden
        $refresh = $this->ReadAttributeString('RefreshToken');
        if ($refresh !== '') {
            $tokens = $this->refreshTokens($refresh);
            if ($tokens !== null) {
                $this->storeTokens($tokens);
                return $tokens['access_token'];
            }
        }

        // 2. Versuch: Vollständiger Login
        $tokens = $this->login();
        if ($tokens === null) {
            return '';
        }
        $this->storeTokens($tokens);
        return $tokens['access_token'];
    }

    private function storeTokens(array $tokens): void
    {
        $this->WriteAttributeString('AccessToken', $tokens['access_token']);
        if (!empty($tokens['refresh_token'])) {
            $this->WriteAttributeString('RefreshToken', $tokens['refresh_token']);
        }
        $expiresIn = isset($tokens['expires_in']) ? (int) $tokens['expires_in'] : 3600;
        $this->WriteAttributeInteger('TokenExpiry', time() + $expiresIn);
    }

    private function refreshTokens(string $refreshToken): ?array
    {
        [$status, $response] = $this->httpRequest(
            'POST',
            self::AUTH_BASE_URL . '/connect/token',
            ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
            http_build_query([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => self::OAUTH_CLIENT_ID
            ])
        );

        if ($status !== 200) {
            $this->SendDebug(__FUNCTION__, 'Refresh fehlgeschlagen: HTTP ' . $status, 0);
            return null;
        }
        $tokens = json_decode($response, true);
        return isset($tokens['access_token']) ? $tokens : null;
    }

    /**
     * Vollständiger PKCE-Login mit Cognito-Federated-Login.
     */
    private function login(): ?array
    {
        $email    = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        if ($email === '' || $password === '') {
            return null;
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'melc_');

        try {
            // PKCE-Parameter
            $codeVerifier  = $this->base64Url(random_bytes(48));
            $codeChallenge = $this->base64Url(hash('sha256', $codeVerifier, true));
            $state         = $this->base64Url(random_bytes(16));

            // Schritt 1: Pushed Authorization Request (PAR)
            [$status, $response] = $this->httpRequest(
                'POST',
                self::AUTH_BASE_URL . '/connect/par',
                ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
                http_build_query([
                    'response_type'         => 'code',
                    'client_id'             => self::OAUTH_CLIENT_ID,
                    'redirect_uri'          => self::OAUTH_REDIRECT,
                    'scope'                 => self::OAUTH_SCOPES,
                    'state'                 => $state,
                    'code_challenge'        => $codeChallenge,
                    'code_challenge_method' => 'S256'
                ]),
                $cookieJar
            );
            if ($status !== 201 && $status !== 200) {
                throw new Exception('PAR fehlgeschlagen: HTTP ' . $status);
            }
            $par = json_decode($response, true);
            if (!isset($par['request_uri'])) {
                throw new Exception('PAR ohne request_uri');
            }

            // Schritt 2: Authorize -> Redirect zur Cognito-Loginseite
            $authorizeUrl = self::AUTH_BASE_URL . '/connect/authorize?' . http_build_query([
                'client_id'   => self::OAUTH_CLIENT_ID,
                'request_uri' => $par['request_uri']
            ]);
            $loginPage = $this->followToLoginPage($authorizeUrl, $cookieJar, $loginUrl);
            if ($loginUrl === '') {
                throw new Exception('Cognito-Loginseite nicht erreicht');
            }

            // Schritt 3: CSRF-Token aus der Loginseite extrahieren
            $csrf = $this->extractCsrf($loginPage);

            // Schritt 4: Zugangsdaten an Cognito senden -> Auth-Code abfangen
            $code = $this->submitCredentials($loginUrl, $csrf, $email, $password, $cookieJar);
            if ($code === '') {
                throw new Exception('Kein Auth-Code erhalten (Zugangsdaten prüfen)');
            }

            // Schritt 5: Token-Tausch
            [$status, $response] = $this->httpRequest(
                'POST',
                self::AUTH_BASE_URL . '/connect/token',
                ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . self::USER_AGENT],
                http_build_query([
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => self::OAUTH_REDIRECT,
                    'code_verifier' => $codeVerifier,
                    'client_id'     => self::OAUTH_CLIENT_ID
                ]),
                $cookieJar
            );
            if ($status !== 200) {
                throw new Exception('Token-Tausch fehlgeschlagen: HTTP ' . $status);
            }
            $tokens = json_decode($response, true);
            return isset($tokens['access_token']) ? $tokens : null;
        } catch (Exception $e) {
            $this->SendDebug('login', $e->getMessage(), 0);
            $this->LogMessage('MELCloud-Login fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            return null;
        } finally {
            if (is_string($cookieJar) && file_exists($cookieJar)) {
                @unlink($cookieJar);
            }
        }
    }

    /**
     * Folgt der Authorize-Weiterleitung bis zur (HTML-)Loginseite und gibt deren
     * Inhalt sowie per Referenz die finale URL zurück.
     */
    private function followToLoginPage(string $url, string $cookieJar, ?string &$finalUrl = null): string
    {
        $finalUrl = '';
        for ($hop = 0; $hop < 10; $hop++) {
            [$status, $body, $location, $effectiveUrl] = $this->httpRequestRaw('GET', $url, ['User-Agent: ' . self::USER_AGENT], null, $cookieJar);

            if ($location !== '') {
                // Auth-Code könnte schon hier zurückkommen (bestehende Session)
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    $finalUrl = $location;
                    return $body;
                }
                $url = $this->resolveUrl($url, $location);
                continue;
            }

            // Keine Weiterleitung mehr -> das ist die Loginseite
            $finalUrl = $effectiveUrl !== '' ? $effectiveUrl : $url;
            return $body;
        }
        return '';
    }

    private function extractCsrf(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/name="csrf[-_]?token"\s+value="([^"]+)"/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    /**
     * Sendet die Zugangsdaten an die Cognito-Loginseite und verfolgt die
     * Weiterleitungskette bis zum custom-scheme-Redirect mit dem Auth-Code.
     */
    private function submitCredentials(string $loginUrl, string $csrf, string $email, string $password, string $cookieJar): string
    {
        $postFields = http_build_query([
            '_csrf'          => $csrf,
            'username'       => $email,
            'password'       => $password,
            'cognitoAsfData' => ''
        ]);

        $url    = $loginUrl;
        $method = 'POST';
        $data   = $postFields;

        for ($hop = 0; $hop < 12; $hop++) {
            $headers = ['User-Agent: ' . self::USER_AGENT, 'Referer: ' . $loginUrl];
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }

            [$status, $body, $location] = $this->httpRequestRaw($method, $url, $headers, $data, $cookieJar);

            if ($location !== '') {
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    return $this->extractCodeFromUrl($location);
                }
                $url    = $this->resolveUrl($url, $location);
                $method = 'GET';
                $data   = null;
                continue;
            }

            // Manche Auth-Code-Übergaben erfolgen über ein Auto-Submit-Formular
            $code = $this->extractCodeFromHtml($body);
            if ($code !== '') {
                return $code;
            }
            break;
        }
        return '';
    }

    private function extractCodeFromUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query === null || $query === false) {
            // custom scheme kann auch hinter '#' liegen
            $query = parse_url($url, PHP_URL_FRAGMENT) ?: '';
        }
        parse_str((string) $query, $params);
        return $params['code'] ?? '';
    }

    private function extractCodeFromHtml(string $html): string
    {
        if (preg_match('/[?&#]code=([^"&\'<>\s]+)/', $html, $m)) {
            return urldecode($m[1]);
        }
        return '';
    }

    /* -------------------------------------------------------------------------
     * HTTP-Hilfsfunktionen
     * ---------------------------------------------------------------------- */

    /**
     * Einfacher Request mit automatischem Folgen von Weiterleitungen.
     *
     * @return array{0:int,1:string} [HTTP-Status, Body]
     */
    private function httpRequest(string $method, string $url, array $headers, ?string $body = null, ?string $cookieJar = null): array
    {
        [$status, $respBody] = $this->httpRequestRaw($method, $url, $headers, $body, $cookieJar, true);
        return [$status, $respBody];
    }

    /**
     * Roh-Request ohne automatisches Folgen (Weiterleitung wird zurückgegeben),
     * sofern $followRedirects = false.
     *
     * @return array{0:int,1:string,2:string,3:string} [Status, Body, Location, EffectiveURL]
     */
    private function httpRequestRaw(string $method, string $url, array $headers, ?string $body = null, ?string $cookieJar = null, bool $followRedirects = false): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS      => 15,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_ENCODING       => ''
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('cURL-Fehler: ' . $error);
        }

        $status       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $effectiveUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        $headerText = substr($raw, 0, $headerSize);
        $respBody   = substr($raw, $headerSize);
        $location   = $this->extractLocationHeader($headerText);

        return [$status, $respBody, $location, $effectiveUrl];
    }

    private function extractLocationHeader(string $headerText): string
    {
        // letzte Location:-Zeile (bei mehreren Headerblöcken)
        $location = '';
        foreach (preg_split('/\r?\n/', $headerText) as $line) {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, strlen('Location:')));
            }
        }
        return $location;
    }

    private function resolveUrl(string $base, string $relative): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $relative) || strpos($relative, self::OAUTH_REDIRECT) === 0) {
            return $relative;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host   = $parts['host'] ?? '';
        $origin = $scheme . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (strpos($relative, '/') === 0) {
            return $origin . $relative;
        }
        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
        return $origin . $path . $relative;
    }

    /* -------------------------------------------------------------------------
     * Kleine Helfer
     * ---------------------------------------------------------------------- */

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function hasAnyKey(array $arr, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $arr)) {
                return true;
            }
        }
        return false;
    }

    private function pick(array $arr, array $keys)
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $arr) && $arr[$key] !== null) {
                return $arr[$key];
            }
        }
        return null;
    }
}
