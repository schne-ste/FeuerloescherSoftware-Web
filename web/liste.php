<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$db = getDB();

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// =====================
// HILFSFUNKTIONEN
// =====================
if (!function_exists('percent')) {
    function percent($teil, $gesamt) {
        return $gesamt > 0 ? round(($teil / $gesamt) * 100) : 0;
    }
}

function getRowStatusClass($row) {
    $isGeprueft = (bool)$row['geprueft'];
    $isBezahlt = (bool)$row['bezahlt'];
    $isDefekt = (bool)$row['defekt'];
    $isAbgeholt = (bool)$row['abgeholt'];

    if (!$isGeprueft) {
        // 1. Nicht geprüft -> Orange (#fff3cd)
        return 'status-orange';
    } elseif ($isBezahlt && $isDefekt && !$isAbgeholt) {
        // 2. Bezahlt, defekt, nicht abgeholt -> Hellgrün (#e4edd4)
        return 'status-hellgruen';
    } elseif (!$isBezahlt && $isDefekt && $isAbgeholt) {
        // 3. Nicht bezahlt, defekt, abgeholt -> Hellgrün (#e4edd4)
        return 'status-hellgruen';
    } elseif ($isBezahlt && !$isDefekt && $isAbgeholt) {
        // 4. Bezahlt, ok (nicht defekt), abgeholt -> Grün (#d4edda)
        return 'status-gruen';
    } else {
        // 5. Alles andere -> Rot (#f8d7da)
        return 'status-rot';
    }
}

function renderRow($row) {
    $rowClass = getRowStatusClass($row);
    $disabled = ($row['active'] == 0) ? 'disabled' : '';

    ob_start();
    ?>
    <tr data-id="<?= $row['id'] ?>" class="<?= $rowClass ?> <?= !$row['active'] ? 'row-inactive' : '' ?>">
        <td><button class="btn btn-sm btn-outline-primary btn-edit" onclick="window.open('add_edit.php?mode=edit&id=<?= $row['id'] ?>', '_blank')"><strong><?= htmlspecialchars($row['nummer']) ?></strong></button></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td>
            <select class="form-select typ-select" <?= $disabled ?>>
                <option value="Standard" <?= $row['typ'] == "Standard" ? "selected" : "" ?>>Standard (<?= PREIS_STANDARD ?> €)</option>
                <option value="Rabatt" <?= $row['typ'] == "Rabatt" ? "selected" : "" ?>>Rabatt (<?= PREIS_RABATT ?> €)</option>
                <option value="Gratis" <?= $row['typ'] == "Gratis" ? "selected" : "" ?>>Gratis (<?= PREIS_GRATIS ?> €)</option>
            </select>
        </td>
        <td><input type="checkbox" class="cb-bezahlt" <?= $row['bezahlt'] ? 'checked' : '' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-geprueft" <?= $row['geprueft'] ? 'checked' : '' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-defekt" <?= $row['defekt'] ? 'checked' : '' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-abgeholt" <?= $row['abgeholt'] ? 'checked' : '' ?> <?= $disabled ?>></td>
        <td><?= nl2br(htmlspecialchars($row['info'])) ?></td>
        <td>
            <?php if ($row['active']): ?>
                <button class="btn btn-sm btn-danger btn-delete">Löschen</button>
            <?php else: ?>
                <span class="badge bg-secondary">Gelöscht</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

function renderRows($result) {
    $html = '';
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $html .= renderRow($row);
    }
    return $html;
}

function getStats($db) {
    $stats = [];
    $stats['gesamt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE active = 1");
    
    $stats['geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND active = 1");
    $stats['nicht_geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 0 AND active = 1");

    $stats['abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 1 AND active = 1");
    $stats['nicht_abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 0 AND active = 1");

    $stats['bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 1 AND active = 1");
    $stats['nicht_bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 0 AND active = 1");

    $stats['ok'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 0 AND active = 1");
    $stats['defekt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 1 AND active = 1");

    $stats['gesamt_ok'] = $stats['gesamt'] - $stats['defekt'];

    $stats['p_defekt'] = percent($stats['defekt'], $stats['gesamt']);
    $stats['p_geprueft'] = percent($stats['geprueft'], $stats['gesamt']);
    $stats['p_abgeholt'] = percent($stats['abgeholt'], $stats['gesamt']);
    $stats['p_bezahlt'] = percent($stats['bezahlt'], $stats['gesamt']);

    // Durchschnittliche Anzahl an Löschern pro Name berechnen
    $stats['avg_pro_name'] = $db->querySingle("SELECT ROUND(AVG(anzahl), 2) FROM (SELECT COUNT(*) as anzahl FROM loescher WHERE active = 1 GROUP BY name ) ") ?: 0;
    
    return $stats;
}



// =====================
// AJAX UPDATES
// =====================
if (isset($_POST['ajax_update'])) {
    $id = (int)$_POST['id'];
    $field = $_POST['field'];
    $value = $_POST['value'];

    $allowed = ['typ','name','etikett_gedruckt','abholschein_gedruckt','geprueft','abgeholt','bezahlt','defekt','active'];
    if (!in_array($field, $allowed)) exit;

    if ($field === 'typ') {
        $preis = PREIS_GRATIS;
        if ($value === "Standard") $preis = PREIS_STANDARD;
        if ($value === "Rabatt") $preis = PREIS_RABATT;

        $stmt = $db->prepare("UPDATE loescher SET typ=:typ, preis=:preis WHERE id=:id");
        $stmt->bindValue(':typ', $value);
        $stmt->bindValue(':preis', $preis);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    } else {

        if ($field === 'active' && $value == 0) {
            $stmt = $db->prepare("
                UPDATE loescher 
                SET active = 0, 
                    info = 
                        CASE 
                            WHEN info IS NULL OR info = '' THEN 'Gelöscht' 
                            ELSE COALESCE(info, '') || '\nGelöscht' 
                        END
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

        } elseif ($field === 'active' && $value == 1) {
            $stmt = $db->prepare("
                UPDATE loescher 
                SET active = 1,
                    info = TRIM(REPLACE(info, 'Gelöscht', ''))
                WHERE id = :id
            ");
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();

        } else {
            $stmt = $db->prepare("UPDATE loescher SET $field=:value WHERE id=:id");
            $stmt->bindValue(':value', $value, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $stmt->execute();
        }

    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['status'=>'ok', 'stats'=>getStats($db)]);
    exit;
}

// =====================
// INITIALE DATEN
// =====================
$where = [];
if (!empty($_GET['suche'])) {
    $suche = SQLite3::escapeString($_GET['suche']);
    $where[] = "(name LIKE '%$suche%' OR nummer LIKE '%$suche%')";
}
if (isset($_GET['nicht_geprueft'])) $where[] = "geprueft = 0";
if (isset($_GET['nicht_abgeholt'])) $where[] = "abgeholt = 0";
if (isset($_GET['nicht_bezahlt'])) $where[] = "bezahlt = 0";
if (isset($_GET['defekt'])) $where[] = "defekt = 1";

$whereSQL = count($where) ? "WHERE " . implode(" AND ", $where) : "";
$result = $db->query("SELECT * FROM loescher $whereSQL ORDER BY id DESC");

if (isset($_GET['ajax_refresh'])) {
    header('Content-Type: application/json; charset=UTF-8');
    $stats = getStats($db);
    echo json_encode(['status'=>'ok', 'html' => renderRows($result), 'stats' => $stats]);
    exit;
}

$stats = getStats($db);
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
    <style>
        /* Farbdefinitionen für die Tabellenzeilen */
        tr.status-orange, tr.status-orange > td {
            background-color: #fff3cd !important;
        }
        tr.status-hellgruen, tr.status-hellgruen > td {
            background-color: #e4edd4 !important;
        }
        tr.status-gruen, tr.status-gruen > td {
            background-color: #d4edda !important;
        }
        tr.status-rot, tr.status-rot > td {
            background-color: #f8d7da !important;
        }

        /* Hover-Effekt */
        tr.status-orange:hover,
        tr.status-hellgruen:hover,
        tr.status-gruen:hover,
        tr.status-rot:hover {
            filter: brightness(0.95);
            transition: filter 0.2s;
        }
    </style>
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - &#128196; Liste aller Löscher
        </span>

        <div class="d-flex gap-2">
            <a href="add_edit.php" class="btn btn-success btn-sm">+ Neuen Löscher anlegen</a>
            <a href="index.php" class="btn btn-outline-light btn-sm">&#127968; Start</a>
            <!--<a href="?logout=1" class="btn btn-danger btn-sm">Abmelden</a>-->
        </div>
    </div>
</nav>

<div class="container mt-5">

<h1>&#128293; Liste aller Löscher</h1>

<div class="row mb-4">
    <div class="col-6 col-md-2">
        <div class="card text-center bg-light mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Gesamt</strong></h6>
                <h5 class="mb-0" id="stat-gesamt"><?= $stats['gesamt'] ?></h5>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Bezahlt</strong></h6>
                <small id="stat-bezahlt">✅ <?= $stats['bezahlt'] ?> | ❌ <?= $stats['nicht_bezahlt'] ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Geprüft</strong></h6>
                <small id="stat-geprueft">✅ <?= $stats['geprueft'] ?> | ❌ <?= $stats['nicht_geprueft'] ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-success text-white mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>OK | DEFEKT</strong></h6>
                <small id="stat-ok">✅ <?= $stats['ok'] ?> | ❌ <?= $stats['defekt'] ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Abgeholt</strong></h6>
                <small id="stat-abgeholt">✅ <?= $stats['abgeholt'] ?> | ❌ <?= $stats['nicht_abgeholt'] ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-light mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Ø Löscher / Kunde</strong></h6>
                <h5 class="mb-0" id="stat-avg"><?= $stats['avg_pro_name'] ?></h5>
            </div>
        </div>
    </div>
</div>



<div class="mb-4">
    <label>Bezahlt (<span id="label-p-bezahlt"><?= $stats['p_bezahlt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-success" id="bar-bezahlt" style="width: <?= $stats['p_bezahlt'] ?>%"></div></div>

    <label>Geprüft (<span id="label-p-geprueft"><?= $stats['p_geprueft'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar" id="bar-geprueft" style="width: <?= $stats['p_geprueft'] ?>%"></div></div>

    <label>Defekt (<span id="label-p-defekt"><?= $stats['p_defekt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-danger" id="bar-defekt" style="width: <?= $stats['p_defekt'] ?>%"></div></div>

    <label>Abgeholt (<span id="label-p-abgeholt"><?= $stats['p_abgeholt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar" id="bar-abgeholt" style="width: <?= $stats['p_abgeholt'] ?>%"></div></div>

</div>

<form method="get" class="row g-1 mb-3 align-items-center">
    <div class="col-auto">
        <input type="text" name="suche" class="form-control form-control-sm me-3" style="width:150px;"
               placeholder="🔍 Name/Nummer" value="<?= htmlspecialchars($_GET['suche'] ?? '') ?>">
    </div>
    <div class="col-auto form-check form-check-inline">
        <input type="checkbox" name="nicht_bezahlt" class="form-check-input" id="chkBezahlt" <?= isset($_GET['nicht_bezahlt'])?'checked':'' ?>>
        <label class="form-check-label" for="chkBezahlt">Nicht bezahlt</label>
    </div>
    <div class="col-auto form-check form-check-inline">
        <input type="checkbox" name="nicht_geprueft" class="form-check-input" id="chkGeprueft" <?= isset($_GET['nicht_geprueft'])?'checked':'' ?>>
        <label class="form-check-label" for="chkGeprueft">Nicht geprüft</label>
    </div>
    <div class="col-auto form-check form-check-inline">
        <input type="checkbox" name="nicht_abgeholt" class="form-check-input" id="chkAbgeholt" <?= isset($_GET['nicht_abgeholt'])?'checked':'' ?>>
        <label class="form-check-label" for="chkAbgeholt">Nicht abgeholt</label>
    </div>
    <div class="col-auto form-check form-check-inline">
        <input type="checkbox" name="defekt" class="form-check-input" id="chkDefekt" <?= isset($_GET['defekt'])?'checked':'' ?>>
        <label class="form-check-label" for="chkDefekt">Defekt</label>
    </div>
    <div class="col-auto">
        <button class="btn btn-primary btn-sm">Filter</button>
    </div>
    <div class="col-auto">
        <a href="liste.php" class="btn btn-secondary btn-sm">Reset</a>
    </div>
</form>

<table class="table table-bordered align-middle">
    <thead>
        <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Typ</th>
            <th>Bezahlt</th>
            <th>Geprüft</th>
            <th>Defekt</th>
            <th>Abgeholt</th>
            <th>Info</th>
            <th>Aktion</th>
        </tr>
    </thead>
    <tbody>
        <tr><td colspan="9" class="text-center">Liste wird geladen...</td></tr>
    </tbody>
</table>

</div>

<script>
// Exakte Farbzuweisung im Frontend per JavaScript
function updateRowClass(row){
    const geprueft = row.querySelector('.cb-geprueft')?.checked ?? false;
    const abgeholt = row.querySelector('.cb-abgeholt')?.checked ?? false;
    const bezahlt = row.querySelector('.cb-bezahlt')?.checked ?? false;
    const defekt = row.querySelector('.cb-defekt')?.checked ?? false;

    // Alle möglichen Statusklassen entfernen
    row.classList.remove('status-orange', 'status-hellgruen', 'status-gruen', 'status-rot', 'table-danger', 'table-warning', 'table-info', 'table-success');

    if (!geprueft) {
        // 1. Nicht geprüft -> Orange
        row.classList.add('status-orange');
    } else if (bezahlt && defekt && !abgeholt) {
        // 2. Bezahlt, defekt, nicht abgeholt -> Hellgrün
        row.classList.add('status-hellgruen');
    } else if (!bezahlt && defekt && abgeholt) {
        // 3. Nicht bezahlt, defekt, abgeholt -> Hellgrün
        row.classList.add('status-hellgruen');
    } else if (bezahlt && !defekt && abgeholt) {
        // 4. Bezahlt, ok (nicht defekt), abgeholt -> Grün
        row.classList.add('status-gruen');
    } else {
        // 5. Alles andere -> Rot
        row.classList.add('status-rot');
    }
}

function updateStatsDOM(stats){
    document.getElementById('stat-gesamt').innerText = stats.gesamt;
    document.getElementById('stat-geprueft').innerText = `✅ ${stats.geprueft} | ❌ ${stats.nicht_geprueft}`;
    document.getElementById('stat-abgeholt').innerText = `✅ ${stats.abgeholt} | ❌ ${stats.nicht_abgeholt}`;
    document.getElementById('stat-bezahlt').innerText = `✅ ${stats.bezahlt} | ❌ ${stats.nicht_bezahlt}`;
    document.getElementById('stat-ok').innerText = `✅ ${stats.ok} | ❌ ${stats.defekt}`;

    document.getElementById('bar-geprueft').style.width = stats.p_geprueft+'%';
    document.getElementById('label-p-geprueft').innerText = stats.p_geprueft;
    
    document.getElementById('bar-abgeholt').style.width = stats.p_abgeholt+'%';
    document.getElementById('label-p-abgeholt').innerText = stats.p_abgeholt;

    document.getElementById('bar-bezahlt').style.width = stats.p_bezahlt+'%';
    document.getElementById('label-p-bezahlt').innerText = stats.p_bezahlt;

    document.getElementById('bar-defekt').style.width = stats.p_defekt+'%';
    document.getElementById('label-p-defekt').innerText = stats.p_defekt;

    if (document.getElementById('stat-avg')) {
        document.getElementById('stat-avg').innerText = stats.avg_pro_name;
    }
}

function ajaxUpdate(id, field, value){
    const params = new URLSearchParams({
        ajax_update: 1,
        id: id,
        field: field,
        value: value
    });
    return fetch('liste.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
        body: params.toString(),
        cache: 'no-store'
    }).then(res=>res.json());
}

function bindRowEvents(row){
    row.querySelectorAll('input[type="checkbox"], select').forEach(el=>{
        el.addEventListener('change', ()=>{
            const id = row.dataset.id;
            let field = '';

            if(el.classList.contains('cb-bezahlt')) field = 'bezahlt';
            else if(el.classList.contains('cb-defekt')) field = 'defekt';
            else if(el.classList.contains('cb-geprueft')) field = 'geprueft';
            else if(el.classList.contains('cb-abgeholt')) field = 'abgeholt';
            else if(el.classList.contains('typ-select')) field = 'typ';

            if(!field) return;
            const value = el.type==='checkbox' ? (el.checked?1:0) : el.value;

            ajaxUpdate(id, field, value)
            .then(data=>{
                if(data.status==='ok'){
                    updateRowClass(row);
                    updateStatsDOM(data.stats);
                }
            })
            .catch(err => console.error('Update fehlgeschlagen:', err));
        });
    });

    const deleteBtn = row.querySelector('.btn-delete');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', () => {
            if (!confirm('Wirklich löschen?')) return;
            const id = row.dataset.id;

            ajaxUpdate(id, 'active', 0)
            .then(data=>{
                if(data.status==='ok'){
                    row.classList.add('table-secondary');
                    row.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
                    row.querySelector('td:nth-child(8)').innerText = 'Gelöscht';
                    deleteBtn.outerHTML = '<button class="btn btn-sm btn-outline-success btn-restore">Wiederherstellen</button>';
                    updateStatsDOM(data.stats);
                }
            })
            .catch(err => console.error('Löschen fehlgeschlagen:', err));
        });
    }
}

function bindAllRows(){
    document.querySelectorAll('tbody tr').forEach(bindRowEvents);
}

let pollInterval = null;
function startPolling(form){
    if (!form) return;
    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(() => {
        const params = new URLSearchParams(new FormData(form));
        params.set('ajax_refresh', '1');
        refreshTable('liste.php?' + params.toString());
    }, 2000);
}

function refreshTable(url){
    fetch(url, {headers:{'Accept':'application/json'}, cache:'no-store'})
    .then(res=>{
        if (!res.ok) throw new Error('Server antwortete nicht OK');
        return res.json();
    })
    .then(data=>{
        if(data.status==='ok'){
            document.querySelector('tbody').innerHTML = data.html;
            updateStatsDOM(data.stats);
            bindAllRows();
        }
    })
    .catch(err => console.error('Liste laden fehlgeschlagen:', err));
}

window.addEventListener('DOMContentLoaded', function(){
    const filterForm = document.querySelector('form');
    if (filterForm) {
        filterForm.addEventListener('submit', function(e){
            e.preventDefault();
            const params = new URLSearchParams(new FormData(filterForm));
            params.set('ajax_refresh', '1');
            refreshTable('liste.php?' + params.toString());
            startPolling(filterForm);
        });
    }

    const resetLink = document.querySelector('a[href="liste.php"]');
    if (resetLink && filterForm) {
        resetLink.addEventListener('click', function(e){
            e.preventDefault();
            filterForm.reset();
            refreshTable('liste.php?ajax_refresh=1');
            startPolling(filterForm);
        });
    }

    if (filterForm) {
        refreshTable('liste.php?ajax_refresh=1&' + new URLSearchParams(new FormData(filterForm)).toString());
        startPolling(filterForm);
    }
});

document.addEventListener('click', function(e){
    if (e.target.classList.contains('btn-restore')) {
        const row = e.target.closest('tr');
        const id = row.dataset.id;

        ajaxUpdate(id, 'active', 1)
        .then(data=>{
            if(data.status==='ok'){
                row.classList.remove('table-secondary');
                row.querySelectorAll('input, select').forEach(el => el.disabled = false);
                const infoCell = row.querySelector('td:nth-child(8)');
                infoCell.innerText = infoCell.innerText.replace('Gelöscht','').replace('|','').trim();
                e.target.outerHTML = '<button class="btn btn-sm btn-danger btn-delete">Löschen</button>';
                updateRowClass(row);
                updateStatsDOM(data.stats);
            }
        })
        .catch(err => console.error('Wiederherstellung fehlgeschlagen:', err));
    }
});

bindAllRows();
</script>

</body>
</html>