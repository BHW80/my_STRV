<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Optionaler Jahresfilter; Standard ist das aktuelle Kalenderjahr
$selectedYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

try {
    // 1. Verfügbare Jahre für das Frontend-Dropdown ermitteln
    $yearsStmt = $pdo->prepare('
        SELECT DISTINCT YEAR(start_date) AS yr 
        FROM my_strv_activities 
        WHERE user_id = :user_id 
        ORDER BY yr DESC
    ');
    $yearsStmt->execute(['user_id' => $userId]);
    $availableYears = array_map('intval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!empty($availableYears) && !in_array($selectedYear, $availableYears, true)) {
        // Falls das gewünschte Jahr keine Daten hat, auf das aktuellste verfügbare Jahr zurückfallen
        $selectedYear = $availableYears[0];
    }

    // 2. All-Time Summen
    $allTimeStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS total_activities,
            COALESCE(SUM(distance), 0) AS total_distance,
            COALESCE(SUM(total_elevation_gain), 0) AS total_elevation,
            COALESCE(SUM(moving_time), 0) AS total_moving_time
        FROM my_strv_activities 
        WHERE user_id = :user_id
    ');
    $allTimeStmt->execute(['user_id' => $userId]);
    $allTime = $allTimeStmt->fetch();

    // 3. Jahres-Summen (Selected Year)
    $yearStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS count,
            COALESCE(SUM(distance), 0) AS distance,
            COALESCE(SUM(total_elevation_gain), 0) AS elevation,
            COALESCE(SUM(moving_time), 0) AS moving_time,
            COALESCE(AVG(average_speed), 0) AS avg_speed,
            COALESCE(MAX(distance), 0) AS max_single_distance
        FROM my_strv_activities 
        WHERE user_id = :user_id AND YEAR(start_date) = :year
    ');
    $yearStmt->execute(['user_id' => $userId, 'year' => $selectedYear]);
    $yearData = $yearStmt->fetch();

    // 4. Sportarten-Aufteilung für das ausgewählte Jahr
    $sportsStmt = $pdo->prepare('
        SELECT 
            sport_type,
            COUNT(*) AS count,
            ROUND(SUM(distance) / 1000, 1) AS distance_km,
            ROUND(SUM(total_elevation_gain), 0) AS elevation_m,
            ROUND(SUM(moving_time) / 3600, 1) AS moving_hours
        FROM my_strv_activities 
        WHERE user_id = :user_id AND YEAR(start_date) = :year
        GROUP BY sport_type
        ORDER BY distance_km DESC, count DESC
    ');
    $sportsStmt->execute(['user_id' => $userId, 'year' => $selectedYear]);
    $sportsBreakdown = $sportsStmt->fetchAll();

    // 5. 28-Tage-Trend (Tagesaggregation der letzten 4 Wochen)
    $trendStmt = $pdo->prepare('
        SELECT 
            DATE(start_date) AS day,
            ROUND(SUM(distance) / 1000, 2) AS distance_km,
            ROUND(SUM(moving_time) / 60, 0) AS moving_minutes,
            ROUND(SUM(total_elevation_gain), 0) AS elevation_m,
            COUNT(*) AS activities_count
        FROM my_strv_activities 
        WHERE user_id = :user_id 
          AND start_date >= DATE_SUB(CURDATE(), INTERVAL 27 DAY)
        GROUP BY DATE(start_date)
        ORDER BY day ASC
    ');
    $trendStmt->execute(['user_id' => $userId]);
    $trendRows = $trendStmt->fetchAll();

    // Kalendertage der letzten 28 Tage mit 0 auffüllen für lückenlose Sparklines
    $trendMap = [];
    foreach ($trendRows as $row) {
        $trendMap[$row['day']] = $row;
    }

    $trend28Days = [];
    for ($i = 27; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        if (isset($trendMap[$d])) {
            $trend28Days[] = $trendMap[$d];
        } else {
            $trend28Days[] = [
                'day'              => $d,
                'distance_km'      => 0,
                'moving_minutes'   => 0,
                'elevation_m'      => 0,
                'activities_count' => 0,
            ];
        }
    }

    echo json_encode([
        'success'         => true,
        'selected_year'   => $selectedYear,
        'available_years' => $availableYears,
        'all_time'        => [
            'total_activities'   => (int)$allTime['total_activities'],
            'total_distance_km'  => round(((float)$allTime['total_distance']) / 1000.0, 1),
            'total_elevation_m'  => round((float)$allTime['total_elevation'], 0),
            'total_moving_hours' => round(((int)$allTime['total_moving_time']) / 3600.0, 1),
        ],
        'yearly_stats'    => [
            'count'               => (int)$yearData['count'],
            'distance_km'         => round(((float)$yearData['distance']) / 1000.0, 1),
            'elevation_m'         => round((float)$yearData['elevation'], 0),
            'moving_hours'        => round(((int)$yearData['moving_time']) / 3600.0, 1),
            'avg_speed_kmh'       => round(((float)$yearData['avg_speed']) * 3.6, 2),
            'max_distance_km'     => round(((float)$yearData['max_single_distance']) / 1000.0, 1),
        ],
        'sports_breakdown' => $sportsBreakdown,
        'trend_28_days'    => $trend28Days,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler beim Laden der Statistik: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
