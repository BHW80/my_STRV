<?php
declare(strict_types=1);

/**
 * my_STRV - Configuration Template
 * Kopiere diese Datei nach `config/config.php` und trage deine echten Zugangsdaten ein.
 * Die Datei `config/config.php` wird von .gitignore ignoriert und enthält sensible Daten.
 */

// ---------------------------------------------------------------------
// 1. Datenbank-Konfiguration (MariaDB / MySQL)
// ---------------------------------------------------------------------
define('DB_HOST', 'localhost');
define('DB_NAME', 'my_strv_db');
define('DB_USER', 'my_strv_user');
define('DB_PASS', 'GEHEIMES_DATENBANK_PASSWORT');
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------
// 2. Globale Strava API v3 Credentials (Fallback für alle Mandanten)
// ---------------------------------------------------------------------
// Registriere deine App unter https://www.strava.com/settings/api
define('GLOBAL_STRAVA_CLIENT_ID', 'DEINE_STRAVA_CLIENT_ID');
define('GLOBAL_STRAVA_CLIENT_SECRET', 'DEIN_STRAVA_CLIENT_SECRET');
define('GLOBAL_STRAVA_REDIRECT_URI', 'https://weltler.at/auth/callback.php');

// ---------------------------------------------------------------------
// 3. Sichere Session-Initialisierung
// ---------------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    // Bei HTTPS wird secure=true erzwungen
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 Tage Persistent Login
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);

    session_start();
}

// ---------------------------------------------------------------------
// 4. PDO Datenbank-Verbindung (Singleton Pattern)
// ---------------------------------------------------------------------
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Bei Produktions-Host niemals Passwörter im Callstack leaken
            error_log('Database connection failed: ' . $e->getMessage());
            die('Datenbankverbindung fehlgeschlagen. Bitte System-Administrator kontaktieren.');
        }
    }

    return $pdo;
}
