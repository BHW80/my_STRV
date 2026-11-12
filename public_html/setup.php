<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

$pdo = getDbConnection();

// Sicherheits-Sperre: Prüfen, ob bereits Benutzer existieren
try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM my_strv_users")->fetchColumn();
    if ($count > 0) {
        die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;"><h2>Setup bereits abgeschlossen</h2><p>Es existiert bereits ein Benutzer. Dieses Skript wurde aus Sicherheitsgründen gesperrt.</p><p><a href="login.php">Hier geht es zum Login</a></p></body></html>');
    }
} catch (Throwable $e) {
    die('<!DOCTYPE html><html><body style="font-family:sans-serif;padding:2rem;"><h2 style="color:red;">Datenbank-Fehler</h2><p>' . htmlspecialchars($e->getMessage()) . '</p><p>Hast du schema.sql schon importiert und stimmen die Zugangsdaten in config/config.php?</p></body></html>');
}

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Bitte Benutzername und Passwort eingeben.';
    } elseif (strlen($password) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Die Passwörter stimmen nicht überein.';
    } else {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO my_strv_users (username, password_hash) VALUES (:u, :p)');
            $stmt->execute(['u' => $username, 'p' => $hash]);
            $userId = (int)$pdo->lastInsertId();

            $settingsStmt = $pdo->prepare('INSERT INTO my_strv_athlete_settings (user_id, ftp, weight, max_hr, rest_hr) VALUES (:id, 250, 75.0, 190, 50)');
            $settingsStmt->execute(['id' => $userId]);

            $pdo->commit();
            $success = true;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Fehler beim Anlegen: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>my_STRV - Ersteinrichtung</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md bg-white border border-slate-200 rounded-2xl p-8 shadow-xl">
        <h1 class="text-2xl font-black text-slate-900 mb-1">my_STRV Ersteinrichtung</h1>
        <p class="text-sm text-slate-500 mb-6">Erstelle deinen persönlichen Administrator-Account.</p>

        <?php if ($success): ?>
            <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl p-4 mb-6">
                <strong>Erfolg!</strong> Dein Benutzer wurde angelegt.
            </div>
            <a href="login.php" class="block w-full py-3 bg-[#FC4C02] text-white font-bold text-center rounded-xl">
                Jetzt anmelden
            </a>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm rounded-xl p-3.5 mb-6">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Benutzername</label>
                    <input type="text" name="username" required autofocus value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Passwort (min. 8 Zeichen)</label>
                    <input type="password" name="password" required
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase text-slate-500 mb-1">Passwort wiederholen</label>
                    <input type="password" name="password_confirm" required
                           class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl">
                </div>
                <button type="submit" class="w-full py-3 bg-[#FC4C02] hover:bg-orange-600 text-white font-bold rounded-xl shadow-md transition">
                    Benutzer anlegen
                </button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>