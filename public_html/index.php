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
    <title>my_STRV - Performance Cockpit</title>
    
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
            
            <!-- Brand Logo & User -->
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

            <!-- Tab Navigation (Desktop) -->
            <nav class="hidden md:flex items-center space-x-1 bg-slate-100/80 p-1 rounded-xl">
                <button @click="switchTab('dashboard')" 
                        :class="currentTab === 'dashboard' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 hover:text-slate-900 font-medium'"
                        class="px-3.5 py-1.5 rounded-lg text-xs transition">
                    📊 Dashboard
                </button>
                <button @click="switchTab('goals')" 
                        :class="currentTab === 'goals' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 hover:text-slate-900 font-medium'"
                        class="px-3.5 py-1.5 rounded-lg text-xs transition">
                    🎯 Ziele
                </button>
                <button @click="switchTab('activities')" 
                        :class="currentTab === 'activities' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 hover:text-slate-900 font-medium'"
                        class="px-3.5 py-1.5 rounded-lg text-xs transition">
                    📋 Aktivitäten
                </button>
                <button @click="switchTab('stats')" 
                        :class="currentTab === 'stats' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 hover:text-slate-900 font-medium'"
                        class="px-3.5 py-1.5 rounded-lg text-xs transition">
                    ⭐ Statistiken
                </button>
            </nav>

            <!-- Controls: Year, Sync, Settings, Logout -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                
                <!-- Jahresauswahl Dropdown -->
                <select x-model="selectedYear" @change="onYearChange()"
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

        <!-- Mobile Sub-Nav -->
        <div class="md:hidden flex border-t border-slate-100 px-4 py-2 bg-slate-50 overflow-x-auto space-x-2">
            <button @click="switchTab('dashboard')" 
                    :class="currentTab === 'dashboard' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 font-medium'"
                    class="px-3 py-1.5 rounded-lg text-xs flex-shrink-0">
                📊 Dashboard
            </button>
            <button @click="switchTab('goals')" 
                    :class="currentTab === 'goals' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 font-medium'"
                    class="px-3 py-1.5 rounded-lg text-xs flex-shrink-0">
                🎯 Ziele
            </button>
            <button @click="switchTab('activities')" 
                    :class="currentTab === 'activities' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 font-medium'"
                    class="px-3 py-1.5 rounded-lg text-xs flex-shrink-0">
                📋 Aktivitäten
            </button>
            <button @click="switchTab('stats')" 
                    :class="currentTab === 'stats' ? 'bg-white text-slate-900 shadow-xs font-bold' : 'text-slate-500 font-medium'"
                    class="px-3 py-1.5 rounded-lg text-xs flex-shrink-0">
                ⭐ Statistiken
            </button>
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

        <!-- ================================================================= -->
        <!-- TAB 1: DASHBOARD                                                  -->
        <!-- ================================================================= -->
        <div x-show="currentTab === 'dashboard'" class="space-y-8">

            <!-- KPI Cards Grid (mit ACWR & Form) -->
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

                <!-- Form (TSB) & ACWR Belastungs-Ampel -->
                <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Form &amp; Belastung</span>
                        <!-- ACWR Badge -->
                        <span class="px-2 py-0.5 text-xs font-bold rounded-md"
                              :class="getAcwrBadgeClass(fitness.current?.acwr_status)">
                            <span x-text="'ACWR ' + (fitness.current?.acwr ?? '1.0')"></span>
                        </span>
                    </div>
                    <div class="mt-2 flex items-baseline space-x-2">
                        <span class="text-3xl font-black tracking-tight" 
                              :class="fitness.current?.form_tsb >= 0 ? 'text-emerald-600' : 'text-slate-900'"
                              x-text="(fitness.current?.form_tsb > 0 ? '+' : '') + (fitness.current?.form_tsb ?? '0')"></span>
                        <span class="text-xs text-slate-400 font-medium">TSB Form</span>
                    </div>
                    <div class="mt-2 text-xs text-slate-500 flex justify-between items-center">
                        <span class="truncate max-w-[170px]" :title="fitness.current?.acwr_label" x-text="fitness.current?.acwr_label ?? 'Sweetspot'"></span>
                        <span class="text-slate-400 font-medium">CTL: <strong class="text-slate-700" x-text="fitness.current?.fitness_ctl ?? '0'"></strong></span>
                    </div>
                </div>
            </section>

            <!-- 80/20 Polarisierte Trainingsanalyse (Letzte 28 Tage) -->
            <section class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs" x-show="stats.polarized_80_20">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                    <div>
                        <div class="flex items-center space-x-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-400">Trainingssteuerung</span>
                            <span class="px-2 py-0.5 rounded-full text-[11px] font-bold bg-sky-50 text-sky-700">80/20 Polarisiert</span>
                        </div>
                        <h3 class="text-base font-bold text-slate-900 mt-0.5">Intensitätsverteilung (Letzte 4 Wochen)</h3>
                    </div>
                    <div class="text-xs text-slate-500 font-medium">
                        Gesamt: <strong class="text-slate-700" x-text="stats.polarized_80_20?.total_hours + ' Std.'"></strong>
                    </div>
                </div>

                <!-- Balken-Grafik -->
                <div class="space-y-2">
                    <div class="w-full h-4 bg-slate-100 rounded-full overflow-hidden flex">
                        <div class="h-full bg-emerald-500 transition-all duration-500" 
                             :style="'width: ' + (stats.polarized_80_20?.low_intensity_ratio ?? 80) + '%'"
                             :title="'Grundlage: ' + stats.polarized_80_20?.low_intensity_ratio + '%'"></div>
                        <div class="h-full bg-rose-500 transition-all duration-500" 
                             :style="'width: ' + (stats.polarized_80_20?.high_intensity_ratio ?? 20) + '%'"
                             :title="'Intensiv: ' + stats.polarized_80_20?.high_intensity_ratio + '%'"></div>
                    </div>
                    <div class="flex items-center justify-between text-xs font-semibold">
                        <span class="text-emerald-700 flex items-center space-x-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 inline-block"></span>
                            <span>Grundlage / Z1-Z2: <strong x-text="stats.polarized_80_20?.low_intensity_ratio + '%'"></strong> (<span x-text="stats.polarized_80_20?.low_hours + 'h'"></span>)</span>
                        </span>
                        <span class="text-rose-700 flex items-center space-x-1.5">
                            <span class="w-2.5 h-2.5 rounded-full bg-rose-500 inline-block"></span>
                            <span>Intensiv / Z3-Z5: <strong x-text="stats.polarized_80_20?.high_intensity_ratio + '%'"></strong> (<span x-text="stats.polarized_80_20?.high_hours + 'h'"></span>)</span>
                        </span>
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

            <!-- Ruder-Spezial Hub (falls vorhanden) -->
            <section class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs" x-show="rowing.summary?.total_sessions > 0">
                <div class="flex items-center justify-between mb-6 pb-4 border-b border-slate-100">
                    <div class="flex items-center space-x-3">
                        <div class="w-9 h-9 rounded-xl bg-sky-50 text-sky-600 flex items-center justify-center font-bold">
                            🚣
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Concept2 Ruder-Analytics</h2>
                            <p class="text-xs text-slate-500">Ergometer-Leistung &amp; Schlagdynamik</p>
                        </div>
                    </div>
                    <span class="text-xs font-semibold px-2.5 py-1 bg-sky-50 text-sky-700 rounded-lg"
                          x-text="rowing.summary?.total_sessions + ' Einheiten'"></span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="p-4 bg-slate-50/70 rounded-xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Ø 500m Split</span>
                        <div class="text-2xl font-black text-slate-900 mt-1" x-text="rowing.summary?.avg_split_500m_formatted ?? '-'"></div>
                    </div>
                    <div class="p-4 bg-slate-50/70 rounded-xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Schlaglänge (DPS)</span>
                        <div class="text-2xl font-black text-slate-900 mt-1" x-text="rowing.summary?.avg_distance_per_stroke ? rowing.summary.avg_distance_per_stroke + ' m' : '-'"></div>
                    </div>
                    <div class="p-4 bg-slate-50/70 rounded-xl border border-slate-100">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Schlagenergie (JPS)</span>
                        <div class="text-2xl font-black text-slate-900 mt-1" x-text="rowing.summary?.avg_joules_per_stroke ? rowing.summary.avg_joules_per_stroke + ' J' : '-'"></div>
                    </div>
                </div>
            </section>

            <!-- GPS-Heatmap & Vorschau -->
            <section class="grid grid-cols-1 lg:grid-cols-3 gap-6">
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

                <!-- Schnelle Übersicht letzte Touren -->
                <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-base font-bold text-slate-900">Letzte Touren</h2>
                            <button @click="switchTab('activities')" class="text-xs font-bold text-brand hover:underline">Alle ansehen &rarr;</button>
                        </div>
                        <div class="divide-y divide-slate-100 max-h-[460px] overflow-y-auto pr-1">
                            <template x-for="act in activities.slice(0, 5)" :key="act.id">
                                <div class="py-3 hover:bg-slate-50 p-2 rounded-xl transition cursor-pointer"
                                     @click="focusActivityOnMap(act)">
                                    <div class="flex items-center justify-between">
                                        <span class="font-bold text-xs sm:text-sm text-slate-900 truncate max-w-[180px]" x-text="act.name"></span>
                                        <span class="text-xs font-semibold text-brand" x-text="act.distance_km + ' km'"></span>
                                    </div>
                                    <div class="flex items-center justify-between mt-1 text-xs text-slate-400">
                                        <span x-text="act.date_formatted"></span>
                                        <span x-text="act.moving_time_formatted"></span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 2: ZIELE (GOALS & PACING MIT SAISONKURVE)                     -->
        <!-- ================================================================= -->
        <div x-show="currentTab === 'goals'" class="space-y-6">
            
            <!-- Ziele Header Bar -->
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs">
                <div>
                    <h2 class="text-xl font-black text-slate-900">Trainings-Ziele &amp; Pacing</h2>
                    <p class="text-xs sm:text-sm text-slate-500">Wochen-, Monats- und Jahresziele mit automatischer Soll/Ist-Berechnung und Saisonkurven</p>
                </div>
                <button @click="openNewGoalModal()" 
                        class="px-4 py-2.5 bg-brand hover:bg-brand-hover text-white text-xs sm:text-sm font-semibold rounded-xl shadow-xs transition flex items-center space-x-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>Neues Ziel anlegen</span>
                </button>
            </div>

            <!-- Keine Ziele hinterlegt -->
            <div x-show="goals.length === 0" class="bg-white border border-slate-200/90 rounded-2xl p-12 text-center space-y-3">
                <div class="w-12 h-12 rounded-full bg-slate-100 text-slate-400 flex items-center justify-center mx-auto text-xl">🎯</div>
                <h3 class="font-bold text-slate-800">Noch keine Ziele für <span x-text="selectedYear"></span> definiert</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto">Setze dir ein Jahresziel (z. B. 4.000 km Rennrad mit Sommer-Peak) oder ein Wochenziel, um deinen Trainingsfortschritt live zu überwachen.</p>
                <button @click="openNewGoalModal()" class="mt-2 px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-800 font-semibold text-xs rounded-xl transition">Jetzt erstes Ziel anlegen</button>
            </div>

            <!-- Ziel-Karten Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6" x-show="goals.length > 0">
                <template x-for="g in goals" :key="g.id">
                    <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between space-y-4">
                        
                        <!-- Top Row: Title, Sport & Delete -->
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wider rounded-md"
                                          :class="g.period_type === 'yearly' ? 'bg-amber-100 text-amber-800' : (g.period_type === 'monthly' ? 'bg-sky-100 text-sky-800' : 'bg-purple-100 text-purple-800')"
                                          x-text="g.period_type === 'yearly' ? 'Jahresziel ' + g.year : (g.period_type === 'monthly' ? 'Monatsziel' : 'Wochenziel')"></span>
                                    <span class="text-xs font-bold text-slate-500" x-text="g.sport_type === 'all' ? 'Alle Sportarten' : g.sport_type"></span>
                                </div>
                                <h3 class="text-lg font-black text-slate-900 mt-1" x-text="g.title"></h3>
                            </div>
                            <button @click="deleteGoal(g.id)" class="text-slate-300 hover:text-rose-600 transition p-1" title="Ziel löschen">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>

                        <!-- Progress Bar & Values -->
                        <div class="space-y-2">
                            <div class="flex items-baseline justify-between">
                                <div>
                                    <span class="text-2xl font-black text-slate-900" x-text="g.current_value"></span>
                                    <span class="text-sm font-semibold text-slate-400">/ <span x-text="g.target_value + ' ' + g.unit"></span></span>
                                </div>
                                <span class="text-sm font-extrabold text-brand" x-text="g.progress_percent + '%'"></span>
                            </div>
                            <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full bg-brand rounded-full transition-all duration-500"
                                     :style="'width: ' + Math.min(100, g.progress_percent) + '%'"></div>
                            </div>
                        </div>

                        <!-- Pacing & Schedule Status -->
                        <div class="p-3.5 bg-slate-50 rounded-xl border border-slate-100 grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <span class="text-slate-400 block text-[11px] font-semibold">Soll-Stand bis heute</span>
                                <strong class="text-slate-800 text-sm font-bold" x-text="g.expected_value_today + ' ' + g.unit"></strong>
                            </div>
                            <div>
                                <span class="text-slate-400 block text-[11px] font-semibold">Zeitplan (Pacing)</span>
                                <span class="font-extrabold text-sm"
                                      :class="g.is_ahead ? 'text-emerald-600' : 'text-rose-600'"
                                      x-text="(g.is_ahead ? '+' : '') + g.delta_to_schedule + ' ' + g.unit + (g.is_ahead ? ' (Voraus)' : ' (Rückstand)')"></span>
                            </div>
                            <div class="col-span-2 pt-1 border-t border-slate-200/60 flex items-center justify-between text-[11px] text-slate-400">
                                <span>Restzeit: <strong class="text-slate-600" x-text="g.remaining_days + ' Tage'"></strong></span>
                                <span x-text="'Kurve: ' + (g.seasonality === 'summer_peak' ? 'Sommer-Peak' : (g.seasonality === 'winter_peak' ? 'Winter-Peak' : 'Gleichmäßig'))"></span>
                            </div>
                        </div>

                    </div>
                </template>
            </div>
        </div>

        <!-- ================================================================= -->
        <!-- TAB 3: AKTIVITÄTEN (LISTEN, FILTER & AUSKLAPPBARE ZONEN)            -->
        <!-- ================================================================= -->
        <div x-show="currentTab === 'activities'" class="space-y-6">
            
            <!-- Filter Bar Card -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs space-y-4">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-xl font-black text-slate-900">Aktivitäten &amp; Zonen-Analyse</h2>
                        <p class="text-xs text-slate-500">Vollständige Trainingshistorie mit HF- und Watt-Zonenverteilung</p>
                    </div>

                    <!-- Search Input -->
                    <div class="relative w-full md:w-72">
                        <input type="text" x-model="activityQuery" @input.debounce.300ms="loadActivities(1)"
                               placeholder="Aktivität suchen..." 
                               class="w-full pl-9 pr-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-800 focus:outline-none focus:border-brand">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                    </div>
                </div>

                <!-- Chips Row: Sportart, Environment, Sortierung -->
                <div class="flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-slate-100">
                    
                    <!-- Sportart Filter Chips -->
                    <div class="flex flex-wrap items-center gap-1.5 text-xs">
                        <button @click="setSportFilter('all')" 
                                :class="activityFilterSport === 'all' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="px-3 py-1.5 rounded-lg transition">Alle</button>
                        <button @click="setSportFilter('Ride')" 
                                :class="activityFilterSport === 'Ride' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="px-3 py-1.5 rounded-lg transition">🚴 Rad</button>
                        <button @click="setSportFilter('Rowing')" 
                                :class="activityFilterSport === 'Rowing' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="px-3 py-1.5 rounded-lg transition">🚣 Rudern</button>
                        <button @click="setSportFilter('Run')" 
                                :class="activityFilterSport === 'Run' ? 'bg-slate-900 text-white font-bold' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'"
                                class="px-3 py-1.5 rounded-lg transition">🏃 Laufen</button>
                    </div>

                    <!-- Environment & Sort Dropdown -->
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <!-- Jahresfilter für Aktivitäten -->
                        <select x-model="activityFilterYear" @change="loadActivities(1)"
                                class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-semibold text-slate-700 focus:outline-none">
                            <option value="all">Alle Jahre</option>
                            <template x-for="yr in availableYears" :key="yr">
                                <option :value="yr" x-text="yr"></option>
                            </template>
                        </select>

                        <select x-model="activityEnvironment" @change="loadActivities(1)"
                                class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-semibold text-slate-700 focus:outline-none">
                            <option value="all">Indoor &amp; Outdoor</option>
                            <option value="outdoor">Nur Outdoor</option>
                            <option value="indoor">Nur Indoor</option>
                        </select>

                        <select x-model="activitySortBy" @change="loadActivities(1)"
                                class="bg-slate-50 border border-slate-200 rounded-xl px-2.5 py-1.5 font-semibold text-slate-700 focus:outline-none">
                            <option value="date">Neueste zuerst</option>
                            <option value="distance">Nach Distanz</option>
                            <option value="duration">Nach Dauer</option>
                            <option value="elevation">Nach Höhenmetern</option>
                            <option value="watts">Nach Watt</option>
                        </select>
                    </div>

                </div>
            </div>

            <!-- Activity List Feed (Ausklappbare Cards) -->
            <div class="space-y-3">
                <template x-for="act in activities" :key="act.id">
                    <div class="bg-white border border-slate-200/90 rounded-2xl p-5 shadow-xs transition hover:border-slate-300">
                        
                        <!-- Header Line: Click toggles expansion -->
                        <div class="cursor-pointer" @click="toggleActivityExpand(act.id)">
                            <div class="flex items-start justify-between">
                                <div>
                                    <div class="flex items-center space-x-2 text-xs">
                                        <span class="font-bold text-brand" x-text="act.sport_type + (act.is_indoor ? ' (Indoor)' : '')"></span>
                                        <span class="text-slate-300">•</span>
                                        <span class="text-slate-500" x-text="act.date_formatted"></span>
                                    </div>
                                    <h3 class="text-base font-bold text-slate-900 mt-1" x-text="act.name"></h3>
                                </div>
                                <div class="text-right">
                                    <div class="text-lg font-black text-slate-900" x-text="act.distance_km + ' km'"></div>
                                    <div class="text-xs text-slate-400 font-medium" x-text="act.moving_time_formatted"></div>
                                </div>
                            </div>

                            <!-- Summary Badges Row (Always Visible) -->
                            <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-slate-100 text-xs">
                                <span x-show="act.elevation_gain_m > 0" class="px-2 py-0.5 bg-slate-100 text-slate-700 rounded-md font-semibold" x-text="act.elevation_gain_m + ' hm'"></span>
                                <span x-show="act.avg_watts" class="px-2 py-0.5 bg-sky-50 text-sky-700 rounded-md font-semibold" x-text="act.avg_watts + ' W (Ø)'"></span>
                                <span x-show="act.normalized_power" class="px-2 py-0.5 bg-sky-100 text-sky-800 rounded-md font-semibold" x-text="'NP ' + act.normalized_power + ' W'"></span>
                                <span x-show="act.avg_hr" class="px-2 py-0.5 bg-rose-50 text-rose-700 rounded-md font-semibold" x-text="'HF ' + act.avg_hr + ' bpm'"></span>
                                <span x-show="act.tss" class="px-2 py-0.5 bg-amber-50 text-amber-700 rounded-md font-semibold" x-text="'TSS ' + act.tss"></span>
                                <span x-show="act.suffer_score" class="px-2 py-0.5 bg-orange-50 text-orange-700 rounded-md font-semibold" x-text="'Suffer ' + act.suffer_score"></span>
                                <span class="ml-auto text-[11px] font-bold text-slate-400 flex items-center space-x-1">
                                    <span x-text="expandedActivityId === act.id ? 'Einklappen' : 'Details & Zonen'"></span>
                                    <svg class="w-3.5 h-3.5 transition-transform" :class="expandedActivityId === act.id ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                </span>
                            </div>
                        </div>

                        <!-- Ausklappbarer Detailbereich (Parameter Grid & Zonen) -->
                        <div x-show="expandedActivityId === act.id" x-collapse class="mt-4 pt-4 border-t border-slate-100 space-y-4">
                            
                            <!-- Parameter Grid (2 Spalten wie in Android) -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-100">
                                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Geschwindigkeit</span>
                                    <strong class="text-slate-800 text-sm font-bold" x-text="act.avg_speed_kmh ? act.avg_speed_kmh + ' km/h' : '-'"></strong>
                                    <span class="text-[10px] text-slate-400 block" x-show="act.max_speed_kmh" x-text="'Max: ' + act.max_speed_kmh + ' km/h'"></span>
                                </div>
                                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-100">
                                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Leistung &amp; NP</span>
                                    <strong class="text-slate-800 text-sm font-bold" x-text="act.avg_watts ? act.avg_watts + ' W' : '-'"></strong>
                                    <span class="text-[10px] text-slate-400 block" x-show="act.w_kg" x-text="act.w_kg + ' W/kg'"></span>
                                </div>
                                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-100">
                                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Herzfrequenz</span>
                                    <strong class="text-slate-800 text-sm font-bold" x-text="act.avg_hr ? act.avg_hr + ' bpm' : '-'"></strong>
                                    <span class="text-[10px] text-slate-400 block" x-show="act.max_hr" x-text="'Max: ' + act.max_hr + ' bpm'"></span>
                                </div>
                                <div class="p-2.5 bg-slate-50 rounded-xl border border-slate-100">
                                    <span class="text-slate-400 block text-[10px] uppercase font-bold">Trittfrequenz &amp; Kalorien</span>
                                    <strong class="text-slate-800 text-sm font-bold" x-text="act.avg_cadence ? act.avg_cadence + ' rpm' : (act.calories ? act.calories + ' kcal' : '-')"></strong>
                                    <span class="text-[10px] text-slate-400 block" x-show="act.calories && act.avg_cadence" x-text="act.calories + ' kcal'"></span>
                                </div>
                            </div>

                            <!-- Herzfrequenz-Zonen (Z1-Z5) -->
                            <div x-show="act.hr_zones && act.hr_zones.length > 0" class="p-4 bg-slate-50/70 rounded-xl border border-slate-100 space-y-2">
                                <div class="text-xs font-bold text-slate-700 flex items-center justify-between">
                                    <span>❤️ Herzfrequenz-Zonenverteilung (Z1 - Z5)</span>
                                </div>
                                <div class="w-full h-3 rounded-full overflow-hidden flex bg-slate-200">
                                    <template x-for="z in act.hr_zones" :key="z.zone">
                                        <div class="h-full transition-all" :style="'width: ' + z.percent + '%; background-color: ' + z.color" :title="z.zone + ' ' + z.name + ': ' + z.percent + '%'"></div>
                                    </template>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 pt-1 text-[11px]">
                                    <template x-for="z in act.hr_zones" :key="z.zone">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block" :style="'background-color: ' + z.color"></span>
                                            <span class="text-slate-600 font-semibold" x-text="z.zone + ': ' + z.percent + '%'"></span>
                                            <span class="text-slate-400" x-text="'(' + z.range_label + ')'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Coggan Watt-Zonen (Z1-Z7) -->
                            <div x-show="act.power_zones && act.power_zones.length > 0" class="p-4 bg-slate-50/70 rounded-xl border border-slate-100 space-y-2">
                                <div class="text-xs font-bold text-slate-700 flex items-center justify-between">
                                    <span>⚡ Coggan-Leistungszonen (Z1 - Z7)</span>
                                </div>
                                <div class="w-full h-3 rounded-full overflow-hidden flex bg-slate-200">
                                    <template x-for="pz in act.power_zones" :key="pz.zone">
                                        <div class="h-full transition-all" :style="'width: ' + pz.percent + '%; background-color: ' + pz.color" :title="pz.zone + ' ' + pz.name + ': ' + pz.percent + '%'"></div>
                                    </template>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 text-[11px]">
                                    <template x-for="pz in act.power_zones" :key="pz.zone">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="w-2 h-2 rounded-full inline-block" :style="'background-color: ' + pz.color"></span>
                                            <span class="text-slate-600 font-semibold" x-text="pz.zone + ': ' + pz.percent + '%'"></span>
                                            <span class="text-slate-400" x-text="'(' + pz.range_label + ')'"></span>
                                        </div>
                                    </template>
                                </div>
                            </div>

                        </div>

                    </div>
                </template>
            </div>

            <!-- Pagination Controls -->
            <div class="p-4 bg-white border border-slate-200/90 rounded-2xl flex items-center justify-between text-xs text-slate-600">
                <button @click="loadActivities(pagination.current_page - 1)" 
                        :disabled="pagination.current_page <= 1"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-semibold transition">
                    &larr; Vorherige Seite
                </button>
                <span>Seite <strong class="text-slate-900" x-text="pagination.current_page"></strong> von <strong class="text-slate-900" x-text="pagination.total_pages || 1"></strong> (<span x-text="pagination.total_items"></span> Touren)</span>
                <button @click="loadActivities(pagination.current_page + 1)" 
                        :disabled="pagination.current_page >= pagination.total_pages"
                        class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 disabled:opacity-40 font-semibold transition">
                    Nächste Seite &rarr;
                </button>
            </div>

        </div>

        <!-- ================================================================= -->
        <!-- TAB 4: STATISTIKEN & AGGREGATIONEN                                -->
        <!-- ================================================================= -->
        <div x-show="currentTab === 'stats'" class="space-y-6">
            
            <!-- 4-Wochen-Volumentrend (Wochen-Karten) -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">4-Wochen-Volumentrend</h2>
                        <p class="text-xs text-slate-500">Rollende Wochenblöcke der letzten 28 Tage im direkten Vergleich</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <template x-for="w in stats.four_weeks_trend" :key="w.week_index">
                        <div class="p-4 rounded-xl border border-slate-100 bg-slate-50/70 flex flex-col justify-between space-y-2">
                            <div class="flex items-center justify-between text-xs">
                                <span class="font-bold text-slate-800" x-text="w.label"></span>
                                <span class="text-slate-400 text-[10px]" x-text="w.start_date + ' bis ' + w.end_date"></span>
                            </div>
                            <div>
                                <div class="text-2xl font-black text-slate-900" x-text="w.distance_km + ' km'"></div>
                                <div class="text-xs text-slate-500 font-medium" x-text="w.hours + ' Std. • ' + w.elevation_m + ' hm • ' + w.count + 'x'"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Regenerations-Quote & Monatstrend (MoM) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <!-- Ruhetage-Monitoring -->
                <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Trainings-Konsistenz &amp; Regeneration</h3>
                        <p class="text-xs text-slate-500 mb-4">Aktivtage vs. Ruhetage im Jahr <span x-text="selectedYear"></span></p>

                        <div class="p-5 bg-slate-50 rounded-xl border border-slate-100 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-600">Aktivtage</span>
                                <strong class="text-base font-black text-emerald-600" x-text="stats.rest_days_stats?.active_days + ' Tage'"></strong>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-slate-600">Ruhetage</span>
                                <strong class="text-base font-black text-slate-700" x-text="stats.rest_days_stats?.rest_days + ' Tage'"></strong>
                            </div>
                            <div class="pt-2 border-t border-slate-200 flex items-center justify-between">
                                <span class="text-xs font-bold text-slate-700">Ruhetags-Quote</span>
                                <strong class="text-lg font-black text-brand" x-text="stats.rest_days_stats?.rest_day_ratio_pct + '%'"></strong>
                            </div>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-400 mt-3">Tipp aus der Sportwissenschaft: Eine gesunde Ruhetags-Quote von 35-50% sichert Superkompensation und schützt vor Übertraining.</p>
                </div>

                <!-- Monatstrend (MoM) -->
                <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs flex flex-col justify-between">
                    <div>
                        <h3 class="text-base font-bold text-slate-900">Monatstrend (MoM)</h3>
                        <p class="text-xs text-slate-500 mb-4">Aktueller Kalendermonat bisher im Vergleich zum Vormonat</p>

                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-4 bg-brand/5 border border-brand/20 rounded-xl">
                                <span class="text-xs font-bold text-brand uppercase">Aktueller Monat</span>
                                <div class="text-2xl font-black text-slate-900 mt-1" x-text="stats.mom_trend?.current_month?.distance_km + ' km'"></div>
                                <div class="text-xs text-slate-500 mt-1" x-text="stats.mom_trend?.current_month?.hours + 'h • ' + stats.mom_trend?.current_month?.elevation_m + ' hm'"></div>
                            </div>
                            <div class="p-4 bg-slate-50 border border-slate-100 rounded-xl">
                                <span class="text-xs font-bold text-slate-400 uppercase">Vormonat</span>
                                <div class="text-2xl font-black text-slate-700 mt-1" x-text="stats.mom_trend?.previous_month?.distance_km + ' km'"></div>
                                <div class="text-xs text-slate-500 mt-1" x-text="stats.mom_trend?.previous_month?.hours + 'h • ' + stats.mom_trend?.previous_month?.elevation_m + ' hm'"></div>
                            </div>
                        </div>
                    </div>
                    <div class="text-xs text-slate-500 pt-3">
                        Einheiten bisher: <strong class="text-slate-800" x-text="stats.mom_trend?.current_month?.count"></strong> (Vormonat gesamt: <span x-text="stats.mom_trend?.previous_month?.count"></span>)
                    </div>
                </div>

            </div>

            <!-- Sportarten Breakdown Tabelle -->
            <div class="bg-white border border-slate-200/90 rounded-2xl p-6 shadow-xs">
                <h3 class="text-base font-bold text-slate-900 mb-4">Sportarten-Aufteilung (<span x-text="selectedYear"></span>)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="text-[11px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100">
                            <tr>
                                <th class="pb-3">Sportart</th>
                                <th class="pb-3">Einheiten</th>
                                <th class="pb-3">Distanz</th>
                                <th class="pb-3">Höhenmeter</th>
                                <th class="pb-3">Dauer</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-medium text-slate-700">
                            <template x-for="s in stats.sports" :key="s.sport_type">
                                <tr class="hover:bg-slate-50">
                                    <td class="py-3 font-bold text-slate-900" x-text="s.sport_type"></td>
                                    <td class="py-3" x-text="s.count + 'x'"></td>
                                    <td class="py-3 font-bold" x-text="s.distance_km + ' km'"></td>
                                    <td class="py-3" x-text="s.elevation_m + ' m'"></td>
                                    <td class="py-3" x-text="s.moving_hours + ' Std.'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>

    </main>

    <!-- ================================================================= -->
    <!-- MODAL: NEUES ZIEL ANLEGEN                                          -->
    <!-- ================================================================= -->
    <div x-show="newGoalModalOpen" 
         class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/40 backdrop-blur-xs flex items-center justify-center p-4"
         x-transition.opacity>
        <div @click.away="newGoalModalOpen = false"
             class="bg-white border border-slate-200 rounded-3xl p-6 sm:p-8 max-w-lg w-full shadow-2xl space-y-5">
            
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h3 class="text-lg font-bold text-slate-900">Neues Trainings-Ziel</h3>
                <button @click="newGoalModalOpen = false" class="text-slate-400 hover:text-slate-700 text-xl font-bold">&times;</button>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Titel des Ziels</label>
                    <input type="text" x-model="newGoalForm.title" placeholder="z. B. 4.000 km Rennrad Saison 2026"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs sm:text-sm text-slate-900 focus:outline-none focus:border-brand">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Sportart</label>
                        <select x-model="newGoalForm.sport_type"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:border-brand">
                            <option value="all">Alle Sportarten</option>
                            <option value="Ride">Rennrad / Rad</option>
                            <option value="Rowing">Rudern</option>
                            <option value="Run">Laufen</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Zeitraum</label>
                        <select x-model="newGoalForm.period_type"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:border-brand">
                            <option value="yearly">Jahresziel</option>
                            <option value="monthly">Monatsziel</option>
                            <option value="weekly">Wochenziel</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Metrik</label>
                        <select x-model="newGoalForm.metric_type"
                                class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:border-brand">
                            <option value="distance">Distanz (km)</option>
                            <option value="time">Trainingszeit (Std.)</option>
                            <option value="elevation">Höhenmeter (m)</option>
                            <option value="calories">Kalorien (kcal)</option>
                            <option value="workouts">Workouts (Anzahl)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Zielwert</label>
                        <input type="number" step="1" x-model.number="newGoalForm.target_value"
                               class="w-full px-4 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold text-slate-900 focus:outline-none focus:border-brand">
                    </div>
                </div>

                <!-- Seasonality Curve (nur bei Jahreszielen) -->
                <div x-show="newGoalForm.period_type === 'yearly'">
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Saisonale Pacing-Kurve</label>
                    <select x-model="newGoalForm.seasonality"
                            class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-xs font-semibold focus:outline-none focus:border-brand">
                        <option value="linear">Gleichmäßig (Linear - Tag 1 bis 365)</option>
                        <option value="summer_peak">Sommer-Peak (Rennrad, MTB, Outdoor)</option>
                        <option value="winter_peak">Winter-Peak (Rudern, Indoor, Ergometer)</option>
                    </select>
                    <p class="text-[11px] text-slate-400 mt-1">Passt das Soll-Ziel tagesaktuell an die Saison an (z. B. mehr km im Juli als im Januar).</p>
                </div>
            </div>

            <div class="pt-3 flex justify-end space-x-2">
                <button @click="newGoalModalOpen = false" class="px-4 py-2 text-xs font-semibold text-slate-500 hover:bg-slate-100 rounded-xl">Abbrechen</button>
                <button @click="saveNewGoal()" class="px-5 py-2 bg-brand hover:bg-brand-hover text-white text-xs font-semibold rounded-xl shadow-xs transition">Ziel speichern</button>
            </div>
        </div>
    </div>

    <!-- ================================================================= -->
    <!-- SETTINGS MODAL                                                    -->
    <!-- ================================================================= -->
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
                    <p class="text-[11px] text-slate-400 mt-1">Basis für Normalized Power, Intensity Factor (IF) und Coggan-Leistungszonen.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Körpergewicht (kg)</label>
                    <input type="number" step="0.5" x-model.number="settingsForm.weight" min="30" max="250"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    <p class="text-[11px] text-slate-400 mt-1">Zur Berechnung von Watt pro Kilogramm (W/kg).</p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Max. Herzfrequenz (bpm)</label>
                        <input type="number" x-model.number="settingsForm.max_hr"
                               class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-slate-900 focus:outline-none focus:border-brand">
                    </div>
                    <div>
                        <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Ruhepuls (bpm)</label>
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
                    <p>Dies löscht deinen Account, alle importierten Strava-Aktivitäten, GPS-Tracks, Trainings-Ziele und Logs permanent aus unserer Datenbank.</p>
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
                currentTab: 'dashboard',
                syncing: false,
                selectedYear: new Date().getFullYear(),
                availableYears: [new Date().getFullYear()],
                stats: {},
                fitness: {},
                rowing: {},
                
                // Aktivitäten State
                activities: [],
                pagination: { current_page: 1, total_pages: 1, total_items: 0 },
                activityFilterSport: 'all',
                activityFilterYear: 'all',
                activityEnvironment: 'all',
                activitySortBy: 'date',
                activityQuery: '',
                expandedActivityId: null,

                // Ziele State
                goals: [],
                newGoalModalOpen: false,
                newGoalForm: {
                    title: '',
                    sport_type: 'all',
                    metric_type: 'distance',
                    target_value: 3000,
                    period_type: 'yearly',
                    year: new Date().getFullYear(),
                    seasonality: 'linear'
                },

                // Settings
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
                    this.loadGoals();
                    this.loadActivities(1);

                    window.addEventListener('resize', () => {
                        this.resizeCharts();
                        if (this.map) this.map.invalidateSize();
                    });
                },

                switchTab(tab) {
                    this.currentTab = tab;
                    if (tab === 'dashboard') {
                        this.$nextTick(() => {
                            this.resizeCharts();
                            if (this.map) this.map.invalidateSize();
                        });
                    }
                },

                onYearChange() {
                    this.loadOverviewStats();
                    this.loadGoals();
                    this.loadActivities(1);
                },

                showToast(message, type = 'success') {
                    this.toast.message = message;
                    this.toast.type = type;
                    this.toast.show = true;
                    setTimeout(() => { this.toast.show = false; }, 4000);
                },

                // 1. Übersicht & Statistiken
                async loadOverviewStats() {
                    try {
                        const res = await fetch(`/api/stats_overview.php?year=${this.selectedYear}`);
                        const data = await res.json();
                        if (data.success) {
                            this.stats = {
                                all_time: data.all_time,
                                yearly: data.yearly_stats,
                                sports: data.sports_breakdown,
                                polarized_80_20: data.polarized_80_20,
                                four_weeks_trend: data.four_weeks_trend,
                                rest_days_stats: data.rest_days_stats,
                                mom_trend: data.mom_trend
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
                        if (!chartEl) return;
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

                // 2. Fitness & Form (CTL / ATL / TSB / ACWR)
                async loadFitnessTrend() {
                    try {
                        const res = await fetch('/api/fitness_trend.php?days=180');
                        const data = await res.json();
                        if (!data.success) return;

                        this.fitness = data;

                        const chartEl = document.getElementById('chart-fitness');
                        if (!chartEl) return;
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
                                    lineStyle: { color: '#f43f5e', width: 1.5, type: 'dashed' }
                                },
                                {
                                    name: 'TSB (Form)',
                                    type: 'line',
                                    smooth: true,
                                    showSymbol: false,
                                    data: data.series.tsb,
                                    areaStyle: { opacity: 0.1, color: '#10b981' },
                                    lineStyle: { color: '#10b981', width: 2 }
                                }
                            ]
                        }, true);
                    } catch (e) {
                        console.error('Fitness trend error:', e);
                    }
                },

                getAcwrBadgeClass(status) {
                    switch (status) {
                        case 'sweetspot': return 'bg-emerald-100 text-emerald-800';
                        case 'caution':   return 'bg-amber-100 text-amber-800';
                        case 'danger':    return 'bg-rose-100 text-rose-800';
                        default:          return 'bg-sky-100 text-sky-800';
                    }
                },

                // 3. Ruder-Spezial
                async loadRowingAnalysis() {
                    try {
                        const res = await fetch('/api/rowing_analysis.php');
                        const data = await res.json();
                        if (data.success) {
                            this.rowing = data;
                        }
                    } catch (e) {
                        console.error('Rowing error:', e);
                    }
                },

                // 4. Ziele (Goals)
                async loadGoals() {
                    try {
                        const res = await fetch(`/api/goals.php?year=${this.selectedYear}`);
                        const data = await res.json();
                        if (data.success) {
                            this.goals = data.goals;
                        }
                    } catch (e) {
                        console.error('Goals error:', e);
                    }
                },

                openNewGoalModal() {
                    this.newGoalForm.year = this.selectedYear;
                    this.newGoalModalOpen = true;
                },

                async saveNewGoal() {
                    try {
                        const res = await fetch('/api/goals.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(this.newGoalForm)
                        });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('Ziel erfolgreich gespeichert!');
                            this.newGoalModalOpen = false;
                            this.loadGoals();
                        } else {
                            this.showToast(data.error || 'Fehler beim Speichern', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler', 'error');
                    }
                },

                async deleteGoal(id) {
                    if (!confirm('Möchtest du dieses Ziel wirklich löschen?')) return;
                    try {
                        const res = await fetch(`/api/goals.php?id=${id}`, { method: 'DELETE' });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast('Ziel gelöscht');
                            this.loadGoals();
                        }
                    } catch (e) {
                        this.showToast('Fehler beim Löschen', 'error');
                    }
                },

                // 5. Aktivitäten
                async loadActivities(page = 1) {
                    try {
                        const params = new URLSearchParams({
                            page: page,
                            per_page: 20,
                            sport_type: this.activityFilterSport,
                            environment: this.activityEnvironment,
                            sort_by: this.activitySortBy,
                            query: this.activityQuery,
                        });
                        if (this.activityFilterYear !== 'all') {
                            params.append('year', this.activityFilterYear);
                        }
                        const res = await fetch(`/api/activities.php?${params}`);
                        const data = await res.json();
                        if (data.success) {
                            this.activities = data.activities;
                            this.pagination = data.pagination;
                            this.renderMapPolylines();
                        }
                    } catch (e) {
                        console.error('Activities error:', e);
                    }
                },

                setSportFilter(sport) {
                    this.activityFilterSport = (this.activityFilterSport === sport && sport !== 'all') ? 'all' : sport;
                    this.loadActivities(1);
                },

                toggleActivityExpand(id) {
                    this.expandedActivityId = this.expandedActivityId === id ? null : id;
                },

                // 6. Leaflet Map
                initMap() {
                    const mapEl = document.getElementById('leaflet-map');
                    if (!mapEl) return;

                    this.map = L.map('leaflet-map').setView([47.5, 14.5], 7);

                    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        maxZoom: 19,
                        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
                    }).addTo(this.map);

                    setTimeout(() => {
                        this.map.invalidateSize();
                    }, 400);
                },

                renderMapPolylines() {
                    if (!this.map) return;

                    this.polylineLayers.forEach(l => this.map.removeLayer(l));
                    this.polylineLayers = [];

                    const bounds = [];

                    this.activities.forEach(act => {
                        if (act.summary_polyline) {
                            const coords = this.decodePolyline(act.summary_polyline);
                            if (coords.length > 0) {
                                const line = L.polyline(coords, {
                                    color: '#FC4C02',
                                    weight: 2.5,
                                    opacity: 0.65
                                }).addTo(this.map);

                                line.bindPopup(`<strong>${act.name}</strong><br>${act.date_formatted} • ${act.distance_km} km`);
                                this.polylineLayers.push(line);
                                coords.forEach(c => bounds.push(c));
                            }
                        }
                    });

                    if (bounds.length > 0) {
                        this.map.fitBounds(bounds, { padding: [25, 25] });
                    }
                },

                focusActivityOnMap(act) {
                    if (this.currentTab !== 'dashboard') {
                        this.switchTab('dashboard');
                    }
                    if (act.start_lat && act.start_lng) {
                        this.map.setView([act.start_lat, act.start_lng], 13);
                    }
                },

                decodePolyline(str, precision = 5) {
                    let index = 0, lat = 0, lng = 0, coordinates = [], shift = 0, result = 0, byte = null, latitude_change, longitude_change, factor = Math.pow(10, precision);
                    while (index < str.length) {
                        byte = null; shift = 0; result = 0;
                        do { byte = str.charCodeAt(index++) - 63; result |= (byte & 0x1f) << shift; shift += 5; } while (byte >= 0x20);
                        latitude_change = ((result & 1) ? ~(result >> 1) : (result >> 1));
                        shift = 0; result = 0;
                        do { byte = str.charCodeAt(index++) - 63; result |= (byte & 0x1f) << shift; shift += 5; } while (byte >= 0x20);
                        longitude_change = ((result & 1) ? ~(result >> 1) : (result >> 1));
                        lat += latitude_change; lng += longitude_change;
                        coordinates.push([lat / factor, lng / factor]);
                    }
                    return coordinates;
                },

                resizeCharts() {
                    const yEl = document.getElementById('chart-yearly');
                    if (yEl) { const c = echarts.getInstanceByDom(yEl); if (c) c.resize(); }
                    const fEl = document.getElementById('chart-fitness');
                    if (fEl) { const c = echarts.getInstanceByDom(fEl); if (c) c.resize(); }
                },

                // 7. Synchronisation & Settings
                async triggerSync() {
                    this.syncing = true;
                    try {
                        const res = await fetch('/api/sync.php', { method: 'POST' });
                        const data = await res.json();
                        if (data.success) {
                            this.showToast(data.message || 'Synchronisation abgeschlossen!');
                            this.loadOverviewStats();
                            this.loadFitnessTrend();
                            this.loadGoals();
                            this.loadActivities(1);
                        } else {
                            this.showToast(data.error || 'Fehler beim Abgleich', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler beim Sync', 'error');
                    } finally {
                        this.syncing = false;
                    }
                },

                async openSettingsModal() {
                    this.settingsModalOpen = true;
                    try {
                        const res = await fetch('/api/settings.php');
                        const data = await res.json();
                        if (data.success) {
                            this.settingsData = data;
                            this.settingsForm = {
                                ftp: data.athlete?.ftp ?? 250,
                                weight: data.athlete?.weight ?? 75,
                                max_hr: data.athlete?.max_hr ?? 190,
                                rest_hr: data.athlete?.rest_hr ?? 50,
                                custom_client_id: data.strava?.custom_client_id ?? '',
                                custom_client_secret: ''
                            };
                        }
                    } catch (e) {
                        console.error('Settings error:', e);
                    }
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
                            this.showToast('Einstellungen gespeichert!');
                            this.settingsModalOpen = false;
                            this.loadOverviewStats();
                            this.loadFitnessTrend();
                            this.loadActivities(this.pagination.current_page);
                        } else {
                            this.showToast(data.error || 'Fehler beim Speichern', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler', 'error');
                    }
                },

                async confirmDeleteAccount() {
                    if (!confirm('Bist du absolut sicher? Alle deine Daten werden unwiderruflich gelöscht!')) return;
                    this.deleteLoading = true;
                    try {
                        const res = await fetch('/api/account_delete.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ password: this.deleteConfirmPassword })
                        });
                        const data = await res.json();
                        if (data.success) {
                            alert('Dein Account wurde erfolgreich gelöscht.');
                            window.location.href = 'login.php';
                        } else {
                            this.showToast(data.error || 'Löschen fehlgeschlagen', 'error');
                        }
                    } catch (e) {
                        this.showToast('Netzwerkfehler beim Löschen', 'error');
                    } finally {
                        this.deleteLoading = false;
                    }
                }
            };
        }
    </script>
</body>
</html>
