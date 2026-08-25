<?php
require 'config.php';

if (isset($_GET["ajax"])) {
    ajax();
    exit;
}

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
    $editId = $_GET['id'] ?? null;
    if ($editId) {
        $editEntry = $db->querySingle("SELECT * FROM loescher WHERE id = " . (int) $editId, true);
    }
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
            $nummer = (int) $input;
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
                header("Location: ?mode=edit&id=" . $rows[0]['id']);
                exit;
            } elseif (count($rows) > 1) {
                $searchResults = $rows;
            } else {
                $successMessage = "&#10060; Kein Datensatz für '$input' gefunden!";
                $messageType = "danger";
            }
        }
    }
}

// =====================
// AUSGEWÄHLTER DATENSATZ (wenn mehrere Treffer)
// =====================
if (isset($_POST['select_entry'])) {
    $selectedId = (int) $_POST['selected_entry'];
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
    $nummer = (int) $_POST['nummer'];
    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;

    $stmt = $db->prepare("
        UPDATE loescher SET
            name = :name,
            telefon = :telefon,
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
    $stmt->bindValue(':telefon', $_POST['telefon'] ?? '');
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

    $_SESSION['success_msg'] = "&#9989; Datensatz " . sprintf("%03d", $nummer) . " erfolgreich aktualisiert!";
    $_SESSION['msg_type'] = "success";
    header("Location: ?mode=edit&id=" . (int) $_POST['edit_id']);
    exit;
}

// =====================
// DATENSATZ NEU LADEN (im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['refresh_entry'])) {
    $nummer = (int) $_POST['nummer'];
    $editEntry = $db->query("SELECT * FROM loescher WHERE CAST(nummer AS INTEGER) = $nummer")->fetchArray(SQLITE3_ASSOC);
    $successMessage = "&#128260; Datensatz neu geladen!";
    $messageType = "info";
}

// =====================
// NEUE LÖSCHER HINZUFÜGEN (Fallback, falls ohne JS gesendet)
// =====================
if ($mode === 'add' && isset($_POST['add_loscher'])) {
    $typ = $_POST['typ'] ?? '';
    $preis = $preise[$typ] ?? 0;
    $anzahl = max(1, (int) ($_POST['anzahl'] ?? 1));
    $nummern = [];
    for ($i = 0; $i < $anzahl; $i++) {
        $nummer = generateNummer($db);
        $nummern[] = $nummer;
        $stmt = $db->prepare("
            INSERT INTO loescher (
                nummer, name, telefon, typ, preis, loeschertyp,
                menge, einheit, etikett_gedruckt,
                abholschein_gedruckt, bezahlt, geprueft, abgeholt, defekt, active, info, zeitstempel
            ) VALUES (
                :nummer, :name, :telefon, :typ, :preis, :loeschertyp,
                :menge, :einheit, 0, 0, :bezahlt, 0, 0, 0, 1, :info, :zeitstempel
            )
        ");

        $stmt->bindValue(':nummer', $nummer);
        $stmt->bindValue(':name', $_POST['name']);
        $stmt->bindValue(':telefon', $_POST['telefon'] ?? '');
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

    $_SESSION['success_msg'] = "&#9989; $anzahl Löscher erfolgreich hinzugefügt! [" . implode(", ", $nummern) . "]";
    $_SESSION['msg_type'] = "success";
    $_SESSION['focus_suchfeld'] = true;

    header("Location: ?mode=add");
    exit;
}

// =====================
// ETIKETTE / ABHOLSCHEIN NACHDRUCK (nur im edit mode)
// =====================
if ($mode === 'edit' && isset($_POST['redruck_etikett'])) {
    $nummer = (int) $_POST['nummer'];
    $editId = (int) ($_POST['edit_id'] ?? 0);
    $result = $db->exec("UPDATE loescher SET etikett_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    if ($result) {
        $_SESSION['success_msg'] = "&#9989; Etikette für Datensatz " . sprintf("%03d", $nummer) . " zum Nachdrucken freigegeben!";
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
    $nummer = (int) $_POST['nummer'];
    $editId = (int) ($_POST['edit_id'] ?? 0);
    $result = $db->exec("UPDATE loescher SET abholschein_gedruckt = 0 WHERE CAST(nummer AS INTEGER) = $nummer");
    if ($result) {
        $_SESSION['success_msg'] = "&#9989; Abholschein für Datensatz " . sprintf("%03d", $nummer) . " zum Nachdrucken freigegeben!";
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
    $nummer = (int) $_POST['nummer'];
    $zeitstempelNow = date('d.m.Y H:i:s');
    $eintrag = "Geld an Kunde retour gegeben - $zeitstempelNow";

    $stmt = $db->prepare("
        UPDATE loescher 
        SET bezahlt = 0, 
            info = CASE 
                WHEN info IS NULL OR info = '' THEN :text_first 
                ELSE info || :text_append 
            END
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");
    $stmt->bindValue(':text_first', $eintrag);
    $stmt->bindValue(':text_append', "\n" . $eintrag);
    $stmt->bindValue(':nummer', $nummer);
    $stmt->execute();

    $_SESSION['success_msg'] = "&#9989; Geld retour gebucht!";
    $_SESSION['msg_type'] = "warning";
    header("Location: ?mode=edit&id=" . (int) $_POST['edit_id']);
    exit;
}
$isActive = ($editEntry['active'] ?? 0) == 1;

?>

<!DOCTYPE html>
<html>

<head>
    <style>
        .highlight {
            box-shadow: 0 .125rem .25rem rgba(0, 0, 0, .35) !important;
        }

        .status-unbekannt {
            filter: grayscale(1);
            opacity: 0.6;
        }

        .alert {
            position: fixed !important;
            top: 100px !important;
            right: 20px !important;
            z-index: 10000000 !important;
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
                let response = await fetch(`./add_edit.php?nummer=${num}&ajax`, { cache: 'no-store' });
                let data = await response.json();
                if (!data || data.error) return;

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
                let response = await fetch(`./add_edit.php?nummer=${num}&ajax`, { cache: 'no-store' });
                let data = await response.json();
                if (!data || data.error) return;

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
                let response = await fetch(`./add_edit.php?nummer=${num}&ajax`, { cache: 'no-store' });
                let data = await response.json();
                if (!data || data.error) return;

                let infoTextarea = document.querySelector('#infotext textarea');
                if (infoTextarea) {
                    // \r entfernen, um Unix/Windows Line-Break-Unterschiede zu vereinheitlichen
                    let newText = (data.info || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
                    
                    if (infoTextarea.value !== newText) {
                        infoTextarea.value = newText;
                        let lineCount = newText ? newText.split('\n').length : 1;
                        infoTextarea.rows = lineCount;
                    }
                }
            } catch (err) {
                console.error('loadInfo error:', err);
            }
        }


        let int = null;

        function setupPolling() {
            console.log("loaded!!");
            <?php
            if (is_null($editEntry) || !$editEntry) { ?>
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
            if (int === null) {
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

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (document.getElementById('wechselgeldModal')?.classList.contains('show') || 
                    document.getElementById('duplicateNameModal')?.classList.contains('show')) {
                    return;
                }
                const suchfeld = document.getElementById('suchfeld');
                if (suchfeld) {
                    suchfeld.focus();
                    suchfeld.select();
                }
            }
        });

        window.addEventListener('load', function () {
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
                    &#127968; Start
                </a>
                <!--<a href="?logout=1" class="btn btn-danger btn-sm">
                    Abmelden
                </a>-->
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

                    <div class="input-group">
                        <input type="text" name="suchfeld" id="suchfeld" list="loescherListe" class="form-control"
                            placeholder="Nummer oder Name" required
                            value="<?= htmlspecialchars($_POST['suchfeld'] ?? '') ?>" autocomplete="off">
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
                        <option value="<?= $r['id'] ?>"><?= sprintf("%03d", $r['nummer']) ?> -
                            <?= htmlspecialchars($r['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="select_entry" class="btn btn-primary">Datensatz laden</button>
            </form>
        <?php endif; ?>

        <?php if ($mode === 'edit' && $editEntry): ?>
            <div class="row g-2 mb-3" id="editButtons">
                <div class="col-6">
                    <a href="?mode=add" class="btn btn-secondary w-100">
                        &#10010; Neuer Eintrag
                    </a>
                </div>
                <div class="col-6">
                    <form method="post" class="m-0"> <input type="hidden" name="mode" value="edit"> <input type="hidden"
                            name="nummer" value="<?= $editEntry['nummer'] ?>">
                        <button type="submit" name="refresh_entry" class="btn btn-info w-100">
                            &#128260; Neu laden
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'edit' && $editEntry): ?>
            <form method="post" class="card shadow p-3 mb-4" id="editForm">
                <input type="hidden" name="mode" value="edit">
                <input type="hidden" name="edit_id" value="<?= $editEntry['id'] ?>">
                <input type="hidden" name="nummer" value="<?= $editEntry['nummer'] ?>">

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
                            <input type="text" class="form-control bg-light"
                                value="<?= sprintf("%03d", $editEntry['nummer']) ?>" disabled>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">&#128100; Name</label>
                            <input type="text" name="name" class="form-control"
                                value="<?= htmlspecialchars($editEntry['name']) ?>" required <?= !$isActive ? 'disabled' : '' ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">&#128222; Kontakt</label>
                            <input type="tel" name="telefon" class="form-control"
                                value="<?= htmlspecialchars($editEntry['telefon'] ?? '') ?>" <?= !$isActive ? 'disabled' : '' ?>>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">&#128176; Preis je Löscher</label>
                            <select name="typ" class="form-select mb-1" id="editTypSelect" <?= !$isActive ? 'disabled' : '' ?>>
                                <?php foreach ($preise as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($editEntry['typ'] == $k) ? 'selected' : '' ?>><?= $k ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" id="editPreisField" class="form-control bg-light"
                                value="<?= $preise[$editEntry['typ']] . ",00 &euro;" ?>" disabled>
                        </div>

                        <div class="mb-3" id="infotext">
                            <label class="form-label">&#128161; Info</label>
                            <?php
                            $infoText = $editEntry['info'] ?? '';
                            $rowCount = max(1, substr_count($infoText, "\n") + 1);
                            ?>
                            <textarea name="info" onfocus="pausePolling()" onblur="resumePolling()"
                            oninput="markDirty(); this.rows = (this.value.split('\n').length || 1);"
                            class="form-control" style="resize:none; overflow:hidden;" rows="<?= $rowCount ?>"
                            <?= !$isActive ? 'disabled' : '' ?>><?= htmlspecialchars($infoText) ?></textarea>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <div class="row">
                            <div class="col-md-6 border-end" id="status">
                                <label
                                    class="form-label fw-bold small text-uppercase text-muted mb-3">Statusübersicht</label>

                                <div class="form-check mb-2">
                                    <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()"
                                        oninput="markDirty()" name="bezahlt" class="form-check-input" id="bezahltCheck"
                                        <?= $editEntry['bezahlt'] ? 'checked' : '' ?>     <?= !$isActive ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="bezahltCheck">&#128176; Bezahlt</label>
                                </div>

                                <div class="form-check mb-2">
                                    <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()"
                                        oninput="markDirty()" name="geprueft" class="form-check-input" id="geprueftCheck"
                                        <?= $editEntry['geprueft'] ? 'checked' : '' ?>     <?= !$isActive ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="geprueftCheck">&#129514; Geprüft</label>
                                </div>

                                <div class="form-check mb-2">
                                    <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()"
                                        oninput="markDirty()" name="abgeholt" class="form-check-input" id="abgeholtCheck"
                                        <?= $editEntry['abgeholt'] ? 'checked' : '' ?>     <?= !$isActive ? 'disabled' : '' ?>>
                                    <label class="form-check-label" for="abgeholtCheck">&#128230; Abgeholt</label>
                                </div>

                                <div class="form-check mb-2"
                                    style="<?= ($editEntry['defekt'] == -1) ? 'filter: grayscale(1); opacity: 0.5;' : '' ?>">
                                    <input type="checkbox" onfocus="pausePolling()" onblur="resumePolling()"
                                        oninput="markDirty()" name="defekt" class="form-check-input" id="defektCheck"
                                        <?= ($editEntry['defekt'] == 1) ? 'checked' : '' ?>     <?= !$isActive ? 'disabled' : '' ?>>
                                    <label class="form-check-label text-danger" for="defektCheck">
                                        &#9940; Defekt
                                        <?= ($editEntry['defekt'] == -1) ? '<small class="text-muted">(noch ungeprüft)</small>' : '' ?>
                                    </label>
                                </div>
                            </div>

                            <script>
                                function triggerAction(form, action) {
                                    const input = document.createElement('input');
                                    input.type = 'hidden';
                                    input.name = action;
                                    input.value = 1;
                                    form.appendChild(input);
                                    form.submit();
                                }
                            </script>

                            <div class="col-md-6" id="print">
                                <label class="form-label fw-bold small text-uppercase text-muted mb-3">Druck</label>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small text-muted">
                                            Etikette:
                                            <span
                                                class="badge <?= $editEntry['etikett_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $editEntry['etikett_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
                                            </span>
                                        </span>
                                        <span class="print-icon">
                                            <?= $editEntry['etikett_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
                                        </span>
                                    </div>

                                    <button type="button" onclick="triggerAction(this.form, 'redruck_etikett')"
                                        class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
                                        &#127991; Nachdrucken
                                    </button>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="small text-muted">
                                            Abholschein:
                                            <span
                                                class="badge <?= $editEntry['abholschein_gedruckt'] == 1 ? 'bg-success' : 'bg-danger' ?>">
                                                <?= $editEntry['abholschein_gedruckt'] == 1 ? 'Gedruckt' : 'Offen' ?>
                                            </span>
                                        </span>
                                        <span class="print-icon">
                                            <?= $editEntry['abholschein_gedruckt'] == 1 ? '&#9989;' : '&#10060;' ?>
                                        </span>
                                    </div>

                                    <button type="button" onclick="triggerAction(this.form, 'redruck_abholschein')"
                                        class="btn btn-outline-secondary btn-sm w-100" <?= !$isActive ? 'disabled' : '' ?>>
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
                        Erstellt am:
                        <?= !empty($editEntry['zeitstempel']) ? date('d.m.Y H:i', strtotime($editEntry['zeitstempel'])) : '-' ?>
                    </div>
                    <div class="d-flex gap-2">
                        <?php if ($editEntry['bezahlt'] && $editEntry['defekt']): ?>
                            <button type="submit" class="btn btn-warning" name="geld_retour" <?= !$isActive ? 'disabled' : '' ?>>
                                &#128176; Geld retour gegeben (Nicht bei Entsorgung)
                            </button>
                        <?php endif; ?>
                        <button type="button" id="prevBtn" class="btn btn-outline-secondary"
                            title="Vorheriger">&#11013;</button>
                        <button type="button" id="nextBtn" class="btn btn-outline-secondary"
                            title="Nächster">&#10145;</button>
                        <button type="submit" class="btn btn-success px-4" name="save_entry" <?= !$isActive ? 'disabled' : '' ?>>&#128190; Speichern</button>
                    </div>
                </div>
            </form>
        <?php endif; ?>


        <?php if ($mode === 'add'): ?>
            <script>
                function enterWeiter(event) {
                    if (event.key !== "Enter") return;

                    event.preventDefault();

                    if (event.target.name === "telefon") {
                        const anzField = document.getElementsByName('anzahl')[0];
                        if (anzField) {
                            anzField.focus();
                            anzField.select?.();
                        }
                        return; 
                    }

                    if (event.target.name === "anzahl") {
                        document.getElementById('submitAddBtn')?.click();
                        return;
                    }

                    const form = event.target.form;
                    const felder = [...form.querySelectorAll(
                        'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])'
                    )].filter(el => el.offsetParent !== null);

                    const index = felder.indexOf(event.target);

                    if (index !== -1 && index < felder.length - 1) {
                        felder[index + 1].focus();
                        felder[index + 1].select?.();
                    }
                }
            </script>
            <form method="post" class="card shadow p-3 mb-4" id="addForm">
                <h3 id="pageTitle">&#10133; Neu Anlegen</h3>
                <hr>

                <div class="mb-3">
                    <label class="form-label">&#128100; Name</label>
                    <input type="text" name="name" class="form-control highlight" onkeydown="enterWeiter(event)" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128222; Kontakt</label>
                    <input type="tel" name="telefon" class="form-control highlight" onkeydown="enterWeiter(event)">
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128290; Anzahl Löscher</label>
                    <input type="number" name="anzahl" id="addAnzahlField" class="form-control highlight" onkeydown="enterWeiter(event)" value="1" min="1">
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128176; Preis je Löscher</label>
                    <select name="typ" class="form-select" id="addTypSelect">
                        <?php foreach ($preise as $k => $v): ?>
                            <option value="<?= $k ?>" <?= ($k === 'Standard') ? 'selected' : '' ?>><?= $k ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="addPreisField" class="form-control"
                        value="<?= $preise['Standard'] . ",00 &euro;" ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label">&#128161; Info</label>
                    <textarea name="info" class="form-control" oninput="this.rows = (this.value.split('\n').length || 1);"
                        style="resize:none; overflow:hidden;" rows="1"></textarea>
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" name="bezahlt" class="form-check-input" id="addBezahltCheck" checked>
                    <label class="form-check-label" for="addBezahltCheck">&#128176; Bezahlt</label>
                </div>

                <div class="text-start">
                    <input type="hidden" name="add_loscher" value="1">
                    <input type="hidden" name="force_add" id="forceAddInput" value="0">
                    <button type="button" id="submitAddBtn" class="btn btn-success px-2">&#128190; Speichern</button>
                </div>
            </form>
        <?php endif; ?>

        <!-- Modal für Wechselgeld -->
        <div class="modal fade" id="wechselgeldModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1"
            aria-labelledby="wechselgeldModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" id="wechselgeldModalLabel">&#128181; Wechselgeldrechner</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            aria-label="Schließen" id="closeModalCrossBtn"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label text-muted small">Gesamtpreis (zu bezahlen):</label>
                            <input type="text" id="modalGesamtpreisField"
                                class="form-control bg-light fw-bold text-dark fs-4" readonly value="0,00 €">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold text-success">Gegebener Betrag (€):</label>
                            <input type="number" step="0.01" min="0" id="modalGegebenField"
                                class="form-control form-control-lg highlight" placeholder="0,00" autocomplete="off">
                        </div>
                        <div class="mb-2">
                            <label class="form-label fw-bold text-dark">Rückgeld / Wechselgeld:</label>
                            <input type="text" id="modalWechselgeldField"
                                class="form-control bg-light fw-bold text-dark fs-3" readonly value="0,00 €">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"
                            id="closeModalBtn">Abbrechen</button>
                        <button type="button" class="btn btn-success px-4 fs-5" id="confirmKassierenBtn">&#128178;
                            Kassieren & Schließen</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal für Namens-Duplikat-Bestätigung -->
        <div class="modal fade" id="duplicateNameModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title">&#9888;&#65039; Kunde existiert bereits</h5>
                    </div>
                    <div class="modal-body fs-5" id="duplicateNameModalBody">
                        Ein Kunde mit diesem Namen ist bereits gespeichert. Sollen die neuen Löscher wirklich zu diesem Namen hinzugefügt werden?
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="confirmDuplicateNoBtn">Nein, abbrechen</button>
                        <button type="button" class="btn btn-primary" id="confirmDuplicateYesBtn">Ja, hinzufügen</button>
                    </div>
                </div>
            </div>
        </div>

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

            document.getElementById('addTypSelect')?.addEventListener('change', () => {
                let preis = preisMap[document.getElementById('addTypSelect').value] ?? 0;
                if (typeof preis === "string") {
                    preis = parseFloat(preis);
                }
                let preisString = preis.toLocaleString(navigator.language, {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }) + " €";
                document.getElementById('addPreisField').value = preisString;
            });

            // --- SPEICHERN & POPUP LOGIK (WECHSELGELD + DUPLIKAT-PRÜFUNG) ---
            document.addEventListener('DOMContentLoaded', () => {
                const submitAddBtn = document.getElementById('submitAddBtn');
                const addForm = document.getElementById('addForm');
                const addBezahltCheck = document.getElementById('addBezahltCheck');
                const addTypSelect = document.getElementById('addTypSelect');
                const addAnzahlField = document.getElementById('addAnzahlField');
                const nameInput = document.querySelector('input[name="name"]');
                const forceAddInput = document.getElementById('forceAddInput');

                // Modal Elemente: Wechselgeld
                const wechselgeldModalEl = document.getElementById('wechselgeldModal');
                let wechselgeldModal = wechselgeldModalEl ? new bootstrap.Modal(wechselgeldModalEl) : null;

                const modalGesamtpreisField = document.getElementById('modalGesamtpreisField');
                const modalGegebenField = document.getElementById('modalGegebenField');
                const modalWechselgeldField = document.getElementById('modalWechselgeldField');
                const confirmKassierenBtn = document.getElementById('confirmKassierenBtn');
                const closeModalBtn = document.getElementById('closeModalBtn');
                const closeModalCrossBtn = document.getElementById('closeModalCrossBtn');

                // Modal Elemente: Duplikat Name
                const duplicateModalEl = document.getElementById('duplicateNameModal');
                let duplicateModal = duplicateModalEl ? new bootstrap.Modal(duplicateModalEl) : null;
                const confirmDuplicateYesBtn = document.getElementById('confirmDuplicateYesBtn');
                const confirmDuplicateNoBtn = document.getElementById('confirmDuplicateNoBtn');

                let currentGesamtpreis = 0;

                // Live-Berechnung Wechselgeld
                function calculateModalWechselgeld() {
                    let gegeben = parseFloat(modalGegebenField.value) || 0;
                    let wechselgeld = gegeben - currentGesamtpreis;

                    if (modalWechselgeldField) {
                        if (modalGegebenField.value === '' || gegeben <= 0) {
                            modalWechselgeldField.value = "0,00 €";
                            modalWechselgeldField.className = "form-control bg-light fw-bold text-dark fs-3";
                        } else if (wechselgeld >= 0) {
                            modalWechselgeldField.value = wechselgeld.toLocaleString(navigator.language, {
                                minimumFractionDigits: 2, maximumFractionDigits: 2
                            }) + " €";
                            modalWechselgeldField.className = "form-control bg-light fw-bold text-success fs-3";
                        } else {
                            modalWechselgeldField.value = "Zu wenig! (" + wechselgeld.toLocaleString(navigator.language, {
                                minimumFractionDigits: 2, maximumFractionDigits: 2
                            }) + " €)";
                            modalWechselgeldField.className = "form-control bg-light fw-bold text-danger fs-3";
                        }
                    }
                }

                // Erst Daten in die DB schreiben, danach Wechselgeldrechner öffnen oder Seite neu laden
                async function proceedToSaveOrWechselgeld() {
                    const formData = new FormData(addForm);
                    formData.append('add_ajax', '1');

                    try {
                        let response = await fetch('./add_edit.php?ajax=1', {
                            method: 'POST',
                            body: formData
                        });
                        let result = await response.json();

                        if (!result.success) {
                            alert('Fehler beim Speichern des Datensatzes.');
                            return;
                        }

                        if (addBezahltCheck && addBezahltCheck.checked && wechselgeldModal) {
                            let einzelpreis = preisMap[addTypSelect.value] ?? 0;
                            if (typeof einzelpreis === "string") einzelpreis = parseFloat(einzelpreis);
                            let anzahl = parseInt(addAnzahlField.value) || 1;
                            if (anzahl < 1) anzahl = 1;

                            currentGesamtpreis = einzelpreis * anzahl;

                            modalGesamtpreisField.value = currentGesamtpreis.toLocaleString(navigator.language, {
                                minimumFractionDigits: 2, maximumFractionDigits: 2
                            }) + " €";
                            modalGegebenField.value = '';
                            modalWechselgeldField.value = "0,00 €";
                            modalWechselgeldField.className = "form-control bg-light fw-bold text-dark fs-3";

                            wechselgeldModal.show();

                            wechselgeldModalEl.addEventListener('shown.bs.modal', () => {
                                modalGegebenField.focus();
                            }, { once: true });

                        } else {
                            window.location.href = "?mode=add";
                        }
                    } catch (err) {
                        console.error("Fehler beim Speichern:", err);
                        alert('Fehler beim Speichern.');
                    }
                }

                // Speichern-Button Klick
                submitAddBtn?.addEventListener('click', async (e) => {
                    if (!addForm.checkValidity()) {
                        addForm.reportValidity();
                        return;
                    }

                    const nameVal = nameInput ? nameInput.value.trim() : '';

                    // Falls vom Nutzer schon bestätigt ODER kein Name angegeben
                    if (forceAddInput.value === "1" || !nameVal) {
                        proceedToSaveOrWechselgeld();
                        return;
                    }

                    // AJAX Prüf-Request
                    try {
                        let resp = await fetch(`./add_edit.php?check_name=${encodeURIComponent(nameVal)}&ajax`, { cache: 'no-store' });
                        let resData = await resp.json();

                        if (resData.exists) {
                            document.getElementById('duplicateNameModalBody').textContent = 
                                `Ein Kunde mit dem Namen "${nameVal}" existiert bereits. Möchtest du diese Löscher wirklich zu diesem Namen hinzufügen?`;
                            duplicateModal.show();
                        } else {
                            proceedToSaveOrWechselgeld();
                        }
                    } catch (err) {
                        console.error("Fehler beim Prüfen des Namens:", err);
                        proceedToSaveOrWechselgeld();
                    }
                });

                // Klick auf "Ja, hinzufügen" im Duplikat-Modal
                confirmDuplicateYesBtn?.addEventListener('click', () => {
                    forceAddInput.value = "1";
                    duplicateModal.hide();
                    proceedToSaveOrWechselgeld();
                });

                // Klick auf "Nein, abbrechen" im Duplikat-Modal
                confirmDuplicateNoBtn?.addEventListener('click', () => {
                    duplicateModal.hide();
                    if (nameInput) {
                        nameInput.focus();
                        nameInput.select();
                    }
                });

                modalGegebenField?.addEventListener('input', calculateModalWechselgeld);

                modalGegebenField?.addEventListener('keydown', (e) => {
                    if (e.key === 'Tab' && !e.shiftKey) {
                        e.preventDefault();
                        confirmKassierenBtn.focus();
                    }
                });

                modalGegebenField?.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (modalGegebenField.value.trim() === '') {
                            modalGegebenField.value = currentGesamtpreis;
                        }
                        confirmKassierenBtn.click();
                    }
                });

                wechselgeldModalEl?.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        e.stopPropagation();
                        closeModalBtn.click();
                    }
                });

                confirmKassierenBtn?.addEventListener('click', () => {
                    window.location.href = "?mode=add";
                });

                const focusBack = () => { window.location.href = "?mode=add"; };
                closeModalBtn?.addEventListener('click', focusBack);
                closeModalCrossBtn?.addEventListener('click', focusBack);
            });

            document.addEventListener("keydown", function (e) {
                if (e.key === "Escape") {
                    if (document.getElementById('wechselgeldModal').classList.contains('show') || 
                        document.getElementById('duplicateNameModal').classList.contains('show')) {
                        return;
                    }
                    e.preventDefault();
                    e.stopPropagation();
                    window.location.href = "?mode=add";
                }
            }, true);

            document.getElementById('searchForm')?.addEventListener('submit', function (e) {
                setTimeout(() => {
                    const searchField = document.getElementById('suchfeld');
                    if (searchField) searchField.value = '';
                }, 50);
            });

            document.addEventListener('DOMContentLoaded', function () {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(function (alert) {
                    setTimeout(function () {
                        let bsAlert = new bootstrap.Alert(alert);
                        bsAlert.close();
                    }, 3000);
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
                let prevBtn = document.getElementById('prevBtn');
                let nextBtn = document.getElementById('nextBtn');
                let currentNumberInput = document.querySelector('.form-control.bg-light');
                let suchfeld = document.getElementById('suchfeld');

                nextBtn?.addEventListener('click', function () {
                    let currentNumber = parseInt(currentNumberInput.value.trim());

                    if (isNaN(currentNumber)) {
                        alert("Bitte eine gültige Nummer eingeben.");
                        return;
                    }

                    let nextNumber = currentNumber + 1;
                    suchfeld.value = nextNumber;

                    if (document.querySelector('button[name="suche_nummer"]')) {
                        document.querySelector('button[name="suche_nummer"]').click();
                    }
                });

                prevBtn?.addEventListener('click', function () {
                    let currentNumber = parseInt(currentNumberInput.value.trim());

                    if (isNaN(currentNumber)) {
                        alert("Bitte eine gültige Nummer eingeben.");
                        return;
                    }

                    let prevNumber = currentNumber - 1;
                    suchfeld.value = prevNumber;

                    if (document.querySelector('button[name="suche_nummer"]')) {
                        document.querySelector('button[name="suche_nummer"]').click();
                    }
                });

                suchfeld?.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        if (document.querySelector('button[name="suche_nummer"]')) {
                            document.querySelector('button[name="suche_nummer"]').click();
                        }
                    }
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
                <?php if (!empty($_SESSION['focus_suchfeld'])): ?>
                    document.getElementById('suchfeld')?.focus();
                    document.getElementById('suchfeld')?.select();
                    <?php unset($_SESSION['focus_suchfeld']); endif; ?>
            });
    </script>
</body>

</html>

<?php

function ajax()
{
    if (!isset($_SESSION['logged_in'])) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(["error" => "Nicht angemeldet"]);
        exit;
    }

    $db = getDB();

    // Namensprüfung via AJAX
    if (isset($_GET['check_name'])) {
        $name = trim($_GET['check_name']);
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM loescher WHERE name = :name AND active = 1");
        $stmt->bindValue(':name', $name);
        $res = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode(['exists' => ($res['count'] > 0)]);
        exit;
    }

    // AJAX Speichern beim Neuanlegen
    if (isset($_POST['add_ajax'])) {
        $preise = [
            'Standard' => PREIS_STANDARD,
            'Rabatt' => PREIS_RABATT,
            'Gratis' => PREIS_GRATIS
        ];
        $zeitstempel = date('Y-m-d H:i:s');
        $typ = $_POST['typ'] ?? '';
        $preis = $preise[$typ] ?? 0;
        $anzahl = max(1, (int) ($_POST['anzahl'] ?? 1));
        $nummern = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $nummer = generateNummer($db);
            $nummern[] = $nummer;
            $stmt = $db->prepare("
                INSERT INTO loescher (
                    nummer, name, telefon, typ, preis, loeschertyp,
                    menge, einheit, etikett_gedruckt,
                    abholschein_gedruckt, bezahlt, geprueft, abgeholt, defekt, active, info, zeitstempel
                ) VALUES (
                    :nummer, :name, :telefon, :typ, :preis, :loeschertyp,
                    :menge, :einheit, 0, 0, :bezahlt, 0, 0, 0, 1, :info, :zeitstempel
                )
            ");

            $stmt->bindValue(':nummer', $nummer);
            $stmt->bindValue(':name', $_POST['name']);
            $stmt->bindValue(':telefon', $_POST['telefon'] ?? '');
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

        $_SESSION['success_msg'] = "&#9989; $anzahl Löscher erfolgreich hinzugefügt! [" . implode(", ", $nummern) . "]";
        $_SESSION['msg_type'] = "success";
        $_SESSION['focus_suchfeld'] = true;

        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'nummern' => $nummern]);
        exit;
    }

    $nummer = $_GET['nummer'] ?? null;

    if (!$nummer) {
        header('Content-Type: application/json');
        echo json_encode(["error" => "keine nummer"]);
        exit;
    }

    $nummerSafe = (int) $nummer;

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(nummer AS INTEGER) = $nummerSafe
    ");

    $entry = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

    header('Content-Type: application/json');
    header('Cache-Control: no-cache');

    if ($entry) {
        echo json_encode($entry);
    } else {
        echo json_encode(["error" => "nicht gefunden"]);
    }
}