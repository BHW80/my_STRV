<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/StravaClient.php';
require_once __DIR__ . '/MetricsCalculator.php';

/**
 * my_STRV - Smart Sync Engine
 * 
 * Orchestriert die Synchronisation zwischen Strava und MariaDB:
 * - Unterscheidet automatisch zwischen Initial Load (Paginierung) und Delta-Sync (after={timestamp})
 * - Berechnet alle sportwissenschaftlichen Metriken via MetricsCalculator
 * - Speichert Aktivitäten und GPS-Polylines mandantenisoliert mit (id, user_id)
 * - Aktualisiert die Ausrüstung (Gear) des Benutzers
 * - Protokolliert Ergebnisse und Rate-Limit-Verbrauch in my_strv_sync_logs
 */
class SyncEngine
{
    private PDO $pdo;
    private StravaClient $client;
    private int $userId;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
        $this->pdo = getDbConnection();
        $this->client = new StravaClient($userId);
    }

    /**
     * Führt die Synchronisation für den Benutzer aus.
     */
    public function sync(bool $forceFull = false): array
    {
        $startTime = microtime(true);
        $syncType = 'delta';
        $itemsSynced = 0;
        $itemsUpdated = 0;

        try {
            // 1. Athleten-Einstellungen (FTP, Gewicht) laden
            $settings = $this->loadAthleteSettings();
            $ftp = (float)($settings['ftp'] ?? 250.0);
            $weight = (float)($settings['weight'] ?? 75.0);

            // 2. Letzten Aktivitätszeitstempel ermitteln
            $lastTimestamp = null;
            if (!$forceFull) {
                $lastTimestamp = $this->getLastActivityTimestamp();
            }

            if ($lastTimestamp === null) {
                $syncType = 'full';
                [$itemsSynced, $itemsUpdated] = $this->runInitialLoad($ftp, $weight);
            } else {
                $syncType = 'delta';
                [$itemsSynced, $itemsUpdated] = $this->runDeltaSync($lastTimestamp, $ftp, $weight);
            }

            // 3. Ausrüstung (Bikes & Schuhe) aktualisieren
            $this->syncAthleteGear();

            $rateLimitRemaining = $this->client->get15MinRemaining();
            $message = sprintf(
                'Sync erfolgreich (%s). %d neue Einheiten importiert, %d aktualisiert.',
                $syncType,
                $itemsSynced,
                $itemsUpdated
            );

            // 4. Log in my_strv_sync_logs schreiben
            $this->logSync($syncType, 'success', $itemsSynced + $itemsUpdated, $rateLimitRemaining, $message);

            return [
                'status'        => 'success',
                'mode'          => $syncType,
                'added'         => $itemsSynced,
                'updated'       => $itemsUpdated,
                'message'       => $message,
                'duration_sec'  => round(microtime(true) - $startTime, 2),
                'rate_limit_15' => $rateLimitRemaining,
            ];

        } catch (Throwable $e) {
            $rateLimitRemaining = $this->client->get15MinRemaining();
            $errorMsg = 'Sync-Fehler: ' . $e->getMessage();
            error_log("[SyncEngine User {$this->userId}] " . $errorMsg);

            $this->logSync($syncType, 'error', $itemsSynced + $itemsUpdated, $rateLimitRemaining, $errorMsg);

            return [
                'status'        => 'error',
                'mode'          => $syncType,
                'added'         => $itemsSynced,
                'updated'       => $itemsUpdated,
                'message'       => $errorMsg,
                'rate_limit_15' => $rateLimitRemaining,
            ];
        }
    }

    /**
     * Initialer Vollimport: Ruft paginiert alle historischen Aktivitäten ab (200 pro Seite).
     */
    private function runInitialLoad(float $ftp, float $weight): array
    {
        $page = 1;
        $perPage = 200;
        $totalAdded = 0;
        $totalUpdated = 0;

        while (true) {
            $activities = $this->client->get('/athlete/activities', [
                'page'     => $page,
                'per_page' => $perPage,
            ]);

            if (empty($activities) || !is_array($activities)) {
                break;
            }

            foreach ($activities as $act) {
                $isNew = $this->upsertActivity($act, $ftp, $weight);
                if ($isNew) {
                    $totalAdded++;
                } else {
                    $totalUpdated++;
                }
            }

            // Wenn weniger als perPage geliefert wurden, ist das die letzte Seite
            if (count($activities) < $perPage) {
                break;
            }

            $page++;
        }

        return [$totalAdded, $totalUpdated];
    }

    /**
     * Delta-Sync: Lädt nur Aktivitäten, die nach dem letzten bekannten Timestamp liegen.
     */
    private function runDeltaSync(int $afterTimestamp, float $ftp, float $weight): array
    {
        $page = 1;
        $perPage = 200;
        $totalAdded = 0;
        $totalUpdated = 0;

        while (true) {
            $activities = $this->client->get('/athlete/activities', [
                'after'    => $afterTimestamp,
                'page'     => $page,
                'per_page' => $perPage,
            ]);

            if (empty($activities) || !is_array($activities)) {
                break;
            }

            foreach ($activities as $act) {
                $isNew = $this->upsertActivity($act, $ftp, $weight);
                if ($isNew) {
                    $totalAdded++;
                } else {
                    $totalUpdated++;
                }
            }

            if (count($activities) < $perPage) {
                break;
            }

            $page++;
        }

        return [$totalAdded, $totalUpdated];
    }

    /**
     * Speichert eine Aktivität via INSERT ... ON DUPLICATE KEY UPDATE in my_strv_activities.
     * Gibt true zurück, wenn die Aktivität neu angelegt wurde.
     */
    private function upsertActivity(array $act, float $ftp, float $weight): bool
    {
        $activityId = (int)$act['id'];
        $athleteId = (int)($act['athlete']['id'] ?? 0);

        // Prüfen, ob die Aktivität für diesen User bereits existiert
        $checkStmt = $this->pdo->prepare('SELECT id FROM my_strv_activities WHERE id = :id AND user_id = :user_id LIMIT 1');
        $checkStmt->execute(['id' => $activityId, 'user_id' => $this->userId]);
        $exists = (bool)$checkStmt->fetch();

        // Metriken über MetricsCalculator berechnen
        $metrics = MetricsCalculator::calculate($act, $ftp, $weight);

        // Startdatum in MySQL DATETIME umwandeln
        $rawStartDate = $act['start_date_local'] ?? $act['start_date'] ?? date('Y-m-d H:i:s');
        $startDate = date('Y-m-d H:i:s', strtotime($rawStartDate));

        // GPS Startkoordinaten & Polyline
        $startLat = isset($act['start_latlng'][0]) ? (float)$act['start_latlng'][0] : null;
        $startLng = isset($act['start_latlng'][1]) ? (float)$act['start_latlng'][1] : null;
        $polyline = $act['map']['summary_polyline'] ?? null;

        $sql = '
            INSERT INTO my_strv_activities (
                id, user_id, athlete_id, name, sport_type, start_date, distance,
                moving_time, elapsed_time, total_elevation_gain,
                average_speed, max_speed, average_cadence, average_heartrate, max_heartrate,
                average_watts, max_watts, weighted_average_watts, kilojoules, suffer_score,
                split_500m_seconds, distance_per_stroke, joules_per_stroke, max_cadence,
                w_kg, normalized_power, intensity_factor, tss, efficiency_factor, vam,
                summary_polyline, start_lat, start_lng, raw_summary
            ) VALUES (
                :id, :user_id, :athlete_id, :name, :sport_type, :start_date, :distance,
                :moving_time, :elapsed_time, :total_elevation_gain,
                :average_speed, :max_speed, :average_cadence, :average_heartrate, :max_heartrate,
                :average_watts, :max_watts, :weighted_average_watts, :kilojoules, :suffer_score,
                :split_500m_seconds, :distance_per_stroke, :joules_per_stroke, :max_cadence,
                :w_kg, :normalized_power, :intensity_factor, :tss, :efficiency_factor, :vam,
                :summary_polyline, :start_lat, :start_lng, :raw_summary
            ) ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                sport_type = VALUES(sport_type),
                start_date = VALUES(start_date),
                distance = VALUES(distance),
                moving_time = VALUES(moving_time),
                elapsed_time = VALUES(elapsed_time),
                total_elevation_gain = VALUES(total_elevation_gain),
                average_speed = VALUES(average_speed),
                max_speed = VALUES(max_speed),
                average_cadence = VALUES(average_cadence),
                average_heartrate = VALUES(average_heartrate),
                max_heartrate = VALUES(max_heartrate),
                average_watts = VALUES(average_watts),
                max_watts = VALUES(max_watts),
                weighted_average_watts = VALUES(weighted_average_watts),
                kilojoules = VALUES(kilojoules),
                suffer_score = VALUES(suffer_score),
                split_500m_seconds = VALUES(split_500m_seconds),
                distance_per_stroke = VALUES(distance_per_stroke),
                joules_per_stroke = VALUES(joules_per_stroke),
                max_cadence = VALUES(max_cadence),
                w_kg = VALUES(w_kg),
                normalized_power = VALUES(normalized_power),
                intensity_factor = VALUES(intensity_factor),
                tss = VALUES(tss),
                efficiency_factor = VALUES(efficiency_factor),
                vam = VALUES(vam),
                summary_polyline = VALUES(summary_polyline),
                start_lat = VALUES(start_lat),
                start_lng = VALUES(start_lng),
                raw_summary = VALUES(raw_summary)
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'                     => $activityId,
            'user_id'                => $this->userId,
            'athlete_id'             => $athleteId,
            'name'                   => (string)($act['name'] ?? 'Aktivität'),
            'sport_type'             => (string)($act['sport_type'] ?? $act['type'] ?? 'Workout'),
            'start_date'             => $startDate,
            'distance'               => (float)($act['distance'] ?? 0),
            'moving_time'            => (int)($act['moving_time'] ?? 0),
            'elapsed_time'           => (int)($act['elapsed_time'] ?? 0),
            'total_elevation_gain'   => (float)($act['total_elevation_gain'] ?? 0),
            'average_speed'          => isset($act['average_speed']) ? (float)$act['average_speed'] : null,
            'max_speed'              => isset($act['max_speed']) ? (float)$act['max_speed'] : null,
            'average_cadence'        => isset($act['average_cadence']) ? (float)$act['average_cadence'] : null,
            'average_heartrate'      => isset($act['average_heartrate']) ? (float)$act['average_heartrate'] : null,
            'max_heartrate'          => isset($act['max_heartrate']) ? (float)$act['max_heartrate'] : null,
            'average_watts'          => isset($act['average_watts']) ? (float)$act['average_watts'] : null,
            'max_watts'              => isset($act['max_watts']) ? (float)$act['max_watts'] : null,
            'weighted_average_watts' => isset($act['weighted_average_watts']) ? (float)$act['weighted_average_watts'] : null,
            'kilojoules'             => isset($act['kilojoules']) ? (float)$act['kilojoules'] : null,
            'suffer_score'           => isset($act['suffer_score']) ? (int)$act['suffer_score'] : null,
            'split_500m_seconds'     => $metrics['split_500m_seconds'] ?? null,
            'distance_per_stroke'    => $metrics['distance_per_stroke'] ?? null,
            'joules_per_stroke'      => $metrics['joules_per_stroke'] ?? null,
            'max_cadence'            => $metrics['max_cadence'] ?? null,
            'w_kg'                   => $metrics['w_kg'] ?? null,
            'normalized_power'       => $metrics['normalized_power'] ?? null,
            'intensity_factor'       => $metrics['intensity_factor'] ?? null,
            'tss'                    => $metrics['tss'] ?? null,
            'efficiency_factor'      => $metrics['efficiency_factor'] ?? null,
            'vam'                    => $metrics['vam'] ?? null,
            'summary_polyline'       => $polyline,
            'start_lat'              => $startLat,
            'start_lng'              => $startLng,
            'raw_summary'            => json_encode($act, JSON_UNESCAPED_UNICODE),
        ]);

        return !$exists;
    }

    /**
     * Synchronisiert Ausrüstung (Bikes & Schuhe) aus dem Athletenprofil.
     */
    private function syncAthleteGear(): void
    {
        try {
            $athlete = $this->client->get('/athlete');
            $gears = array_merge($athlete['bikes'] ?? [], $athlete['shoes'] ?? []);

            if (empty($gears)) {
                return;
            }

            $stmt = $this->pdo->prepare('
                INSERT INTO my_strv_gear (id, user_id, name, distance, primary_gear)
                VALUES (:id, :user_id, :name, :distance, :primary_gear)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    distance = VALUES(distance),
                    primary_gear = VALUES(primary_gear)
            ');

            foreach ($gears as $g) {
                if (empty($g['id'])) {
                    continue;
                }
                $stmt->execute([
                    'id'           => (string)$g['id'],
                    'user_id'      => $this->userId,
                    'name'         => (string)($g['name'] ?? 'Ausrüstung'),
                    'distance'     => (float)($g['distance'] ?? 0),
                    'primary_gear' => !empty($g['primary']) ? 1 : 0,
                ]);
            }
        } catch (Throwable $e) {
            error_log("Warnung: Gear-Sync für User {$this->userId} übersprungen: " . $e->getMessage());
        }
    }

    private function getLastActivityTimestamp(): ?int
    {
        $stmt = $this->pdo->prepare('
            SELECT UNIX_TIMESTAMP(MAX(start_date)) as last_time 
            FROM my_strv_activities 
            WHERE user_id = :user_id
        ');
        $stmt->execute(['user_id' => $this->userId]);
        $val = $stmt->fetchColumn();

        return $val ? (int)$val : null;
    }

    private function loadAthleteSettings(): array
    {
        $stmt = $this->pdo->prepare('SELECT ftp, weight FROM my_strv_athlete_settings WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $this->userId]);
        return $stmt->fetch() ?: ['ftp' => 250, 'weight' => 75.0];
    }

    private function logSync(string $syncType, string $status, int $itemsSynced, ?int $rateLimit, string $message): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO my_strv_sync_logs (user_id, sync_type, status, items_synced, rate_limit_15min_remaining, message)
            VALUES (:user_id, :sync_type, :status, :items_synced, :rate_limit, :message)
        ');
        $stmt->execute([
            'user_id'      => $this->userId,
            'sync_type'    => $syncType,
            'status'       => $status,
            'items_synced' => $itemsSynced,
            'rate_limit'   => $rateLimit,
            'message'      => $message,
        ]);
    }
}
