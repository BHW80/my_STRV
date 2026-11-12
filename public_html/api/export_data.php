<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

$pdo = getDbConnection();

// Alle Aktivitäten des eingeloggten Benutzers abrufen
$actStmt = $pdo->prepare('
    SELECT * FROM my_strv_activities 
    WHERE user_id = :user_id 
    ORDER BY start_date DESC
');
$actStmt->execute(['user_id' => $userId]);
$activities = $actStmt->fetchAll();

// Alle Ziele abrufen
$goalsStmt = $pdo->prepare('
    SELECT * FROM my_strv_goals 
    WHERE user_id = :user_id 
    ORDER BY created_at DESC
');
$goalsStmt->execute(['user_id' => $userId]);
$goals = $goalsStmt->fetchAll();

// Athleten-Einstellungen abrufen
$setStmt = $pdo->prepare('
    SELECT ftp, weight, max_hr, rest_hr 
    FROM my_strv_athlete_settings 
    WHERE user_id = :user_id
');
$setStmt->execute(['user_id' => $userId]);
$settings = $setStmt->fetch();

$backupData = [
    'platform'         => 'my_STRV',
    'version'          => '0.3.0',
    'exported_at'      => date('c'),
    'user_id'          => $userId,
    'athlete_settings' => $settings,
    'goals'            => $goals,
    'activities_count' => count($activities),
    'activities'       => $activities,
];

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="my_strv_backup_' . date('Y-m-d_His') . '.json"');

echo json_encode($backupData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
