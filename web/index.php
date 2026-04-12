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

    if (!isset($_POST['reset_password']) || $_POST['reset_password'] !== RESET_PASSWORD) {
    $errorMessage = "Falsches Passwort!";
    } else {

    $backupDir = 'backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $timestamp = date('Ymd_His');

    // 1. Datenbank sichern
    $backupFile = $backupDir . '/feuerloescher_backup_' . $timestamp . '.db';
    if (file_exists(DB_FILE)) {
        copy(DB_FILE, $backupFile);
    }

    // 2. _Rechnungen Ordner sichern
    $rechnungenDir = '_Rechnungen';
    if (is_dir($rechnungenDir)) {
        $zipFile = $backupDir . '/feuerloescher_backup_' . $timestamp . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::CREATE) === true) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rechnungenDir));
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($rechnungenDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
        }
        // Ordner leeren
        function rrmdir($dir) {
            if (!is_dir($dir)) return;
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getRealPath());
                } else {
                    unlink($file->getRealPath());
                }
            }
        }

        rrmdir($rechnungenDir);
    }

    // 3. Datenbank initialisieren
    require 'init_db.php';

    $successMessage = "Datenbank wurde zurückgesetzt! Backup DB: $backupFile, Backup Rechnungen: $zipFile";
    }
}

// Einstellungen speichern
if (isset($_POST['save_settings'])) {

    if ($_POST['settings_password'] !== RESET_PASSWORD) {
        $errorMessage = "Falsches Passwort!";
    } else {

        $configFile = 'config.php';
        $configContent = file_get_contents($configFile);

        function replaceDefine($content, $key, $value) {
            $value = addslashes($value);
            return preg_replace(
                "/define\('$key',\s*.*?\);/",
                "define('$key', '$value');",
                $content
            );
        }

        function replaceDefineNumber($content, $key, $value) {
            return preg_replace(
                "/define\('$key',\s*.*?\);/",
                "define('$key', " . floatval($value) . ");",
                $content
            );
        }

        // Preise
        $configContent = replaceDefineNumber($configContent, 'PREIS_STANDARD', $_POST['preis_standard']);
        $configContent = replaceDefineNumber($configContent, 'PREIS_RABATT', $_POST['preis_rabatt']);
        $configContent = replaceDefineNumber($configContent, 'PREIS_GRATIS', $_POST['preis_gratis']);

        // Firma
        $configContent = replaceDefine($configContent, 'FIRMA_NAME', $_POST['firma_name']);
        $configContent = replaceDefine($configContent, 'FIRMA_ADRESSE', $_POST['firma_adresse']);
        $configContent = replaceDefine($configContent, 'FIRMA_PLZORT', $_POST['firma_plzort']);
        $configContent = replaceDefine($configContent, 'FIRMA_WEB', $_POST['firma_web']);

        file_put_contents($configFile, $configContent);

        $successMessage = "Einstellungen gespeichert!";
    }
}
?>


<!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>&#128293; Feuerlöscher Software</title>
    <link rel="icon" href="./images/Feuerlöscher.ico" type="image/x-icon">
    <link rel="shortcut icon" href="./images/Feuerlöscher.ico">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software
        </span>

        <div class="d-flex gap-2">
            <a href="?logout=1" class="btn btn-danger btn-sm">
                Abmelden
            </a>
        </div>
    </div>
</nav>

<div class="container mt-5 flex-grow-1">
    <h1>&#128293; Feuerlöscher Software</h1>
    <br>

    <?php if(isset($errorMessage)): ?>
        <div class="alert alert-danger">
            <?php echo $errorMessage; ?>
        </div>
    <?php endif; ?>

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
                    <h5 class="card-title">&#10010; Löscher verwalten</h5>
                    <p class="card-text">Löscher anlegen oder bearbeiten</p>
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
                    <h5 class="card-title">&#128196; Liste</h5>
                    <p class="card-text">Alle Feuerlöscher anzeigen</p>
                    <a href="liste.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128179; Rechnung</h5>
                    <p class="card-text">Rechnungen erstellen oder earbeiten</p>
                    <a href="rechnung.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128200; Statistik</h5>
                    <p class="card-text">Statistiken</p>
                    <a href="statistik.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128228; Verrechnung</h5>
                    <p class="card-text">Auswertung zur Verrechnung</p>
                    <a href="verrechnung.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>

    </div>

    <div class="mt-5">
    <div class="card shadow-sm">

        <div class="card-body text-center">
            <button class="btn btn-secondary w-100" data-bs-toggle="collapse" data-bs-target="#settingsAll">
                ⚙️ Einstellungen
            </button>
        </div>

        <div id="settingsAll" class="collapse">
            <div class="card-body">

                <div class="row g-4">

                    <!-- SCHILDER -->
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body text-center">
                                <h5>📁 Schilder</h5>

                                <div class="d-flex justify-content-center gap-2 flex-wrap">
                                    <a href="./data/Schilder.pdf" target="_blank" class="btn btn-outline-danger">
                                        PDF
                                    </a>

                                    <a href="./data/Schilder.pptx" target="_blank" class="btn btn-outline-primary">
                                        PowerPoint
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- RESET -->
                    <div class="col-md-4">
                        <div class="card border-danger shadow-sm h-100">
                            <div class="card-body text-center">
                                <h5 class="text-danger">⚠ Datenbank zurücksetzen</h5>

                                <p class="card-text small">
                                    Alle Daten werden gelöscht.<br>
                                    Ein Backup wird automatisch erstellt.
                                </p>

                                <form id="resetForm" method="post">
                                    <input type="hidden" name="reset_db" value="1">
                                    <input type="hidden" name="reset_password" id="reset_password">

                                    <button type="button" class="btn btn-danger w-100" onclick="confirmReset()">
                                        Datenbank zurücksetzen
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- EINSTELLUNGEN -->
                    <div class="col-md-4">
                        <div class="card shadow-sm h-100">
                            <div class="card-body">

                                <form id="settingsForm" method="post">
                                    <input type="hidden" name="save_settings" value="1">
                                    <input type="hidden" name="settings_password" id="settings_password">

                                    <h5 class="text-center">⚙️ Preise</h5>

                                    <label>Standard</label>
                                    <input type="number" step="0.01" name="preis_standard"
                                           value="<?php echo PREIS_STANDARD; ?>"
                                           class="form-control mb-2" required>

                                    <label>Rabatt</label>
                                    <input type="number" step="0.01" name="preis_rabatt"
                                           value="<?php echo PREIS_RABATT; ?>"
                                           class="form-control mb-2" required>

                                    <label>Gratis</label>
                                    <input type="number" step="0.01" name="preis_gratis"
                                           value="<?php echo PREIS_GRATIS; ?>"
                                           class="form-control mb-3" required>

                                    <!--<h5 class="text-center">🏢 Feuerwehr Daten</h5>

                                    <input type="text" name="firma_name"
                                           value="<?php echo FIRMA_NAME; ?>"
                                           class="form-control mb-2" placeholder="Name" required>

                                    <input type="text" name="firma_adresse"
                                           value="<?php echo FIRMA_ADRESSE; ?>"
                                           class="form-control mb-2" placeholder="Adresse" required>

                                    <input type="text" name="firma_plzort"
                                           value="<?php echo FIRMA_PLZORT; ?>"
                                           class="form-control mb-2" placeholder="PLZ Ort" required>

                                    <input type="text" name="firma_web"
                                           value="<?php echo FIRMA_WEB; ?>"
                                           class="form-control mb-3" placeholder="Website" required>-->

                                    <button type="button" class="btn btn-success w-100" onclick="confirmSettingsSave()">
                                        💾 Speichern
                                    </button>
                                </form>

                            </div>
                        </div>
                    </div>

                </div>

            </div>
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

// Logik zum Ausblenden der Meldung
document.addEventListener('DOMContentLoaded', function() {
    const alert = document.querySelector('.alert-danger'); // Wir wählen die rote Alert-Box
    
    if (alert) {
        // Starte Timer für 3 Sekunden (3000ms)
        setTimeout(() => {
            // Weicher Übergang
            alert.style.transition = "opacity 0.8s ease, transform 0.8s ease, height 0.8s ease, margin 0.8s ease, padding 0.8s ease";
            alert.style.opacity = "0";
            alert.style.transform = "translateY(-10px)";
            
            // Nach dem Fade-out das Element komplett entfernen
            setTimeout(() => {
                alert.style.height = "0";
                alert.style.margin = "0";
                alert.style.padding = "0";
                alert.style.overflow = "hidden";
                setTimeout(() => alert.remove(), 800);
            }, 400);
        }, 3000);
    }
});

function confirmSettingsSave() {
    if (!confirm("Einstellungen wirklich speichern?")) return;

    const pwd = prompt("Admin-Passwort eingeben:");
    if (!pwd) {
        alert("Passwort erforderlich!");
        return;
    }

    document.getElementById("settings_password").value = pwd;
    document.getElementById("settingsForm").submit();
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<div class="small d-hidden p-4"></div>
<footer class="bg-light text-center text-muted py-2 small border-top fixed-bottom">
    &copy; Freiwillige Feuerwehr Wallern - Stefan Schneebauer <?php echo date('Y'); ?>
</footer>
</body>
</html>