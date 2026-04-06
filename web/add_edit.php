<?php
require 'config.php';

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

$db = getDB();
$successMessage = '';
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
                // Suchfeld leeren, wenn nur ein Treffer
                $_POST['suchfeld'] = '';
            } elseif (count($rows) > 1) {
                $searchResults = $rows;
            } else {
				$successMessage = "❌ Kein Datensatz für '$input' gefunden!";
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

// =====================
// DATENSATZ BEARBEITEN
// =====================
if (isset($_POST['refresh_entry'])) {
    $nummer = (int)$_POST['nummer'];

    $editEntry = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(nummer AS INTEGER) = $nummer
    ")->fetchArray(SQLITE3_ASSOC);

    $successMessage = "🔄 Datensatz neu geladen!";
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
    $successMessage = "✅ Datensatz ".sprintf("%03d", $nummer)." erfolgreich aktualisiert!";
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

		$successMessage = "✅ $anzahl Löscher erfolgreich hinzugefügt!";
}

// =====================
// ETIKETTE / ABHOLSCHIEN NACHDRUCK
// =====================
if (isset($_POST['redruck_etikett'])) {
    $nummer = (int)$_POST['nummer'];
    $db->exec("UPDATE loescher SET etikett_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    $successMessage = "✅ Etikette für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

if (isset($_POST['redruck_abholschein'])) {
    $nummer = (int)$_POST['nummer'];
    $db->exec("UPDATE loescher SET abholschein_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    $successMessage = "✅ Abholschein für Datensatz ".sprintf("%03d", $nummer)." zum Nachdrucken freigegeben!";
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
            active = 0, 
            info = COALESCE(info,'') || :text
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");
    $stmt->bindValue(':text', "\nGeld retour - $zeitstempelNow");
    $stmt->bindValue(':nummer', $nummer);
    $stmt->execute();

	$successMessage = "✅ Geld retour gebucht und Datensatz deaktiviert!";
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
}

?>

<!DOCTYPE html>
<html>
<head>
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
        <? 
            if( is_null($editEntry) || !$editEntry ) { ?> 
                console.log("no edit entry, not starting polling");
                return; <?
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

<body>

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

<div class="container mt-5">

<?php if ($successMessage): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($successMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- SUCHE -->
<form method="post" class="card p-3 mb-4" id="searchForm">
    <div class="row g-2">
        <div class="col-md-6">
            <label class="form-label">&#128269; Nummer oder Name suchen</label>
            <input type="text" name="suchfeld" id="suchfeld" class="form-control" placeholder="Nummer oder Name" required value="<?= htmlspecialchars($_POST['suchfeld'] ?? '') ?>" autocomplete="off">
        </div>
        <div class="col-md-2 align-self-end">
            <button type="submit" name="suche_nummer" class="btn btn-primary w-100">Suchen</button>
        </div>
    </div>
</form>

<!-- MEHRFACH-TREFFER -->
<?php if ($searchResults): ?>
<form method="post" class="card p-3 mb-4" id="multiSelectForm">
    <label class="form-label">Mehrere Treffer gefunden. Wähle einen Datensatz:</label>
    <select name="selected_entry" class="form-select mb-2" id="multiSelect">
        <?php foreach ($searchResults as $r): ?>
        <option value="<?= $r['id'] ?>"><?= sprintf("%03d", $r['nummer']) ?> - <?= htmlspecialchars($r['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" name="select_entry" class="btn btn-primary">Datensatz laden</button>
</form>
<?php endif; ?>

<!-- BUTTON ZURÜCK ZUM ADD-MODUS -->
<?php if ($editEntry): ?>
<div class="mb-3">
    <button type="button" class="btn btn-secondary w-100" id="backToAdd">
        Neuer Eintrag
    </button>
</div>
<?php endif; ?>

<!-- DATENSATZ BEARBEITEN -->
<?php if ($editEntry): ?>
<form method="post" class="card p-3 mb-4" id="editForm">
    <input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">
    <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">

    <div class="mb-3">
        <label class="form-label">Nummer</label>
        <input type="text" class="form-control" value="<?= sprintf("%03d", $editEntry['nummer']) ?>" disabled>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128100; Name</label>
        <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($editEntry['name']) ?>" required>
    </div>
    
    <div class="mb-3 mt-3">
        <button type="submit" name="redruck_etikett" class="btn btn-warning me-2">
            &#127991; Etikette nachdrucken
        </button>
        <button type="submit" name="redruck_abholschein" class="btn btn-warning">
            &#129534; Abholschein nachdrucken
        </button>
        <button type="submit" class="btn btn-info" name="refresh_entry">
            🔄 Datensatz Aktualisieren
        </button>
        <p id="print">
            &#127991; Etikette gedruckt: <?= $editEntry['etikett_gedruckt'] ? '&#9989;': '&#10060;'?> <br>
            &#129534; Abholschein gedruckt: <?= $editEntry['abholschein_gedruckt'] ? '&#9989;': '&#10060;' ?>
        </p>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128176; Preis</label>
        <select name="typ" class="form-select" id="editTypSelect">
            <?php foreach ($preise as $k => $v): ?>
            <option value="<?= $k ?>" <?= ($editEntry['typ'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="editPreisField" class="form-control" value="<?= $preise[$editEntry['typ']] . ",00 €" ?>" disabled>
    </div>

    <div class="mb-3" id="infotext">
        <label class="form-label">&#8505; Info</label>
        <textarea name="info" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" class="form-control" rows="3"><?= htmlspecialchars($editEntry['info']) ?></textarea>
    </div>

    <!-- OPTIONALE FELDER (EINKLAPPBAR) 
    <div class="mb-2">
        <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="collapse" data-bs-target="#optionalFields">
            Optionale Angaben anzeigen
        </button>

        <div class="collapse mt-2" id="optionalFields">
            <div class="card card-body">
                <div class="mb-2">
                    <label class="form-label">Löschertyp (optional)</label>
                    <input type="text" name="loeschertyp" class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label">Menge (optional)</label>
                    <input type="number" name="menge" class="form-control">
                </div>
                <div class="mb-2">
                    <label class="form-label">Einheit (optional)</label>
                    <input type="text" name="einheit" class="form-control">
                </div>
            </div>
        </div>
    </div>-->
    <div id="status">
        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck"
                <?= $editEntry['bezahlt'] ? 'checked' : '' ?>>
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
        
        <div class="form-check mb-2">
            <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()" oninput="markDirty()" name="defekt" class="form-check-input" id="defektCheck" <?= $editEntry['defekt'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="defektCheck">&#9940; Defekt</label>
        </div>
    </div>
    
    <?php
	$formattedZeit = '';
	if (!empty($editEntry['zeitstempel'])) {
	    $formattedZeit = date('d.m.Y H:i', strtotime($editEntry['zeitstempel']));
	}
	?>
	
	<div class="mb-3">
	    <label class="form-label">Erstellt am</label>
	    <input type="text" class="form-control" value="<?= $formattedZeit ?>" disabled>
	</div>

    <div class="d-flex gap-2 mt-3">
	    <button type="submit" class="btn btn-success" name="edit_id">Speichern</button>
	
	    <?php if ($editEntry['bezahlt'] && $editEntry['defekt']): ?>
	        <button type="submit" class="btn btn-warning" name="geld_retour">
	             &#128176; Geld retour
	        </button>
	    <?php endif; ?>
	</div>

</form>
<?php endif; ?>

<!-- NEUES FORMULAR -->
<form method="post" class="card p-3 mb-4" id="addForm" style="<?= $editEntry ? 'display:none;' : 'display:block;' ?>">
    <div class="mb-3">
        <label class="form-label">&#128100; Name</label>
        <input type="text" name="name" class="form-control" required>
    </div>

    <div class="mb-3">
        <label class="form-label">&#128176; Preis</label>
        <select name="typ" class="form-select" id="addTypSelect">
            <?php foreach ($preise as $k => $v): ?>
                <option value="<?= $k ?>" <?= ($k === 'Standard') ? 'selected' : '' ?>><?= $k ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" id="addPreisField" class="form-control" value="<?= $preise['Standard'] . ",00 €" ?>" disabled>
    </div>

    <div class="mb-3">
        <label class="form-label">&#8505; Info</label>
        <textarea name="info" class="form-control" rows="3"></textarea>
    </div>

    <!-- OPTIONALE FELDER (EINKLAPPBAR) 
    <div class="mb-3">
        <button class="btn btn-outline-secondary w-100" type="button" data-bs-toggle="collapse" data-bs-target="#optionalFields">
            Optionale Angaben anzeigen
        </button>

        <div class="collapse mt-3" id="optionalFields">
            <div class="card card-body">
                <div class="mb-3">
                    <label class="form-label">Löschertyp (optional)</label>
                    <input type="text" name="loeschertyp" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Menge (optional)</label>
                    <input type="number" name="menge" class="form-control">
                </div>
                <div class="mb-3">
                    <label class="form-label">Einheit (optional)</label>
                    <input type="text" name="einheit" class="form-control">
                </div>
            </div>
        </div>
    </div>-->
    
    <div class="form-check mb-3">
        <input type="checkbox" name="bezahlt" class="form-check-input" id="addBezahltCheck" checked>
        <label class="form-check-label" for="addBezahltCheck">&#128176; Bezahlt</label>
    </div>

    <div class="mb-3">
        <label class="form-label">Anzahl gleiche Löscher</label>
        <input type="number" name="anzahl" class="form-control" value="1" min="1">
    </div>

    <button type="submit" class="btn btn-success" name="add_loscher">Speichern</button>
</form>
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
    if(e.key==="Escape"){
        document.getElementById('suchfeld').value='';
        document.getElementById('editForm')?.remove();
        document.getElementById('multiSelectForm')?.remove();
        const addForm = document.getElementById('addForm');
        addForm.style.display='block';
        addForm.querySelector('input[name="name"]')?.focus();
        removePolling();
    }
});

// BUTTON: Zurück zum Add-Modus (Touch)
document.getElementById('backToAdd')?.addEventListener('click',()=>{
    document.getElementById('editForm')?.remove();
    document.getElementById('multiSelectForm')?.remove();

    // Add-Form anzeigen
    const addForm = document.getElementById('addForm');
    addForm.style.display = 'block';
    addForm.querySelector('input[name="name"]')?.focus();

    // Suchfeld zurücksetzen
    const searchField = document.getElementById('suchfeld');
    if(searchField) searchField.value = '';

    // Button selbst ausblenden
    const backButton = document.getElementById('backToAdd');
    if(backButton) backButton.style.display = 'none';

    removePolling();
});

document.getElementById('searchForm')?.addEventListener('submit', function(e) {
    // Nach dem Absenden das Feld leeren
    setTimeout(() => {
        const searchField = document.getElementById('suchfeld');
        if(searchField) searchField.value = '';
    }, 50); // kleine Verzögerung, damit das Formular noch gesendet wird
});
</script>
</body>
</html>