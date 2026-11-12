<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/StravaOAuth.php';

$userId = Auth::requireAuth();

// Prüfen auf Fehler von Strava (z. B. wenn der Benutzer im OAuth-Screen "Abbrechen" klickt)
if (isset($_GET['error'])) {
    $errorMsg = urlencode('Strava-Verbindung abgebrochen: ' . $_GET['error']);
    header("Location: /index.php?error={$errorMsg}");
    exit;
}

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$sessionState = $_SESSION['strava_oauth_state'] ?? null;

// CSRF-Schutz: State prüfen
if (empty($code) || empty($state) || $state !== $sessionState) {
    http_response_code(400);
    die('Ungültiger oder abgelaufener OAuth-State (CSRF-Schutz). Bitte erneut versuchen.');
}

// State nach Prüfung verbrauchen
unset($_SESSION['strava_oauth_state']);

try {
    StravaOAuth::handleCallback($userId, $code);
    header('Location: /index.php?strava=connected');
    exit;
} catch (Throwable $e) {
    error_log("Fehler bei Strava Callback für User $userId: " . $e->getMessage());
    $errorMsg = urlencode('Verbindung fehlgeschlagen: ' . $e->getMessage());
    header("Location: /index.php?error={$errorMsg}");
    exit;
}
