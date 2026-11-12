<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/SyncEngine.php';

// Session-Guard erzwingt eingeloggten Zustand (liefert HTTP 401 für APIs)
$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

// Timeout bei großen Initial-Loads auf 180 Sekunden erweitern (sofern Hosting-Umgebung erlaubt)
@set_time_limit(180);

$forceFull = false;
if (isset($_GET['full']) && ($_GET['full'] === '1' || $_GET['full'] === 'true')) {
    $forceFull = true;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $postData = json_decode($rawInput, true);
    if (!empty($postData['full'])) {
        $forceFull = true;
    }
}

try {
    $engine = new SyncEngine($userId);
    $result = $engine->sync($forceFull);

    if ($result['status'] === 'error') {
        http_response_code(500);
    } else {
        http_response_code(200);
    }

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Unerwarteter Serverfehler beim Synchronisieren: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
