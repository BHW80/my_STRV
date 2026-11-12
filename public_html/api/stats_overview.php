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

    // 6. Athleten-Leistungsdaten laden (für Intensitätszonen)
    $athStmt = $pdo->prepare('SELECT ftp, weight, max_hr, rest_hr FROM my_strv_athlete_settings WHERE user_id = :user_id');
    $athStmt->execute(['user_id' => $userId]);
    $athlete = $athStmt->fetch() ?: ['ftp' => 250, 'weight' => 75.0, 'max_hr' => 190, 'rest_hr' => 50];

    // 7. Regenerations- & Aktivitäts-Quote (Aktivtage vs. Ruhetage)
    $activeDaysStmt = $pdo->prepare('
        SELECT COUNT(DISTINCT DATE(start_date)) AS active_days
        FROM my_strv_activities
        WHERE user_id = :user_id AND YEAR(start_date) = :year
    ');
    $activeDaysStmt->execute(['user_id' => $userId, 'year' => $selectedYear]);
    $activeDays = (int)$activeDaysStmt->fetchColumn();

    $isCurrentYear = ($selectedYear === (int)date('Y'));
    $totalDaysInPeriod = $isCurrentYear ? ((int)date('z') + 1) : 365;
    $restDays = max(0, $totalDaysInPeriod - $activeDays);
    $restDayRatio = $totalDaysInPeriod > 0 ? round(($restDays / (float)$totalDaysInPeriod) * 100.0, 1) : 0.0;

    // 8. 4-Wochen-Volumentrend (Wochen-Blöcke W-4 bis W-1 rollend)
    $fourWeeks = [];
    for ($w = 4; $w >= 1; $w--) {
        $startDaysAgo = $w * 7 - 1;
        $endDaysAgo = ($w - 1) * 7;
        $wStart = date('Y-m-d 00:00:00', strtotime("-$startDaysAgo days"));
        $wEnd = date('Y-m-d 23:59:59', strtotime("-$endDaysAgo days"));

        $wStmt = $pdo->prepare('
            SELECT 
                COUNT(*) AS count,
                COALESCE(SUM(distance), 0) / 1000.0 AS distance_km,
                COALESCE(SUM(moving_time), 0) / 3600.0 AS hours,
                COALESCE(SUM(total_elevation_gain), 0) AS elevation_m
            FROM my_strv_activities
            WHERE user_id = :user_id AND start_date >= :w_start AND start_date <= :w_end
        ');
        $wStmt->execute([
            'user_id' => $userId,
            'w_start' => $wStart,
            'w_end'   => $wEnd,
        ]);
        $wData = $wStmt->fetch();

        $label = match ($w) {
            1 => 'Aktuelle Woche',
            2 => 'Vor 1 Woche',
            3 => 'Vor 2 Wochen',
            4 => 'Vor 3 Wochen',
        };

        $fourWeeks[] = [
            'week_index'   => $w,
            'label'        => $label,
            'start_date'   => substr($wStart, 0, 10),
            'end_date'     => substr($wEnd, 0, 10),
            'count'        => (int)$wData['count'],
            'distance_km'  => round((float)$wData['distance_km'], 1),
            'hours'        => round((float)$wData['hours'], 1),
            'elevation_m'  => round((float)$wData['elevation_m'], 0),
        ];
    }

    // 9. 80/20 Polarisierte Intensitätsanalyse (letzte 28 Tage)
    $last28DaysStart = date('Y-m-d 00:00:00', strtotime('-27 days'));
    $act28Stmt = $pdo->prepare('
        SELECT moving_time, suffer_score, average_watts, average_heartrate
        FROM my_strv_activities
        WHERE user_id = :user_id AND start_date >= :start_date
    ');
    $act28Stmt->execute([
        'user_id'    => $userId,
        'start_date' => $last28DaysStart,
    ]);
    $acts28 = $act28Stmt->fetchAll();

    $lowIntensitySec = 0;
    $highIntensitySec = 0;
    $ftpThreshold = ((float)($athlete['ftp'] ?? 250)) * 0.85;
    $hrThreshold = ((float)($athlete['max_hr'] ?? 190)) * 0.80;

    foreach ($acts28 as $act) {
        $sec = (int)$act['moving_time'];
        $isHigh = false;

        if ($act['suffer_score'] !== null && (int)$act['suffer_score'] > 60) {
            $isHigh = true;
        } elseif ($act['average_watts'] !== null && (float)$act['average_watts'] > $ftpThreshold) {
            $isHigh = true;
        } elseif ($act['average_heartrate'] !== null && (float)$act['average_heartrate'] > $hrThreshold) {
            $isHigh = true;
        }

        if ($isHigh) {
            $highIntensitySec += $sec;
        } else {
            $lowIntensitySec += $sec;
        }
    }

    $totalIntensitySec = $lowIntensitySec + $highIntensitySec;
    $lowRatioPct = $totalIntensitySec > 0 ? round(($lowIntensitySec / $totalIntensitySec) * 100.0, 1) : 100.0;
    $highRatioPct = $totalIntensitySec > 0 ? round(($highIntensitySec / $totalIntensitySec) * 100.0, 1) : 0.0;

    // 10. Monatstrend (MoM - Month over Month)
    $curMonthStart = date('Y-m-01 00:00:00');
    $prevMonthStart = date('Y-m-01 00:00:00', strtotime('first day of last month'));
    $prevMonthEnd = date('Y-m-t 23:59:59', strtotime('last day of last month'));

    $curMonthStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS count,
            COALESCE(SUM(distance), 0) / 1000.0 AS distance_km,
            COALESCE(SUM(moving_time), 0) / 3600.0 AS hours,
            COALESCE(SUM(total_elevation_gain), 0) AS elevation_m
        FROM my_strv_activities
        WHERE user_id = :user_id AND start_date >= :cur_start
    ');
    $curMonthStmt->execute(['user_id' => $userId, 'cur_start' => $curMonthStart]);
    $curMonthData = $curMonthStmt->fetch();

    $prevMonthStmt = $pdo->prepare('
        SELECT 
            COUNT(*) AS count,
            COALESCE(SUM(distance), 0) / 1000.0 AS distance_km,
            COALESCE(SUM(moving_time), 0) / 3600.0 AS hours,
            COALESCE(SUM(total_elevation_gain), 0) AS elevation_m
        FROM my_strv_activities
        WHERE user_id = :user_id AND start_date >= :prev_start AND start_date <= :prev_end
    ');
    $prevMonthStmt->execute([
        'user_id'    => $userId,
        'prev_start' => $prevMonthStart,
        'prev_end'   => $prevMonthEnd,
    ]);
    $prevMonthData = $prevMonthStmt->fetch();

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
        'rest_days_stats'  => [
            'total_days'          => $totalDaysInPeriod,
            'active_days'         => $activeDays,
            'rest_days'           => $restDays,
            'rest_day_ratio_pct'  => $restDayRatio,
        ],
        'four_weeks_trend' => $fourWeeks,
        'polarized_80_20'  => [
            'low_intensity_ratio'  => $lowRatioPct,
            'high_intensity_ratio' => $highRatioPct,
            'low_hours'            => round($lowIntensitySec / 3600.0, 1),
            'high_hours'           => round($highIntensitySec / 3600.0, 1),
            'total_hours'          => round($totalIntensitySec / 3600.0, 1),
        ],
        'mom_trend'        => [
            'current_month' => [
                'distance_km' => round((float)$curMonthData['distance_km'], 1),
                'hours'       => round((float)$curMonthData['hours'], 1),
                'elevation_m' => round((float)$curMonthData['elevation_m'], 0),
                'count'       => (int)$curMonthData['count'],
            ],
            'previous_month' => [
                'distance_km' => round((float)$prevMonthData['distance_km'], 1),
                'hours'       => round((float)$prevMonthData['hours'], 1),
                'elevation_m' => round((float)$prevMonthData['elevation_m'], 0),
                'count'       => (int)$prevMonthData['count'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler beim Laden der Statistik: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
