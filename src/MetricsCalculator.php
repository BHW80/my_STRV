<?php
declare(strict_types=1);

/**
 * my_STRV - Sports Science Metrics Calculation Service
 * 
 * Berechnet abgeleitete Leistungs- und Effizienzwerte für:
 * - Rudern (500m Split-Pace, Distance Per Stroke, Joules Per Stroke)
 * - Radsport (Watt/kg, Normalized Power, Intensity Factor, TSS, VAM, Efficiency Factor)
 * - Laufen (Pace in s/km, Schrittlänge in Metern)
 */
class MetricsCalculator
{
    /**
     * Berechnet alle relevanten Metriken abhängig von der Sportart.
     * 
     * @param array $act Rohdaten der Aktivität (Strava API oder DB)
     * @param float $ftp Schwellenleistung des Athleten in Watt
     * @param float $weight Körpergewicht des Athleten in kg
     * @return array Assoziatives Array mit berechneten Feldern für my_strv_activities
     */
    public static function calculate(array $act, float $ftp = 250.0, float $weight = 75.0): array
    {
        $sport = (string)($act['sport_type'] ?? $act['type'] ?? '');
        $metrics = [];

        if (self::isRowing($sport)) {
            $metrics = self::calculateRowingMetrics($act);
        } elseif (self::isCycling($sport)) {
            $metrics = self::calculateCyclingMetrics($act, $ftp, $weight);
        } elseif (self::isRunning($sport)) {
            $metrics = self::calculateRunningMetrics($act);
        }

        return $metrics;
    }

    /**
     * Ruder-spezifische Berechnungen:
     * - 500m Split in Sekunden
     * - DPS (Distance Per Stroke) in Metern
     * - JPS (Joules Per Stroke) in Joule
     */
    public static function calculateRowingMetrics(array $act): array
    {
        $avgSpeed   = (float)($act['average_speed'] ?? 0);       // in m/s
        $distance   = (float)($act['distance'] ?? 0);            // in m
        $movingTime = (int)($act['moving_time'] ?? 0);           // in Sekunden
        $cadence    = (float)($act['average_cadence'] ?? 0);     // Schlagzahl (SPM)
        $avgWatts   = (float)($act['average_watts'] ?? 0);       // Watt
        $maxCadence = isset($act['max_cadence']) ? (float)$act['max_cadence'] : null;

        // 1. 500m-Pace in Sekunden: Zeit = 500m / Geschwindigkeit (m/s)
        $split500m = ($avgSpeed > 0) ? round(500.0 / $avgSpeed, 2) : null;

        // 2. DPS (Meter pro Schlag): Distanz / Gesamtschläge
        // Gesamtschläge = SPM * (moving_time / 60)
        $dps = null;
        if ($cadence > 0 && $movingTime > 0) {
            $totalStrokes = $cadence * ($movingTime / 60.0);
            if ($totalStrokes > 0) {
                $dps = round($distance / $totalStrokes, 2);
            }
        }

        // 3. JPS (Joule pro Schlag): Energie pro Minute / Schläge pro Minute
        // Energie (Joule) = Watt * Sekunden -> pro Minute = avgWatts * 60
        $jps = null;
        if ($cadence > 0 && $avgWatts > 0) {
            $jps = round(($avgWatts * 60.0) / $cadence, 1);
        }

        return [
            'split_500m_seconds'  => $split500m,
            'distance_per_stroke' => $dps,
            'joules_per_stroke'   => $jps,
            'max_cadence'         => $maxCadence,
        ];
    }

    /**
     * Radsport-spezifische Berechnungen:
     * - W/kg (Leistungsgewicht)
     * - NP (Normalized Power)
     * - IF (Intensity Factor)
     * - TSS (Training Stress Score nach Dr. Andrew Coggan)
     * - VAM (Vertical Ascent Meters pro Stunde)
     * - EF (Efficiency Factor = NP / avg_hr)
     */
    public static function calculateCyclingMetrics(array $act, float $ftp, float $weight): array
    {
        $avgWatts      = (float)($act['average_watts'] ?? 0);
        $weightedWatts = isset($act['weighted_average_watts']) ? (float)$act['weighted_average_watts'] : null;
        $movingTime    = (int)($act['moving_time'] ?? 0);
        $elevationGain = (float)($act['total_elevation_gain'] ?? 0);
        $avgHr         = (float)($act['average_heartrate'] ?? 0);

        // 1. W/kg
        $wKg = ($weight > 0 && $avgWatts > 0) ? round($avgWatts / $weight, 2) : null;

        // 2. Normalized Power (bevorzugt Strava weighted_average_watts, sonst avgWatts)
        $np = ($weightedWatts !== null && $weightedWatts > 0) ? $weightedWatts : ($avgWatts > 0 ? $avgWatts : null);

        // 3. Intensity Factor (IF = NP / FTP)
        $if = ($ftp > 0 && $np !== null && $np > 0) ? round($np / $ftp, 3) : null;

        // 4. Training Stress Score (TSS = (sec * NP * IF) / (FTP * 3600) * 100)
        $tss = null;
        if ($ftp > 0 && $np !== null && $if !== null && $movingTime > 0) {
            $tss = round((($movingTime * $np * $if) / ($ftp * 3600.0)) * 100.0, 1);
        }

        // 5. VAM (Höhenmeter pro Stunde): (elevation_gain * 3600) / moving_time
        $vam = null;
        if ($movingTime > 0 && $elevationGain > 0) {
            $vam = round(($elevationGain * 3600.0) / $movingTime, 1);
        }

        // 6. Efficiency Factor (EF = NP / avg_hr)
        $ef = null;
        if ($np !== null && $np > 0 && $avgHr > 0) {
            $ef = round($np / $avgHr, 2);
        }

        return [
            'w_kg'              => $wKg,
            'normalized_power'  => $np,
            'intensity_factor'  => $if,
            'tss'               => $tss,
            'vam'               => $vam,
            'efficiency_factor' => $ef,
        ];
    }

    /**
     * Lauf-spezifische Berechnungen:
     * - Pace (min/km als Dezimalzahl / Sekunden)
     * - Schrittlänge (Stride Length in Metern)
     */
    public static function calculateRunningMetrics(array $act): array
    {
        $avgSpeed = (float)($act['average_speed'] ?? 0);       // m/s
        $cadence  = (float)($act['average_cadence'] ?? 0);     // Schritte/Minute (SPM)

        // 1. Pace in Sekunden pro Kilometer
        $paceSecondsPerKm = ($avgSpeed > 0) ? round(1000.0 / $avgSpeed, 1) : null;

        // 2. Schrittlänge in Metern
        // Strava meldet Lauf-Kadenz meist als SPM (beide Beine zusammen).
        // Geschwindigkeit in m/min = avgSpeed * 60
        // Schrittlänge = (avgSpeed * 60) / cadence
        $strideLength = null;
        if ($cadence > 0 && $avgSpeed > 0) {
            // Wenn Kadenz im einbeinigen Bereich liegt (< 120), mit 2 multiplizieren
            $effectiveCadence = ($cadence < 120) ? ($cadence * 2) : $cadence;
            $strideLength = round(($avgSpeed * 60.0) / $effectiveCadence, 2);
        }

        return [
            'pace_seconds_km' => $paceSecondsPerKm,
            'stride_length'   => $strideLength,
        ];
    }

    /**
     * Erkennt Rudereinheiten (inkl. Indoor/Virtual Row)
     */
    public static function isRowing(string $sportType): bool
    {
        $s = strtolower($sportType);
        return in_array($s, ['rowing', 'virtualrow'], true);
    }

    /**
     * Erkennt Radeinheiten (Outdoor, Indoor, Gravel, E-Bike)
     */
    public static function isCycling(string $sportType): bool
    {
        $s = strtolower($sportType);
        return in_array($s, ['ride', 'virtualride', 'gravelride', 'mountainbikeride', 'ebikeride'], true);
    }

    /**
     * Erkennt Laufeinheiten (Outdoor, Trail, Treadmill)
     */
    public static function isRunning(string $sportType): bool
    {
        $s = strtolower($sportType);
        return in_array($s, ['run', 'trailrun', 'virtualrun'], true);
    }
}
