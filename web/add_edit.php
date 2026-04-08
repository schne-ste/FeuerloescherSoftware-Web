<?php
require 'config.php';

// Falls noch kein session_start() in der config.php ist, hier ergänzen:
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

// Nachricht aus Session abholen (falls vorhanden)
$successMessage = $_SESSION['success_msg'] ?? '';
$messageType = $_SESSION['msg_type'] ?? 'success';
unset($_SESSION['success_msg'], $_SESSION['msg_type']);

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

$db = getDB();

if (!isset($successMessage) || $successMessage === '') {
    $successMessage = '';
    $messageType = 'success';
}

$editEntry = null;
$searchResults = [];

// =====================
// PREISE
// =====================
$preise = [
    'Standard' => PREIS_STANDARD,
    'Rabatt' => PREIS_RABATT,
    'Gratis' => PREIS_GRATIS
];

$zeitstempel = date('Y-m-d H:i:s');

// =====================
// DATENSATZ LADEN (SUCHEN)
// =====================
if (isset($_POST['suche_nummer'])) {
    $input = trim($_POST['suchfeld'] ?? '');
    if ($input !== '') {
        if (is_numeric($input)) {
            $nummer = (int)$input;
            $result = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer");
        } else {
            $name = $db->escapeString($input);
            $result = $db->query("SELECT * FROM loescher WHERE name LIKE '%$name%'");
        }

        if ($result) {
            $rows = [];
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
            }
            if (count($rows) === 1) {
                $editEntry = $rows[0];
                $_POST['suchfeld'] = '';
            } elseif (count($rows) > 1) {
                $searchResults = $rows;
            } else {
                $successMessage = "&#10060; Kein Datensatz für '$input' gefunden!";
                $messageType = "danger"; // Rot bei Fehler
            }
        }
    }
}

// =====================
// AUSGEWÄHLTER DATENSATZ (wenn mehrere Treffer)
// =====================
if (isset($_POST['select_entry'])) {
    $selectedId = (int)$_POST['selected_entry'];
    $editEntry = $db->query("SELECT * FROM loescher WHERE id = $selectedId")->fetchArray(SQLITE3_ASSOC);
}

// Alle verfügbaren Löscher für die Autocomplete-Vorschlagsliste laden
$allEntriesResult = $db->query("SELECT nummer, name FROM loescher WHERE active = 1 ORDER BY nummer ASC");
$allEntries = [];
if ($allEntriesResult) {
    while ($row = $allEntriesResult->fetchArray(SQLITE3_ASSOC)) {
        $allEntries[] = $row;
    }
}

// =====================
// DATENSATZ BEARBEITEN
// =====================
if (isset($_POST['refresh_entry'])) {
    $nummer = (int)$_POST['nummer'];
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
    $successMessage = "&#128260; Datensatz neu geladen!";
    $messageType = "info";
} elseif (isset($_POST['edit_id'])) {
    $nummer = (int)$_POST['nummer'];
    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;

    $stmt = $db->prepare("
        UPDATE loescher SET
            name = :name,
            typ = :typ,
            preis = :preis,
            loeschertyp = :loeschertyp,
            menge = :menge,
            einheit = :einheit,
            bezahlt = :bezahlt,
            geprueft = :geprueft,
            abgeholt = :abgeholt,
            defekt = :defekt,
            info = :info
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");

    $stmt->bindValue(':name', $_POST['name']);
    $stmt->bindValue(':typ', $typ);
    $stmt->bindValue(':preis', $preis);
    $stmt->bindValue(':loeschertyp', $_POST['loeschertyp'] ?? '');
    $stmt->bindValue(':menge', $_POST['menge'] ?? '');
    $stmt->bindValue(':einheit', $_POST['einheit'] ?? '');
    $stmt->bindValue(':bezahlt', isset($_POST['bezahlt']) ? 1 : 0);
    $stmt->bindValue(':geprueft', isset($_POST['geprueft']) ? 1 : 0);
    $stmt->bindValue(':abgeholt', isset($_POST['abgeholt']) ? 1 : 0);
    $stmt->bindValue(':info', $_POST['info'] ?? '');
    $stmt->bindValue(':nummer', $nummer);
    $stmt->bindValue(':defekt', isset($_POST['defekt']) ? 1 : 0);

    $stmt->execute();
    $successMessage = "&#9989; Datensatz ".sprintf("%03d", $nummer)." erfolgreich aktualisiert!";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// NEUE LÖSCHER HINZUFÜGEN
// =====================
if (!$editEntry && isset($_POST['add_loscher'])) {
    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;
    $anzahl = max(1, (int)($_POST['anzahl'] ?? 1));

    for ($i = 0; $i < $anzahl; $i++) {
        $nummer = generateNummer($db);
        $stmt = $db->prepare("
            INSERT INTO loescher (
                nummer, name, typ, preis, loeschertyp,
                menge, einheit, etikett_gedruckt,
                abholschein_gedruckt, bezahlt, geprueft, abgeholt, defekt, active, info, zeitstempel
            ) VALUES (
                :nummer, :name, :typ, :preis, :loeschertyp,
                :menge, :einheit, 0, 0, :bezahlt, 0, 0, 0, 1, :info, :zeitstempel
            )
        ");
        
        $stmt->bindValue(':nummer', $nummer);
        $stmt->bindValue(':name', $_POST['name']);
        $stmt->bindValue(':typ', $typ);
        $stmt->bindValue(':preis', $preis);
        $stmt->bindValue(':loeschertyp', $_POST['loeschertyp'] ?? '');
        $stmt->bindValue(':menge', $_POST['menge'] ?? '');
        $stmt->bindValue(':einheit', $_POST['einheit'] ?? '');
        $stmt->bindValue(':bezahlt', isset($_POST['bezahlt']) ? 1 : 0);
        $stmt->bindValue(':info', $_POST['info'] ?? '');
        $stmt->bindValue(':zeitstempel', $zeitstempel);
        
        $stmt->execute();
    }

    // Erfolg in Session speichern
    $_SESSION['success_msg'] = "&#9989; $anzahl Löscher erfolgreich hinzugefügt!";
    $_SESSION['msg_type'] = "success";

    // Seite komplett neu laden (Dropdown wird dadurch frisch befüllt)
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// =====================
// ETIKETTE / ABHOLSCHIEN NACHDRUCK
// =====================
if (isset($_POST['redruck_etikett'])) {
    $nummer = (int)$_POST['nummer'];
    $db->exec("UPDATE loescher SET etikett_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    $successMessage = "&#9989; Etikette für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

if (isset($_POST['redruck_abholschein'])) {
    $nummer = (int)$_POST['nummer'];
    $db->exec("UPDATE loescher SET abholschein_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    $successMessage = "&#9989; Abholschein für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

// =====================
// GELD RETOUR BUTTON AKTION
// =====================
if (isset($_POST['geld_retour']) && isset($_POST['edit_id'])) {
    $nummer = (int)$_POST['nummer'];
    $zeitstempelNow = date('d.m.Y H:i:s');

    $stmt = $db->prepare("
        UPDATE loescher 
        SET bezahlt = 0, 
            info = COALESCE(info,'') || :text
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");
    $stmt->bindValue(':text', "\nGeld an Kunde retour gegeben - $zeitstempelNow");
    $stmt->bindValue(':nummer', $nummer);
    $stmt->execute();

    $successMessage = "&#9989; Geld retour gebucht!";
    $messageType = "warning";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

?>

<!DOCTYPE html>
<html>
<head>
<style>
    .highlight {
        box-shadow: 0 .125rem .25rem rgba(0,0,0,.35) !important;
    }
    .status-unbekannt {
	    filter: grayscale(1);
	    opacity: 0.6;
	}
</style>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>&#128293; Feuerlöscher Software</title>
<link rel="icon" href="./images/Feuerlöscher.ico" type="image/x-icon">
<link rel="shortcut icon" href="./images/Feuerlöscher.ico">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<script>
    let polling = true;
    let dirty = false;
    function pausePolling() {
        console.log("not polling!");
        polling = false;
    }

    function resumePolling() {
        console.log("resuming polling");
        polling = true;
    }

    function markDirty() {
        console.log("data dirty, not updating from polling");
        dirty = true;
    }

    
    async function loadPrintStatus() {
        let num = getCurrentNumber();
        if (!num) return;

        let response = await fetch(`./add_edit_ajax.php?number=${num}&module=print`);
        document.getElementById("print").outerHTML = await response.text();
    }


    async function loadStatus() {
        if (!polling || dirty) return;

        let num = getCurrentNumber();
        if (!num) return;

        let response = await fetch(`./add_edit_ajax.php?number=${num}&module=status`);
        let content = await response.text();
        document.getElementById("status").outerHTML = content;
    }

    
    async function loadInfo() {
        if (!polling || dirty) return;

        let num = getCurrentNumber();
        if (!num) return;

        let response = await fetch(`./add_edit_ajax.php?number=${num}&module=infotext`);
        document.getElementById("infotext").outerHTML = await response.text();
    }


    let int = null;

    function setupPolling() {
        console.log("loaded!!");
        <?php 
            if( is_null($editEntry) || !$editEntry ) { ?> 
                console.log("no edit entry, not starting polling");
                return; <?php
            }
        ?>
        int = setInterval(() => {
            loadPrintStatus();
            loadStatus();
            loadInfo();
        }, 2000);
    }

    function removePolling() {
        if(int === null) {
            console.log("polling never started, not proceeding");
            return;
        }

        clearInterval(int);
        console.log("stopped polling");
    }


    function getCurrentNumber() {
        let n = document.querySelector('input[name="nummer"]');
        if (!n) return null;
        return n.value;
    }


    window.onload = setupPolling; 


</script>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - Löscher aufnehmen / bearbeiten
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

<div class="container mt-5 bg-light">

<?php if ($successMessage): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
    <?= $successMessage ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<form method="post" class="card shadow p-3 mb-4" id="searchForm">
    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label">&#128269; Nummer oder Name suchen</label>
            
            <input type="text" name="suchfeld" id="suchfeld" list="loescherListe" class="form-control" placeholder="Nummer oder Name" required value="<?= htmlspecialchars($_POST['suchfeld'] ?? '') ?>" autocomplete="off">
            
            <datalist id="loescherListe">
                <?php foreach ($allEntries as $entry): ?>
                    <?php 
                        $val = sprintf("%03d", $entry['nummer']); 
                        $label = $val . " - " . htmlspecialchars($entry['name']);
                    ?>
                    <option value="<?= $val ?>"><?= $label ?></option>
                <?php endforeach; ?>
            </datalist>

        </div>
        <div class="col-md-2 align-self-end">
            <button type="submit" name="suche_nummer" class="btn btn-primary w-100">Suchen</button>
        </div>
    </div>
</form>

<?php if ($searchResults): ?>
	<form method="post" class="card shadow p-3 mb-4" id="multiSelectForm">
	    <label class="form-label">Mehrere Treffer gefunden. Wähle einen Datensatz:</label>
	    <select name="selected_entry" class="form-select mb-2" id="multiSelect">
	        <?php foreach ($searchResults as $r): ?>
	        <option value="<?= $r['id'] ?>"><?= sprintf("%03d", $r['nummer']) ?> - <?= htmlspecialchars($r['name']) ?></option>
	        <?php endforeach; ?>
	    </select>
	    <button type="submit" name="select_entry" class="btn btn-primary">Datensatz laden</button>
	</form>
<?php endif; ?>

<?php if ($editEntry): ?>
	<div class="row g-2 mb-3" id="editButtons">
	    <div class="col-6">
	        <button type="button" class="btn btn-secondary w-100" id="backToAdd">
	            &#10010; Neuer Eintrag
	        </button>
	    </div>
	    <div class="col-6">
	        <form method="post" class="m-0">
	            <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">
	            <button type="submit" name="refresh_entry" class="btn btn-info w-100">
	                &#128260; Neu laden
	            </button>
	        </form>
	    </div>
	</div>
<?php endif; ?>

<?php if ($editEntry): ?>
    <form method="post" class="card shadow p-3 mb-4" id="editForm">
        <input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">
        <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">

        <div class="row">
            <div class="col-md-6 border-end">
                <div class="mb-3">
                    <label class="form-label">Nummer</label>
                    <input type="text" class="form-control bg-light" value="<?= sprintf("%03d", $editEntry['nummer']) ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128100; Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editEntry['name']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128176; Preis je Löscher</label>
                    <select name="typ" class="form-select mb-1" id="editTypSelect">
                        <?php foreach ($preise as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($editEntry['typ'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="editPreisField" class="form-control bg-light" value="<?= $preise[$editEntry['typ']] . ",00 &euro;" ?>" disabled>
                </div>

                <div class="mb-3" id="infotext">
                    <label class="form-label">&#128161; Info</label>
                    <?php 
                        $infoText = $editEntry['info'] ?? '';
                        // Berechne Zeilen, mindestens 1
                        $rowCount = max(1, substr_count($infoText, "\n") + 1);
                    ?>
                    <textarea 
                        name="info" 
                        onfocus="pausePolling()" 
                        onblur="resumePolling()" 
                        oninput="markDirty(); this.rows = (this.value.split('\n').length || 1);" 
                        class="form-control" 
                        style="resize:none; overflow:hidden;"
                        rows="<?= $rowCount ?>"
                    ><?= htmlspecialchars($infoText) ?></textarea>
                </div>
            </div>

            <div class="col-md-6">
                <div class="row">
                    <div class="col-md-6 border-end" id="status">
                        <label class="form-label fw-bold small text-uppercase text-muted mb-3">Statusübersicht</label>
                        
                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck" <?= $editEntry['bezahlt'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
                        </div>

                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="geprueft" class="form-check-input" id="geprueftCheck" <?= $editEntry['geprueft'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="geprueftCheck">&#129514; Geprüft</label>
                        </div>

                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="abgeholt" class="form-check-input" id="abgeholtCheck" <?= $editEntry['abgeholt'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="abgeholtCheck">&#128230; Abgeholt</label>
                        </div>
                    
						<div class="form-check mb-2" style="<?= ($editEntry['defekt'] == -1) ? 'filter: grayscale(1); opacity: 0.5;' : '' ?>">
						    <input type="checkbox" 
						           onfocus="pausePolling()" 
						           onblur="resumePolling()" 
						           oninput="markDirty()" 
						           name="defekt" 
						           class="form-check-input" 
						           id="defektCheck" 
						           <?= ($editEntry['defekt'] == 1) ? 'checked' : '' ?>>
						    <label class="form-check-label text-danger" for="defektCheck">
						        &#9940; Defekt <?= ($editEntry['defekt'] == -1) ? '<small class="text-muted">(noch ungeprüft)</small>' : '' ?>
						    </label>
						</div>
                    </div>

                    <div class="col-md-6" id="print">
                        <label class="form-label fw-bold small text-uppercase text-muted mb-3">Druck</label>
                        
                        <div class="mb-3">
						    <div class="d-flex justify-content-between align-items-center mb-1">
						        <span class="small text-muted">
						            Etikette: 
						            <span class="badge <?= $editEntry['etikett_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
						                <?= $editEntry['etikett_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
						            </span>
						        </span>
						        <span>
						            <?= $editEntry['etikett_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
						        </span>
						    </div>
						
						    <button type="submit" name="redruck_etikett" class="btn btn-outline-secondary btn-sm w-100">
						        &#127991; Nachdrucken
						    </button>
						</div>

                        <div class="mb-3">
						    <div class="d-flex justify-content-between align-items-center mb-1">
						        <span class="small text-muted">
						            Abholschein: 
						            <span class="badge <?= $editEntry['abholschein_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
						                <?= $editEntry['abholschein_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
						            </span>
						        </span>
						        <span>
						            <?= $editEntry['abholschein_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
						        </span>
						    </div>
						
						    <button type="submit" name="redruck_abholschein" class="btn btn-outline-secondary btn-sm w-100">
						        &#129534; Nachdrucken
						    </button>
						</div>
                    </div>
                </div>
            </div>
        </div>

        <hr class="mt-4">
        
        <div class="d-flex justify-content-between align-items-center">
            <div class="text-muted small">
                Erstellt am: <?= !empty($editEntry['zeitstempel']) ? date('d.m.Y H:i', strtotime($editEntry['zeitstempel'])) : '-' ?>
            </div>
            <div class="d-flex gap-2">
                <?php if ($editEntry['bezahlt'] && $editEntry['defekt']): ?>
                    <button type="submit" class="btn btn-warning" name="geld_retour">
                         &#128176; Geld retour gegeben
                    </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-success px-4" name="edit_id">&#128190;  Speichern</button>
            </div>
        </div>
    </form>
<?php endif; ?>


<!-- Add-Formular wird nur angezeigt, wenn kein Eintrag zur Bearbeitung geladen ist -->
<form method="post" class="card shadow p-3 mb-4" id="addForm" style="<?= $editEntry ? 'display:none;' : 'display:block;' ?>">
    <div class="mb-3">
        <label class="form-label">&#128100; Name</label>
        <input type="text" name="name" class="form-control highlight" required>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128176; Preis je Löscher</label>
        <select name="typ" class="form-select" id="addTypSelect">
            <?php foreach ($preise as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($k === 'Standard') ? 'selected' : '' ?>><?= $k ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="addPreisField" class="form-control" value="<?= $preise['Standard'] . ",00 &euro;" ?>" disabled>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128161; Info</label>
        <textarea 
            name="info" 
            class="form-control" 
            oninput="this.rows = (this.value.split('\n').length || 1);" 
            style="resize:none; overflow:hidden;" 
            rows="1"
        ></textarea>
    </div>
    
    <div class="form-check mb-3">
        <input type="checkbox" name="bezahlt" class="form-check-input" id="addBezahltCheck" checked>
        <label class="form-check-label" for="addBezahltCheck">&#128176; Bezahlt</label>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128290; Anzahl gleiche Löscher</label>
        <input type="number" name="anzahl" class="form-control highlight" value="1" min="1">
    </div>

    <button type="submit" class="btn btn-success" name="add_loscher">&#128190;  Speichern</button>
</form>
<?php include 'massenverwaltung.php'; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const preisMap = <?= json_encode($preise) ?>;

document.getElementById('editTypSelect')?.addEventListener('change', () => {
    let preis = preisMap[document.getElementById('editTypSelect').value] ?? 0;
    if (typeof preis === "string") {
        preis = parseFloat(preis);
    }
    let preisString = preis.toLocaleString(navigator.language, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + " €";
    document.getElementById('editPreisField').value = preisString;
});

document.getElementById('addTypSelect')?.addEventListener('change',()=>{
    let preis = preisMap[document.getElementById('addTypSelect').value] ?? 0;
    if(typeof preis === "string") {
        preis = parseFloat(preis);
    }
    let preisString = preis.toLocaleString(navigator.language, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + " €";
    document.getElementById('addPreisField').value = preisString;
});

// ESC: Bearbeitung zurücksetzen, Add anzeigen
document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){
        document.getElementById('suchfeld').value = '';
        document.getElementById('editForm')?.remove();
        document.getElementById('editButtons')?.remove(); // NEU: Buttons entfernen
        document.getElementById('multiSelectForm')?.remove();
        
        const addForm = document.getElementById('addForm');
        if(addForm) {
            addForm.style.display = 'block';
            addForm.querySelector('input[name="name"]')?.focus();
        }
        removePolling();
    }
});

// BUTTON: Zurück zum Add-Modus (Klick auf "Neuer Eintrag")
document.getElementById('backToAdd')?.addEventListener('click', () => {
    document.getElementById('editForm')?.remove();
    document.getElementById('editButtons')?.remove(); // NEU: Buttons entfernen
    document.getElementById('multiSelectForm')?.remove();

    // Add-Form anzeigen
    const addForm = document.getElementById('addForm');
    if(addForm) {
        addForm.style.display = 'block';
        addForm.querySelector('input[name="name"]')?.focus();
    }

    // Suchfeld zurücksetzen
    const searchField = document.getElementById('suchfeld');
    if(searchField) searchField.value = '';

    removePolling();
});

/*document.getElementById('searchForm')?.addEventListener('submit', function(e) {
    // Nach dem Absenden das Feld leeren
    setTimeout(() => {
        const searchField = document.getElementById('suchfeld');
        if(searchField) searchField.value = '';
    }, 50); // kleine Verzögerung, damit das Formular noch gesendet wird
});*/

// Automatisch Erfolgsmeldungen nach 3 Sekunden schließen
document.addEventListener('DOMContentLoaded', function() {
    const alerts = document.querySelectorAll('.alert'); // Betrifft alle Alerts
    alerts.forEach(function(alert) {
        setTimeout(function() {
            let bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        }, 3000); 
    });
});
</script>
</body>
</html>