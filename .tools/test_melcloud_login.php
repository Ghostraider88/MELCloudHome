<?php

declare(strict_types=1);

/**
 * Eigenständiges Testskript für den MELCloud-Home-Login (OAuth 2.0 + PKCE).
 *
 * Verwendung:
 *   MELCLOUD_EMAIL='mail@example.com' MELCLOUD_PASSWORD='geheim' php tools/test_melcloud_login.php
 *
 * Das Skript meldet sich an, holt /context und gibt die gefundenen Klimageräte aus.
 * Es dient ausschließlich der Verifikation des Anmeldeablaufs außerhalb von IP-Symcon.
 */

const AUTH_BASE_URL   = 'https://auth.melcloudhome.com';
const API_BASE_URL    = 'https://mobile.bff.melcloudhome.com';
const OAUTH_CLIENT_ID = 'homemobile';
const OAUTH_REDIRECT  = 'melcloudhome://';
const OAUTH_SCOPES    = 'openid profile email offline_access IdentityServerApi';
const USER_AGENT      = 'MonitorAndControl.App.Mobile/52 CFNetwork/3860.400.51 Darwin/25.3.0';

$email    = getenv('MELCLOUD_EMAIL') ?: '';
$password = getenv('MELCLOUD_PASSWORD') ?: '';
if ($email === '' || $password === '') {
    fwrite(STDERR, "Bitte MELCLOUD_EMAIL und MELCLOUD_PASSWORD als Umgebungsvariablen setzen.\n");
    exit(1);
}

$cookieJar = tempnam(sys_get_temp_dir(), 'melc_');

function base64url(string $d): string
{
    return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
}

function request(string $method, string $url, array $headers, ?string $body, string $cookieJar, bool $follow): array
{
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS      => 15,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
        CURLOPT_TIMEOUT        => 30
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('cURL: ' . curl_error($ch));
    }
    $status     = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $respBody   = substr($raw, $headerSize);
    $location   = '';
    foreach (preg_split('/\r?\n/', $headerText) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    return [$status, $respBody, $location];
}

function resolveUrl(string $base, string $rel): string
{
    if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $rel) || strpos($rel, OAUTH_REDIRECT) === 0) {
        return $rel;
    }
    $p = parse_url($base);
    $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '');
    if (strpos($rel, '/') === 0) {
        return $origin . $rel;
    }
    $path = isset($p['path']) ? preg_replace('#/[^/]*$#', '/', $p['path']) : '/';
    return $origin . $path . $rel;
}

try {
    $verifier  = base64url(random_bytes(48));
    $challenge = base64url(hash('sha256', $verifier, true));
    $state     = base64url(random_bytes(16));

    echo "1) PAR ...\n";
    [$st, $body] = request('POST', AUTH_BASE_URL . '/connect/par',
        ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . USER_AGENT],
        http_build_query([
            'response_type'         => 'code',
            'client_id'             => OAUTH_CLIENT_ID,
            'redirect_uri'          => OAUTH_REDIRECT,
            'scope'                 => OAUTH_SCOPES,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256'
        ]), $cookieJar, false);
    if ($st !== 201 && $st !== 200) {
        throw new RuntimeException("PAR HTTP $st: $body");
    }
    $par = json_decode($body, true);
    $requestUri = $par['request_uri'] ?? '';
    echo "   request_uri = $requestUri\n";

    echo "2) authorize -> login page ...\n";
    $url = AUTH_BASE_URL . '/connect/authorize?' . http_build_query([
        'client_id'   => OAUTH_CLIENT_ID,
        'request_uri' => $requestUri
    ]);
    $loginPage = '';
    $loginUrl  = '';
    $code      = '';
    for ($i = 0; $i < 10; $i++) {
        [$st, $body, $loc] = request('GET', $url, ['User-Agent: ' . USER_AGENT], null, $cookieJar, false);
        if ($loc !== '') {
            if (strpos($loc, OAUTH_REDIRECT) === 0) {
                parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
                $code = $q['code'] ?? '';
                break;
            }
            $url = resolveUrl($url, $loc);
            continue;
        }
        $loginPage = $body;
        $loginUrl  = $url;
        break;
    }

    if ($code === '') {
        echo "3) submit credentials ...\n";
        $csrf = '';
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/i', $loginPage, $m)) {
            $csrf = $m[1];
        }
        echo '   csrf = ' . ($csrf !== '' ? 'gefunden' : 'NICHT gefunden') . "\n";

        $url    = $loginUrl;
        $method = 'POST';
        $data   = http_build_query(['_csrf' => $csrf, 'username' => $email, 'password' => $password, 'cognitoAsfData' => '']);
        for ($i = 0; $i < 12; $i++) {
            $headers = ['User-Agent: ' . USER_AGENT, 'Referer: ' . $loginUrl];
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }
            [$st, $body, $loc] = request($method, $url, $headers, $data, $cookieJar, false);
            if ($loc !== '') {
                if (strpos($loc, OAUTH_REDIRECT) === 0) {
                    parse_str((string) parse_url($loc, PHP_URL_QUERY), $q);
                    $code = $q['code'] ?? '';
                    break;
                }
                $url = resolveUrl($url, $loc);
                $method = 'GET';
                $data = null;
                continue;
            }
            if (preg_match('/[?&#]code=([^"&\'<>\s]+)/', $body, $m)) {
                $code = urldecode($m[1]);
                break;
            }
            break;
        }
    }

    if ($code === '') {
        throw new RuntimeException('Kein Auth-Code erhalten – Login-Ablauf prüfen.');
    }
    echo "   auth code erhalten\n";

    echo "4) token exchange ...\n";
    [$st, $body] = request('POST', AUTH_BASE_URL . '/connect/token',
        ['Content-Type: application/x-www-form-urlencoded', 'User-Agent: ' . USER_AGENT],
        http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => OAUTH_REDIRECT,
            'code_verifier' => $verifier,
            'client_id'     => OAUTH_CLIENT_ID
        ]), $cookieJar, false);
    if ($st !== 200) {
        throw new RuntimeException("Token HTTP $st: $body");
    }
    $tokens = json_decode($body, true);
    echo "   access_token erhalten (expires_in=" . ($tokens['expires_in'] ?? '?') . ")\n";

    echo "5) GET /context ...\n";
    [$st, $body] = request('GET', API_BASE_URL . '/context',
        ['Authorization: Bearer ' . $tokens['access_token'], 'Accept: application/json', 'User-Agent: ' . USER_AGENT],
        null, $cookieJar, true);
    if ($st !== 200) {
        throw new RuntimeException("/context HTTP $st: $body");
    }

    $context = json_decode($body, true);
    file_put_contents('melcloud_context.json', json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "   /context gespeichert in melcloud_context.json (zum Abgleich der Feldnamen)\n";
    echo "Fertig.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'FEHLER: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_string($cookieJar) && file_exists($cookieJar)) {
        @unlink($cookieJar);
    }
}
