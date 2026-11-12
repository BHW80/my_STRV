<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

$userId = Auth::requireAuth();
$username = Auth::username() ?? 'Athlet';

$pdo = getDbConnection();

// Strava-Verbindungsstatus vorab prüfen
$tokenStmt = $pdo->prepare('SELECT athlete_id, updated_at FROM my_strv_strava_tokens WHERE user_id = :user_id LIMIT 1');
$tokenStmt->execute(['user_id' => $userId]);
$tokenData = $tokenStmt->fetch();
$isStravaConnected = !empty($tokenData);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>my_STRV - Cockpit</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#FC4C02', // Strava Orange
                            hover: '#E04302',
                            light: '#FFF5F0',
                            50: '#FFF8F5',
                        }
                    }
                }
            }
        }
    </script>

    <!-- Alpine.js -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>

    <!-- Apache ECharts -->
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.0/dist/echarts.min.js"></script>

    <!-- Leaflet.js -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        .chart-box { min-height: 320px; width: 100%; }
        #leaflet-map { min-height: 420px; width: 100%; border-radius: 1rem; z-index: 10; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen antialiased selection:bg-brand/10 selection:text-brand" x-data="dashboardApp()" x-cloak>

    <!-- Top Notification Banner -->
    <div x-show="toast.show" 
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="fixed top-5 right-5 z-50 max-w-md px-5 py-3.5 rounded-xl shadow-lg border text-sm font-medium flex items-center space-x-3"
         :class="toast.type === 'error' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-emerald-50 text-emerald-800 border-emerald-200'">
        <span x-text="toast.message"></span>
        <button @click="toast.show = false" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
    </div>

    <!-- Header Navigation -->
    <header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-18 flex items-center justify-between">
            
            <!-- Brand Logo -->
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 rounded-xl bg-brand/10 text-brand flex items-center justify-center font-bold">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7.99 15.772h4.173"/>
                    </svg>
                </div>
                <div>
                    <span class="text-xl font-black tracking-tight text-slate-900">
                        my_<span class="text-brand">STRV</span>
                    </span>
                    <span class="hidden sm:inline-block ml-2 px-2 py-0.5 text-xs font-semibold bg-slate-100 text-slate-600 rounded-md">
                        <?= htmlspecialchars($username, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </div>

            <!-- Controls: Year, Sync, Settings, Logout -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                
                <!-- Jahresauswahl Dropdown -->
                <select x-model="selectedYear" @change="loadOverviewStats()"
                    class="bg-slate-50 border border-slate-200 text-slate-800 text-xs sm:text-sm font-semibold rounded-xl px-3 py-2 focus:outline-none focus:border-brand transition">
                    <template x-for="yr in availableYears" :key="yr">
                        <option :value="yr" x-text="yr"></option>
                    </template>
                </select>

                <!-- Sync Button -->
                <button @click="triggerSync()" :disabled="syncing"
                    class="bg-brand hover:bg-brand-hover text-white text-xs sm:text-sm font-semibold rounded-xl px-3.5 py-2 shadow-xs hover:shadow-sm active:scale-[0.98] transition flex items-center space-x-1.5 disabled:opacity-60">
                    <svg class="w-4 h-4" :class="{ 'animate-spin': syncing }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span class="hidden sm:inline" x-text="syncing ? 'Synchronisiere...' : 'Sync'"></span>
                </button>

                <!-- Settings Modal Trigger -->
                <button @click="openSettingsModal()"
                    class="p-2 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-xl transition" title="Einstellungen">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                    </svg>
                </button>

                <!-- Logout -->
                <a href="logout.php"
                    class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-xl transition" title="Abmelden">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                    </svg>
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        <!-- Strava Unconnected Banner (falls nicht autorisiert) -->
        <?php if (!$isStravaConnected): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 rounded-xl bg-amber-100 text-amber-700 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-amber-900">Kein Strava-Konto verbunden</h2>
                        <p class="text-xs sm:text-sm text-amber-700">Verknüpfe deinen Strava-Account, um deine Aktivitäten, Segmente und GPS-Tracks zu importieren.</p>
                    </div>
                </div>
                <a href="auth/connect.php" 
                   class="px-5 py-2.5 bg-brand hover:bg-brand-hover text-white text-sm font-semibold rounded-xl shadow-xs transition duration-150 flex-shrink-0">
                    Mit Strava verbinden
                </a>
            </div>
        <?php endif; ?>

        <!-- KPI Cards Grid -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
            <!-- Distanz -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Distanz (<span x-text="selectedYear"></span>)</span>
                <div class="mt-2 flex items-baseline space-x-2">
                    <span class="text-3xl font-black text-slate-900 tracking-tight" x-text="stats.yearly?.distance_km ?? '0'"></span>
                    <span class="text-sm font-semibold text-slate-500">km</span>
                </div>
                <div class="mt-2 text-xs text-slate-400">
                    All-Time: <strong class="text-slate-600" x-text="stats.all_time?.total_distance_km ?? '0'"></strong> km
                </div>
            </div>

            <!-- Höhenmeter -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Höhenmeter (<span x-text="selectedYear"></span>)</span>
                <div class="mt-2 flex items-baseline space-x-2">
                    <span class="text-3xl font-black text-slate-900 tracking-tight" x-text="stats.yearly?.elevation_m ?? '0'"></span>
                    <span class="text-sm font-semibold text-slate-500">m</span>
                </div>
                <div class="mt-2 text-xs text-slate-400">
                    All-Time: <strong class="text-slate-600" x-text="stats.all_time?.total_elevation_m ?? '0'"></strong> m
                </div>
            </div>

            <!-- Trainingszeit -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Trainingszeit (<span x-text="selectedYear"></span>)</span>
                <div class="mt-2 flex items-baseline space-x-2">
                    <span class="text-3xl font-black text-slate-900 tracking-tight" x-text="stats.yearly?.moving_hours ?? '0'"></span>
                    <span class="text-sm font-semibold text-slate-500">Std.</span>
                </div>
                <div class="mt-2 text-xs text-slate-400">
                    Einheiten: <strong class="text-slate-600" x-text="stats.yearly?.count ?? '0'"></strong>
                </div>
            </div>

            <!-- TSB Formwert -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Form (TSB)</span>
                    <span class="px-2 py-0.5 text-xs font-bold rounded-md"
                          :class="getTsbBadgeClass(fitness.current?.form_tsb)">
                        <span x-text="getTsbLabel(fitness.current?.form_tsb)"></span>
                    </span>
                </div>
                <div class="mt-2 flex items-baseline space-x-2">
                    <span class="text-3xl font-black tracking-tight" 
                          :class="fitness.current?.form_tsb >= 0 ? 'text-emerald-600' : 'text-slate-900'"
                          x-text="(fitness.current?.form_tsb > 0 ? '+' : '') + (fitness.current?.form_tsb ?? '0')"></span>
                    <span class="text-xs text-slate-400 font-medium">Score</span>
                </div>
                <div class="mt-2 text-xs text-slate-400 flex justify-between">
                    <span>Fitness (CTL): <strong class="text-slate-700" x-text="fitness.current?.fitness_ctl ?? '0'"></strong></span>
                    <span>Ermüdung: <strong class="text-slate-700" x-text="fitness.current?.fatigue_atl ?? '0'"></strong></span>
                </div>
            </div>
        </section>

        <!-- Charts Grid (Jahresvergleich & Fitness-Modell) -->
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            
            <!-- Chart 1: Kumulativer Jahresvergleich -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Kumulierter Jahresvergleich</h2>
                        <p class="text-xs text-slate-500">Kilometer-Fortschritt Tag 1 bis Tag 365</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <span class="w-3 h-3 rounded-full bg-brand inline-block"></span>
                        <span class="text-xs font-semibold text-slate-600">Aktuelles Jahr</span>
                    </div>
                </div>
                <div id="chart-yearly" class="chart-box"></div>
            </div>

            <!-- Chart 2: Fitness & Form (CTL / ATL / TSB) -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Fitness &amp; Ermüdung</h2>
                        <p class="text-xs text-slate-500">CTL (42 Tage), ATL (7 Tage), Form (TSB)</p>
                    </div>
                    <div class="flex items-center space-x-3 text-xs font-medium">
                        <span class="flex items-center text-sky-600"><span class="w-2.5 h-2.5 rounded-full bg-sky-500 mr-1.5"></span>CTL</span>
                        <span class="flex items-center text-rose-500"><span class="w-2.5 h-2.5 rounded-full bg-rose-500 mr-1.5"></span>ATL</span>
                        <span class="flex items-center text-emerald-600"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500 mr-1.5"></span>TSB</span>
                    </div>
                </div>
                <div id="chart-fitness" class="chart-box"></div>
            </div>
        </section>

        <!-- Ruder-Spezial Hub -->
        <section class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs" x-show="rowing.summary?.total_sessions > 0">
            <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100">
                <div class="flex items-center space-x-3">
                    <div class="w-9 h-9 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
                        🚣
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Ruder-Performance Hub</h2>
                        <p class="text-xs text-slate-500">Schlaganalyse, DPS und 500m-Pace</p>
                    </div>
                </div>
                <div class="text-right">
                    <span class="text-xs font-semibold text-slate-400">Durchschnitts-Pace:</span>
                    <span class="text-sm font-black text-slate-800 ml-1" x-text="rowing.summary?.avg_split_formatted + ' / 500m'"></span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Scatter Chart: SPM vs DPS -->
                <div class="lg:col-span-2">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Schlagzahl (SPM) vs. Distanz pro Schlag (DPS)</div>
                    <div id="chart-rowing-scatter" class="chart-box"></div>
                </div>

                <!-- Top Ergometer Einheiten -->
                <div class="space-y-3">
                    <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Top 5 schnellste 500m-Splits (≥ 1000m)</div>
                    <div class="divide-y divide-slate-100">
                        <template x-for="(top, idx) in rowing.top_performances" :key="top.id">
                            <div class="py-2.5 flex items-center justify-between text-xs">
                                <div>
                                    <div class="font-bold text-slate-800" x-text="top.name"></div>
                                    <div class="text-slate-400" x-text="top.date + ' • ' + (top.distance_m / 1000).toFixed(1) + ' km'"></div>
                                </div>
                                <div class="text-right">
                                    <div class="font-black text-brand text-sm" x-text="top.split_formatted"></div>
                                    <div class="text-slate-400" x-text="top.avg_watts ? top.avg_watts + ' W' : ''"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </section>

        <!-- Leaflet GPS-Heatmap & Aktivitätenliste -->
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            
            <!-- GPS-Karte (2 Spalten) -->
            <div class="lg:col-span-2 bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">GPS Streckennetz &amp; Heatmap</h2>
                        <p class="text-xs text-slate-500">Alle aufgezeichneten Outdoor-Touren auf einen Blick</p>
                    </div>
                    <span class="text-xs font-medium text-slate-400" x-text="activities.length + ' Strecken geladen'"></span>
                </div>
                <div id="leaflet-map" class="flex-grow"></div>
            </div>

            <!-- Letzte Aktivitäten (1 Spalte) -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="text-base font-bold text-slate-900">Aktivitäten</h2>
                        <select x-model="activityFilterSport" @change="loadActivities(1)"
                                class="bg-slate-50 border border-slate-200 text-xs font-semibold rounded-lg px-2.5 py-1.5 focus:outline-none">
                            <option value="all">Alle Sportarten</option>
                            <option value="Ride">Rennrad / Rad</option>
                            <option value="Rowing">Rudern</option>
                            <option value="Run">Laufen</option>
                        </select>
                    </div>

                    <div class="divide-y divide-slate-100 max-h-[460px] overflow-y-auto pr-1">
                        <template x-for="act in activities" :key="act.id">
                            <div class="py-3 hover:bg-slate-50/70 p-2 rounded-xl transition cursor-pointer"
                                 @click="focusActivityOnMap(act)">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-xs sm:text-sm text-slate-900 truncate max-w-[180px]" x-text="act.name"></span>
                                    <span class="text-xs font-semibold text-brand" x-text="act.distance_km + ' km'"></span>
                                </div>
                                <div class="flex items-center justify-between mt-1 text-xs text-slate-400">
                                    <span x-text="act.date_formatted"></span>
                                    <span x-text="act.moving_time_formatted"></span>
                                </div>
                                <div class="flex items-center space-x-3 mt-1.5 text-[11px] text-slate-500">
                                    <span x-show="act.avg_watts" x-text="act.avg_watts + ' W'"></span>
                                    <span x-show="act.w_kg" x-text="act.w_kg + ' W/kg'"></span>
                                    <span x-show="act.tss" x-text="'TSS ' + act.tss"></span>
                                    <span x-show="act.elevation_gain_m" x-text="act.elevation_gain_m + ' hm'"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Pagination Controls -->
                <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                    <button @click="loadActivities(pagination.current_page - 1)" 
                            :disabled="pagination.current_page <= 1"
                            class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-semibold">
                        Zurück
                    </button>
                    <span x-text="'Seite ' + pagination.current_page + ' von ' + (pagination.total_pages || 1)"></span>
                    <button @click="loadActivities(pagination.current_page + 1)" 
                            :disabled="pagination.current_page >= pagination.total_pages"
                            class="px-2.5 py-1 rounded bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-semibold">
                        Weiter
                    </button>
                </div>
            </div>
        </section>

    </main>

    <!-- Settings Modal mit Gefahrenzone -->
    <div x-show="settingsModalOpen" 
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4"
         x-transition.opacity>
        <div @click.away="settingsModalOpen = false"
             class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 max-w-xl w-full shadow-2xl space-y-6">
            
            <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                <h3 class="text-xl font-bold text-slate-900">Einstellungen</h3>
                <button @click="settingsModalOpen = false" class="text-slate-400 hover:text-slate-700 text-xl font-bold">&times;</button>
            </div>

            <!-- Tab Navigation -->
            <div class="flex space-x-2 border-b border-slate-100 pb-2">
                <button @click="settingsTab = 'athlete'" 
                        :class="settingsTab === 'athlete' ? 'bg-slate-100 text-slate-900 font-bold' : 'text-slate-500 font-medium'"
                        class="px-3.5 py-1.5 rounded-xl text-xs sm:text-sm transition">
                    Athleten-Profil
                </button>
                <button @click="settingsTab = 'strava'" 
                        :class="settingsTab === 'strava' ? 'bg-slate-100 text-slate-900 font-bold' : 'text-slate-500 font-medium'"
                        class="px-3.5 py-1.5 rounded-xl text-xs sm:text-sm transition">
                    Strava API
                </button>
                <button @click="settingsTab = 'danger'" 
                        :class="settingsTab === 'danger' ? 'bg-rose-50 text-rose-700 font-bold' : 'text-rose-500 font-medium'"
                        class="px-3.5 py-1.5 rounded-xl text-xs sm:text-sm transition">
                    Gefahrenzone
                </button>
            </div>

            <!-- Tab 1: Athleten-Profil -->
            <div x-show="settingsTab === 'athlete'" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Schwellenleistung (FTP in Watt)</label>
                    <input type="number" x-model.number="settingsForm.ftp" min="50" max="600"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    <p class="text-[11px] text-slate-400 mt-1">Basis für Normalized Power, Intensity Factor (IF) und TSS-Berechnung.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Körpergewicht (kg)</label>
                    <input type="number" step="0.5" x-model.number="settingsForm.weight" min="30" max="250"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    <p class="text-[11px] text-slate-400 mt-1">Zur Berechnung von Watt pro Kilogramm (W/kg).</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Max. Herzfrequenz (Spm)</label>
                        <input type="number" x-model.number="settingsForm.max_hr"
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Ruhepuls (Spm)</label>
                        <input type="number" x-model.number="settingsForm.rest_hr"
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    </div>
                </div>

                <div class="pt-4 flex justify-end">
                    <button @click="saveSettings()"
                            class="px-5 py-2.5 bg-brand hover:bg-brand-hover text-white text-sm font-semibold rounded-xl transition shadow-xs">
                        Speichern
                    </button>
                </div>
            </div>

            <!-- Tab 2: Strava API Verbindung -->
            <div x-show="settingsTab === 'strava'" class="space-y-4">
                <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Status</span>
                        <div class="font-bold text-sm text-slate-800" x-text="settingsData.strava?.is_connected ? 'Verbunden mit Strava' : 'Nicht verbunden'"></div>
                    </div>
                    <a href="auth/connect.php" class="px-3.5 py-1.5 text-xs font-semibold bg-white border border-slate-200 rounded-xl hover:bg-slate-50 transition">
                        Erneut verbinden
                    </a>
                </div>

                <div class="pt-2">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Eigene Strava Client ID (Optional)</label>
                    <input type="text" x-model="settingsForm.custom_client_id" placeholder="Standard: Globale App"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Eigener Strava Client Secret (Optional)</label>
                    <input type="password" x-model="settingsForm.custom_client_secret" placeholder="••••••••••••••••"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    <p class="text-[11px] text-slate-400 mt-1">Nur eintragen, wenn du deine eigene Strava-Entwickler-App nutzen möchtest.</p>
                </div>

                <div class="pt-4 flex justify-end">
                    <button @click="saveSettings()"
                            class="px-5 py-2.5 bg-brand hover:bg-brand-hover text-white text-sm font-semibold rounded-xl transition shadow-xs">
                        Speichern
                    </button>
                </div>
            </div>

            <!-- Tab 3: Gefahrenzone (Account löschen) -->
            <div x-show="settingsTab === 'danger'" class="space-y-4">
                <div class="p-4 bg-rose-50 border border-rose-200 rounded-2xl text-rose-800 text-xs sm:text-sm space-y-2">
                    <div class="font-bold flex items-center space-x-1.5">
                        <svg class="w-4 h-4 text-rose-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                        </svg>
                        <span>Achtung: Unwiderrufliche Datenlöschung</span>
                    </div>
                    <p>Dies löscht deinen Account, alle importierten Strava-Aktivitäten, GPS-Tracks, Trainings-Einstellungen und Logs permanent aus unserer MariaDB-Datenbank und entzieht der App den Zugriff bei Strava.</p>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Zur Bestätigung Passwort eingeben</label>
                    <input type="password" x-model="deleteConfirmPassword" placeholder="Dein my_STRV Passwort"
                           class="w-full px-4 py-2.5 bg-rose-50/30 border border-rose-300 rounded-xl text-slate-900 focus:outline-none focus:border-rose-600">
                </div>

                <div class="pt-4 flex justify-end">
                    <button @click="confirmDeleteAccount()" :disabled="!deleteConfirmPassword || deleteLoading"
                            class="px-5 py-2.5 bg-rose-600 hover:bg-rose-700 disabled:opacity-40 text-white text-sm font-semibold rounded-xl transition shadow-xs flex items-center space-x-2">
                        <span x-text="deleteLoading ? 'Lösche Daten...' : 'Account endgültig löschen'"></span>
                    </button>
                </div>
            </div>

        </div>
    </div>

    <!-- Frontend Application Logic (Alpine + ECharts + Leaflet) -->
    <script>
        function dashboardApp() {
            return {
                syncing: false,
                selectedYear: new Date().getFullYear(),
                availableYears: [new Date().getFullYear()],
                stats: {},
                fitness: {},
                rowing: {},
                activities: [],
                pagination: { current_page: 1, total_pages: 1 },
                activityFilterSport: 'all',
                
                settingsModalOpen: false,
                settingsTab: 'athlete',
                settingsData: {},
                settingsForm: { ftp: 250, weight: 75, max_hr: 190, rest_hr: 50, custom_client_id: '', custom_client_secret: '' },
                
                deleteConfirmPassword: '',
                deleteLoading: false,

                toast: { show: false, message: '', type: 'success' },

                map: null,
                polylineLayers: [],

                init() {
                    this.initMap();
                    this.loadOverviewStats();
                    this.loadFitnessTrend();
                    this.loadRowingAnalysis();
                    this.loadActivities(1);

                    window.addEventListener('resize', () => {
                        this.resizeCharts();
                        if (this.map) this.map.invalidateSize();
                    });
                },

                showToast(message, type = 'success') {
                    this.toast.message = message;
                    this.toast.type = type;
                    this.toast.show = true;
                    setTimeout(() => { this.toast.show = false; }, 4000);
                },

                // 1. Übersicht & Jahresvergleich
                async loadOverviewStats() {
                    try {
                        const res = await fetch(`/api/stats_overview.php?year=${this.selectedYear}`);
                        const data = await res.json();
                        if (data.success) {
                            this.stats = {
                                all_time: data.all_time,
                                yearly: data.yearly_stats,
                                sports: data.sports_breakdown,
                            };
                            this.availableYears = data.available_years.length ? data.available_years : [this.selectedYear];
                        }

                        this.loadYearlyComparisonChart();
                    } catch (e) {
                        console.error('Stats error:', e);
                    }
                },

                async loadYearlyComparisonChart() {
                    try {
                        const res = await fetch('/api/yearly_comparison.php?metric=distance');
                        const data = await res.json();
                        if (!data.success) return;

                        const chartEl = document.getElementById('chart-yearly');
                        const chart = echarts.getInstanceByDom(chartEl) || echarts.init(chartEl);

                        const currentYearStr = String(new Date().getFullYear());
                        const seriesList = [];

                        for (const [yr, values] of Object.entries(data.series)) {
                            const isCurrent = (yr === currentYearStr);
                            seriesList.push({
                                name: yr,
                                type: 'line',
                                smooth: true,
                                showSymbol: false,
                                data: values,
                                lineStyle: {
                                    width: isCurrent ? 3.5 : 1.5,
                                    color: isCurrent ? '#FC4C02' : '#94a3b8',
                                    type: isCurrent ? 'solid' : 'dashed'
                                }
                            });
                        }

                        chart.setOption({
                            tooltip: { trigger: 'axis', valueFormatter: v => (v ? v.toFixed(1) + ' km' : '0 km') },
                            legend: { top: 0, textStyle: { color: '#64748b' } },
                            grid: { top: 40, right: 15, bottom: 25, left: 45 },
                            xAxis: {
                                type: 'category',
                                data: data.day_labels,
                                axisLine: { lineStyle: { color: '#cbd5e1' } },
                                axisLabel: { color: '#64748b', interval: 60 }
                            },
                            yAxis: {
                                type: 'value',
                                axisLine: { show: false },
                                splitLine: { lineStyle: { color: '#f1f5f9' } },
                                axisLabel: { color: '#64748b' }
                            },
                            series: seriesList
                        }, true);
                    } catch (e) {
                        console.error('Yearly chart error:', e);
                    }
                },

                // 2. Fitness & Form (CTL / ATL / TSB)
                async loadFitnessTrend() {
                    try {
                        const res = await fetch('/api/fitness_trend.php?days=180');
                        const data = await res.json();
                        if (!data.success) return;

                        this.fitness = data;

                        const chartEl = document.getElementById('chart-fitness');
                        const chart = echarts.getInstanceByDom(chartEl) || echarts.init(chartEl);

                        chart.setOption({
                            tooltip: { trigger: 'axis' },
                            grid: { top: 30, right: 15, bottom: 25, left: 35 },
                            xAxis: {
                                type: 'category',
                                data: data.series.dates,
                                axisLine: { lineStyle: { color: '#cbd5e1' } },
                                axisLabel: { color: '#64748b', interval: 30 }
                            },
                            yAxis: {
                                type: 'value',
                                splitLine: { lineStyle: { color: '#f1f5f9' } },
                                axisLabel: { color: '#64748b' }
                            },
                            series: [
                                {
                                    name: 'CTL (Fitness)',
                                    type: 'line',
                                    smooth: true,
                                    showSymbol: false,
                                    data: data.series.ctl,
                                    lineStyle: { color: '#0284c7', width: 2.5 }
                                },
                                {
                                    name: 'ATL (Ermüdung)',
                                    type: 'line',
                                    smooth: true,
                                    showSymbol: false,
                                    data: data.series.atl,
                                    lineStyle: { color: '#e11d48', width: 1.5, type: 'dashed' }
                                },
                                {
                                    name: 'TSB (Form)',
                                    type: 'line',
                                    smooth: true,
                                    showSymbol: false,
                                    areaStyle: {
                                        color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                                            { offset: 0, color: 'rgba(5, 150, 105, 0.25)' },
                                            { offset: 1, color: 'rgba(5, 150, 105, 0.0)' }
                                        ])
                                    },
                                    data: data.series.tsb,
                                    lineStyle: { color: '#059669', width: 2 }
                                }
                            ]
                        }, true);

                    } catch (e) {
                        console.error('Fitness trend error:', e);
                    }
                },

                // 3. Ruder-Analyse
                async loadRowingAnalysis() {
                    try {
                        const res = await fetch('/api/rowing_analysis.php');
                        const data = await res.json();
                        if (!data.success) return;

                        this.rowing = data;

                        if (data.scatter_data && data.scatter_data.length) {
                            this.$nextTick(() => {
                                const chartEl = document.getElementById('chart-rowing-scatter');
                                if (!chartEl) return;
                                const chart = echarts.getInstanceByDom(chartEl) || echarts.init(chartEl);

                                const scatterPoints = data.scatter_data.map(d => [d.spm, d.dps, d.name, d.split_formatted, d.date]);

                                chart.setOption({
                                    tooltip: {
                                        formatter: p => `<strong>${p.value[2]}</strong><br>Datum: ${p.value[4]}<br>Kadenz: ${p.value[0]} SPM<br>DPS: ${p.value[1]} m/Zug<br>Pace: ${p.value[3]} / 500m`
                                    },
                                    grid: { top: 20, right: 20, bottom: 35, left: 40 },
                                    xAxis: {
                                        name: 'SPM',
                                        type: 'value',
                                        splitLine: { lineStyle: { color: '#f1f5f9' } },
                                        axisLabel: { color: '#64748b' }
                                    },
                                    yAxis: {
                                        name: 'Meter / Zug',
                                        type: 'value',
                                        splitLine: { lineStyle: { color: '#f1f5f9' } },
                                        axisLabel: { color: '#64748b' }
                                    },
                                    series: [{
                                        type: 'scatter',
                                        symbolSize: 11,
                                        data: scatterPoints,
                                        itemStyle: { color: '#0284c7' }
                                    }]
                                }, true);
                            });
                        }
                    } catch (e) {
                        console.error('Rowing error:', e);
                    }
                },

                // 4. Aktivitäten & Karte
                async loadActivities(page = 1) {
                    try {
                        let url = `/api/activities.php?page=${page}&per_page=15`;
                        if (this.activityFilterSport !== 'all') {
                            url += `&sport_type=${this.activityFilterSport}`;
                        }

                        const res = await fetch(url);
                        const data = await res.json();
                        if (data.success) {
                            this.activities = data.activities;
                            this.pagination = data.pagination;
                            this.renderPolylinesOnMap(data.activities);
                        }
                    } catch (e) {
                        console.error('Activities error:', e);
                    }
                },

                // Leaflet Map Initialisierung
                initMap() {
                    // Start-Zentrum Wien / Österreich als Standard
                    this.map = L.map('leaflet-map', { zoomControl: true }).setView([48.2082, 16.3738], 11);

                    // OpenStreetMap (100% frei, zuverlässig, ohne API-Key)
                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
                        maxZoom: 19
                    }).addTo(this.map);

                    // Verhindert graue Kacheln/Fehlausrichtungen bei flexiblen CSS-Layouts
                    setTimeout(() => {
                        if (this.map) this.map.invalidateSize();
                    }, 300);
                },

                // Zeichnet alle geladenen Polylines auf die Karte
                renderPolylinesOnMap(acts) {
                    if (!this.map) return;

                    // Größe neu berechnen, falls sich Container geändert hat
                    this.map.invalidateSize();

                    // Alte Layer leeren
                    this.polylineLayers.forEach(l => this.map.removeLayer(l));
                    this.polylineLayers = [];

                    const allBounds = [];

                    acts.forEach(act => {
                        if (!act.summary_polyline) return;
                        const coords = this.decodePolyline(act.summary_polyline);
                        if (!coords || !coords.length) return;

                        const polyline = L.polyline(coords, {
                            color: '#FC4C02',
                            weight: 3.5,
                            opacity: 0.75
                        }).bindPopup(`<strong>${act.name}</strong><br>${act.distance_km} km • ${act.date_formatted}`);

                        polyline.addTo(this.map);
                        this.polylineLayers.push(polyline);
                        coords.forEach(pt => allBounds.push(pt));
                    });

                    if (allBounds.length > 0) {
                        this.map.fitBounds(allBounds, { padding: [30, 30] });
                    }
                },

                focusActivityOnMap(act) {
                    if (!this.map || !act.summary_polyline) return;
                    const coords = this.decodePolyline(act.summary_polyline);
                    if (coords && coords.length) {
                        this.map.fitBounds(coords, { padding: [40, 40] });
                    }
                },

                // Google Encoded Polyline Decoder (reines JavaScript)
                decodePolyline(str, precision = 5) {
                    let index = 0, lat = 0, lng = 0, coordinates = [], shift = 0, result = 0, byte = null, latitude_change, longitude_change, factor = Math.pow(10, precision);
                    while (index < str.length) {
                        byte = null; shift = 0; result = 0;
                        do {
                            byte = str.charCodeAt(index++) - 63;
                            result |= (byte & 0x1f) << shift;
                            shift += 5;
                        } while (byte >= 0x20);
                        latitude_change = ((result & 1) ? ~(result >> 1) : (result >> 1));
                        shift = 0; result = 0;
                        do {
                            byte = str.charCodeAt(index++) - 63;
                            result |= (byte & 0x1f) << shift;
                            shift += 5;
                        } while (byte >= 0x20);
                        longitude_change = ((result & 1) ? ~(result >> 1) : (result >> 1));
                        lat += latitude_change;
                        lng += longitude_change;
                        coordinates.push([lat / factor, lng / factor]);
                    }
                    return coordinates;
                },

                // 5. Synchronisation anstoßen
                async triggerSync() {
                    this.syncing = true;
                    this.showToast('Synchronisiere mit Strava API...', 'success');

                    try {
                        const res = await fetch('/api/sync.php', { method: 'POST' });
                        const data = await res.json();

                        if (data.status === 'success') {
                            this.showToast(data.message, 'success');
                            await this.loadOverviewStats();
                            await this.loadFitnessTrend();
                            await this.loadActivities(1);
                        } else {
                            this.showToast(data.message || 'Sync fehlgeschlagen', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler beim Synchronisieren', 'error');
                    } finally {
                        this.syncing = false;
                    }
                },

                // 6. Settings Modal
                async openSettingsModal() {
                    try {
                        const res = await fetch('/api/settings.php');
                        const data = await res.json();
                        if (data.success) {
                            this.settingsData = data;
                            this.settingsForm = {
                                ftp: data.athlete.ftp,
                                weight: data.athlete.weight,
                                max_hr: data.athlete.max_hr,
                                rest_hr: data.athlete.rest_hr,
                                custom_client_id: data.strava.custom_client_id || '',
                                custom_client_secret: '',
                            };
                        }
                    } catch (e) {
                        console.error('Settings error:', e);
                    }
                    this.settingsModalOpen = true;
                },

                async saveSettings() {
                    try {
                        const res = await fetch('/api/settings.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.settingsForm)
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('Einstellungen erfolgreich gespeichert!', 'success');
                            this.settingsModalOpen = false;
                        } else {
                            this.showToast(data.error || 'Fehler beim Speichern', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler', 'error');
                    }
                },

                // 7. Permanente Account-Löschung
                async confirmDeleteAccount() {
                    if (!confirm('Bist du absolut sicher? Dies löscht ausnahmslos alle deine Daten unwiderruflich!')) {
                        return;
                    }

                    this.deleteLoading = true;

                    try {
                        const res = await fetch('/api/account_delete.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ password_confirm: this.deleteConfirmPassword })
                        });
                        const data = await res.json();

                        if (data.success) {
                            alert(data.message);
                            window.location.href = '/login.php';
                        } else {
                            this.showToast(data.error || 'Löschung fehlgeschlagen', 'error');
                        }
                    } catch (e) {
                        this.showToast('Fehler bei der Verbindung', 'error');
                    } finally {
                        this.deleteLoading = false;
                    }
                },

                getTsbBadgeClass(tsb) {
                    if (tsb === null || tsb === undefined) return 'bg-slate-100 text-slate-600';
                    if (tsb >= 10) return 'bg-emerald-50 text-emerald-700 border border-emerald-200';
                    if (tsb >= -10) return 'bg-sky-50 text-sky-700 border border-sky-200';
                    if (tsb >= -25) return 'bg-amber-50 text-amber-700 border border-amber-200';
                    return 'bg-rose-50 text-rose-700 border border-rose-200';
                },

                getTsbLabel(tsb) {
                    if (tsb === null || tsb === undefined) return 'Keine Daten';
                    if (tsb >= 15) return 'Peak Form';
                    if (tsb >= 5) return 'Frisch';
                    if (tsb >= -10) return 'Neutral';
                    if (tsb >= -25) return 'Aufbau / Belastung';
                    return 'Hohe Ermüdung';
                },

                resizeCharts() {
                    ['chart-yearly', 'chart-fitness', 'chart-rowing-scatter'].forEach(id => {
                        const el = document.getElementById(id);
                        if (el) {
                            const inst = echarts.getInstanceByDom(el);
                            if (inst) inst.resize();
                        }
                    });
                }
            }
        }
    </script>
</body>
</html>
