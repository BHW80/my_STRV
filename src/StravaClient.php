<?php
declare(strict_types=1);

require_once __DIR__ . '/StravaOAuth.php';

/**
 * my_STRV - Strava API Client mit Rate Limit Watcher
 * 
 * cURL-Wrapper für https://www.strava.com/api/v3/
 * Überwacht Stravas 15-Minuten- und Tages-Limits (X-RateLimit-Limit / X-RateLimit-Usage)
 * und bricht bei Erschöpfung kontrolliert ab, um API-Sperren zu verhindern.
 */
class StravaClient
{
    private const BASE_URL = 'https://www.strava.com/api/v3';

    private int $userId;
    private ?int $rateLimit15MinLimit = null;
    private ?int $rateLimit15MinUsage = null;
    private ?int $rateLimitDailyLimit = null;
    private ?int $rateLimitDailyUsage = null;

    public function __construct(int $userId)
    {
        $this->userId = $userId;
    }

    /**
     * Führt einen authentifizierten GET-Request an die Strava v3 API aus.
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        // 1. Wenn wir bereits wissen, dass das 15-Min-Limit kritisch ist (< 3 verbleibend), sofort stoppen
        if ($this->get15MinRemaining() !== null && $this->get15MinRemaining() < 3) {
            throw new RuntimeException("Strava 15-Minuten Rate Limit nahezu erschöpft ({$this->get15MinRemaining()} übrig). Sync pausiert.");
        }

        $token = StravaOAuth::getValidAccessToken($this->userId);

        $url = self::BASE_URL . '/' . ltrim($endpoint, '/');
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true, // Header mitsenden, um Rate-Limits zu parsen
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
                'User-Agent: my_STRV/1.0',
            ],
        ]);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($err) {
            throw new RuntimeException("cURL-Verbindungsfehler zu Strava ($endpoint): $err");
        }

        // Header und Body separieren
        $headerText = substr((string)$response, 0, $headerSize);
        $body = substr((string)$response, $headerSize);

        // Rate-Limit Header extrahieren
        $this->parseRateLimitHeaders($headerText);

        $decoded = json_decode($body, true);

        if ($httpCode === 429) {
            throw new RuntimeException("HTTP 429: Strava Rate-Limit überschritten. Bitte 15 Minuten warten.");
        }

        if ($httpCode >= 400 || !is_array($decoded)) {
            $message = $decoded['message'] ?? $body;
            throw new RuntimeException("Strava API Fehler ($httpCode): $message");
        }

        return $decoded;
    }

    /**
     * Parst die X-RateLimit-Header aus der HTTP-Antwort.
     * Strava sendet Formate wie:
     *   X-RateLimit-Limit: 100,1000  (15-Min-Limit, 24h-Limit)
     *   X-RateLimit-Usage: 14,120    (15-Min-Verbrauch, 24h-Verbrauch)
     */
    private function parseRateLimitHeaders(string $headerText): void
    {
        $lines = explode("\r\n", $headerText);
        foreach ($lines as $line) {
            if (stripos($line, 'X-RateLimit-Limit:') === 0) {
                $parts = explode(',', trim(substr($line, strlen('X-RateLimit-Limit:'))));
                $this->rateLimit15MinLimit = isset($parts[0]) ? (int)trim($parts[0]) : null;
                $this->rateLimitDailyLimit = isset($parts[1]) ? (int)trim($parts[1]) : null;
            } elseif (stripos($line, 'X-RateLimit-Usage:') === 0) {
                $parts = explode(',', trim(substr($line, strlen('X-RateLimit-Usage:'))));
                $this->rateLimit15MinUsage = isset($parts[0]) ? (int)trim($parts[0]) : null;
                $this->rateLimitDailyUsage = isset($parts[1]) ? (int)trim($parts[1]) : null;
            }
        }
    }

    /**
     * Verbleibende Requests im aktuellen 15-Minuten-Fenster.
     */
    public function get15MinRemaining(): ?int
    {
        if ($this->rateLimit15MinLimit !== null && $this->rateLimit15MinUsage !== null) {
            return max(0, $this->rateLimit15MinLimit - $this->rateLimit15MinUsage);
        }
        return null;
    }

    /**
     * Verbleibende Requests im aktuellen Tagesfenster.
     */
    public function getDailyRemaining(): ?int
    {
        if ($this->rateLimitDailyLimit !== null && $this->rateLimitDailyUsage !== null) {
            return max(0, $this->rateLimitDailyLimit - $this->rateLimitDailyUsage);
        }
        return null;
    }

    public function getRateLimitStatus(): array
    {
        return [
            '15min_limit'     => $this->rateLimit15MinLimit,
            '15min_usage'     => $this->rateLimit15MinUsage,
            '15min_remaining' => $this->get15MinRemaining(),
            'daily_limit'     => $this->rateLimitDailyLimit,
            'daily_usage'     => $this->rateLimitDailyUsage,
            'daily_remaining' => $this->getDailyRemaining(),
        ];
    }
}
