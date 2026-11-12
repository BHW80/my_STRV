<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

$sportType = isset($_GET['sport_type']) && trim((string)$_GET['sport_type']) !== '' ? trim((string)$_GET['sport_type']) : 'all';
$metric = isset($_GET['metric']) && in_array($_GET['metric'], ['distance', 'elevation', 'time'], true) ? $_GET['metric'] : 'distance';

try {
    // 1. Alle verfügbaren Jahre des Athleten ermitteln
    $yearsStmt = $pdo->prepare('
        SELECT DISTINCT YEAR(start_date) AS yr 
        FROM my_strv_activities 
        WHERE user_id = :user_id 
        ORDER BY yr ASC
    ');
    $yearsStmt->execute(['user_id' => $userId]);
    $years = array_map('intval', $yearsStmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($years)) {
        echo json_encode([
            'success'    => true,
            'years'      => [],
            'metric'     => $metric,
            'series'     => [],
            'day_labels' => []
        ]);
        exit;
    }

    // 2. Tägliche Werte nach Jahr und Tag des Jahres abfragen
    $where = ['user_id = :user_id'];
    $params = ['user_id' => $userId];

    if ($sportType !== 'all') {
        $where[] = 'sport_type = :sport_type';
        $params['sport_type'] = $sportType;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
        SELECT 
            YEAR(start_date) AS yr,
            DAYOFYEAR(start_date) AS day_of_year,
            SUM(distance) AS total_distance,
            SUM(total_elevation_gain) AS total_elevation,
            SUM(moving_time) AS total_moving_time
        FROM my_strv_activities
        WHERE $whereSql
        GROUP BY yr, day_of_year
        ORDER BY yr ASC, day_of_year ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // In Matrix gruppieren: $matrix[Jahr][TagDesJahres] = Wert
    $matrix = [];
    foreach ($rows as $r) {
        $y = (int)$r['yr'];
        $d = (int)$r['day_of_year'];

        if ($metric === 'elevation') {
            $val = (float)$r['total_elevation'];
        } elseif ($metric === 'time') {
            $val = ((float)$r['total_moving_time']) / 3600.0; // Stunden
        } else {
            $val = ((float)$r['total_distance']) / 1000.0; // Kilometer
        }

        $matrix[$y][$d] = $val;
    }

    $currentYear = (int)date('Y');
    $currentDayOfYear = (int)date('z') + 1; // 1 bis 366

    $series = [];

    foreach ($years as $y) {
        $isLeap = ($y % 4 === 0 && ($y % 100 !== 0 || $y % 400 === 0));
        $maxDaysInYear = $isLeap ? 366 : 365;

        // Wenn es das laufende Jahr ist, nur bis heute kumulieren (nicht flach bis Jahresende ziehen)
        $limitDay = ($y === $currentYear) ? min($currentDayOfYear, $maxDaysInYear) : $maxDaysInYear;

        $cumulativeSum = 0.0;
        $cumulativeCurve = [];

        for ($day = 1; $day <= $limitDay; $day++) {
            $dailyValue = $matrix[$y][$day] ?? 0.0;
            $cumulativeSum += $dailyValue;
            $cumulativeCurve[] = round($cumulativeSum, 1);
        }

        $series[(string)$y] = $cumulativeCurve;
    }

    // 3. Achsen-Labels generieren (z. B. "01.01.", "02.01." ... "31.12.")
    $dayLabels = [];
    $refDate = new DateTime('2024-01-01'); // 2024 als Schaltjahr für 366 Tage
    for ($i = 0; $i < 366; $i++) {
        $dayLabels[] = $refDate->format('d.m.');
        $refDate->modify('+1 day');
    }

    echo json_encode([
        'success'    => true,
        'metric'     => $metric,
        'sport_type' => $sportType,
        'years'      => $years,
        'series'     => $series,
        'day_labels' => $dayLabels,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler beim Berechnen des Jahresvergleichs: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
