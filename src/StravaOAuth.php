<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * my_STRV - Multi-Tenant Strava OAuth2 Service
 * 
 * Behandelt:
 * - Generierung der Strava OAuth Authorize-URL (Mandantenspezifisch oder Global)
 * - Tausch des Authorization Codes gegen Access- & Refresh-Tokens
 * - Automatisches Token-Refreshing (mit 5-Minuten-Sicherheitsabstand)
 * - DSGVO-Deautorisierung bei Strava (POST /oauth/deauthorize)
 */
class StravaOAuth
{
    private const AUTH_URL  = 'https://www.strava.com/oauth/authorize';
    private const TOKEN_URL = 'https://www.strava.com/oauth/token';
    private const DEAUTH_URL = 'https://www.strava.com/oauth/deauthorize';

    /**
     * Ermittelt die Strava API Credentials für einen bestimmten Benutzer.
     * Nutzt entweder die benutzerspezifischen Custom Keys oder die globalen Defaults.
     */
    public static function getCredentials(int $userId): array
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('SELECT custom_client_id, custom_client_secret FROM my_strv_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        $clientId = (!empty($row['custom_client_id'])) ? trim($row['custom_client_id']) : GLOBAL_STRAVA_CLIENT_ID;
        $clientSecret = (!empty($row['custom_client_secret'])) ? trim($row['custom_client_secret']) : GLOBAL_STRAVA_CLIENT_SECRET;

        return [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => GLOBAL_STRAVA_REDIRECT_URI,
        ];
    }

    /**
     * Generiert die Strava Authorize-URL für den eingeloggten Nutzer.
     */
    public static function getAuthorizationUrl(int $userId): string
    {
        $creds = self::getCredentials($userId);

        // Scopes: read, profile:read_all, activity:read_all (erforderlich für detaillierte Leistungs- und GPS-Daten)
        $params = [
            'client_id'       => $creds['client_id'],
            'redirect_uri'    => $creds['redirect_uri'],
            'response_type'   => 'code',
            'approval_prompt' => 'auto',
            'scope'           => 'read,profile:read_all,activity:read_all',
            'state'           => bin2hex(random_bytes(16)), // CSRF-Schutz
        ];

        // State in Session hinterlegen zur Verifikation beim Callback
        $_SESSION['strava_oauth_state'] = $params['state'];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Verarbeitet den OAuth-Callback von Strava, tauscht Code gegen Tokens
     * und persistiert sie isoliert in my_strv_strava_tokens.
     */
    public static function handleCallback(int $userId, string $code): array
    {
        $creds = self::getCredentials($userId);

        $payload = [
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'code'          => $code,
            'grant_type'    => 'authorization_code',
        ];

        $response = self::executePost(self::TOKEN_URL, $payload);

        if (!isset($response['access_token'], $response['refresh_token'], $response['expires_at'])) {
            throw new RuntimeException('Ungültige Antwort von Strava beim Token-Austausch: ' . json_encode($response));
        }

        $athleteId = (int)($response['athlete']['id'] ?? 0);
        $accessToken = (string)$response['access_token'];
        $refreshToken = (string)$response['refresh_token'];
        $expiresAt = (int)$response['expires_at'];
        $scope = $_SESSION['strava_oauth_scope'] ?? 'read,profile:read_all,activity:read_all';

        $pdo = getDbConnection();
        $stmt = $pdo->prepare('
            INSERT INTO my_strv_strava_tokens (user_id, athlete_id, access_token, refresh_token, expires_at, scope)
            VALUES (:user_id, :athlete_id, :access_token, :refresh_token, :expires_at, :scope)
            ON DUPLICATE KEY UPDATE
                athlete_id = VALUES(athlete_id),
                access_token = VALUES(access_token),
                refresh_token = VALUES(refresh_token),
                expires_at = VALUES(expires_at),
                scope = VALUES(scope),
                updated_at = NOW()
        ');

        $stmt->execute([
            'user_id'       => $userId,
            'athlete_id'    => $athleteId,
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at'    => $expiresAt,
            'scope'         => $scope,
        ]);

        // Auch Grundeinstellungen anlegen, falls noch nicht vorhanden
        $settingsStmt = $pdo->prepare('
            INSERT IGNORE INTO my_strv_athlete_settings (user_id, ftp, weight, max_hr, rest_hr)
            VALUES (:user_id, 250, 75.0, 190, 50)
        ');
        $settingsStmt->execute(['user_id' => $userId]);

        return $response;
    }

    /**
     * Liefert einen garantierten, gültigen Access Token für den Benutzer.
     * Prüft das Ablaufdatum; läuft der Token innerhalb der nächsten 5 Minuten ab,
     * wird er automatisch via Refresh-Token bei Strava erneuert.
     */
    public static function getValidAccessToken(int $userId): string
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('
            SELECT athlete_id, access_token, refresh_token, expires_at 
            FROM my_strv_strava_tokens 
            WHERE user_id = :user_id LIMIT 1
        ');
        $stmt->execute(['user_id' => $userId]);
        $tokenData = $stmt->fetch();

        if (!$tokenData) {
            throw new RuntimeException("Kein Strava-Konto mit User-ID $userId verbunden.");
        }

        // Puffer von 300 Sekunden (5 Minuten)
        if (time() < ($tokenData['expires_at'] - 300)) {
            return $tokenData['access_token'];
        }

        // Token ist abgelaufen oder läuft bald ab -> Refresh via Strava
        $creds = self::getCredentials($userId);

        $payload = [
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokenData['refresh_token'],
        ];

        $response = self::executePost(self::TOKEN_URL, $payload);

        if (!isset($response['access_token'], $response['refresh_token'], $response['expires_at'])) {
            throw new RuntimeException('Fehler beim Aktualisieren des Strava-Tokens: ' . json_encode($response));
        }

        $newAccessToken = (string)$response['access_token'];
        $newRefreshToken = (string)$response['refresh_token'];
        $newExpiresAt = (int)$response['expires_at'];

        $updateStmt = $pdo->prepare('
            UPDATE my_strv_strava_tokens 
            SET access_token = :access_token,
                refresh_token = :refresh_token,
                expires_at = :expires_at,
                updated_at = NOW()
            WHERE user_id = :user_id
        ');
        $updateStmt->execute([
            'access_token'  => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_at'    => $newExpiresAt,
            'user_id'       => $userId,
        ]);

        return $newAccessToken;
    }

    /**
     * DSGVO-Widerruf: Entzieht der App den Zugriff bei Strava (POST /oauth/deauthorize)
     * und löscht den Token-Eintrag in der Datenbank.
     */
    public static function deauthorize(int $userId): void
    {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('SELECT access_token FROM my_strv_strava_tokens WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $token = $stmt->fetchColumn();

        if ($token) {
            try {
                // Strava Deauthorization Call
                self::executePost(self::DEAUTH_URL, ['access_token' => $token]);
            } catch (Throwable $e) {
                error_log("Strava deauthorize HTTP call failed for user $userId: " . $e->getMessage());
            }

            // Lokalen Token löschen
            $deleteStmt = $pdo->prepare('DELETE FROM my_strv_strava_tokens WHERE user_id = :user_id');
            $deleteStmt->execute(['user_id' => $userId]);
        }
    }

    /**
     * Führt einen sicheren cURL POST-Request aus.
     */
    private static function executePost(string $url, array $params): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'my_STRV/1.0',
        ]);

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL-Fehler bei Anfrage an $url: $err");
        }

        $decoded = json_decode((string)$body, true);

        if ($httpCode >= 400 || !is_array($decoded)) {
            $msg = $decoded['message'] ?? $body;
            throw new RuntimeException("HTTP $httpCode Fehler von Strava: $msg");
        }

        return $decoded;
    }
}
