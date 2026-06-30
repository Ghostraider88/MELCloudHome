<?php

declare(strict_types=1);

/**
 * MELCloud Connection (Splitter)
 *
 * Hält die Verbindung zur MELCloud Home Cloud (OAuth 2.0 + PKCE), pollt den
 * Gerätestatus über /context und verteilt ihn an die Klimageräte-Instanzen.
 * Steuerbefehle der Kinder werden per ForwardData entgegengenommen und als
 * PUT /monitor/ataunit/{unit} an die Cloud gesendet.
 */
class MELCloudConnection extends IPSModuleStrict
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

    public function Create(): void
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

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('Email') === '' || $this->ReadPropertyString('Password') === '') {
            $this->SetStatus(104); // inaktiv: Zugangsdaten fehlen
            $this->SetTimerInterval('UpdateStatus', 0);
            $this->SetTimerInterval('UpdateEnergy', 0);
            return;
        }

        $this->SetStatus(102); // aktiv

        // Status (Solltemperatur, Raumtemperatur, Modus, Lüfter, Vanes, Power) – 60s ist die
        // sinnvolle Untergrenze für MELCloud; aggressiveres Polling (10s/30s) wird unterbunden.
        $statusInterval = max(60, $this->ReadPropertyInteger('UpdateInterval')) * 1000;
        // Energie-/Verbrauchsdaten – deutlich rate-limit-empfindlicher (bekannte 429-Fehler),
        // daher mindestens 30 Minuten.
        $energyInterval = max(30, $this->ReadPropertyInteger('EnergyInterval')) * 60 * 1000;
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
            $this->ReloadForm();
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

        $allInstances = IPS_GetInstanceListByModuleID('{73860314-C683-4067-B8BC-00005121318D}');
        $connectedCount = 0;
        foreach ($allInstances as $instID) {
            $connID = IPS_GetInstance($instID)['ConnectionID'];
            $isConnected = ($connID === $this->InstanceID);
            if ($isConnected) {
                $connectedCount++;
            }
            $this->SendDebug('UpdateStatus', 'Instanz #' . $instID . ' ConnectionID=' . $connID . ($isConnected ? ' [verbunden ✓]' : ' [NICHT verbunden, erwartet=' . $this->InstanceID . ']'), 0);
        }
        $this->SendDebug('UpdateStatus', 'Sende an ' . count($devices) . ' Cloud-Geräte, ' . $connectedCount . '/' . count($allInstances) . ' Klimageraet-Instanzen verbunden', 0);
        foreach ($devices as $device) {
            $uid = (string) $device['UnitID'];
            $payload = (string) json_encode([
                'DataID'  => self::TX_TO_CHILD,
                'UnitID'  => $uid,
                'Buffer'  => bin2hex((string) json_encode($device))
            ]);
            $this->SendDebug('UpdateStatus', 'SendDataToChildren UnitID=' . $uid, 0);
            $this->SendDataToChildren($payload);
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
            $this->SendDataToChildren((string) json_encode([
                'DataID'  => self::TX_TO_CHILD,
                'UnitID'  => $unitID,
                'Buffer'  => bin2hex((string) json_encode([
                    'UnitID'         => $unitID,
                    'EnergyConsumed' => $energy
                ]))
            ]));
        }
    }

    /* -------------------------------------------------------------------------
     * Datenfluss von den Kindern (Steuerbefehle)
     * ---------------------------------------------------------------------- */

    public function ForwardData(string $JSONString): string
    {
        $this->SendDebug('ForwardData', 'Empfangen: ' . substr($JSONString, 0, 300), 0);

        $outer = json_decode($JSONString, true);
        $data  = isset($outer['Buffer']) ? json_decode(hex2bin($outer['Buffer']), true) : null;
        if (!is_array($data) || !isset($data['UnitID'], $data['Control'])) {
            $this->SendDebug('ForwardData', 'Ungültige Anfrage (kein UnitID/Control)', 0);
            return (string) json_encode(['success' => false, 'error' => 'invalid request']);
        }

        try {
            $this->sendControl($data['UnitID'], $data['Control']);
            $this->SendDebug('ForwardData', 'sendControl OK für UnitID=' . $data['UnitID'], 0);
            // Bewusst kein Sofort-Refresh: die Cloud übernimmt den neuen Wert ggf. erst
            // mit Verzögerung, ein sofortiger UpdateStatus() würde den optimistisch
            // gesetzten Wert wieder mit dem alten Cloud-Stand überschreiben.
            return (string) json_encode(['success' => true]);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, 'Control-Fehler: ' . $e->getMessage(), 0);
            return (string) json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /* -------------------------------------------------------------------------
     * Exportierte Funktion für den Konfigurator
     * ---------------------------------------------------------------------- */

    /**
     * Liefert die Geräteliste als JSON-String an den MELCloud Configurator.
     */
    public function GetDeviceListJSON(): string
    {
        try {
            $context = $this->fetchContext();
            $devices = $this->extractDevices($context);
            return (string) json_encode($devices);
        } catch (Exception $e) {
            $this->SendDebug(__FUNCTION__, $e->getMessage(), 0);
            return '[]';
        }
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
        $this->SendDebug('fetchContext', 'Rohe Antwort (1500 Zeichen): ' . substr($response, 0, 1500), 0);
        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new Exception('Ungültige /context-Antwort');
        }
        $this->SendDebug('fetchContext', 'Top-Level-Keys: ' . implode(', ', array_keys($data)), 0);
        return $data;
    }

    /**
     * Extrahiert die ATA-Klimageräte aus der /context-Antwort.
     *
     * Struktur: context.buildings[*].airToAirUnits[*]
     * Status-Felder stehen im settings-Array als {name, value}-Paare.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractDevices(array $context): array
    {
        $devices = [];
        foreach ($context['buildings'] ?? [] as $building) {
            foreach ($building['airToAirUnits'] ?? [] as $unit) {
                $normalized = $this->normalizeUnit($unit);
                if ($normalized !== null) {
                    $devices[] = $normalized;
                }
            }
        }
        $this->SendDebug('extractDevices', count($devices) . ' Geräte gefunden', 0);
        return $devices;
    }

    private function normalizeUnit(array $unit): ?array
    {
        $unitID = $unit['id'] ?? null;
        if ($unitID === null) {
            return null;
        }

        // settings ist ein [{name, value}]-Array → in eine Map umwandeln
        $settings = [];
        foreach ($unit['settings'] ?? [] as $s) {
            if (isset($s['name'])) {
                $settings[$s['name']] = $s['value'] ?? null;
            }
        }

        $power = isset($settings['Power']) ? strtolower((string) $settings['Power']) !== 'false' : false;

        return [
            'UnitID'                  => (string) $unitID,
            'Name'                    => $unit['givenDisplayName'] ?? $unit['displayName'] ?? (string) $unitID,
            'Power'                   => $power,
            'OperationMode'           => $settings['OperationMode'] ?? null,
            'SetTemperature'          => isset($settings['SetTemperature']) ? (float) $settings['SetTemperature'] : null,
            'RoomTemperature'         => isset($settings['RoomTemperature']) ? (float) $settings['RoomTemperature'] : null,
            'SetFanSpeed'             => $settings['SetFanSpeed'] ?? $settings['ActualFanSpeed'] ?? null,
            'VaneVerticalDirection'   => $settings['VaneVerticalDirection'] ?? null,
            'VaneHorizontalDirection' => $settings['VaneHorizontalDirection'] ?? null,
            'InStandbyMode'           => isset($settings['InStandbyMode']) && strtolower((string) $settings['InStandbyMode']) !== 'false',
            'IsInError'               => isset($settings['IsInError']) && strtolower((string) $settings['IsInError']) !== 'false',
            'rssi'                    => isset($settings['rssi']) ? (int) $settings['rssi'] : null,
            'Connected'               => true
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

        $this->apiRequest('PUT', '/monitor/ataunit/' . rawurlencode($unitID), $body);
    }

    /**
     * Holt den (kumulierten) Energieverbrauch eines Geräts in kWh (letzte 24h, stündlich).
     */
    private function fetchEnergy(string $unitID): float
    {
        $now  = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $now->modify('-1 day');
        $query = http_build_query([
            'from'     => $from->format('Y-m-d H:i'),
            'to'       => $now->format('Y-m-d H:i'),
            'interval' => 'Hour',
            'measure'  => 'cumulative_energy_consumed_since_last_upload'
        ]);

        $response = $this->apiRequest('GET', '/telemetry/telemetry/energy/' . rawurlencode($unitID) . '?' . $query);
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

    private function login(): ?array
    {
        $email    = $this->ReadPropertyString('Email');
        $password = $this->ReadPropertyString('Password');
        if ($email === '' || $password === '') {
            return null;
        }

        $cookieJar = tempnam(sys_get_temp_dir(), 'melc_');
        $this->SendDebug('login', 'Start – E-Mail: ' . $email, 0);

        try {
            // Schritt 1: PAR
            $codeVerifier  = $this->base64Url(random_bytes(48));
            $codeChallenge = $this->base64Url(hash('sha256', $codeVerifier, true));
            $state         = $this->base64Url(random_bytes(16));

            $this->SendDebug('login/1-PAR', 'POST ' . self::AUTH_BASE_URL . '/connect/par', 0);
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
            $this->SendDebug('login/1-PAR', 'HTTP ' . $status . ' – Body: ' . substr($response, 0, 300), 0);
            if ($status !== 201 && $status !== 200) {
                throw new Exception('PAR fehlgeschlagen: HTTP ' . $status);
            }
            $par = json_decode($response, true);
            if (!isset($par['request_uri'])) {
                throw new Exception('PAR ohne request_uri – Antwort: ' . substr($response, 0, 200));
            }
            $this->SendDebug('login/1-PAR', 'request_uri: ' . $par['request_uri'], 0);

            // Schritt 2: Authorize → Loginseite
            $authorizeUrl = self::AUTH_BASE_URL . '/connect/authorize?' . http_build_query([
                'client_id'   => self::OAUTH_CLIENT_ID,
                'request_uri' => $par['request_uri']
            ]);
            $this->SendDebug('login/2-Authorize', 'URL: ' . $authorizeUrl, 0);
            $loginPage = $this->followToLoginPage($authorizeUrl, $cookieJar, $loginUrl);
            $this->SendDebug('login/2-Authorize', 'Finale Login-URL: ' . $loginUrl, 0);
            $this->SendDebug('login/2-Authorize', 'Seiteninhalt (500 Zeichen): ' . substr(strip_tags($loginPage), 0, 500), 0);
            if ($loginUrl === '') {
                throw new Exception('Cognito-Loginseite nicht erreicht – Seiteninhalt: ' . substr($loginPage, 0, 300));
            }

            // Schritt 3: CSRF
            $csrf = $this->extractCsrf($loginPage);
            $this->SendDebug('login/3-CSRF', $csrf !== '' ? 'Gefunden: ' . substr($csrf, 0, 20) . '…' : 'NICHT gefunden – möglicherweise anderes CSRF-Feld', 0);
            if ($csrf === '') {
                // Alle input-Felder im HTML loggen für Diagnose
                preg_match_all('/<input[^>]+>/i', $loginPage, $inputs);
                $this->SendDebug('login/3-CSRF', 'HTML-input-Felder: ' . implode(' | ', array_map(fn($t) => strip_tags('<x ' . $t . '>'), array_slice($inputs[0], 0, 20))), 0);
            }

            // Schritt 4: Zugangsdaten senden
            $this->SendDebug('login/4-Submit', 'POST an: ' . $loginUrl . ' (CSRF: ' . ($csrf !== '' ? 'ja' : 'nein') . ')', 0);
            $code = $this->submitCredentials($loginUrl, $csrf, $email, $password, $cookieJar);
            $this->SendDebug('login/4-Submit', $code !== '' ? 'Auth-Code erhalten (Länge ' . strlen($code) . ')' : 'KEIN Auth-Code erhalten', 0);
            if ($code === '') {
                throw new Exception('Kein Auth-Code erhalten (Zugangsdaten prüfen)');
            }

            // Schritt 5: Token-Tausch
            $this->SendDebug('login/5-Token', 'POST ' . self::AUTH_BASE_URL . '/connect/token', 0);
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
            $this->SendDebug('login/5-Token', 'HTTP ' . $status . ' – Body: ' . substr($response, 0, 200), 0);
            if ($status !== 200) {
                throw new Exception('Token-Tausch fehlgeschlagen: HTTP ' . $status . ' – ' . substr($response, 0, 200));
            }
            $tokens = json_decode($response, true);
            if (!isset($tokens['access_token'])) {
                throw new Exception('Kein access_token in Antwort: ' . substr($response, 0, 200));
            }
            $this->SendDebug('login/5-Token', 'Erfolgreich – Token-Typ: ' . ($tokens['token_type'] ?? '?') . ', gültig: ' . ($tokens['expires_in'] ?? '?') . 's', 0);
            return $tokens;
        } catch (Exception $e) {
            $this->SendDebug('login', 'FEHLER: ' . $e->getMessage(), 0);
            $this->LogMessage('MELCloud-Login fehlgeschlagen: ' . $e->getMessage(), KL_ERROR);
            return null;
        } finally {
            if (is_string($cookieJar) && file_exists($cookieJar)) {
                @unlink($cookieJar);
            }
        }
    }

    private function followToLoginPage(string $url, string $cookieJar, ?string &$finalUrl = null): string
    {
        $finalUrl = '';
        for ($hop = 0; $hop < 10; $hop++) {
            $this->SendDebug('followToLoginPage', 'Hop ' . $hop . ': GET ' . $url, 0);
            [$status, $body, $location, $effectiveUrl] = $this->httpRequestRaw('GET', $url, ['User-Agent: ' . self::USER_AGENT], null, $cookieJar);
            $this->SendDebug('followToLoginPage', 'Hop ' . $hop . ': HTTP ' . $status . ' – Location: ' . ($location ?: '(keine)') . ' – Body: ' . strlen($body) . ' Bytes', 0);

            if ($location !== '') {
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    $this->SendDebug('followToLoginPage', 'Sofort-Redirect mit Auth-Code erkannt', 0);
                    $finalUrl = $location;
                    return $body;
                }
                $url = $this->resolveUrl($url, $location);
                continue;
            }

            $finalUrl = $effectiveUrl !== '' ? $effectiveUrl : $url;
            return $body;
        }
        $this->SendDebug('followToLoginPage', 'Zu viele Weiterleitungen (>10)', 0);
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
        // Weitere bekannte Varianten
        if (preg_match('/["\']csrf["\']\s*:\s*["\']([^"\']+)["\']/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

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

            $this->SendDebug('submitCredentials', 'Hop ' . $hop . ': ' . $method . ' ' . $url, 0);
            [$status, $body, $location] = $this->httpRequestRaw($method, $url, $headers, $data, $cookieJar);
            $this->SendDebug('submitCredentials', 'Hop ' . $hop . ': HTTP ' . $status . ' – Location: ' . ($location ?: '(keine)') . ' – Body: ' . strlen($body) . ' Bytes', 0);

            if ($location !== '') {
                if (strpos($location, self::OAUTH_REDIRECT) === 0) {
                    $this->SendDebug('submitCredentials', 'Redirect-URL mit Auth-Code: ' . substr($location, 0, 120), 0);
                    return $this->extractCodeFromUrl($location);
                }
                $url    = $this->resolveUrl($url, $location);
                $method = 'GET';
                $data   = null;
                continue;
            }

            // Kein Redirect – Body auf Code/Fehler prüfen
            $this->SendDebug('submitCredentials', 'Kein Redirect – Body (400 Zeichen): ' . substr(strip_tags($body), 0, 400), 0);
            $code = $this->extractCodeFromHtml($body);
            if ($code !== '') {
                $this->SendDebug('submitCredentials', 'Auth-Code aus HTML-Body extrahiert', 0);
                return $code;
            }

            // JavaScript-Redirect-Seite: RedirectUri aus aktuellem URL-Parameter auslesen
            $nextUrl = $this->extractJsRedirect($body, $url);
            if ($nextUrl !== '') {
                $this->SendDebug('submitCredentials', 'JS-Redirect folgen: ' . substr($nextUrl, 0, 150), 0);
                $url    = $nextUrl;
                $method = 'GET';
                $data   = null;
                continue;
            }

            break;
        }
        return '';
    }

    private function extractJsRedirect(string $body, string $currentUrl): string
    {
        // window.location / window.location.href = "..."
        if (preg_match('/window\.location(?:\.href)?\s*=\s*["\']([^"\']{5,})["\']/', $body, $m)) {
            return $this->resolveUrl($currentUrl, $m[1]);
        }
        // <meta http-equiv="refresh" content="0; url=...">
        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\'][^;]+;\s*url=([^"\'>\s]+)/i', $body, $m)) {
            return $this->resolveUrl($currentUrl, html_entity_decode($m[1]));
        }
        // RedirectUri=... Parameter aus der aktuellen URL
        $query = parse_url($currentUrl, PHP_URL_QUERY) ?? '';
        if ($query !== '') {
            parse_str($query, $params);
            if (!empty($params['RedirectUri'])) {
                return $this->resolveUrl($currentUrl, $params['RedirectUri']);
            }
        }
        return '';
    }

    private function extractCodeFromUrl(string $url): string
    {
        // PHP parse_url() cannot handle custom schemes like melcloudhome://, use regex first
        if (preg_match('/[?&]code=([^&\s#]+)/', $url, $m)) {
            return urldecode($m[1]);
        }
        $query = parse_url($url, PHP_URL_QUERY);
        if ($query !== null && $query !== false && $query !== '') {
            parse_str($query, $params);
            if (!empty($params['code'])) {
                return $params['code'];
            }
        }
        $fragment = parse_url($url, PHP_URL_FRAGMENT) ?: '';
        if ($fragment !== '') {
            parse_str($fragment, $params);
            if (!empty($params['code'])) {
                return $params['code'];
            }
        }
        return '';
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
