<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Parameter & Filter auslesen
$page = max(1, isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1);
$perPage = max(1, min(100, isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? (int)$_GET['per_page'] : 20));
$offset = ($page - 1) * $perPage;

$sportType = isset($_GET['sport_type']) && trim((string)$_GET['sport_type']) !== '' ? trim((string)$_GET['sport_type']) : null;
$year = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : null;
$search = isset($_GET['query']) && trim((string)$_GET['query']) !== '' ? trim((string)$_GET['query']) : null;

try {
    // 1. Dynamische WHERE-Bedingungen aufbauen (strikte Mandantenisolation)
    $where = ['user_id = :user_id'];
    $params = ['user_id' => $userId];

    if ($sportType !== null && $sportType !== 'all') {
        $where[] = 'sport_type = :sport_type';
        $params['sport_type'] = $sportType;
    }

    if ($year !== null && $year > 2000) {
        $where[] = 'YEAR(start_date) = :year';
        $params['year'] = $year;
    }

    if ($search !== null) {
        $where[] = 'name LIKE :search';
        $params['search'] = '%' . $search . '%';
    }

    $whereSql = implode(' AND ', $where);

    // 2. Gesamtzahl der gefilterten Aktivitäten für die Paginierung
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM my_strv_activities WHERE $whereSql");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalItems / $perPage);

    // 3. Paginierte Aktivitäten abfragen
    $sql = "
        SELECT 
            id,
            athlete_id,
            name,
            sport_type,
            start_date,
            distance,
            moving_time,
            elapsed_time,
            total_elevation_gain,
            elevation_per_km,
            pause_time,
            average_speed,
            max_speed,
            average_cadence,
            average_heartrate,
            max_heartrate,
            average_watts,
            max_watts,
            weighted_average_watts,
            suffer_score,
            split_500m_seconds,
            distance_per_stroke,
            joules_per_stroke,
            w_kg,
            normalized_power,
            intensity_factor,
            tss,
            efficiency_factor,
            vam,
            summary_polyline,
            start_lat,
            start_lng
        FROM my_strv_activities
        WHERE $whereSql
        ORDER BY start_date DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // 4. Formatierung für UI-Karten
    $activities = [];
    foreach ($rows as $row) {
        $movingSec = (int)$row['moving_time'];
        $hours = floor($movingSec / 3600);
        $minutes = floor(($movingSec % 3600) / 60);
        $seconds = $movingSec % 60;
        $formattedDuration = $hours > 0 
            ? sprintf('%dh %02dm', $hours, $minutes) 
            : sprintf('%02dm %02ds', $minutes, $seconds);

        $distMeters = (float)$row['distance'];
        $distKm = round($distMeters / 1000.0, 2);

        $activities[] = [
            'id'                     => (int)$row['id'],
            'name'                   => $row['name'],
            'sport_type'             => $row['sport_type'],
            'start_date'             => $row['start_date'],
            'date_formatted'         => date('d.m.Y H:i', strtotime($row['start_date'])),
            'distance_km'            => $distKm,
            'moving_time_formatted'  => $formattedDuration,
            'moving_time_seconds'    => $movingSec,
            'elevation_gain_m'       => round((float)$row['total_elevation_gain'], 0),
            'elevation_per_km'       => $row['elevation_per_km'] !== null ? round((float)$row['elevation_per_km'], 1) : 0,
            'avg_speed_kmh'          => $row['average_speed'] !== null ? round(((float)$row['average_speed']) * 3.6, 1) : null,
            'avg_watts'              => $row['average_watts'] !== null ? round((float)$row['average_watts'], 0) : null,
            'normalized_power'       => $row['normalized_power'] !== null ? round((float)$row['normalized_power'], 0) : null,
            'w_kg'                   => $row['w_kg'] !== null ? round((float)$row['w_kg'], 2) : null,
            'tss'                    => $row['tss'] !== null ? round((float)$row['tss'], 1) : null,
            'avg_hr'                 => $row['average_heartrate'] !== null ? round((float)$row['average_heartrate'], 0) : null,
            'max_hr'                 => $row['max_heartrate'] !== null ? round((float)$row['max_heartrate'], 0) : null,
            'avg_cadence'            => $row['average_cadence'] !== null ? round((float)$row['average_cadence'], 0) : null,
            'split_500m_seconds'     => $row['split_500m_seconds'] !== null ? round((float)$row['split_500m_seconds'], 1) : null,
            'distance_per_stroke'    => $row['distance_per_stroke'] !== null ? round((float)$row['distance_per_stroke'], 2) : null,
            'suffer_score'           => $row['suffer_score'] !== null ? (int)$row['suffer_score'] : null,
            'has_map'                => !empty($row['summary_polyline']),
            'summary_polyline'       => $row['summary_polyline'],
            'start_lat'              => $row['start_lat'],
            'start_lng'              => $row['start_lng'],
        ];
    }

    echo json_encode([
        'success'    => true,
        'pagination' => [
            'current_page' => $page,
            'per_page'     => $perPage,
            'total_items'  => $totalItems,
            'total_pages'  => $totalPages,
        ],
        'filters'    => [
            'sport_type' => $sportType,
            'year'       => $year,
            'query'      => $search,
        ],
        'activities' => $activities,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler beim Laden der Aktivitäten: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
