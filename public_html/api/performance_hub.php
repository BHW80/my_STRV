<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Optionaler Jahresfilter
$yearParam = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : null;

try {
    $where = ['user_id = :user_id', "(average_watts > 0 OR normalized_power > 0 OR total_elevation_gain > 50)"];
    $params = ['user_id' => $userId];

    if ($yearParam !== null && $yearParam > 2000) {
        $where[] = 'YEAR(start_date) = :year';
        $params['year'] = $yearParam;
    }

    $whereSql = implode(' AND ', $where);

    // 1. Aggregierte Leistungsmetriken
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_rides,
            AVG(w_kg) AS avg_w_kg,
            MAX(w_kg) AS max_w_kg,
            AVG(normalized_power) AS avg_np,
            MAX(normalized_power) AS max_np,
            AVG(efficiency_factor) AS avg_ef,
            AVG(intensity_factor) AS avg_if,
            AVG(CASE WHEN average_watts > 0 AND normalized_power > 0 THEN normalized_power / average_watts ELSE NULL END) AS avg_vi,
            MAX(vam) AS max_vam,
            AVG(vam) AS avg_vam,
            AVG(elevation_per_km) AS avg_elevation_per_km
        FROM my_strv_activities
        WHERE $whereSql
    ");
    $stmt->execute($params);
    $summary = $stmt->fetch();

    // 2. Top 5 intensivste Einheiten nach TSS
    $topTssStmt = $pdo->prepare("
        SELECT 
            id, name, sport_type, start_date, distance, moving_time,
            tss, normalized_power, w_kg, efficiency_factor, total_elevation_gain, vam
        FROM my_strv_activities
        WHERE user_id = :user_id AND tss > 0
        ORDER BY tss DESC
        LIMIT 5
    ");
    $topTssStmt->execute(['user_id' => $userId]);
    $topTssRows = $topTssStmt->fetchAll();

    $topTss = [];
    foreach ($topTssRows as $r) {
        $movingSec = (int)$r['moving_time'];
        $hours = floor($movingSec / 3600);
        $minutes = floor(($movingSec % 3600) / 60);

        $topTss[] = [
            'id'               => (int)$r['id'],
            'name'             => $r['name'],
            'sport_type'       => $r['sport_type'],
            'date'             => date('d.m.Y', strtotime($r['start_date'])),
            'distance_km'      => round(((float)$r['distance']) / 1000.0, 1),
            'duration'         => sprintf('%dh %02dm', $hours, $minutes),
            'tss'              => round((float)$r['tss'], 0),
            'np'               => $r['normalized_power'] !== null ? round((float)$r['normalized_power'], 0) : null,
            'w_kg'             => $r['w_kg'] !== null ? round((float)$r['w_kg'], 2) : null,
            'ef'               => $r['efficiency_factor'] !== null ? round((float)$r['efficiency_factor'], 2) : null,
            'elevation_gain_m' => round((float)$r['total_elevation_gain'], 0),
            'vam'              => $r['vam'] !== null ? round((float)$r['vam'], 0) : null,
        ];
    }

    echo json_encode([
        'success' => true,
        'summary' => [
            'total_rides'           => (int)$summary['total_rides'],
            'avg_w_kg'              => $summary['avg_w_kg'] !== null ? round((float)$summary['avg_w_kg'], 2) : null,
            'max_w_kg'              => $summary['max_w_kg'] !== null ? round((float)$summary['max_w_kg'], 2) : null,
            'avg_np'                => $summary['avg_np'] !== null ? round((float)$summary['avg_np'], 0) : null,
            'max_np'                => $summary['max_np'] !== null ? round((float)$summary['max_np'], 0) : null,
            'avg_ef'                => $summary['avg_ef'] !== null ? round((float)$summary['avg_ef'], 2) : null,
            'avg_if'                => $summary['avg_if'] !== null ? round((float)$summary['avg_if'], 2) : null,
            'avg_vi'                => $summary['avg_vi'] !== null ? round((float)$summary['avg_vi'], 2) : null,
            'max_vam'               => $summary['max_vam'] !== null ? round((float)$summary['max_vam'], 0) : null,
            'avg_vam'               => $summary['avg_vam'] !== null ? round((float)$summary['avg_vam'], 0) : null,
            'avg_elevation_per_km'  => $summary['avg_elevation_per_km'] !== null ? round((float)$summary['avg_elevation_per_km'], 1) : null,
        ],
        'top_performances' => $topTss,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler beim Laden des Performance Hubs: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
