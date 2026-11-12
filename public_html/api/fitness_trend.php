<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';

$userId = Auth::requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDbConnection();

// Parameter: Betrachtungszeitraum in Tagen (Standard: 180 Tage, max: 730 Tage)
$daysParam = isset($_GET['days']) && is_numeric($_GET['days']) ? (int)$_GET['days'] : 180;
$days = max(30, min(730, $daysParam));

// Warm-up-Puffer: Um CTL (42 Tage Zeitkonstante) mathematisch einzuschwingen,
// beginnen wir die Berechnung 90 Tage vor dem eigentlichen Auswertungsfenster.
$warmupDays = 90;
$totalCalcDays = $days + $warmupDays;

$startDate = date('Y-m-d', strtotime("-$totalCalcDays days"));
$endDate = date('Y-m-d');

try {
    // 1. Tägliche Trainingsbelastung (Training Load / TSS) aus der Datenbank abfragen
    $stmt = $pdo->prepare('
        SELECT 
            DATE(start_date) AS day,
            SUM(
                CASE 
                    WHEN tss IS NOT NULL AND tss > 0 THEN tss
                    WHEN suffer_score IS NOT NULL AND suffer_score > 0 THEN suffer_score
                    WHEN moving_time > 0 THEN ROUND((moving_time / 3600.0) * 45.0, 1)
                    ELSE 0
                END
            ) AS daily_load
        FROM my_strv_activities
        WHERE user_id = :user_id AND DATE(start_date) >= :start_date
        GROUP BY DATE(start_date)
        ORDER BY day ASC
    ');
    $stmt->execute([
        'user_id'    => $userId,
        'start_date' => $startDate,
    ]);
    $rawLoads = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // ['2026-01-01' => 65.5, ...]

    // 2. Zeitkonstanten nach Dr. Andrew Coggan
    // Fitness (CTL): 42 Tage, Ermüdung (ATL): 7 Tage
    $tcCtl = 42.0;
    $tcAtl = 7.0;

    $kCtl = 1.0 - exp(-1.0 / $tcCtl);
    $kAtl = 1.0 - exp(-1.0 / $tcAtl);

    $ctl = 0.0;
    $atl = 0.0;

    $cutoffDate = date('Y-m-d', strtotime("-$days days"));

    $dates = [];
    $ctlSeries = [];
    $atlSeries = [];
    $tsbSeries = [];
    $loadSeries = [];

    // 3. Kontinuierliche tägliche Iteration (inklusive Ruhetage mit Load = 0)
    $currentDateObj = new DateTime($startDate);
    $endDateObj = new DateTime($endDate);

    while ($currentDateObj <= $endDateObj) {
        $dateStr = $currentDateObj->format('Y-m-d');
        $load = isset($rawLoads[$dateStr]) ? (float)$rawLoads[$dateStr] : 0.0;

        // TSB (Form) basiert auf dem Vortag
        $tsb = $ctl - $atl;

        // Exponentielle Glättung für den aktuellen Tag
        $ctl = $ctl + ($load - $ctl) * $kCtl;
        $atl = $atl + ($load - $atl) * $kAtl;

        // Nur Daten im gewünschten Anzeigefenster für das JSON sammeln
        if ($dateStr >= $cutoffDate) {
            $dates[] = $dateStr;
            $loadSeries[] = round($load, 1);
            $ctlSeries[] = round($ctl, 1);
            $atlSeries[] = round($atl, 1);
            $tsbSeries[] = round($tsb, 1);
        }

        $currentDateObj->modify('+1 day');
    }

    $lastIdx = count($ctlSeries) - 1;
    $latestCtl = $lastIdx >= 0 ? $ctlSeries[$lastIdx] : 0.0;
    $latestAtl = $lastIdx >= 0 ? $atlSeries[$lastIdx] : 0.0;
    $latestTsb = $lastIdx >= 0 ? $tsbSeries[$lastIdx] : 0.0;

    // ACWR (Acute:Chronic Workload Ratio) zur Verletzungs- & Überlastungsprävention
    $acwr = ($latestCtl > 0.0) ? round($latestAtl / $latestCtl, 2) : 1.0;
    $acwrStatus = match (true) {
        $acwr < 0.8  => 'underload',
        $acwr <= 1.3 => 'sweetspot',
        $acwr <= 1.5 => 'caution',
        default      => 'danger',
    };
    $acwrLabel = match ($acwrStatus) {
        'underload' => 'Unterbelastung / Formverlust',
        'sweetspot' => 'Sweetspot (Optimaler Aufbau)',
        'caution'   => 'Erhöhte Ermüdung (Vorsicht)',
        'danger'    => 'Gefahrenbereich (Überlastung!)',
    };

    echo json_encode([
        'success'      => true,
        'days'         => $days,
        'current'      => [
            'fitness_ctl' => $latestCtl,
            'fatigue_atl' => $latestAtl,
            'form_tsb'    => $latestTsb,
            'acwr'        => $acwr,
            'acwr_status' => $acwrStatus,
            'acwr_label'  => $acwrLabel,
        ],
        'series'       => [
            'dates'      => $dates,
            'daily_load' => $loadSeries,
            'ctl'        => $ctlSeries,
            'atl'        => $atlSeries,
            'tsb'        => $tsbSeries,
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Fehler bei der Berechnung des Fitness-Trends: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
