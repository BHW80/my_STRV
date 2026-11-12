<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Auth.php';

// Falls bereits eingeloggt, direkt zum Dashboard
if (Auth::id() !== null) {
    header('Location: index.php');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } elseif (Auth::login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Ungültige Anmeldedaten. Bitte erneut versuchen.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>my_STRV - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            DEFAULT: '#FC4C02', // Strava Signature Orange
                            hover: '#E04302',
                            light: '#FFF5F0',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4 antialiased selection:bg-brand/10 selection:text-brand">
    <div class="w-full max-w-md bg-white border border-slate-200/80 rounded-2xl p-8 sm:p-10 shadow-xl shadow-slate-200/50">
        <!-- Logo & Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-brand/10 text-brand mb-4">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M15.387 17.944l-2.089-4.116h-3.065L15.387 24l5.15-10.172h-3.066m-7.008-5.599l2.836 5.598h4.172L10.463 0l-7.99 15.772h4.173"/>
                </svg>
            </div>
            <h1 class="text-3xl font-black tracking-tight text-slate-900">
                my_<span class="text-brand">STRV</span>
            </h1>
            <p class="text-sm font-medium text-slate-500 mt-1">Persönliche Strava-Analyseplattform</p>
        </div>

        <!-- Fehlermeldung -->
        <?php if ($error): ?>
            <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium rounded-xl p-3.5 mb-6 flex items-center space-x-2.5">
                <svg class="w-5 h-5 flex-shrink-0 text-rose-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Anmeldeformular -->
        <form method="POST" action="login.php" class="space-y-5">
            <div>
                <label for="username" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Benutzername</label>
                <input type="text" id="username" name="username" required autofocus
                    placeholder="Dein Benutzername"
                    class="w-full px-4 py-3 bg-slate-50/50 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand focus:bg-white transition duration-150">
            </div>

            <div>
                <label for="password" class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-2">Passwort</label>
                <input type="password" id="password" name="password" required
                    placeholder="••••••••"
                    class="w-full px-4 py-3 bg-slate-50/50 border border-slate-200 rounded-xl text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-brand/20 focus:border-brand focus:bg-white transition duration-150">
            </div>

            <button type="submit"
                class="w-full py-3.5 px-4 bg-brand hover:bg-brand-hover text-white font-semibold rounded-xl shadow-md shadow-brand/20 hover:shadow-lg hover:shadow-brand/30 active:scale-[0.99] transition duration-150 flex items-center justify-center space-x-2">
                <span>Anmelden</span>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-100 text-center">
            <span class="inline-flex items-center text-xs font-medium text-slate-400 space-x-1.5">
                <svg class="w-3.5 h-3.5 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span>End-to-End mandantensicher &amp; isoliert</span>
            </span>
        </div>
    </div>
</body>
</html>
