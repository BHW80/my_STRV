<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

$userId = Auth::requireAuth();
$username = Auth::username();

$pdo = getDbConnection();

// Strava-Status abfragen
$stmt = $pdo->prepare('SELECT athlete_id, expires_at, updated_at FROM my_strv_strava_tokens WHERE user_id = :user_id');
$stmt->execute(['user_id' => $userId]);
$tokenData = $stmt->fetch();

$isStravaConnected = !empty($tokenData);
?>
<!DOCTYPE html>
<html lang="de" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>my_STRV - Dashboard (Phase 1 Status)</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: '#FC4C02',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen p-8">
    <div class="max-w-4xl mx-auto">
        <header class="flex justify-between items-center pb-6 border-b border-slate-800 mb-8">
            <h1 class="text-2xl font-bold tracking-wider">
                my_<span class="text-brand">STRV</span>
            </h1>
            <div class="flex items-center space-x-4">
                <span class="text-sm text-slate-400">Eingeloggt als: <strong class="text-white"><?= htmlspecialchars($username ?? 'User', ENT_QUOTES, 'UTF-8') ?></strong> (ID: <?= $userId ?>)</span>
                <a href="logout.php" class="text-sm px-3 py-1.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded transition">
                    Abmelden
                </a>
            </div>
        </header>

        <?php if (isset($_GET['strava']) && $_GET['strava'] === 'connected'): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/50 text-emerald-400 p-4 rounded-xl mb-6">
                Strava-Konto erfolgreich verbunden!
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="bg-red-500/10 border border-red-500/50 text-red-400 p-4 rounded-xl mb-6">
                <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-2">Strava-Verbindung</h2>
                <?php if ($isStravaConnected): ?>
                    <p class="text-sm text-emerald-400 mb-4">
                        Verbunden mit Athleten-ID: <?= htmlspecialchars((string)$tokenData['athlete_id'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <a href="auth/connect.php" class="inline-block text-xs px-3 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded transition">
                        Erneut autorisieren
                    </a>
                <?php else: ?>
                    <p class="text-sm text-slate-400 mb-4">
                        Noch kein Strava-Konto verknüpft. Klicke unten, um dein Konto zu verbinden.
                    </p>
                    <a href="auth/connect.php" class="inline-block px-4 py-2 bg-brand hover:bg-orange-600 text-white text-sm font-semibold rounded-lg transition shadow-lg">
                        Mit Strava verbinden
                    </a>
                <?php endif; ?>
            </div>

            <div class="bg-slate-900 border border-slate-800 rounded-xl p-6">
                <h2 class="text-lg font-semibold text-white mb-2">Systemstatus (Phase 1)</h2>
                <ul class="text-sm text-slate-400 space-y-2">
                    <li class="flex items-center text-emerald-400">
                        <span class="mr-2">✓</span> MariaDB Schema &amp; Mandanten-Isolation
                    </li>
                    <li class="flex items-center text-emerald-400">
                        <span class="mr-2">✓</span> Session-Guard &amp; Auth-Service
                    </li>
                    <li class="flex items-center text-emerald-400">
                        <span class="mr-2">✓</span> Strava OAuth2 &amp; Token-Refresh
                    </li>
                    <li class="flex items-center text-slate-500">
                        <span class="mr-2">○</span> Phase 2: Smart Sync Engine &amp; Metriken
                    </li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
