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
// AJAX UPDATES
// =====================
if (isset($_POST['ajax_update'])) {
    $id = (int)$_POST['id'];
    $field = $_POST['field'];
    $value = $_POST['value'];

    // Definiere erlaubte Felder für AJAX
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
        $stmt = $db->prepare("UPDATE loescher SET $field=:value WHERE id=:id");
        $stmt->bindValue(':value', $value, SQLITE3_INTEGER);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
    }
    echo "ok";
    exit;
}

// =====================
// STATISTIK
// =====================
function percent($teil, $gesamt) {
    return $gesamt > 0 ? round(($teil / $gesamt) * 100) : 0;
}

$stats = [];
$stats['gesamt'] = $db->querySingle("SELECT COUNT(*) FROM loescher");
$stats['geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1");
$stats['nicht_geprueft'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 0");
$stats['abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 1");
$stats['nicht_abgeholt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE abgeholt = 0");
$stats['bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 1");
$stats['nicht_bezahlt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE bezahlt = 0");
$stats['ok'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE geprueft = 1 AND defekt = 0");
$stats['defekt'] = $db->querySingle("SELECT COUNT(*) FROM loescher WHERE defekt = 1");
$stats['p_defekt'] = percent($stats['defekt'], $stats['gesamt']);
$stats['p_geprueft'] = percent($stats['geprueft'], $stats['gesamt']);
$stats['p_abgeholt'] = percent($stats['abgeholt'], $stats['gesamt']);
$stats['p_bezahlt'] = percent($stats['gesamt'] - $stats['nicht_bezahlt'], $stats['gesamt']);

// =====================
// FILTER
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
.table-danger { background-color: #f8d7da !important; }
.table-success { background-color: #d4edda !important; }
.table-warning { background-color: #fff3cd !important; }
.table-info { background-color: #d1ecf1 !important; }
.row-inactive { opacity: 0.4; pointer-events: none; }
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - Alle Löscher
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

    <!-- Gesamt -->
    <div class="col-6 col-md-2">
        <div class="card text-center bg-light mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Gesamt</strong></h6>
                <h5 class="mb-0"><?= $stats['gesamt'] ?></h5>
            </div>
        </div>
    </div>

    <!-- Geprüft / Nicht geprüft -->
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Geprüft</strong></h6>
                <small>✅ <?= $stats['geprueft'] ?> | ❌ <?= $stats['nicht_geprueft'] ?></small>
            </div>
        </div>
    </div>

    <!-- Abgeholt / Nicht abgeholt -->
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Abgeholt</strong></h6>
                <small>✅ <?= $stats['abgeholt'] ?> | ❌ <?= $stats['nicht_abgeholt'] ?></small>
            </div>
        </div>
    </div>

    <!-- Bezahlt / Nicht bezahlt -->
    <div class="col-6 col-md-2">
        <div class="card text-center bg-warning mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>Bezahlt</strong></h6>
                <small>✅ <?= $stats['bezahlt'] ?> | ❌ <?= $stats['nicht_bezahlt'] ?></small>
            </div>
        </div>
    </div>

    <!-- OK / Defekt -->
    <div class="col-6 col-md-2">
        <div class="card text-center bg-success text-white mb-2">
            <div class="card-body p-2">
                <h6 class="mb-1"><strong>OK/DEFEKT</strong></h6>
                <small>✅ <?= $stats['ok'] ?> | ❌ <?= $stats['defekt'] ?></small>
            </div>
        </div>
    </div>

</div>

<!-- STATISTIK KARTEN 
 <div class="row mb-4">
    <div class="col-6 col-md-2"><div class="card text-center bg-light"><div class="card-body"><h6>Gesamt</h6><h4><?= $stats['gesamt'] ?></h4></div></div></div>
</div>
<div class="row mb-4">
    <div class="col-6 col-md-2"><div class="card text-center bg-warning"><div class="card-body"><h6>Nicht geprüft</h6><h4><?= $stats['nicht_geprueft'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-warning"><div class="card-body"><h6>Nicht abgeholt</h6><h4><?= $stats['nicht_abgeholt'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-warning"><div class="card-body"><h6>Nicht bezahlt</h6><h4><?= $stats['nicht_bezahlt'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-danger text-white"><div class="card-body"><h6>Defekt</h6><h4><?= $stats['defekt'] ?></h4></div></div></div>
</div>
<div class="row mb-4">
    <div class="col-6 col-md-2"><div class="card text-center bg-success text-white"><div class="card-body"><h6>Geprüft</h6><h4><?= $stats['geprueft'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-success text-white"><div class="card-body"><h6>Abgeholt</h6><h4><?= $stats['abgeholt'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-success text-white"><div class="card-body"><h6>Bezahlt</h6><h4><?= $stats['bezahlt'] ?></h4></div></div></div>
    <div class="col-6 col-md-2"><div class="card text-center bg-success text-white"><div class="card-body"><h6>OK</h6><h4><?= $stats['ok'] ?></h4></div></div></div>
</div>-->

<!-- PROGRESSBALKEN -->
<div class="mb-4">
    <label>Geprüft (<?= $stats['p_geprueft'] ?>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-success" style="width: <?= $stats['p_geprueft'] ?>%"></div></div>

    <label>Abgeholt (<?= $stats['p_abgeholt'] ?>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-info" style="width: <?= $stats['p_abgeholt'] ?>%"></div></div>

    <label>Bezahlt (<?= $stats['p_bezahlt'] ?>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-dark" style="width: <?= $stats['p_bezahlt'] ?>%"></div></div>

    <label>Defekt (<?= $stats['p_defekt'] ?>%)</label>
    <div class="progress mb-2"><div class="progress-bar bg-danger" style="width: <?= $stats['p_defekt'] ?>%"></div></div>
</div>

<!-- FILTER -->
<form method="get" class="row g-1 mb-3 align-items-center">
    <div class="col-auto">
        <input type="text" name="suche" class="form-control form-control-sm me-3" style="width:150px;"
               placeholder="&#128269; Name/Nummer" value="<?= htmlspecialchars($_GET['suche'] ?? '') ?>">
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

<!-- TABELLE -->
<table class="table table-bordered table-striped align-middle">
<thead>
<tr>
<th>ID</th><th>Name</th><th>Typ</th><th>Bezahlt</th><th>Defekt</th><th>Geprüft</th><th>Abgeholt</th><th>Info</th><th>Aktion</th>
</tr>
</thead>
<tbody>
<?php while ($row = $result->fetchArray()): 
    $rowClass = !$row['geprueft'] ? "table-danger" : ($row['geprueft'] && !$row['abgeholt'] ? "table-warning" : ($row['abgeholt'] ? "table-info" : "table-success"));
?>
<tr data-id="<?= $row['id'] ?>" class="<?= $rowClass ?> <?= !$row['active']?'row-inactive':'' ?>">
<td><?= htmlspecialchars($row['nummer']) ?></td>
<td><?= htmlspecialchars($row['name']) ?></td>
<td>
<select class="form-select typ-select">
    <option value="Standard" <?= $row['typ']=="Standard"?"selected":"" ?>>Standard (<?= PREIS_STANDARD ?> €)</option>
    <option value="Rabatt" <?= $row['typ']=="Rabatt"?"selected":"" ?>>Rabatt (<?= PREIS_RABATT ?> €)</option>
    <option value="Gratis" <?= $row['typ']=="Gratis"?"selected":"" ?>>Gratis (<?= PREIS_GRATIS ?> €)</option>
</select>
</td>
<td><input type="checkbox" class="cb-bezahlt" <?= $row['bezahlt']?'checked':'' ?>></td>
<td><input type="checkbox" class="cb-defekt" <?= $row['defekt']?'checked':'' ?>></td>
<td><input type="checkbox" class="cb-geprueft" <?= $row['geprueft']?'checked':'' ?>></td>
<td><input type="checkbox" class="cb-abgeholt" <?= $row['abgeholt']?'checked':'' ?>></td>
<td><?= nl2br(htmlspecialchars($row['info'])) ?></td>
<td>
<?php if ($row['active']): ?>
<button class="btn btn-sm btn-danger btn-delete">Löschen</button>
<?php else: ?>
<span class="text-muted">Gelöscht</span>
<?php endif; ?>
</td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
</div>

<script>
function updateField(id, field, value, callback=null){
    const formData = new FormData();
    formData.append('ajax_update',1);
    formData.append('id',id);
    formData.append('field',field);
    formData.append('value',value);

    fetch('liste.php',{method:'POST',body:formData})
        .then(resp=>resp.text())
        .then(data=>{ if(callback) callback(); })
        .catch(err=>console.error(err));
}

// Checkboxen und Typ-Select
['geprueft','abgeholt','bezahlt','defekt'].forEach(field=>{
    document.querySelectorAll(`.cb-${field}`).forEach(cb=>{
        cb.addEventListener('change',()=>{
            const row = cb.closest('tr');
            updateField(row.dataset.id, field, cb.checked?1:0);
        });
    });
});

document.querySelectorAll('.typ-select').forEach(sel=>{
    sel.addEventListener('change',()=>{
        const row = sel.closest('tr');
        updateField(row.dataset.id,'typ',sel.value);
    });
});

// Löschen → active=0
document.querySelectorAll('.btn-delete').forEach(btn=>{
    btn.addEventListener('click',()=>{
        if(!confirm("Wirklich löschen?")) return;
        const row = btn.closest('tr');
        updateField(row.dataset.id,'active',0,()=>row.classList.add('row-inactive'));
    });
});
</script>

</body>
</html>