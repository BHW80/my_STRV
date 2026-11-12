<?php
declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/StravaOAuth.php';

// Nur eingeloggte Benutzer dürfen ihren Account verbinden
$userId = Auth::requireAuth();

// Generiert die Strava OAuth URL und leitet weiter
$authUrl = StravaOAuth::getAuthorizationUrl($userId);

header('Location: ' . $authUrl);
exit;
