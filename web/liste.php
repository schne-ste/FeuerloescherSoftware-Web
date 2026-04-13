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

function getStats($db) {
    $stats = [];
    // Nur aktive Löscher zählen
    $stats['gesamt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE active = 1");
    
    $stats['geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND active = 1");
    $stats['nicht_geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 0 AND active = 1");
    
    $stats['abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 1 AND active = 1");
    $stats['nicht_abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 0 AND active = 1");
    
    $stats['bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 1 AND active = 1");
    $stats['nicht_bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 0 AND active = 1");
    
    $stats['ok'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND defekt = 0 AND active = 1");
    $stats['defekt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 1 AND active = 1");
    
    // Prozente basierend auf den neuen aktiven Zahlen berechnen
    $stats['p_defekt'] = percent($stats['defekt'], $stats['gesamt']);
    $stats['p_geprueft'] = percent($stats['geprueft'], $stats['gesamt']);
    $stats['p_abgeholt'] = percent($stats['abgeholt'], $stats['gesamt']);
    $stats['p_bezahlt'] = percent($stats['bezahlt'], $stats['gesamt']);
    
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

        // LÖSCHEN
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

        // WIEDERHERSTELLEN
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

    echo json_encode(['status'=>'ok', 'stats'=>getStats($db)]);
    exit;
}

// =====================
// INITIALE DATEN
// =====================
$stats = getStats($db);

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
</head>

<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - &#128196; Liste aller Löscher
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
                <h6 class="mb-1"><strong>Geprüft</strong></h6>
                <small id="stat-geprueft">✅ <?= $stats['geprueft'] ?> | ❌ <?= $stats['nicht_geprueft'] ?></small>
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
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Bezahlt</strong></h6>
                <small id="stat-bezahlt">✅ <?= $stats['bezahlt'] ?> | ❌ <?= $stats['nicht_bezahlt'] ?></small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-2">
        <div class="card text-center bg-success text-white mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>OK/DEFEKT</strong></h6>
                <small id="stat-ok">✅ <?= $stats['ok'] ?> | ❌ <?= $stats['defekt'] ?></small>
            </div>
        </div>
    </div>
</div>

<div class="mb-4">
    <label>Geprüft (<span id="label-p-geprueft"><?= $stats['p_geprueft'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-success" id="bar-geprueft" style="width: <?= $stats['p_geprueft'] ?>%"></div></div>

    <label>Abgeholt (<span id="label-p-abgeholt"><?= $stats['p_abgeholt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-info" id="bar-abgeholt" style="width: <?= $stats['p_abgeholt'] ?>%"></div></div>

    <label>Bezahlt (<span id="label-p-bezahlt"><?= $stats['p_bezahlt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-dark" id="bar-bezahlt" style="width: <?= $stats['p_bezahlt'] ?>%"></div></div>

    <label>Defekt (<span id="label-p-defekt"><?= $stats['p_defekt'] ?></span>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-danger" id="bar-defekt" style="width: <?= $stats['p_defekt'] ?>%"></div></div>
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

<table class="table table-bordered table-striped align-middle">
    <thead>
        <tr><th>ID</th><th>Name</th><th>Typ</th><th>Bezahlt</th><th>Defekt</th><th>Geprüft</th><th>Abgeholt</th><th>Info</th><th>Aktion</th></tr>
    </thead>
    <tbody>
    <?php while ($row = $result->fetchArray()): 
        $rowClass = !$row['geprueft'] ? "table-warning" : (!$row['abgeholt'] ? "table-warning" : "table-info");
        if ($row['geprueft'] && $row['abgeholt'] && $row['bezahlt'] && !$row['defekt']) $rowClass="table-success";
        if ($row['defekt']) $rowClass="table-danger";
        
        // Prüfen ob deaktiviert
        $disabled = ($row['active'] == 0) ? 'disabled' : '';
    ?>
    <tr data-id="<?= $row['id'] ?>" class="<?= $rowClass ?> <?= !$row['active']?'row-inactive':'' ?>">
        <td><?= htmlspecialchars($row['nummer']) ?></td>
        <td><?= htmlspecialchars($row['name']) ?></td>
        <td>
            <select class="form-select typ-select" <?= $disabled ?>>
                <option value="Standard" <?= $row['typ']=="Standard"?"selected":"" ?>>Standard (<?= PREIS_STANDARD ?> €)</option>
                <option value="Rabatt" <?= $row['typ']=="Rabatt"?"selected":"" ?>>Rabatt (<?= PREIS_RABATT ?> €)</option>
                <option value="Gratis" <?= $row['typ']=="Gratis"?"selected":"" ?>>Gratis (<?= PREIS_GRATIS ?> €)</option>
            </select>
        </td>
        <td><input type="checkbox" class="cb-bezahlt" <?= $row['bezahlt']?'checked':'' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-defekt" <?= $row['defekt']?'checked':'' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-geprueft" <?= $row['geprueft']?'checked':'' ?> <?= $disabled ?>></td>
        <td><input type="checkbox" class="cb-abgeholt" <?= $row['abgeholt']?'checked':'' ?> <?= $disabled ?>></td>
        <td><?= nl2br(htmlspecialchars($row['info'])) ?></td>
        <td>
            <?php if ($row['active']): ?>
                <button class="btn btn-sm btn-danger btn-delete">Löschen</button>
            <?php else: ?>
                <span class="badge bg-secondary">Gelöscht</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endwhile; ?>
    </tbody>
    </table>

</div>

<script>
// Hilfsfunktion für Zeilenfarben (Exakt nach Logik der Vorgabe)
function updateRowClass(row){
    const geprueft = row.querySelector('.cb-geprueft').checked;
    const abgeholt = row.querySelector('.cb-abgeholt').checked;
    const bezahlt = row.querySelector('.cb-bezahlt').checked;
    const defekt = row.querySelector('.cb-defekt').checked;

    row.classList.remove('table-danger','table-warning','table-info','table-success');

    if (defekt) {
        row.classList.add('table-danger');
    } else if (geprueft && abgeholt && bezahlt) {
        row.classList.add('table-success');
    } else if (!geprueft || !abgeholt) {
        row.classList.add('table-warning');
    } else {
        row.classList.add('table-info');
    }
}

// Live-Update der Statistik-Anzeige
function updateStatsDOM(stats){
    document.getElementById('stat-gesamt').innerText = stats.gesamt;
    document.getElementById('stat-geprueft').innerText = `✅ ${stats.geprueft} | ❌ ${stats.nicht_geprueft}`;
    document.getElementById('stat-abgeholt').innerText = `✅ ${stats.abgeholt} | ❌ ${stats.nicht_abgeholt}`;
    document.getElementById('stat-bezahlt').innerText = `✅ ${stats.bezahlt} | ❌ ${stats.nicht_bezahlt}`;
    document.getElementById('stat-ok').innerText = `✅ ${stats.ok} | ❌ ${stats.defekt}`;

    // Balken und Prozent-Labels
    document.getElementById('bar-geprueft').style.width = stats.p_geprueft+'%';
    document.getElementById('label-p-geprueft').innerText = stats.p_geprueft;
    
    document.getElementById('bar-abgeholt').style.width = stats.p_abgeholt+'%';
    document.getElementById('label-p-abgeholt').innerText = stats.p_abgeholt;

    document.getElementById('bar-bezahlt').style.width = stats.p_bezahlt+'%';
    document.getElementById('label-p-bezahlt').innerText = stats.p_bezahlt;

    document.getElementById('bar-defekt').style.width = stats.p_defekt+'%';
    document.getElementById('label-p-defekt').innerText = stats.p_defekt;
}

// AJAX LIVE UPDATE
document.querySelectorAll('tbody tr').forEach(row=>{
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

            let value = el.type==='checkbox' ? (el.checked?1:0) : el.value;

            fetch('liste.php', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:`ajax_update=1&id=${id}&field=${field}&value=${encodeURIComponent(value)}`
            })
            .then(res=>res.json())
            .then(data=>{
                if(data.status==='ok'){
                    updateRowClass(row);
                    updateStatsDOM(data.stats);
                }
            })
            .catch(err => console.error("Update fehlgeschlagen:", err));
        });
    });
});

// DELETE BUTTON
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', () => {
        if (!confirm('Wirklich löschen?')) return;

        const row = btn.closest('tr');
        const id = row.dataset.id;

        fetch('liste.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax_update=1&id=${id}&field=active&value=0`
        })
        .then(res=>res.json())
        .then(data=>{
            if(data.status==='ok'){
                row.classList.add('table-secondary');
                row.querySelectorAll('input, select, button').forEach(el => el.disabled = true);

                // Info setzen
                row.querySelector('td:nth-child(8)').innerText = "Gelöscht";

                // Button ersetzen
                btn.outerHTML = '<button class="btn btn-sm btn-outline-success btn-restore">Wiederherstellen</button>';

                updateStatsDOM(data.stats);
            }
        });
    });
});

// RESTORE BUTTON
document.addEventListener('click', function(e){
    if(e.target.classList.contains('btn-restore')){

        const row = e.target.closest('tr');
        const id = row.dataset.id;

        fetch('liste.php', {
            method:'POST',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:`ajax_update=1&id=${id}&field=active&value=1`
        })
        .then(res=>res.json())
        .then(data=>{
            if(data.status==='ok'){

                row.classList.remove('table-secondary');
                row.querySelectorAll('input, select').forEach(el => el.disabled = false);

                // Info bereinigen
                let infoCell = row.querySelector('td:nth-child(8)');
                infoCell.innerText = infoCell.innerText.replace('Gelöscht','').replace('|','').trim();

                // Button zurück
                e.target.outerHTML = '<button class="btn btn-sm btn-danger btn-delete">Löschen</button>';

                updateRowClass(row);
                updateStatsDOM(data.stats);
            }
        });
    }
});
</script>

</body>
</html>