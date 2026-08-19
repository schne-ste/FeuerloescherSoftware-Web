<?php
session_start();

// Pfad-Sicherheit und Verzeichnis anlegen
$dbDir = 'databases';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0755, true);
}

// 1. Hilfsfunktion zum Ändern der config.php (Robust & mit OpCache-Leeren)
function updateConfigDefine($key, $value, $isNumber = false) {
    $configFile = 'config.php';
    if (!file_exists($configFile)) return false;

    $content = file_get_contents($configFile);
    if ($isNumber) {
        $replacement = "define('$key', " . floatval($value) . ");";
    } else {
        $escapedValue = addslashes($value);
        $replacement = "define('$key', '$escapedValue');";
    }

    $pattern = "/define\(\s*['\"]" . preg_quote($key, '/') . "['\"]\s*,\s*(?:'[^']*'|\"[^\"]*\"|[\d.]+)\s*\)\s*;/i";

    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $replacement, $content);
    } else {
        $content = str_replace('?>', $replacement . "\n?>", $content);
    }

    $result = file_put_contents($configFile, $content) !== false;

    // OpCache leeren, damit PHP die config.php sofort neu einliest
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate(realpath($configFile), true);
    }

    return $result;
}

// --- DATENBANKEN ERSTELLEN / WECHSELN / LÖSCHEN ---

if (isset($_POST['create_db'])) {
    require_once 'config.php';
    if (!isset($_POST['db_password']) || $_POST['db_password'] !== RESET_PASSWORD) {
        $errorMessage = "Falsches Passwort!";
    } else {
        $newName = trim($_POST['new_db_name']);
        
        if (!preg_match('/^\d{4}$/', $newName)) {
            $errorMessage = sprintf("Ungültiger Datenbankname! Der Name darf nur aus einer 4-stelligen Jahreszahl bestehen (z.B. %s).", date('Y'));
        } else {
            $newDbPath = $dbDir . '/' . $newName . '.db';
            if (file_exists($newDbPath)) {
                $errorMessage = "Datenbank existiert bereits!";
            } else {
                updateConfigDefine('DB_FILE', $newDbPath);
                
                $touchDb = new SQLite3($newDbPath);
                $touchDb->close();

                header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?success=" . urlencode("Datenbank '$newName.db' erfolgreich angelegt und gewechselt!"));
                exit;
            }
        }
    }
}

if (isset($_POST['select_db'])) {
    $selectedDb = $_POST['selected_db'];
    if (file_exists($selectedDb) && strpos(realpath($selectedDb), realpath($dbDir)) === 0) {
        updateConfigDefine('DB_FILE', $selectedDb);

        // --- EVENT_NAME beim Datenbankwechsel aktualisieren ---
        $dbNameOnly = basename($selectedDb, '.db');
        $fullEventName = "Feuerlöscherüberprüfung " . $dbNameOnly;
        updateConfigDefine('EVENT_NAME', $fullEventName, false);

        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?success=" . urlencode("Erfolgreich zur Datenbank " . basename($selectedDb) . " gewechselt!"));
        exit;
    } else {
        $errorMessage = "Ungültige Datenbank-Auswahl!";
    }
}

if (isset($_POST['delete_db'])) {
    require_once 'config.php';
    if (!isset($_POST['db_password']) || $_POST['db_password'] !== RESET_PASSWORD) {
        $errorMessage = "Falsches Passwort!";
    } else {
        $toDelete = $_POST['db_to_delete'];
        if (file_exists($toDelete) && strpos(realpath($toDelete), realpath($dbDir)) === 0) {
            if ($toDelete === DB_FILE) {
                $errorMessage = "Die aktuell aktive Datenbank kann nicht gelöscht werden!";
            } else {
                $dbNameOnly = pathinfo($toDelete, PATHINFO_FILENAME);
                $pdfFolderToDelete = __DIR__ . '/_Rechnungen/' . $dbNameOnly;

                if (!function_exists('rrmdir')) {
                    function rrmdir($dir) {
                        if (!is_dir($dir)) return;
                        $files = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::CHILD_FIRST
                        );
                        foreach ($files as $file) {
                            if ($file->isDir()) { rmdir($file->getRealPath()); } 
                            else { unlink($file->getRealPath()); }
                        }
                        rmdir($dir);
                    }
                }

                if (is_dir($pdfFolderToDelete)) {
                    rrmdir($pdfFolderToDelete);
                }

                unlink($toDelete);
                
                header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?success=" . urlencode("Datenbank " . basename($toDelete) . " und alle zugehörigen PDF-Rechnungen wurden gelöscht!"));
                exit;
            }
        } else {
            $errorMessage = "Datenbank nicht gefunden oder ungültig!";
        }
    }
}

// Config einlesen
require_once 'config.php';

// Nur eingeloggte Benutzer
if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Erfolgsmeldungen aus Redirects abfangen
if (isset($_GET['success'])) {
    $successMessage = htmlspecialchars($_GET['success']);
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Reset der aktuellen DB durchführen
if (isset($_POST['reset_db'])) {
    if (!isset($_POST['reset_password']) || $_POST['reset_password'] !== RESET_PASSWORD) {
        $errorMessage = "Falsches Passwort!";
    } else {
        $backupDir = 'backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp = date('Ymd_His');

        $currentDbName = basename(DB_FILE, '.db');
        $backupFile = $backupDir . '/backup_' . $currentDbName . '_' . $timestamp . '.db';
        if (file_exists(DB_FILE)) {
            copy(DB_FILE, $backupFile);
        }

        $dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);
        $rechnungenDir = '_Rechnungen/' . $dbNameOnly;
        
        if (is_dir($rechnungenDir)) {
            $zipFile = $backupDir . '/rechnungen_' . $dbNameOnly . '_backup_' . $timestamp . '.zip';
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
            
            if (!function_exists('rrmdir')) {
                function rrmdir($dir) {
                    if (!is_dir($dir)) return;
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::CHILD_FIRST
                    );
                    foreach ($files as $file) {
                        if ($file->isDir()) { rmdir($file->getRealPath()); } 
                        else { unlink($file->getRealPath()); }
                    }
                    rmdir($dir);
                }
            }
            rrmdir($rechnungenDir);
        }

        if (file_exists(DB_FILE)) {
            unlink(DB_FILE);
        }
        $resetDb = getDB(); 
        $resetDb->close();

        $successMessage = "Datenbank wurde zurückgesetzt! Backup DB: $backupFile";
        if (isset($zipFile)) {
            $successMessage .= ", Backup Rechnungen: $zipFile";
        }
    }
}

// Einstellungen speichern (Preise & SumUp)
if (isset($_POST['save_settings'])) {
    if ($_POST['settings_password'] !== RESET_PASSWORD) {
        $errorMessage = "Falsches Passwort!";
    } else {
        // 1. Preise in der aktiven Datenbank speichern
        $db = getDB();
        $pStd = floatval($_POST['preis_standard']);
        $pRab = floatval($_POST['preis_rabatt']);

        $stmt = $db->prepare("INSERT INTO einstellungen (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = :v");
        
        $stmt->bindValue(':k', 'preis_standard', SQLITE3_TEXT);
        $stmt->bindValue(':v', (string)$pStd, SQLITE3_TEXT);
        $stmt->execute();

        $stmt->bindValue(':k', 'preis_rabatt', SQLITE3_TEXT);
        $stmt->bindValue(':v', (string)$pRab, SQLITE3_TEXT);
        $stmt->execute();

        // 2. SumUp Einstellungen in config.php aktualisieren
        $sumUpAvailable = isset($_POST['sumup_available']) ? 'TRUE' : 'FALSE';

        // --- NEU HINZUFÜGEN: EVENT_NAME ---
        $dbNameOnly = basename(DB_FILE, '.db');
        // Baue den String zusammen
        $fullEventName = "Feuerlöscherüberprüfung " . $dbNameOnly;
        // Speichere es als define in der config.php
        updateConfigDefine('EVENT_NAME', $fullEventName, false);
        
        // Transaktionsgebühr in % umrechnen in Faktor (z.B. 2.0% -> 1.020)
        $gebuehrProzent = floatval($_POST['sumup_fee_percent']);
        $sumUpFaktor = 1.0 + ($gebuehrProzent / 100.0);

        updateConfigDefine('SumUp_AVALIABLE', $sumUpAvailable, false);
        updateConfigDefine('SumUp_PRICE_FAKTOR', $sumUpFaktor, true);

        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?success=" . urlencode("Einstellungen gespeichert!"));
        exit;
    }
}

$dbFiles = glob($dbDir . '/*.db');
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

<body class="bg-light pb-5">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software
        </span>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-success me-2">Aktive DB: <?php echo basename(DB_FILE); ?></span>
            <a href="?logout=1" class="btn btn-danger btn-sm">Abmelden</a>
        </div>
    </div>
</nav>

<div class="container mt-5">
    <h1>&#128293; Feuerlöscher Software</h1>
    <br>

    <?php if(isset($errorMessage)): ?>
        <div class="alert alert-danger">
            <?php echo $errorMessage; ?>
        </div>
    <?php endif; ?>

    <?php if(isset($successMessage)): ?>
        <div class="alert alert-success">
            <?php echo $successMessage; ?>
        </div>
    <?php endif; ?>

    <!-- FUNKTIONEN GRID -->
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
                    <h5 class="card-title">&#128179; Rechnungen</h5>
                    <p class="card-text">Rechnungen erstellen oder bearbeiten</p>
                    <a href="rechnung.php" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128179; Rechnungen anzeigen</h5>
                    <p class="card-text">Alle Rechnungen anzeigen und exportieren</p>
                    <a href="rechnungen_anzeigen.php" class="btn btn-primary w-100">Öffnen</a>
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
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <h5 class="card-title">&#128421; Liveticker</h5>
                    <p class="card-text">Live Übersicht für TV Monitor</p>
                    <a href="viewer.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary w-100">Öffnen</a>
                </div>
            </div>
        </div>
    </div>

    <!-- EINSTELLUNGEN CONTAINER -->
    <div class="mt-5 mb-5">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <button class="btn btn-secondary w-100" data-bs-toggle="collapse" data-bs-target="#settingsAll">
                    &#9881; Einstellungen &amp; Datenbanken
                </button>
            </div>

            <div id="settingsAll" class="collapse">
                <p class="small text-muted mb-0" style="text-align: center;">Veranstalterdaten (Name, Adresse, Bankdaten) und Präfix für Rechnungsnummern in config.php eintragen</p>
                <div class="card-body">
                    <div class="row g-4">

                        <!-- SCHILDER -->
                        <div class="col-md-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-body text-center">
                                    <h5>&#128293;&#128220; Schilder</h5>
                                    <div class="d-flex flex-column gap-3">
                                        <a href="schilder.php" target="_blank" class="btn btn-outline-danger w-100">
                                            Standard-Set generieren
                                        </a>
                                        <hr class="my-1">
                                        <form action="schilder.php" method="GET" target="_blank">
                                            <p class="small mb-1 text-muted">ID Bereich (z.B. 1-30)</p>
                                            <div class="input-group">
                                                <input type="text" name="id" class="form-control form-control-sm" placeholder="Bereich..." required>
                                                <button type="submit" class="btn btn-sm btn-outline-success">Erstellen</button>
                                            </div>
                                        </form>
                                        <hr class="my-1">
                                        <a href="oenormf1053.php" target="_blank" class="btn btn-outline-danger w-100">
                                            ÖNORM F 1053 Flyer generieren
                                        </a>
                                        <a href="ablauf_flyer.php" target="_blank" class="btn btn-outline-danger w-100">
                                            Kunden-Flyer generieren
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- DATENBANK VERWALTUNG & RESET -->
                        <div class="col-md-4">
                            <div class="card border-warning shadow-sm h-100">
                                <div class="card-body">
                                    <h5 class="text-warning text-center">📂 Datenbanken verwalten</h5>
                                    
                                    <!-- DB Auswählen -->
                                    <form method="post" class="mb-3">
                                        <label class="small text-muted">Aktive DB wechseln:</label>
                                        <div class="input-group">
                                            <select name="selected_db" class="form-select form-select-sm" required>
                                                <?php foreach ($dbFiles as $file): ?>
                                                    <option value="<?php echo $file; ?>" <?php echo ($file === DB_FILE) ? 'selected' : ''; ?>>
                                                        <?php echo basename($file); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" name="select_db" class="btn btn-sm btn-warning">Wählen</button>
                                        </div>
                                    </form>

                                    <hr>

                                    <!-- Neue DB anlegen -->
                                    <form id="createDbForm" method="post" class="mb-3">
                                        <input type="hidden" name="create_db" value="1">
                                        <input type="hidden" name="db_password" id="create_db_password">
                                        <label class="small text-muted">Neue DB erstellen (Nur 4-stellige Jahreszahl):</label>
                                        <div class="input-group">
                                            <input type="text" name="new_db_name" class="form-control form-control-sm" 
                                                   placeholder="z.B. <?= date('Y') ?>" required
                                                   pattern="[0-9]{4}" maxlength="4" minlength="4">
                                            <button type="button" class="btn btn-sm btn-success" onclick="confirmDbCreate()">Erstellen</button>
                                        </div>
                                    </form>

                                    <hr>

                                    <!-- DB Löschen -->
                                    <form id="deleteDbForm" method="post" class="mb-3">
                                        <input type="hidden" name="delete_db" value="1">
                                        <input type="hidden" name="db_password" id="delete_db_password">
                                        <label class="small text-danger">Datenbank löschen:</label>
                                        <div class="input-group">
                                            <select name="db_to_delete" id="db_to_delete" class="form-select form-select-sm" required>
                                                <option value="">-- DB wählen --</option>
                                                <?php foreach ($dbFiles as $file): ?>
                                                    <?php if ($file !== DB_FILE): ?>
                                                        <option value="<?php echo $file; ?>"><?php echo basename($file); ?></option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-danger" onclick="confirmDbDelete()">Löschen</button>
                                        </div>
                                    </form>

                                    <hr>

                                    <!-- RESET DER AKTUELLEN DB -->
                                    <div class="text-center mt-2">
                                        <span class="small text-danger d-block mb-1">Aktive DB zurücksetzen:</span>
                                        <form id="resetForm" method="post">
                                            <input type="hidden" name="reset_db" value="1">
                                            <input type="hidden" name="reset_password" id="reset_password">
                                            <button type="button" class="btn btn-danger btn-sm w-100" onclick="confirmReset()">
                                                ⚠ Zurücksetzen (<?php echo basename(DB_FILE); ?>)
                                            </button>
                                        </form>
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- EINSTELLUNGEN (Preise & SumUp) -->
                        <div class="col-md-4">
                            <div class="card shadow-sm h-100">
                                <div class="card-body">
                                    <form id="settingsForm" method="post">
                                        <input type="hidden" name="save_settings" value="1">
                                        <input type="hidden" name="settings_password" id="settings_password">

                                        <h5 class="text-center">&#9881; Preise (DB: <?php echo basename(DB_FILE); ?>)</h5>

                                        <label class="small fw-bold">Standard (€)</label>
                                        <input type="number" step="0.01" name="preis_standard"
                                               value="<?php echo PREIS_STANDARD; ?>"
                                               class="form-control form-control-sm mb-2" required>

                                        <label class="small fw-bold">Rabatt (€)</label>
                                        <input type="number" step="0.01" name="preis_rabatt"
                                               value="<?php echo PREIS_RABATT; ?>"
                                               class="form-control form-control-sm mb-2" required>

                                        <hr class="my-3">

                                        <h5 class="text-center">&#128179; SumUp Kartenzahlung</h5>

                                        <?php 
                                            $isSumUpActive = defined('SumUp_AVALIABLE') && (strtoupper((string)SumUp_AVALIABLE) === 'TRUE' || SumUp_AVALIABLE === true);
                                            $currentFaktor = defined('SumUp_PRICE_FAKTOR') ? floatval(SumUp_PRICE_FAKTOR) : 1.0;
                                            $currentFeePercent = round(($currentFaktor - 1.0) * 100, 2);
                                        ?>

                                        <div class="form-check form-switch mb-2">
                                            <input class="form-check-input" type="checkbox" name="sumup_available" id="sumup_available" value="1" <?php echo $isSumUpActive ? 'checked' : ''; ?>>
                                            <label class="form-check-label small fw-bold" for="sumup_available">SumUp aktivieren</label>
                                            <p class="small text-muted mb-0">URL und Token in config.php eintragen</p>
                                        </div>

                                        <label class="small fw-bold">Transaktionsgebühr (%)</label>
                                        <div class="input-group input-group-sm mb-3">
                                            <input type="number" step="0.01" min="0" name="sumup_fee_percent"
                                                   value="<?php echo $currentFeePercent; ?>"
                                                   class="form-control" required>
                                            <span class="input-group-text">%</span>
                                        </div>

                                        <button type="button" class="btn btn-success btn-sm w-100" onclick="confirmSettingsSave()">
                                            &#128190; Speichern
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
function confirmDbCreate() {
    const dbNameInput = document.getElementsByName("new_db_name")[0];
    const dbName = dbNameInput.value.trim();
    
    const regex = /^\d{4}$/;
    if (!regex.test(dbName)) {
        alert("Fehler: Der Datenbankname muss exakt eine 4-stellige Jahreszahl sein (z.B. 2026)!");
        dbNameInput.focus();
        return;
    }
    
    const pwd = prompt("Admin-Passwort zum Erstellen der Datenbank eingeben:");
    if (!pwd) return;

    document.getElementById("create_db_password").value = pwd;
    document.getElementById("createDbForm").submit();
}

function confirmDbDelete() {
    const selectEl = document.getElementById("db_to_delete");
    if (!selectEl.value) {
        alert("Bitte wähle eine Datenbank aus, die gelöscht werden soll!");
        return;
    }
    const dbName = selectEl.options[selectEl.selectedIndex].text;
    if (!confirm("ACHTUNG!\nMöchtest du die Datenbank '" + dbName + "' wirklich unwiderruflich löschen?")) return;

    const pwd = prompt("Admin-Passwort zum Löschen eingeben:");
    if (!pwd) return;

    document.getElementById("delete_db_password").value = pwd;
    document.getElementById("deleteDbForm").submit();
}

function confirmReset() {
    if (!confirm("ACHTUNG!\n\nAlle Daten der AKTIVEN DATENBANK werden gelöscht!\nEin Backup wird erstellt.\n\nFortfahren?")) return;

    const pwd = prompt("Admin-Passwort eingeben:");
    if (!pwd) {
        alert("Passwort erforderlich!");
        return;
    }

    document.getElementById("reset_password").value = pwd;
    document.getElementById("resetForm").submit();
}

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

document.addEventListener('DOMContentLoaded', function() {
    const alert = document.querySelector('.alert');
    if (alert) {
        setTimeout(() => {
            alert.style.transition = "opacity 0.8s ease, transform 0.8s ease";
            alert.style.opacity = "0";
            alert.style.transform = "translateY(-10px)";
            setTimeout(() => {
                alert.remove();
                if (window.history && window.history.replaceState) {
                    const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname;
                    window.history.replaceState({path: cleanUrl}, '', cleanUrl);
                }
            }, 800);
        }, 3000);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<footer class="bg-light text-center text-muted py-2 small border-top fixed-bottom">
    &copy; Freiwillige Feuerwehr Wallern - Stefan Schneebauer <?php echo date('Y'); ?>
</footer>
</body>
</html>