<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>my_STRV - Diagnose</title></head><body style="font-family:sans-serif; padding:2rem; line-height:1.6;">';
echo '<h1>my_STRV System-Diagnose</h1>';

// 1. PHP Version
echo '<h3>1. PHP Status</h3>';
echo '<p style="color:green;">✓ PHP läuft einwandfrei! (Version: ' . htmlspecialchars(phpversion()) . ')</p>';

// 2. config.php Existenz
echo '<h3>2. Konfigurationsdatei (config/config.php)</h3>';
$configPath = __DIR__ . '/../config/config.php';
if (!file_exists($configPath)) {
    echo '<p style="color:red; font-weight:bold;">✗ FEHLER: Die Datei <code>' . htmlspecialchars($configPath) . '</code> existiert auf dem Server nicht!</p>';
    echo '<p>Lösung: Erstelle die Datei <code>config/config.php</code> aus der Vorlage <code>config/config.example.php</code>.</p>';
    echo '</body></html>';
    exit;
}
echo '<p style="color:green;">✓ Datei config/config.php existiert.</p>';

// 3. config.php einbinden
echo '<h3>3. Konfiguration laden</h3>';
try {
    require_once $configPath;
    echo '<p style="color:green;">✓ config/config.php erfolgreich geladen.</p>';
} catch (Throwable $e) {
    echo '<p style="color:red; font-weight:bold;">✗ FEHLER beim Laden von config.php: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
    echo '</body></html>';
    exit;
}

// 4. Session-Test
echo '<h3>4. PHP Session Status</h3>';
if (session_status() === PHP_SESSION_ACTIVE) {
    echo '<p style="color:green;">✓ Session aktiv (ID: ' . htmlspecialchars(session_id()) . ')</p>';
} else {
    echo '<p style="color:orange;">⚠ Session ist nicht aktiv.</p>';
}

// 5. Auth Service
echo '<h3>5. Authentifizierungs-Klasse (src/Auth.php)</h3>';
$authPath = __DIR__ . '/../src/Auth.php';
if (!file_exists($authPath)) {
    echo '<p style="color:red; font-weight:bold;">✗ FEHLER: src/Auth.php existiert nicht unter: ' . htmlspecialchars($authPath) . '</p>';
    echo '</body></html>';
    exit;
}
try {
    require_once $authPath;
    echo '<p style="color:green;">✓ src/Auth.php erfolgreich geladen.</p>';
} catch (Throwable $e) {
    echo '<p style="color:red; font-weight:bold;">✗ FEHLER in src/Auth.php: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}

// 6. Datenbank-Verbindung
echo '<h3>6. MariaDB Datenbank-Verbindung</h3>';
try {
    $pdo = getDbConnection();
    echo '<p style="color:green;">✓ Verbindung zur Datenbank erfolgreich hergestellt!</p>';

    // Tabellen prüfen
    $tables = $pdo->query("SHOW TABLES LIKE 'my_strv_%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($tables)) {
        echo '<p style="color:green;">✓ Gefundene Tabellen: ' . implode(', ', $tables) . '</p>';
    } else {
        echo '<p style="color:orange; font-weight:bold;">⚠ Verbindung steht, aber es wurden noch keine Tabellen mit Präfix my_strv_ gefunden. Hast du schema.sql schon importiert?</p>';
    }
} catch (Throwable $e) {
    echo '<p style="color:red; font-weight:bold;">✗ Datenbankverbindung fehlgeschlagen: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Prüfe DB_HOST, DB_NAME, DB_USER und DB_PASS in deiner <code>config/config.php</code>.</p>';
}

echo '<hr><h2 style="color:green;">Diagnose abgeschlossen!</h2>';
echo '<p><a href="login.php" style="padding:10px 18px; background:#FC4C02; color:white; text-decoration:none; border-radius:8px; font-weight:bold;">Weiter zu login.php</a></p>';
echo '</body></html>';
