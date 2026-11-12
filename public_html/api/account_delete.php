<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/StravaOAuth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

// Nur POST-Anfragen für destruktive Aktionen zulassen
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed. Nur POST-Anfragen sind für die Account-Löschung gestattet.'
    ]);
    exit;
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

$passwordConfirm = isset($data['password_confirm']) ? (string)$data['password_confirm'] : '';

if (empty($passwordConfirm)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => 'Zur Bestätigung der permanenten Löschung muss das aktuelle Passwort eingegeben werden.'
    ]);
    exit;
}

try {
    $deleted = Auth::deleteAccount($userId, $passwordConfirm);

    if (!$deleted) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error'   => 'Das eingegebene Passwort ist nicht korrekt. Die Account-Löschung wurde abgebrochen.'
        ]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Dein Account, alle Aktivitäten, GPS-Tracks und Strava-Tokens wurden permanent und restlos gelöscht.'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Schwerwiegender Fehler beim Löschen des Accounts: ' . $e->getMessage()
    ]);
}
