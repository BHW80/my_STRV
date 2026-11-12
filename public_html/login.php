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
<html lang="de" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>my_STRV - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        brand: '#FC4C02', // Strava Orange Accent
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl">
        <div class="text-center mb-8">
            <h1 class="text-3xl font-extrabold tracking-wider text-white">
                my_<span class="text-brand">STRV</span>
            </h1>
            <p class="text-sm text-slate-400 mt-2">Persönliche Strava Analyseplattform</p>
        </div>

        <?php if ($error): ?>
            <div class="bg-red-500/10 border border-red-500/50 text-red-400 text-sm rounded-lg p-3 mb-6">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php" class="space-y-5">
            <div>
                <label for="username" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Benutzername</label>
                <input type="text" id="username" name="username" required autofocus
                    class="w-full px-4 py-3 bg-slate-950 border border-slate-800 rounded-lg text-white focus:outline-none focus:border-brand transition">
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Passwort</label>
                <input type="password" id="password" name="password" required
                    class="w-full px-4 py-3 bg-slate-950 border border-slate-800 rounded-lg text-white focus:outline-none focus:border-brand transition">
            </div>

            <button type="submit"
                class="w-full py-3 px-4 bg-brand hover:bg-orange-600 text-white font-semibold rounded-lg shadow-lg hover:shadow-orange-500/20 transition duration-200">
                Anmelden
            </button>
        </form>
    </div>
</body>
</html>
