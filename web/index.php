<?php
require 'config.php';
session_start();

// Nur eingeloggte Benutzer
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Reset durchführen
if (isset($_POST['reset_db'])) {

    if (!isset($_POST['reset_password']) || $_POST['reset_password'] !== PASSWORD) {
        die("Falsches Passwort!");
    }

    $backupDir = 'backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Ymd_His');
    $backupFile = $backupDir . '/feuerloescher_backup_' . $timestamp . '.db';

    if (file_exists(DB_FILE)) {
        copy(DB_FILE, $backupFile);
    }

    require 'init_db.php';

    $successMessage = "Datenbank wurde zurückgesetzt! Backup: $backupFile";
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>&#128293; Feuerlöscher Software</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            &#128293; Feuerlöscher Software
        </span>

        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-light btn-sm">
                Zurück
            </a>
            <a href="?logout=1" class="btn btn-danger btn-sm">
                Abmelden
            </a>
        </div>
    </div>
</nav>

<div class="container mt-5">

    <!-- Erfolgsmeldung -->
    <?php if(isset($successMessage)): ?>
        <div class="alert alert-danger">
            <?php echo $successMessage; ?>
        </div>
    <?php endif; ?>

    <!-- FUNKTIONEN -->
    <div class="row g-4">

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">Löscher verwalten</h5>
                    <p class="card-text">Neue Löscher anlegen oder bearbeiten</p>
                    <a href="add_edit.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128230; &#129514; Abhol-/Prüfstation</h5>
                    <p class="card-text">Status verwalten</p>
                    <a href="abhol_or_pruefung.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">Liste</h5>
                    <p class="card-text">Alle Feuerlöscher anzeigen</p>
                    <a href="liste.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">Rechnung</h5>
                    <p class="card-text">Rechnungen erstellen</p>
                    <a href="rechnung.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

    </div>

    <!-- DATENBANK -->
    <div class="mt-5">
        <div class="card border-danger shadow-sm">
            <div class="card-body">
                <h5 class="card-title text-danger">⚠ Datenbank zurücksetzen</h5>
                <p class="card-text">
                    Alle Daten werden gelöscht. Ein Backup wird automatisch erstellt.
                </p>

                <form id="resetForm" method="post">
                    <input type="hidden" name="reset_db" value="1">
                    <input type="hidden" name="reset_password" id="reset_password">

                    <button type="button" class="btn btn-danger" onclick="confirmReset()">
                        Datenbank zurücksetzen
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
function confirmReset() {
    if (!confirm("ACHTUNG!\n\nAlle Daten werden gelöscht!\nBackup wird erstellt.\n\nFortfahren?")) return;

    const pwd = prompt("Admin-Passwort eingeben:");
    if (!pwd) {
        alert("Passwort erforderlich!");
        return;
    }

    document.getElementById("reset_password").value = pwd;
    document.getElementById("resetForm").submit();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>