<?php
require 'config.php';

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$db = getDB();

// =====================
// DATEN ABFRAGEN
// =====================
$query = "SELECT * FROM rechnungen ORDER BY zeitstempel_erstellung DESC";
$result = $db->query($query);

// Array für die Anzeige
$rechnungen = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rechnungen[] = $row;
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>&#128196; Rechnungsübersicht</title>
    <link rel="icon" href="./images/Feuerlöscher.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table-hover tbody tr {
            cursor: pointer;
            transition: background 0.2s;
        }
        .table-hover tbody tr:hover {
            background-color: #f0f4f8 !important;
        }
        .status-badge {
            width: 100px;
        }
        .search-container {
            background: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand">
            <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
            &#128293; Feuerlöscher Software - Übersicht
        </span>
        <div class="d-flex gap-2">
            <a href="rechnung.php" class="btn btn-success btn-sm">+ Neue Rechnung</a>
            <a href="index.php" class="btn btn-outline-light btn-sm">Zurück</a>
        </div>
    </div>
</nav>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Alle Rechnungen</h2>
        <span class="badge bg-secondary"><?= count($rechnungen) ?> Einträge gesamt</span>
    </div>

    <div class="search-container mb-4">
        <div class="input-group">
            <span class="input-group-text">&#128269;</span>
            <input type="text" id="tableSearch" class="form-control" placeholder="Nach Namen, Rechnungsnummer oder Ort suchen...">
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="rechnungsTabelle">
                <thead class="table-dark">
                    <tr>
                        <th>Nr.</th>
                        <th>Name</th>
                        <th>Adresse / Ort</th>
                        <th class="text-center">Löscher</th>
                        <th class="text-end">Betrag</th>
                        <th class="text-center">Zahlungsart</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rechnungen as $r): 
                        $gesamt = $r['anzahl_loescher'] * $r['preis_pro_loescher'];
                    ?>
                    <tr onclick="window.location.href='rechnung.php?id=<?= $r['id'] ?>'">
                        <td class="fw-bold"><?= htmlspecialchars($r['rechnungsnummer']) ?></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td>
                            <small class="text-muted">
                                <?= htmlspecialchars($r['adresse']) ?><br>
                                <?= htmlspecialchars($r['plz']) ?> <?= htmlspecialchars($r['ort']) ?>
                            </small>
                        </td>
                        <td class="text-center"><?= $r['anzahl_loescher'] ?> Stk.</td>
                        <td class="text-end fw-bold"><?= number_format($gesamt, 2, ',', '.') ?> €</td>
                        <td class="text-center">
                            <span class="badge border text-dark bg-light"><?= htmlspecialchars($r['zahlungsart']) ?></span>
                        </td>
                        <td class="text-center">
                            <?php if ($r['bezahlt']): ?>
                                <span class="badge bg-success status-badge">&#9989; Bezahlt</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark status-badge">&#9203; Offen</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Echtzeit-Suche in der Tabelle
document.getElementById('tableSearch').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let rows = document.querySelectorAll('#rechnungsTabelle tbody tr');

    rows.forEach(row => {
        let text = row.innerText.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>