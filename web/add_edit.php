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

// =====================
// MODE BESTIMMEN (via URL Parameters oder POST)
// =====================
$mode = $_GET['mode'] ?? $_POST['mode'] ?? 'add'; // default = add

if ($mode !== 'add' && $mode !== 'edit') {
    $mode = 'add';
}

// Beim Absenden im Edit-Modus ohne Query-String weiter auf edit bleiben
if ($mode === 'add' && isset($_POST['edit_id'])) {
    $mode = 'edit';
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

// =====================
// EDIT-EINTRAG LADEN (wenn mode=edit)
// =====================
$editEntry = null;
if ($mode === 'edit') {
    // Versuche die ID aus URL zu laden
    $editId = $_GET['id'] ?? null;
    if ($editId) {
        $editEntry = $db->querySingle("SELECT * FROM loescher WHERE id = " . (int)$editId, true);
    }
    // Falls nicht gefunden oder keine ID, fallback auf add mode
    if (!$editEntry) {
        $mode = 'add';
    }
}

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
$searchResults = [];
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
                // Direkt zu edit mode navigieren
                header("Location: ?mode=edit&id=" . $rows[0]['id']);
                exit;
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
    // Navigiere zu edit mode mit der ID
    header("Location: ?mode=edit&id=" . $selectedId);
    exit;
}

// Alle verfügbaren Löscher für die Autocomplete-Vorschlagsliste laden
$allEntriesResult = $db->query("SELECT nummer, name FROM loescher WHERE active = 1 ORDER BY nummer DESC");
$allEntries = [];
if ($allEntriesResult) {
    while ($row = $allEntriesResult->fetchArray(SQLITE3_ASSOC)) {
        $allEntries[] = $row;
    }
}

// =====================
// DATENSATZ BEARBEITEN (nur im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['save_entry']) && isset($_POST['edit_id'])) {
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
    
    // Erfolgsmeldung in Session speichern und zur gleichen Edit-Seite navigieren
    $_SESSION['success_msg'] = "&#9989; Datensatz ".sprintf("%03d", $nummer)." erfolgreich aktualisiert!";
    $_SESSION['msg_type'] = "success";
    header("Location: ?mode=edit&id=" . (int)$_POST['edit_id']);
    exit;
}

// =====================
// DATENSATZ NEU LADEN (im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['refresh_entry'])) {
    $nummer = (int)$_POST['nummer'];
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
    $successMessage = "&#128260; Datensatz neu geladen!";
    $messageType = "info";
}

// =====================
// NEUE LÖSCHER HINZUFÜGEN (nur im add mode)
// =====================
if ($mode === 'add' && isset($_POST['add_loscher'])) {
    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;
    $anzahl = max(1, (int)($_POST['anzahl'] ?? 1));
    $nummern = [];
    for ($i = 0; $i < $anzahl; $i++) {
        $nummer = generateNummer($db);
        $nummern[] = $nummer;
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
        
        $result = $stmt->execute();
    }

    // Erfolg in Session speichern
    $_SESSION['success_msg'] = "&#9989; $anzahl Löscher erfolgreich hinzugefügt! [" . implode(", ", $nummern) . "]";
    $_SESSION['msg_type'] = "success";
    $_SESSION['focus_suchfeld'] = true;

    // Zurück zum add mode
    header("Location: ?mode=add");
    exit;
}

// =====================
// ETIKETTE / ABHOLSCHEIN NACHDRUCK (nur im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['redruck_etikett'])) {
    $nummer = (int)$_POST['nummer'];
    $editId = (int)($_POST['edit_id'] ?? 0);
    $result = $db->exec("UPDATE loescher SET etikett_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    if ($result) {
        $_SESSION['success_msg'] = "&#9989; Etikette für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
        $_SESSION['msg_type'] = "success";
        header("Location: ?mode=edit&id=" . $editId);
        exit;
    } else {
        $_SESSION['success_msg'] = "&#10060; Fehler beim Zurücksetzen der Etikette: " . $db->lastErrorMsg();
        $_SESSION['msg_type'] = "danger";
        header("Location: ?mode=edit&id=" . $editId);
        exit;
    }
}

if ($mode === 'edit' && isset($_POST['redruck_abholschein'])) {
    $nummer = (int)$_POST['nummer'];
    $editId = (int)($_POST['edit_id'] ?? 0);
    $result = $db->exec("UPDATE loescher SET abholschein_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    if ($result) {
        $_SESSION['success_msg'] = "&#9989; Abholschein für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
        $_SESSION['msg_type'] = "success";
        header("Location: ?mode=edit&id=" . $editId);
        exit;
    } else {
        $_SESSION['success_msg'] = "&#10060; Fehler beim Zurücksetzen des Abholscheins: " . $db->lastErrorMsg();
        $_SESSION['msg_type'] = "danger";
        header("Location: ?mode=edit&id=" . $editId);
        exit;
    }
}

// =====================
// GELD RETOUR BUTTON AKTION (nur im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['geld_retour']) && isset($_POST['edit_id'])) {
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

    $_SESSION['success_msg'] = "&#9989; Geld retour gebucht!";
    $_SESSION['msg_type'] = "warning";
    header("Location: ?mode=edit&id=" . (int)$_POST['edit_id']);
    exit;
}

$isActive = ($editEntry['active'] ?? 0) == 1;

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

        try {
            let response = await fetch(`./add_edit_ajax.php?nummer=${num}`, { cache: 'no-store' });
            let data = await response.json();
            if (!data || data.error) return;

            // Update Etikette und Abholschein anhand des sichtbaren Labels
            let printContainer = document.getElementById('print');
            if (printContainer) {
                let printDivs = Array.from(printContainer.querySelectorAll('.mb-3'));

                const getPrintItem = (keyword) => {
                    return printDivs.find(div => {
                        let label = div.querySelector('.small.text-muted');
                        return label && label.textContent.toLowerCase().includes(keyword);
                    });
                };

                const updateItem = (div, value) => {
                    if (!div) return;
                    let badge = div.querySelector('.badge');
                    let icon = div.querySelector('.print-icon');
                    if (badge) {
                        if (value == 1) {
                            badge.classList.remove('bg-danger');
                            badge.classList.add('bg-success');
                            badge.textContent = 'Gedruckt';
                        } else {
                            badge.classList.remove('bg-success');
                            badge.classList.add('bg-danger');
                            badge.textContent = 'Offen';
                        }
                    }
                    if (icon) {
                        icon.innerHTML = value == 1 ? '&#9989;' : '&#10060;';
                    }
                };

                updateItem(getPrintItem('etikette'), data.etikett_gedruckt);
                updateItem(getPrintItem('abholschein'), data.abholschein_gedruckt);
            }
        } catch (err) {
            console.error('loadPrintStatus error:', err);
        }
    }


    async function loadStatus() {
        if (!polling || dirty) return;

        let num = getCurrentNumber();
        if (!num) return;

        try {
            let response = await fetch(`./add_edit_ajax.php?nummer=${num}`, { cache: 'no-store' });
            let data = await response.json();
            if (!data || data.error) return;

            // Update Checkboxen
            let bezahltCheck = document.getElementById('bezahltCheck');
            if (bezahltCheck) {
                bezahltCheck.checked = data.bezahlt == 1;
            }

            let geprueftCheck = document.getElementById('geprueftCheck');
            if (geprueftCheck) {
                geprueftCheck.checked = data.geprueft == 1;
            }

            let abgeholtCheck = document.getElementById('abgeholtCheck');
            if (abgeholtCheck) {
                abgeholtCheck.checked = data.abgeholt == 1;
            }

            let defektCheck = document.getElementById('defektCheck');
            if (defektCheck) {
                defektCheck.checked = data.defekt == 1;
            }
        } catch (err) {
            console.error('loadStatus error:', err);
        }
    }

    
    async function loadInfo() {
        if (!polling || dirty) return;

        let num = getCurrentNumber();
        if (!num) return;

        try {
            let response = await fetch(`./add_edit_ajax.php?nummer=${num}`, { cache: 'no-store' });
            let data = await response.json();
            if (!data || data.error) return;

            // Update Info-Textarea
            let infoTextarea = document.querySelector('#infotext textarea');
            if (infoTextarea) {
                infoTextarea.value = data.info || '';
                infoTextarea.rows = (data.info.split('\n').length || 1);
            }
        } catch (err) {
            console.error('loadInfo error:', err);
        }
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

    // ESC-Taste: Fokus auf Suchfeld setzen
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            const suchfeld = document.getElementById('suchfeld');
            if (suchfeld) {
                suchfeld.focus();
                suchfeld.select();
            }
        }
    });

    // Beim Laden im add-Modus: Suchfeld fokussieren und selektieren
    window.addEventListener('load', function() {
        <?php if ($mode === 'add'): ?>
        const suchfeld = document.getElementById('suchfeld');
        if (suchfeld) {
            suchfeld.focus();
            suchfeld.select();
        }
        <?php endif; ?>
    });

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
            <a href="liste.php" class="btn btn-outline-info btn-sm">Löscherübersicht</a>
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


<!-- Suchformular -->
<form method="post" class="card shadow p-3 mb-4" id="searchForm">
    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label">&#128269; Nummer oder Name suchen</label>

            <div class="input-group">
                <input type="text" 
                    name="suchfeld" 
                    id="suchfeld" 
                    list="loescherListe" 
                    class="form-control" 
                    placeholder="Nummer oder Name" 
                    required 
                    value="<?= htmlspecialchars($_POST['suchfeld'] ?? '') ?>" 
                    autocomplete="off">
            </div>

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
<?php endif; ?><!-- Edit-Buttons (nur wenn mode=edit und editEntry existiert) -->
<?php if ($mode === 'edit' && $editEntry): ?>
	<div class="row g-2 mb-3" id="editButtons">
	    <div class="col-6">
	        <a href="?mode=add" class="btn btn-secondary w-100">
	            &#10010; Neuer Eintrag
	        </a>
	    </div>
	    <div class="col-6">
	        <form method="post" class="m-0">            <input type="hidden" name="mode" value="edit">	            <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">
	            <button type="submit" name="refresh_entry" class="btn btn-info w-100">
	                &#128260; Neu laden
	            </button>
	        </form>
	    </div>
	</div>
<?php endif; ?>

<!-- Edit-Formular (nur wenn mode=edit und editEntry existiert) -->
<?php if ($mode === 'edit' && $editEntry): ?>
    <form method="post" class="card shadow p-3 mb-4" id="editForm">
        <input type="hidden" name="mode" value="edit">
        <input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">
        <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">

        <!--Überschrift-->
        <h3 id="pageTitle">&#9999; Bearbeiten</h3>
        <hr>


        <?php if (!$isActive): ?>
            <div class="alert alert-danger">
                &#128465; Dieser Datensatz ist deaktiviert und kann nicht bearbeitet werden.
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6 border-end">
                <div class="mb-3">
                    <label class="form-label">Nummer</label>
                    <input type="text" class="form-control bg-light" value="<?= sprintf("%03d", $editEntry['nummer']) ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128100; Name</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editEntry['name']) ?>" required <?= !$isActive ? 'disabled' : '' ?>>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128176; Preis je Löscher</label>
                    <select name="typ" class="form-select mb-1" id="editTypSelect" <?= !$isActive ? 'disabled' : '' ?>>
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
                        <?= !$isActive ? 'disabled' : '' ?>
                    ><?= htmlspecialchars($infoText) ?></textarea>
                </div>
            </div>

            <div class="col-md-6">
                <div class="row">
                    <div class="col-md-6 border-end" id="status">
                        <label class="form-label fw-bold small text-uppercase text-muted mb-3">Statusübersicht</label>
                        
                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck" <?= $editEntry['bezahlt'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
                        </div>

                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="geprueft" class="form-check-input" id="geprueftCheck" <?= $editEntry['geprueft'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="geprueftCheck">&#129514; Geprüft</label>
                        </div>

                        <div class="form-check mb-2">
                            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="abgeholt" class="form-check-input" id="abgeholtCheck" <?= $editEntry['abgeholt'] ? 'checked' : '' ?> <?= !$isActive ? 'disabled' : '' ?>>
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
						           <?= ($editEntry['defekt'] == 1) ? 'checked' : '' ?>
                                   <?= !$isActive ? 'disabled' : '' ?>>
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
						        <span class="print-icon">
						            <?= $editEntry['etikett_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
						        </span>
						    </div>
						
						    <button type="submit" name="redruck_etikett" class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
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
						        <span class="print-icon">
						            <?= $editEntry['abholschein_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
						        </span>
						    </div>
						
						    <button type="submit" name="redruck_abholschein" class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
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
                    <button type="submit" class="btn btn-warning" name="geld_retour" <?= !$isActive ? 'disabled' : '' ?>>
                         &#128176; Geld retour gegeben
                    </button>
                <?php endif; ?>
                <button type="button" id="prevBtn" class="btn btn-outline-secondary" title="Vorheriger">&#11013;</button>
                <button type="button" id="nextBtn" class="btn btn-outline-secondary" title="Nächster">&#10145;</button>
                <button type="submit" class="btn btn-success px-4" name="save_entry" <?= !$isActive ? 'disabled' : '' ?>>&#128190;  Speichern</button>
            </div>
        </div>
    </form>
<?php endif; ?>


<!-- Add-Formular (nur wenn mode=add) -->
<?php if ($mode === 'add'): ?>
<form method="post" class="card shadow p-3 mb-4" id="addForm">
    <!--Überschrift-->
    <h3 id="pageTitle">&#10133; Neu Anlegen</h3>
    <hr>

    
    <div class="mb-3">
        <label class="form-label">&#128100; Name</label>
        <input type="text" name="name" class="form-control highlight" required>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128290; Anzahl Löscher</label>
        <input type="number" name="anzahl" class="form-control highlight" value="1" min="1">
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

    <div class="text-start">
        <button type="submit" class="btn btn-success px-2" name="add_loscher">&#128190;  Speichern</button>
    </div>
</form>
<?php endif; ?>

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

// Escape-Taste überwachen, um zum Add-Modus zu wechseln
document.addEventListener("keydown", function(e){
    if (e.key === "Escape") {
        e.preventDefault();
        e.stopPropagation();
        window.location.href = "?mode=add";
    }
}, true);

document.getElementById('searchForm')?.addEventListener('submit', function(e) {
    // Nach dem Absenden das Feld leeren
    setTimeout(() => {
        const searchField = document.getElementById('suchfeld');
        if(searchField) searchField.value = '';
    }, 50); // kleine Verzögerung, damit das Formular noch gesendet wird
});

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

// Vor und Zurück Button für Edit-Modus
document.addEventListener('DOMContentLoaded', function() {
    let prevBtn = document.getElementById('prevBtn');
    let nextBtn = document.getElementById('nextBtn');
    let currentNumberInput = document.querySelector('.form-control.bg-light'); // disabled input with current number
    let suchfeld = document.getElementById('suchfeld');

    // Event-Listener für "Nächster"
    nextBtn?.addEventListener('click', function() {
        let currentNumber = parseInt(currentNumberInput.value.trim());

        if (isNaN(currentNumber)) {
            alert("Bitte eine gültige Nummer eingeben.");
            return;
        }

        let nextNumber = currentNumber + 1;
        suchfeld.value = nextNumber;
        
        // Suche simulieren
        if (document.querySelector('button[name="suche_nummer"]')) {
            document.querySelector('button[name="suche_nummer"]').click();
        }
    });

    // Event-Listener für "Vorheriger"
    prevBtn?.addEventListener('click', function() {
        let currentNumber = parseInt(currentNumberInput.value.trim());

        if (isNaN(currentNumber)) {
            alert("Bitte eine gültige Nummer eingeben.");
            return;
        }

        let prevNumber = currentNumber - 1;
        suchfeld.value = prevNumber;
        
        // Suche simulieren
        if (document.querySelector('button[name="suche_nummer"]')) {
            document.querySelector('button[name="suche_nummer"]').click();
        }
    });

    // Optional: Simuliere den Klick auf den "Suchen"-Button bei Enter
    suchfeld?.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            if (document.querySelector('button[name="suche_nummer"]')) {
                document.querySelector('button[name="suche_nummer"]').click();
            }
        }
    });
});

document.addEventListener('DOMContentLoaded', function() {
    <?php if (!empty($_SESSION['focus_suchfeld'])): ?>
        document.getElementById('suchfeld')?.focus();
        document.getElementById('suchfeld')?.select();
    <?php unset($_SESSION['focus_suchfeld']); endif; ?>
});
</script>
</body>
</html>