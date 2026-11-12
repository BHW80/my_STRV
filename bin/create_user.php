<?php
declare(strict_types=1);

/**
 * my_STRV - CLI User Seeder
 * 
 * Erstellt einen neuen Benutzer in der MariaDB-Datenbank.
 * Aus Sicherheitsgründen nur per CLI (Terminal/SSH) ausführbar.
 * 
 * Verwendung:
 *   php bin/create_user.php <username> <password> [custom_client_id] [custom_client_secret]
 *   oder interaktiv ohne Argumente:
 *   php bin/create_user.php
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die("Forbidden: Dieses Skript kann nur über die Kommandozeile ausgeführt werden.\n");
}

require_once __DIR__ . '/../config/config.php';

echo "========================================\n";
echo "   my_STRV - Benutzer-Erstellung (CLI)   \n";
echo "========================================\n\n";

$username = $argv[1] ?? null;
$password = $argv[2] ?? null;
$customClientId = $argv[3] ?? null;
$customClientSecret = $argv[4] ?? null;

// Interaktive Abfrage, falls keine CLI-Argumente übergeben wurden
if (empty($username)) {
    echo "Benutzername eingeben: ";
    $username = trim((string)fgets(STDIN));
}

if (empty($password)) {
    echo "Passwort eingeben: ";
    $password = trim((string)fgets(STDIN));
}

if (empty($username) || empty($password)) {
    echo "FEHLER: Benutzername und Passwort dürfen nicht leer sein.\n";
    exit(1);
}

if (strlen($password) < 8) {
    echo "WARNUNG: Das Passwort sollte aus Sicherheitsgründen mindestens 8 Zeichen lang sein.\n";
}

$pdo = getDbConnection();

// Prüfen, ob der Benutzer bereits existiert
$checkStmt = $pdo->prepare('SELECT id FROM my_strv_users WHERE username = :username');
$checkStmt->execute(['username' => $username]);
if ($checkStmt->fetch()) {
    echo "FEHLER: Ein Benutzer mit dem Namen '{$username}' existiert bereits.\n";
    exit(1);
}

// Passwort sicher hashen (PASSWORD_DEFAULT nutzt Argon2id oder starkes Bcrypt)
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo->beginTransaction();

    // 1. Benutzer einfügen
    $stmt = $pdo->prepare('
        INSERT INTO my_strv_users (username, password_hash, custom_client_id, custom_client_secret)
        VALUES (:username, :password_hash, :custom_client_id, :custom_client_secret)
    ');
    $stmt->execute([
        'username'             => $username,
        'password_hash'        => $passwordHash,
        'custom_client_id'     => !empty($customClientId) ? trim($customClientId) : null,
        'custom_client_secret' => !empty($customClientSecret) ? trim($customClientSecret) : null,
    ]);

    $userId = (int)$pdo->lastInsertId();

    // 2. Standard-Athleten-Einstellungen anlegen
    $settingsStmt = $pdo->prepare('
        INSERT INTO my_strv_athlete_settings (user_id, ftp, weight, max_hr, rest_hr)
        VALUES (:user_id, 250, 75.0, 190, 50)
    ');
    $settingsStmt->execute(['user_id' => $userId]);

    $pdo->commit();

    echo "\nERFOLG: Benutzer '{$username}' (ID: {$userId}) wurde erfolgreich angelegt!\n";
    echo "- Athleten-Standardeinstellungen (FTP: 250W, Gewicht: 75kg) wurden initialisiert.\n";
    if (!empty($customClientId)) {
        echo "- Eigene Strava API-Keys wurden hinterlegt.\n";
    } else {
        echo "- Es werden die globalen Strava API-Defaults aus config.php genutzt.\n";
    }
    echo "\nDer Benutzer kann sich nun unter https://weltler.at/login.php anmelden.\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "FEHLER beim Anlegen des Benutzers: " . $e->getMessage() . "\n";
    exit(1);
}
