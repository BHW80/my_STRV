<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * my_STRV - Authentication & Account Management Service
 * 
 * Behandelt:
 * - Benutzer-Login & Session-Fixation-Schutz
 * - Session-Guard (requireAuth für Web & API)
 * - Logout & Cookie-Terminierung
 * - DSGVO-konforme Account-Löschung (Passwort-Bestätigung, Strava-Deauth & Kaskaden-Löschung)
 */
class Auth
{
    /**
     * Authentifiziert einen Benutzer und startet eine geschützte Session.
     */
    public static function login(string $username, string $password): bool
    {
        $pdo = getDbConnection();

        $stmt = $pdo->prepare('SELECT id, username, password_hash FROM my_strv_users WHERE username = :username LIMIT 1');
        $stmt->execute(['username' => trim($username)]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        // Session-Fixation verhindern: Session-ID nach erfolgreichem Login regenerieren
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];

        // Letzten Login-Zeitstempel aktualisieren
        $updateStmt = $pdo->prepare('UPDATE my_strv_users SET last_login = NOW() WHERE id = :id');
        $updateStmt->execute(['id' => $user['id']]);

        return true;
    }

    /**
     * Beendet die Session und invalidiert die Cookies vollständig.
     */
    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];

            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }

            session_destroy();
        }
    }

    /**
     * Liefert die ID des aktuell eingeloggten Nutzers oder null.
     */
    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    /**
     * Liefert den Benutzernamen des aktuell eingeloggten Nutzers oder null.
     */
    public static function username(): ?string
    {
        return $_SESSION['username'] ?? null;
    }

    /**
     * Session-Guard: Erzwingt Authentifizierung.
     * 
     * - Web-Anfragen: Weiterleitung auf /login.php
     * - API-Anfragen: HTTP 401 Unauthorized mit JSON-Meldung
     * 
     * @return int Die verifizierte user_id
     */
    public static function requireAuth(): int
    {
        $userId = self::id();

        if ($userId !== null) {
            return $userId;
        }

        // Erkennung, ob es sich um einen API-Aufruf handelt
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $isApi = str_contains($uri, '/api/') || str_contains($accept, 'application/json');

        if ($isApi) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error'   => 'Unauthorized',
                'message' => 'Bitte einloggen, um auf diesen Endpunkt zuzugreifen.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        header('Location: /login.php');
        exit;
    }

    /**
     * DSGVO-konforme, vollständige Account- und Datenlöschung:
     * 
     * 1. Verifiziert das Passwort zur Bestätigung.
     * 2. Ruft StravaOAuth::deauthorize() auf, um Tokens bei Strava zu widerrufen.
     * 3. Löscht den User in my_strv_users (MariaDB ON DELETE CASCADE löscht alle Aktivitäten,
     *    Tokens, Einstellungen, Ausrüstung und Logs restlos mit!).
     * 4. Beendet die Session (logout).
     */
    public static function deleteAccount(int $userId, string $passwordConfirm): bool
    {
        $pdo = getDbConnection();

        // 1. Passwort prüfen
        $stmt = $pdo->prepare('SELECT password_hash FROM my_strv_users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($passwordConfirm, $hash)) {
            return false;
        }

        // 2. Strava OAuth deauthorisieren (falls Klasse existiert und Token vorhanden ist)
        if (class_exists('StravaOAuth')) {
            try {
                StravaOAuth::deauthorize($userId);
            } catch (Throwable $e) {
                error_log("Warnung: Strava-Deauthorisierung für User $userId fehlgeschlagen: " . $e->getMessage());
            }
        }

        // 3. Benutzer löschen -> Kaskadierende Löschung in allen abhängigen Tabellen
        $deleteStmt = $pdo->prepare('DELETE FROM my_strv_users WHERE id = :id');
        $deleteStmt->execute(['id' => $userId]);

        // 4. Session zerstören
        self::logout();

        return true;
    }
}
