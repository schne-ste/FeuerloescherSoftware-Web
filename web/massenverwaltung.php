<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$db = getDB();

if (isset($_POST['status_aendern'])) {
    $start = (int)($_POST['start_nummer'] ?? 0);
    $ende  = (int)($_POST['end_nummer'] ?? 0);

    $bezahlt = $_POST['bezahlt'] ?? '';
    $geprueft = $_POST['geprueft'] ?? '';
    $abgeholt = $_POST['abgeholt'] ?? '';

    if (!$start || !$ende) {
        $successMessage = "&#10060; Bitte Start- und Endnummer eingeben!";
        $messageType = "danger";
    } else {
        $updates = [];
        if ($bezahlt !== '') $updates[] = "bezahlt = " . ((int)$bezahlt);
        if ($geprueft !== '') $updates[] = "geprueft = " . ((int)$geprueft);
        if ($abgeholt !== '') $updates[] = "abgeholt = " . ((int)$abgeholt);

        $info = $_POST['info'] ?? '';

        if ($info !== '') {
            $escapedInfo = SQLite3::escapeString($info);
            $updates[] = "info = CASE
              WHEN info IS NULL OR info = '' THEN '$escapedInfo'
              ELSE info || char(10) || '$escapedInfo'
            END";
        }

        if (count($updates) > 0) {
            $sql = implode(", ", $updates);
            $stmt = $db->prepare("UPDATE loescher SET $sql WHERE CAST(nummer AS INTEGER) BETWEEN :start AND :ende");
            $stmt->bindValue(':start', $start, SQLITE3_INTEGER);
            $stmt->bindValue(':ende', $ende, SQLITE3_INTEGER);
            $stmt->execute();

            $successMessage = "&#9989; Status/Info für Löscher $start bis $ende erfolgreich gesetzt!";
            $messageType = "success";
            //POST-Daten zurücksetzen
$_POST = [];
        } else {
            $successMessage = "&#10060; Bitte mindestens einen Status auswählen!";
            $messageType = "danger";
        }
    }
}

//Daten leeren
if (isset($_POST['daten_leeren'])) {
    unset($_POST['start_nummer'], $_POST['end_nummer'], $_POST['bezahlt'], $_POST['geprueft'], $_POST['abgeholt'], $_POST['info']);
}
?>

<div class="card shadow p-3 mb-4">

<?php if ($successMessage): ?>
<div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert" id="autoCloseAlert">
    <span class="me-2"></span>
    <?= $successMessage ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="accordion" id="massenVerwaltungAccordion">
  <div class="accordion-item">
    <h2 class="accordion-header" id="headingOne">
      <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMassen" aria-expanded="false" aria-controls="collapseMassen">
        &#9881; Massenverwaltung Feuerlöscher
      </button>
    </h2>
    <div id="collapseMassen" class="accordion-collapse collapse">
      <div class="accordion-body">
        <form method="post" class="card shadow p-3">

          <div class="row g-3 mb-3">
            <div class="col-md-3">
              <label class="form-label">&#128202; Start-Nummer <span class="text-danger">*</span></label>
              <input type="number" name="start_nummer" class="form-control" required value="<?= htmlspecialchars($_POST['start_nummer'] ?? '') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label">&#128202; End-Nummer <span class="text-danger">*</span></label>
              <input type="number" name="end_nummer" class="form-control" required value="<?= htmlspecialchars($_POST['end_nummer'] ?? '') ?>">
            </div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-2">
              <label class="form-label">&#128176; Bezahlt</label>
              <select name="bezahlt" class="form-select">
                <option value="" <?= (!isset($_POST['bezahlt'])) ? 'selected' : '' ?>>---</option>
                <option value="1" <?= (($_POST['bezahlt'] ?? '') === '1') ? 'selected' : '' ?>>&#9989; Ja</option>
                <option value="0" <?= (($_POST['bezahlt'] ?? '') === '0') ? 'selected' : '' ?>>&#10060; Nein</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">&#129514; Geprüft</label>
              <select name="geprueft" class="form-select">
                <option value="" <?= (!isset($_POST['geprueft'])) ? 'selected' : '' ?>>---</option>
                <option value="1" <?= (($_POST['geprueft'] ?? '') === '1') ? 'selected' : '' ?>>&#9989; Ja</option>
                <option value="0" <?= (($_POST['geprueft'] ?? '') === '0') ? 'selected' : '' ?>>&#10060; Nein</option>
              </select>
            </div>
            <div class="col-md-2">
              <label class="form-label">&#128230; Abgeholt</label>
              <select name="abgeholt" class="form-select">
                <option value="" <?= (!isset($_POST['abgeholt'])) ? 'selected' : '' ?>>---</option>
                <option value="1" <?= (($_POST['abgeholt'] ?? '') === '1') ? 'selected' : '' ?>>&#9989; Ja</option>
                <option value="0" <?= (($_POST['abgeholt'] ?? '') === '0') ? 'selected' : '' ?>>&#10060; Nein</option>
              </select>
            </div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label">&#128161; Info hinzufügen</label>
              <input type="text" name="info" class="form-control"
                    value="<?= htmlspecialchars($_POST['info'] ?? '') ?>">
            </div>
          </div>

          <div class="row g-3">
            <div class="col-md-2">
              <button type="submit" name="status_aendern" class="btn btn-primary w-100" onclick="return confirm('&#9888; Sind Sie sicher, dass die ausgewählten Status/Infos gesetzt werden sollen?');">&#128190; Speichern</button>
            </div>
            <div class="col-md-2">
              <button type="submit" name="daten_leeren" class="btn btn-secondary w-100">&#128465; Daten leeren</button>
            </div>
          </div>

        </form>
      </div>
    </div>
  </div>
</div>