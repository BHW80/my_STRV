<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Hilfsfunktion: Konvertiert Sekunden in mm:ss.s Format (z.B. 124.5s -> "02:04.5")
function formatPace500m(?float $seconds): ?string
{
    if ($seconds === null || $seconds <= 0) {
        return null;
    }
    $min = floor($seconds / 60);
    $sec = $seconds - ($min * 60);
    return sprintf('%02d:%04.1f', $min, $sec);
}

try {
    // 1. Ruder-Gesamtstatistiken
    $summaryStmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_sessions,
            COALESCE(SUM(distance), 0) AS total_distance,
            COALESCE(SUM(moving_time), 0) AS total_moving_time,
            AVG(split_500m_seconds) AS avg_split_500m,
            MIN(split_500m_seconds) AS best_split_500m,
            AVG(distance_per_stroke) AS avg_dps,
            AVG(average_cadence) AS avg_spm,
            AVG(average_watts) AS avg_watts
        FROM my_strv_activities
        WHERE user_id = :user_id 
          AND sport_type IN ('Rowing', 'VirtualRow')
    ");
    $summaryStmt->execute(['user_id' => $userId]);
    $summary = $summaryStmt->fetch();

    // 2. Scatter-Plot: Schlagzahl (SPM) vs. Distanz pro Schlag (DPS) vs. Joule
    $scatterStmt = $pdo->prepare("
        SELECT 
            id,
            name,
            DATE(start_date) AS date,
            average_cadence AS spm,
            distance_per_stroke AS dps,
            joules_per_stroke AS jps,
            split_500m_seconds,
            average_watts,
            distance
        FROM my_strv_activities
        WHERE user_id = :user_id 
          AND sport_type IN ('Rowing', 'VirtualRow')
          AND average_cadence > 0
          AND distance_per_stroke > 0
        ORDER BY start_date ASC
    ");
    $scatterStmt->execute(['user_id' => $userId]);
    $scatterRaw = $scatterStmt->fetchAll();

    $scatterData = [];
    foreach ($scatterRaw as $r) {
        $scatterData[] = [
            'id'              => (int)$r['id'],
            'name'            => $r['name'],
            'date'            => $r['date'],
            'spm'             => round((float)$r['spm'], 1),
            'dps'             => round((float)$r['dps'], 2),
            'jps'             => $r['jps'] !== null ? round((float)$r['jps'], 1) : null,
            'split_formatted' => formatPace500m(isset($r['split_500m_seconds']) ? (float)$r['split_500m_seconds'] : null),
            'watts'           => $r['average_watts'] !== null ? round((float)$r['average_watts'], 0) : null,
            'distance_m'      => round((float)$r['distance'], 0),
        ];
    }

    // 3. Pace-Entwicklung (Timeline)
    $timelineStmt = $pdo->prepare("
        SELECT 
            id,
            name,
            DATE(start_date) AS date,
            split_500m_seconds,
            distance,
            moving_time,
            average_watts
        FROM my_strv_activities
        WHERE user_id = :user_id 
          AND sport_type IN ('Rowing', 'VirtualRow')
          AND split_500m_seconds > 0
        ORDER BY start_date ASC
    ");
    $timelineStmt->execute(['user_id' => $userId]);
    $timelineRaw = $timelineStmt->fetchAll();

    $timeline = [];
    foreach ($timelineRaw as $t) {
        $sec = (float)$t['split_500m_seconds'];
        $timeline[] = [
            'id'              => (int)$t['id'],
            'name'            => $t['name'],
            'date'            => $t['date'],
            'split_seconds'   => round($sec, 1),
            'split_formatted' => formatPace500m($sec),
            'distance_m'      => round((float)$t['distance'], 0),
            'moving_time_s'   => (int)$t['moving_time'],
            'avg_watts'       => $t['average_watts'] !== null ? round((float)$t['average_watts'], 0) : null,
        ];
    }

    // 4. Bestzeiten & Meilensteine (Top 5 schnellste Einheiten >= 1000m)
    $bestsStmt = $pdo->prepare("
        SELECT 
            id,
            name,
            DATE(start_date) AS date,
            distance,
            moving_time,
            split_500m_seconds,
            average_watts,
            distance_per_stroke
        FROM my_strv_activities
        WHERE user_id = :user_id 
          AND sport_type IN ('Rowing', 'VirtualRow')
          AND distance >= 1000
          AND split_500m_seconds > 0
        ORDER BY split_500m_seconds ASC
        LIMIT 5
    ");
    $bestsStmt->execute(['user_id' => $userId]);
    $bestsRaw = $bestsStmt->fetchAll();

    $topPerformances = [];
    foreach ($bestsRaw as $b) {
        $sec = (float)$b['split_500m_seconds'];
        $topPerformances[] = [
            'id'              => (int)$b['id'],
            'name'            => $b['name'],
            'date'            => $b['date'],
            'distance_m'      => round((float)$b['distance'], 0),
            'split_seconds'   => round($sec, 1),
            'split_formatted' => formatPace500m($sec),
            'avg_watts'       => $b['average_watts'] !== null ? round((float)$b['average_watts'], 0) : null,
            'dps'             => $b['distance_per_stroke'] !== null ? round((float)$b['distance_per_stroke'], 2) : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_sessions'       => (int)$summary['total_sessions'],
            'total_distance_km'    => round(((float)$summary['total_distance']) / 1000.0, 1),
            'total_moving_hours'   => round(((int)$summary['total_moving_time']) / 3600.0, 1),
            'avg_split_formatted'  => formatPace500m(isset($summary['avg_split_500m']) ? (float)$summary['avg_split_500m'] : null),
            'best_split_formatted' => formatPace500m(isset($summary['best_split_500m']) ? (float)$summary['best_split_500m'] : null),
            'avg_dps_meters'       => isset($summary['avg_dps']) ? round((float)$summary['avg_dps'], 2) : null,
            'avg_spm'              => isset($summary['avg_spm']) ? round((float)$summary['avg_spm'], 1) : null,
            'avg_watts'            => isset($summary['avg_watts']) ? round((float)$summary['avg_watts'], 0) : null,
        ],
        'scatter_data'     => $scatterData,
        'pace_timeline'    => $timeline,
        'top_performances' => $topPerformances,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler bei der Ruder-Analyse: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
