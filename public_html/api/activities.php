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
$environment = isset($_GET['environment']) && in_array($_GET['environment'], ['indoor', 'outdoor'], true) ? $_GET['environment'] : 'all';

// Sortierung
$sortByParam = isset($_GET['sort_by']) ? trim((string)$_GET['sort_by']) : 'date';
$sortDirParam = isset($_GET['sort_dir']) && strtoupper((string)$_GET['sort_dir']) === 'ASC' ? 'ASC' : 'DESC';

$sortColumn = match ($sortByParam) {
    'distance'  => 'distance',
    'duration'  => 'moving_time',
    'title'     => 'name',
    'elevation' => 'total_elevation_gain',
    'watts'     => 'average_watts',
    'hr'        => 'average_heartrate',
    default     => 'start_date',
};

// Athleten-Zonenparameter laden (FTP, HFmax)
$athStmt = $pdo->prepare('SELECT ftp, max_hr, weight, rest_hr FROM my_strv_athlete_settings WHERE user_id = :user_id');
$athStmt->execute(['user_id' => $userId]);
$athlete = $athStmt->fetch() ?: ['ftp' => 250, 'max_hr' => 190, 'weight' => 75.0, 'rest_hr' => 50];
$ftp = (int)($athlete['ftp'] ?? 250);
$maxHr = (int)($athlete['max_hr'] ?? 190);

/**
 * Schätzt die Herzfrequenz-Zonenverteilung (Z1-Z5)
 */
function estimateHrZones(float $avgHr, int $maxHr, int $movingSec): array
{
    if ($avgHr <= 0 || $maxHr <= 0 || $movingSec <= 0) {
        return [];
    }

    $ranges = [
        ['label' => 'Z1', 'name' => 'Erholung', 'min' => 0, 'max' => (int)round($maxHr * 0.60), 'color' => '#94A3B8'],
        ['label' => 'Z2', 'name' => 'Ausdauer', 'min' => (int)round($maxHr * 0.60), 'max' => (int)round($maxHr * 0.70), 'color' => '#3B82F6'],
        ['label' => 'Z3', 'name' => 'Tempo',    'min' => (int)round($maxHr * 0.70), 'max' => (int)round($maxHr * 0.80), 'color' => '#10B981'],
        ['label' => 'Z4', 'name' => 'Schwelle', 'min' => (int)round($maxHr * 0.80), 'max' => (int)round($maxHr * 0.90), 'color' => '#F59E0B'],
        ['label' => 'Z5', 'name' => 'Anaerob',  'min' => (int)round($maxHr * 0.90), 'max' => $maxHr + 30,             'color' => '#EF4444'],
    ];

    $weights = [];
    $sigma = 14.0;
    foreach ($ranges as $i => $r) {
        $mid = ($r['min'] + $r['max']) / 2.0;
        $diff = abs($avgHr - $mid);
        $weights[$i] = exp(-0.5 * pow($diff / $sigma, 2.0));
    }

    $sumWeights = max(0.001, array_sum($weights));
    $zones = [];
    foreach ($ranges as $i => $r) {
        $pct = (int)round(($weights[$i] / $sumWeights) * 100.0);
        $sec = (int)round(($pct / 100.0) * $movingSec);
        $zones[] = [
            'zone'        => $r['label'],
            'name'        => $r['name'],
            'range_label' => ($r['max'] > 250) ? ('>' . $r['min']) : ($r['min'] . '-' . $r['max'] . ' bpm'),
            'percent'     => $pct,
            'seconds'     => $sec,
            'color'       => $r['color'],
        ];
    }
    return $zones;
}

/**
 * Schätzt die Coggan-Leistungszonen (Z1-Z7)
 */
function estimatePowerZones(float $avgWatt, int $ftp, int $movingSec): array
{
    if ($avgWatt <= 0 || $ftp <= 0 || $movingSec <= 0) {
        return [];
    }

    $ranges = [
        ['label' => 'Z1', 'name' => 'Aktive Erholung',    'min' => 0, 'max' => (int)round($ftp * 0.55), 'color' => '#94A3B8'],
        ['label' => 'Z2', 'name' => 'Grundlagenausdauer', 'min' => (int)round($ftp * 0.55), 'max' => (int)round($ftp * 0.75), 'color' => '#3B82F6'],
        ['label' => 'Z3', 'name' => 'Tempo',              'min' => (int)round($ftp * 0.75), 'max' => (int)round($ftp * 0.90), 'color' => '#10B981'],
        ['label' => 'Z4', 'name' => 'Schwellenbereich',   'min' => (int)round($ftp * 0.90), 'max' => (int)round($ftp * 1.05), 'color' => '#F59E0B'],
        ['label' => 'Z5', 'name' => 'VO2max',             'min' => (int)round($ftp * 1.05), 'max' => (int)round($ftp * 1.20), 'color' => '#F97316'],
        ['label' => 'Z6', 'name' => 'Anaerobe Kapazität', 'min' => (int)round($ftp * 1.20), 'max' => (int)round($ftp * 1.50), 'color' => '#EF4444'],
        ['label' => 'Z7', 'name' => 'Neuromuskulär',      'min' => (int)round($ftp * 1.50), 'max' => (int)round($ftp * 2.50), 'color' => '#881337'],
    ];

    $weights = [];
    $sigma = max(15.0, $ftp * 0.16);
    foreach ($ranges as $i => $r) {
        $mid = ($r['min'] + $r['max']) / 2.0;
        $diff = abs($avgWatt - $mid);
        $weights[$i] = exp(-0.5 * pow($diff / $sigma, 2.0));
    }

    $sumWeights = max(0.001, array_sum($weights));
    $zones = [];
    foreach ($ranges as $i => $r) {
        $pct = (int)round(($weights[$i] / $sumWeights) * 100.0);
        $sec = (int)round(($pct / 100.0) * $movingSec);
        $zones[] = [
            'zone'        => $r['label'],
            'name'        => $r['name'],
            'range_label' => ($r['max'] > 2000) ? ('>' . $r['min'] . 'W') : ($r['min'] . '-' . $r['max'] . 'W'),
            'percent'     => $pct,
            'seconds'     => $sec,
            'color'       => $r['color'],
        ];
    }
    return $zones;
}

try {
    // 1. Dynamische WHERE-Bedingungen aufbauen
    $where = ['user_id = :user_id'];
    $params = ['user_id' => $userId];

    if ($sportType !== null && $sportType !== 'all') {
        $st = strtolower($sportType);
        if ($st === 'ride' || $st === 'rad') {
            $where[] = "sport_type IN ('Ride', 'VirtualRide', 'MountainBikeRide', 'GravelRide', 'EBikeRide')";
        } elseif ($st === 'rowing' || $st === 'rudern') {
            $where[] = "sport_type IN ('Rowing', 'IndoorRowing')";
        } elseif ($st === 'run' || $st === 'laufen') {
            $where[] = "sport_type IN ('Run', 'TrailRun', 'VirtualRun', 'Walk', 'Hike')";
        } else {
            $where[] = 'sport_type = :sport_type';
            $params['sport_type'] = $sportType;
        }
    }

    if ($year !== null && is_numeric($year) && (int)$year > 2000) {
        $where[] = 'YEAR(start_date) = :year';
        $params['year'] = (int)$year;
    }

    if ($search !== null && trim($search) !== '') {
        $where[] = 'name LIKE :search';
        $params['search'] = '%' . trim($search) . '%';
    }

    if ($environment === 'indoor') {
        $where[] = "sport_type IN ('VirtualRide', 'IndoorRowing', 'Workout', 'WeightTraining')";
    } elseif ($environment === 'outdoor') {
        $where[] = "sport_type NOT IN ('VirtualRide', 'IndoorRowing', 'Workout', 'WeightTraining')";
    }

    $whereSql = implode(' AND ', $where);

    // 2. Gesamtzahl der Treffer für Paginierung
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM my_strv_activities WHERE $whereSql");
    $countStmt->execute($params);
    $totalItems = (int)$countStmt->fetchColumn();
    $totalPages = (int)ceil($totalItems / $perPage);

    // 3. Aktivitäten paginiert und sortiert abfragen
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
            max_cadence,
            average_heartrate,
            max_heartrate,
            average_watts,
            max_watts,
            weighted_average_watts,
            calories,
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
        ORDER BY $sortColumn $sortDirParam
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

    $indoorSports = ['VirtualRide', 'IndoorRowing', 'Workout', 'WeightTraining'];

    // 4. Formatierung & Zonenberechnung
    $activities = [];
    foreach ($rows as $row) {
        $movingSec = (int)$row['moving_time'];
        $elapsedSec = (int)$row['elapsed_time'];
        $pauseSec = max(0, $elapsedSec - $movingSec);

        $hours = floor($movingSec / 3600);
        $minutes = floor(($movingSec % 3600) / 60);
        $seconds = $movingSec % 60;
        $formattedDuration = $hours > 0 
            ? sprintf('%dh %02dm', $hours, $minutes) 
            : sprintf('%02dm %02ds', $minutes, $seconds);

        $distMeters = (float)$row['distance'];
        $distKm = round($distMeters / 1000.0, 2);

        $avgHr = $row['average_heartrate'] !== null ? (float)$row['average_heartrate'] : 0.0;
        $avgWatt = $row['normalized_power'] !== null ? (float)$row['normalized_power'] : ($row['average_watts'] !== null ? (float)$row['average_watts'] : 0.0);

        $isIndoor = in_array($row['sport_type'], $indoorSports, true);

        $hrZones = estimateHrZones($avgHr, $maxHr, $movingSec);
        $powerZones = estimatePowerZones($avgWatt, $ftp, $movingSec);

        $activities[] = [
            'id'                     => (int)$row['id'],
            'name'                   => $row['name'],
            'sport_type'             => $row['sport_type'],
            'is_indoor'              => $isIndoor,
            'start_date'             => $row['start_date'],
            'date_formatted'         => date('d.m.Y H:i', strtotime($row['start_date'])),
            'distance_km'            => $distKm,
            'moving_time_formatted'  => $formattedDuration,
            'moving_time_seconds'    => $movingSec,
            'elapsed_time_seconds'   => $elapsedSec,
            'pause_time_seconds'     => $pauseSec,
            'elevation_gain_m'       => round((float)$row['total_elevation_gain'], 0),
            'elevation_per_km'       => $row['elevation_per_km'] !== null ? round((float)$row['elevation_per_km'], 1) : 0,
            'avg_speed_kmh'          => $row['average_speed'] !== null ? round(((float)$row['average_speed']) * 3.6, 1) : null,
            'max_speed_kmh'          => $row['max_speed'] !== null ? round(((float)$row['max_speed']) * 3.6, 1) : null,
            'avg_watts'              => $row['average_watts'] !== null ? round((float)$row['average_watts'], 0) : null,
            'max_watts'              => $row['max_watts'] !== null ? round((float)$row['max_watts'], 0) : null,
            'normalized_power'       => $row['normalized_power'] !== null ? round((float)$row['normalized_power'], 0) : null,
            'intensity_factor'       => $row['intensity_factor'] !== null ? round((float)$row['intensity_factor'], 2) : null,
            'w_kg'                   => $row['w_kg'] !== null ? round((float)$row['w_kg'], 2) : null,
            'tss'                    => $row['tss'] !== null ? round((float)$row['tss'], 1) : null,
            'avg_hr'                 => $row['average_heartrate'] !== null ? round((float)$row['average_heartrate'], 0) : null,
            'max_hr'                 => $row['max_heartrate'] !== null ? round((float)$row['max_heartrate'], 0) : null,
            'avg_cadence'            => $row['average_cadence'] !== null ? round((float)$row['average_cadence'], 0) : null,
            'max_cadence'            => $row['max_cadence'] !== null ? round((float)$row['max_cadence'], 0) : null,
            'calories'               => $row['calories'] !== null ? (int)$row['calories'] : null,
            'split_500m_seconds'     => $row['split_500m_seconds'] !== null ? round((float)$row['split_500m_seconds'], 1) : null,
            'distance_per_stroke'    => $row['distance_per_stroke'] !== null ? round((float)$row['distance_per_stroke'], 2) : null,
            'joules_per_stroke'      => $row['joules_per_stroke'] !== null ? round((float)$row['joules_per_stroke'], 1) : null,
            'vam'                    => $row['vam'] !== null ? round((float)$row['vam'], 0) : null,
            'suffer_score'           => $row['suffer_score'] !== null ? (int)$row['suffer_score'] : null,
            'has_map'                => !empty($row['summary_polyline']),
            'summary_polyline'       => $row['summary_polyline'],
            'start_lat'              => $row['start_lat'],
            'start_lng'              => $row['start_lng'],
            'hr_zones'               => $hrZones,
            'power_zones'            => $powerZones,
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
            'sport_type'  => $sportType,
            'year'        => $year,
            'query'       => $search,
            'environment' => $environment,
            'sort_by'     => $sortByParam,
            'sort_dir'    => $sortDirParam,
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
