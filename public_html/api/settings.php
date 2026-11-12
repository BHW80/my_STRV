<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// ---------------------------------------------------------------------
// 1. GET: Einstellungen abrufen
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Athleten-Trainingswerte
        $settingsStmt = $pdo->prepare('
            SELECT ftp, weight, max_hr, rest_hr 
            FROM my_strv_athlete_settings 
            WHERE user_id = :user_id LIMIT 1
        ');
        $settingsStmt->execute(['user_id' => $userId]);
        $settings = $settingsStmt->fetch() ?: [
            'ftp'     => 250,
            'weight'  => 75.0,
            'max_hr'  => 190,
            'rest_hr' => 50,
        ];

        // Benutzerprofil & optionale eigene Strava API-Keys
        $userStmt = $pdo->prepare('
            SELECT username, custom_client_id, custom_client_secret, created_at, last_login 
            FROM my_strv_users 
            WHERE id = :user_id LIMIT 1
        ');
        $userStmt->execute(['user_id' => $userId]);
        $user = $userStmt->fetch();

        // Strava Verbindungs-Status
        $tokenStmt = $pdo->prepare('
            SELECT athlete_id, expires_at, updated_at 
            FROM my_strv_strava_tokens 
            WHERE user_id = :user_id LIMIT 1
        ');
        $tokenStmt->execute(['user_id' => $userId]);
        $token = $tokenStmt->fetch();

        echo json_encode([
            'success' => true,
            'profile' => [
                'user_id'    => $userId,
                'username'   => $user['username'] ?? '',
                'created_at' => $user['created_at'] ?? '',
                'last_login' => $user['last_login'] ?? '',
            ],
            'athlete' => [
                'ftp'     => (int)$settings['ftp'],
                'weight'  => (float)$settings['weight'],
                'max_hr'  => (int)$settings['max_hr'],
                'rest_hr' => (int)$settings['rest_hr'],
            ],
            'strava'  => [
                'is_connected'              => !empty($token),
                'athlete_id'                => $token['athlete_id'] ?? null,
                'last_sync'                 => $token['updated_at'] ?? null,
                'has_custom_keys'           => !empty($user['custom_client_id']),
                'custom_client_id'          => $user['custom_client_id'] ?? '',
                // Client-Secret niemals im Klartext zurückgeben, nur ob eins gesetzt ist
                'has_custom_client_secret'  => !empty($user['custom_client_secret']),
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;

    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---------------------------------------------------------------------
// 2. POST: Einstellungen speichern
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ungültiges JSON-Format.']);
        exit;
    }

    $ftp = isset($data['ftp']) ? max(50, min(600, (int)$data['ftp'])) : 250;
    $weight = isset($data['weight']) ? max(30.0, min(250.0, (float)$data['weight'])) : 75.0;
    $maxHr = isset($data['max_hr']) ? max(100, min(240, (int)$data['max_hr'])) : 190;
    $restHr = isset($data['rest_hr']) ? max(30, min(120, (int)$data['rest_hr'])) : 50;

    $customClientId = isset($data['custom_client_id']) ? trim((string)$data['custom_client_id']) : null;
    $customClientSecret = isset($data['custom_client_secret']) ? trim((string)$data['custom_client_secret']) : null;

    try {
        $pdo->beginTransaction();

        // 1. Trainingswerte in my_strv_athlete_settings speichern
        $stmtSettings = $pdo->prepare('
            INSERT INTO my_strv_athlete_settings (user_id, ftp, weight, max_hr, rest_hr)
            VALUES (:user_id, :ftp, :weight, :max_hr, :rest_hr)
            ON DUPLICATE KEY UPDATE
                ftp = VALUES(ftp),
                weight = VALUES(weight),
                max_hr = VALUES(max_hr),
                rest_hr = VALUES(rest_hr)
        ');
        $stmtSettings->execute([
            'user_id' => $userId,
            'ftp'     => $ftp,
            'weight'  => $weight,
            'max_hr'  => $maxHr,
            'rest_hr' => $restHr,
        ]);

        // 2. Custom Strava Keys in my_strv_users aktualisieren (sofern übergeben)
        if ($customClientId !== null) {
            $clientIdVal = !empty($customClientId) ? $customClientId : null;

            if (!empty($customClientSecret)) {
                $userUpdate = $pdo->prepare('
                    UPDATE my_strv_users 
                    SET custom_client_id = :cid, custom_client_secret = :csec 
                    WHERE id = :user_id
                ');
                $userUpdate->execute([
                    'cid'     => $clientIdVal,
                    'csec'    => $customClientSecret,
                    'user_id' => $userId,
                ]);
            } else {
                $userUpdate = $pdo->prepare('
                    UPDATE my_strv_users 
                    SET custom_client_id = :cid 
                    WHERE id = :user_id
                ');
                $userUpdate->execute([
                    'cid'     => $clientIdVal,
                    'user_id' => $userId,
                ]);
            }
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Einstellungen erfolgreich gespeichert.',
            'athlete' => [
                'ftp'     => $ftp,
                'weight'  => $weight,
                'max_hr'  => $maxHr,
                'rest_hr' => $restHr,
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    } catch (Throwable $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Fehler beim Speichern: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
