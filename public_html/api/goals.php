<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Mathematische Saisonkurve (portiert aus Strv2ShtsAI)
 */
function getSeasonalFraction(string $seasonality, int $dayOfYear): float
{
    $day = max(1.0, min(365.0, (float)$dayOfYear));

    if ($seasonality === 'summer_peak') {
        $x = ($day - 182.5) / 38.0;
        $sigmoid = 1.0 / (1.0 + exp(-$x));
        $s0 = 1.0 / (1.0 + exp(-((1.0 - 182.5) / 38.0)));
        $s1 = 1.0 / (1.0 + exp(-((365.0 - 182.5) / 38.0)));
        return max(0.0, min(1.0, ($sigmoid - $s0) / ($s1 - $s0)));
    }

    if ($seasonality === 'winter_peak') {
        $norm = ($day / 365.0) * 2.0 * M_PI;
        $s = ($day / 365.0) - (sin($norm) / (2.0 * M_PI)) * 0.48;
        return max(0.0, min(1.0, $s));
    }

    // Standard: Linear
    return max(0.0, min(1.0, $day / 365.0));
}

/**
 * Prüft ob Sportart zum Ziel passt (inkl. Varianten)
 */
function getSportClause(?string $sportType, array &$params): string
{
    if (empty($sportType) || strtolower($sportType) === 'all') {
        return '';
    }

    $st = strtolower($sportType);
    if ($st === 'ride') {
        return " AND sport_type IN ('Ride', 'VirtualRide', 'MountainBikeRide', 'GravelRide', 'EBikeRide') ";
    }
    if ($st === 'run') {
        return " AND sport_type IN ('Run', 'TrailRun', 'VirtualRun') ";
    }
    if ($st === 'rowing') {
        return " AND sport_type IN ('Rowing', 'IndoorRowing') ";
    }

    $params['sport_type'] = $sportType;
    return " AND sport_type = :sport_type ";
}

try {
    if ($method === 'GET') {
        $targetYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

        $stmt = $pdo->prepare('
            SELECT * FROM my_strv_goals 
            WHERE user_id = :user_id AND year = :year 
            ORDER BY period_type DESC, created_at ASC
        ');
        $stmt->execute([
            'user_id' => $userId,
            'year'    => $targetYear,
        ]);
        $goals = $stmt->fetchAll();

        $today = new DateTime('now', new DateTimeZone('Europe/Vienna'));
        $currentDayOfYear = (int)$today->format('z') + 1; // 1-366

        $enrichedGoals = [];

        foreach ($goals as $goal) {
            $goalId = (int)$goal['id'];
            $periodType = $goal['period_type'];
            $metricType = $goal['metric_type'];
            $targetValue = (float)$goal['target_value'];
            $seasonality = $goal['seasonality'];
            $sportType = $goal['sport_type'];

            // 1. Datumsbereich für den Zeitraum berechnen
            if ($periodType === 'weekly') {
                $startObj = clone $today;
                $startObj->modify('monday this week')->setTime(0, 0, 0);
                $endObj = clone $today;
                $endObj->modify('sunday this week')->setTime(23, 59, 59);
            } elseif ($periodType === 'monthly') {
                $startObj = clone $today;
                $startObj->modify('first day of this month')->setTime(0, 0, 0);
                $endObj = clone $today;
                $endObj->modify('last day of this month')->setTime(23, 59, 59);
            } else { // yearly
                $startObj = new DateTime("$targetYear-01-01 00:00:00", new DateTimeZone('Europe/Vienna'));
                $endObj = new DateTime("$targetYear-12-31 23:59:59", new DateTimeZone('Europe/Vienna'));
            }

            $startDateStr = $startObj->format('Y-m-d H:i:s');
            $endDateStr = $endObj->format('Y-m-d H:i:s');

            // 2. Aktuellen Ist-Wert aus der Datenbank berechnen
            $params = [
                'user_id'    => $userId,
                'start_date' => $startDateStr,
                'end_date'   => $endDateStr,
            ];
            $sportSql = getSportClause($sportType, $params);

            $sql = "
                SELECT 
                    COALESCE(SUM(distance), 0) / 1000.0 AS current_distance,
                    COALESCE(SUM(moving_time), 0) / 3600.0 AS current_time_hours,
                    COALESCE(SUM(total_elevation_gain), 0) AS current_elevation,
                    COALESCE(SUM(calories), 0) AS current_calories,
                    COUNT(*) AS current_workouts
                FROM my_strv_activities
                WHERE user_id = :user_id 
                  AND start_date >= :start_date 
                  AND start_date <= :end_date
                  $sportSql
            ";
            $actStmt = $pdo->prepare($sql);
            $actStmt->execute($params);
            $actRow = $actStmt->fetch();

            $currentValue = 0.0;
            switch ($metricType) {
                case 'distance':
                    $currentValue = round((float)$actRow['current_distance'], 1);
                    break;
                case 'time':
                    $currentValue = round((float)$actRow['current_time_hours'], 1);
                    break;
                case 'elevation':
                    $currentValue = round((float)$actRow['current_elevation'], 0);
                    break;
                case 'calories':
                    $currentValue = round((float)$actRow['current_calories'], 0);
                    break;
                case 'workouts':
                    $currentValue = (float)$actRow['current_workouts'];
                    break;
            }

            // 3. Soll-Wert bis heute (Pacing) berechnen
            $remainingDays = 0;
            $expectedValueToday = 0.0;

            if ($today > $endObj) {
                // Zeitraum bereits vorbei
                $expectedValueToday = $targetValue;
                $remainingDays = 0;
            } elseif ($today < $startObj) {
                // Zeitraum noch nicht begonnen
                $expectedValueToday = 0.0;
                $remainingDays = (int)$today->diff($endObj)->format('%a');
            } else {
                $totalDays = max(1, (int)$startObj->diff($endObj)->format('%a') + 1);
                $elapsedDays = max(1, (int)$startObj->diff($today)->format('%a') + 1);
                $remainingDays = max(0, (int)$today->diff($endObj)->format('%a'));

                if ($periodType === 'yearly') {
                    $fraction = getSeasonalFraction($seasonality, $currentDayOfYear);
                    $expectedValueToday = round($fraction * $targetValue, 1);
                } else {
                    $fraction = min(1.0, $elapsedDays / (float)$totalDays);
                    $expectedValueToday = round($fraction * $targetValue, 1);
                }
            }

            $progressPercent = $targetValue > 0 ? round(($currentValue / $targetValue) * 100.0, 1) : 0.0;
            $deltaToSchedule = round($currentValue - $expectedValueToday, 1);
            $deltaPercent = $expectedValueToday > 0 ? round(($deltaToSchedule / $expectedValueToday) * 100.0, 1) : 0.0;
            $isAhead = $deltaToSchedule >= 0;

            // 4. Bei Jahreszielen: Chart-Kurve für Soll vs Ist generieren (12 Monats-Punkte)
            $chartPoints = [];
            if ($periodType === 'yearly') {
                $sampleDays = [1, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334, 365];
                $isTargetYearCurrent = ($targetYear === (int)$today->format('Y'));
                $maxDay = $isTargetYearCurrent ? $currentDayOfYear : 365;

                // Tägliche Ist-Werte für das Jahr abfragen
                $dailyParams = [
                    'user_id' => $userId,
                    'year'    => $targetYear,
                ];
                $dailySportSql = getSportClause($sportType, $dailyParams);

                $metricCol = match ($metricType) {
                    'distance'  => 'distance / 1000.0',
                    'time'      => 'moving_time / 3600.0',
                    'elevation' => 'total_elevation_gain',
                    'calories'  => 'calories',
                    'workouts'  => '1',
                };

                $dailyStmt = $pdo->prepare("
                    SELECT DAYOFYEAR(start_date) AS doy, SUM($metricCol) AS val
                    FROM my_strv_activities
                    WHERE user_id = :user_id AND YEAR(start_date) = :year
                    $dailySportSql
                    GROUP BY DAYOFYEAR(start_date)
                ");
                $dailyStmt->execute($dailyParams);
                $dailyMap = $dailyStmt->fetchAll(PDO::FETCH_KEY_PAIR); // [dayOfYear => val]

                $runningSum = 0.0;
                $dailyCumulative = [];
                for ($d = 1; $d <= 365; $d++) {
                    if (isset($dailyMap[$d])) {
                        $runningSum += (float)$dailyMap[$d];
                    }
                    $dailyCumulative[$d] = $runningSum;
                }

                foreach ($sampleDays as $sDay) {
                    $expectedFraction = getSeasonalFraction($seasonality, $sDay);
                    $actualFraction = ($sDay <= $maxDay && $targetValue > 0)
                        ? round(($dailyCumulative[$sDay] / $targetValue), 3)
                        : null;

                    $chartPoints[] = [
                        'day'            => $sDay,
                        'expected_ratio' => round($expectedFraction, 3),
                        'actual_ratio'   => $actualFraction,
                    ];
                }
            }

            $enrichedGoals[] = [
                'id'                   => $goalId,
                'title'                => $goal['title'],
                'sport_type'           => $goal['sport_type'] ?? 'all',
                'metric_type'          => $metricType,
                'target_value'         => $targetValue,
                'period_type'          => $periodType,
                'year'                 => (int)$goal['year'],
                'seasonality'          => $seasonality,
                'current_value'        => $currentValue,
                'progress_percent'     => $progressPercent,
                'expected_value_today' => $expectedValueToday,
                'delta_to_schedule'    => $deltaToSchedule,
                'delta_percent'        => $deltaPercent,
                'remaining_days'       => $remainingDays,
                'is_ahead'             => $isAhead,
                'chart_points'         => $chartPoints,
                'unit'                 => match ($metricType) {
                    'distance'  => 'km',
                    'time'      => 'Std',
                    'elevation' => 'm',
                    'calories'  => 'kcal',
                    'workouts'  => 'x',
                },
            ];
        }

        echo json_encode([
            'success' => true,
            'year'    => $targetYear,
            'goals'   => $enrichedGoals,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Ungültige JSON-Eingabe']);
            exit;
        }

        $title = trim((string)($input['title'] ?? ''));
        $targetValue = (float)($input['target_value'] ?? 0);
        $periodType = (string)($input['period_type'] ?? 'yearly');
        $metricType = (string)($input['metric_type'] ?? 'distance');
        $seasonality = (string)($input['seasonality'] ?? 'linear');
        $sportType = !empty($input['sport_type']) && $input['sport_type'] !== 'all' ? trim((string)$input['sport_type']) : null;
        $year = isset($input['year']) && is_numeric($input['year']) ? (int)$input['year'] : (int)date('Y');

        if ($title === '' || $targetValue <= 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Titel und ein positiver Zielwert sind erforderlich.']);
            exit;
        }

        $stmt = $pdo->prepare('
            INSERT INTO my_strv_goals 
            (user_id, title, sport_type, metric_type, target_value, period_type, year, seasonality)
            VALUES (:user_id, :title, :sport_type, :metric_type, :target_value, :period_type, :year, :seasonality)
        ');
        $stmt->execute([
            'user_id'      => $userId,
            'title'        => $title,
            'sport_type'   => $sportType,
            'metric_type'  => $metricType,
            'target_value' => $targetValue,
            'period_type'  => $periodType,
            'year'         => $year,
            'seasonality'  => $seasonality,
        ]);

        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Fehlende oder ungültige ID']);
            exit;
        }

        $stmt = $pdo->prepare('DELETE FROM my_strv_goals WHERE id = :id AND user_id = :user_id');
        $stmt->execute([
            'id'      => $id,
            'user_id' => $userId,
        ]);

        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Methode nicht erlaubt']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler bei der Zielverwaltung: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
