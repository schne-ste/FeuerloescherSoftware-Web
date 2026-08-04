<?php
require 'config.php';

if (isset($_GET['ajax'])) {
    ajax();
    exit;
}

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

$db = getDB();

$eintrag = null;
$message = "";
$statusType = ""; // success, error, warning
$soundType = "";

$modus = $_POST['modus'] ?? "pruefen";  //abholen, pruefen
$nummer = $_POST['nummer'] ?? null;
$bedienmodus = $_POST['bedienmodus'] ?? "manuell"; //manuell, scanner

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// =====================
// AKTION
// =====================
if (isset($_POST['aktion']) && $nummer) {
    $nummerSafe = (int) $nummer;

    $check = $db->query("
        SELECT bezahlt, defekt, active 
        FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $row = $check->fetchArray();

    if (!$row || $row['active'] != 1) {
        $message = "&#128465; Gelöscht - keine Aktion möglich!";
        $statusType = "error";
        $soundType = "error";
    } else {

        if ($modus === "abholen") {
            $check = $db->query("
                SELECT bezahlt, defekt, active FROM loescher 
                WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
            ");
            $row = $check->fetchArray();

            if (!$row) {
                $message = "&#10060; Nummer nicht gefunden!";
                $statusType = "error";
                $soundType = "error";
            } elseif ($row['active'] && !$row['bezahlt'] && !$row['defekt']) {
                $message = "&#128176; Nicht bezahlt – zuerst kassieren!";
                $statusType = "error";
                $soundType = "warning";
            } else {
                // Status umschalten, egal ob defekt
                $db->exec("
                    UPDATE loescher 
                    SET abgeholt = CASE WHEN abgeholt = 1 THEN 0 ELSE 1 END
                    WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
                ");

                if ($row['defekt']) {
                    $message = "&#9888; Löscher defekt – Status trotzdem geändert!";
                    $statusType = "warning";
                    $soundType = "warning";
                } else {
                    $message = "&#9989; Abholung erfolgreich!";
                    $statusType = "success";
                    $soundType = "success";
                }
            }
        }

        if ($modus === "pruefen") {
            $db->exec("
                UPDATE loescher 
                SET geprueft = CASE WHEN geprueft = 1 THEN 0 ELSE 1 END
                WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
            ");
            $message = "&#9989; Prüfung erledigt!";
            $statusType = "success";
            $soundType = "success";
        }
    }
}

// =====================
// DATEN LADEN
// =====================
if ($nummer) {
    $nummerSafe = (int) $nummer;

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    if (!$eintrag) {
        $message = "&#10060; Nummer nicht gefunden!";
        $statusType = "error";
        $soundType = "error";
    }
}

// =====================
// INFO SETZEN (Schaummittel)
// =====================
if (isset($_POST['setInfo']) && $nummer) {
    $nummerSafe = (int) $nummer;

    $stmt = $db->prepare("
        UPDATE loescher 
        SET info = 
            CASE 
                WHEN info IS NULL OR info = '' 
                THEN :text
                ELSE info || :text
            END
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");
    $stmt->bindValue(':text', "\nSchaummittel muss getauscht werden - Kundenentscheidung erforderlich");
    $stmt->bindValue(':nummer', $nummerSafe);
    $stmt->execute();

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    $message = "&#9888; Hinweis gesetzt (Schaummittel)!";
    $statusType = "warning";
    $soundType = "warning";
}

// =====================
// INFO SETZEN (Umbau nötig)
// =====================
if (isset($_POST['setUmbau']) && $nummer) {
    $nummerSafe = (int) $nummer;

    $stmt = $db->prepare("
        UPDATE loescher 
        SET info = 
            CASE 
                WHEN info IS NULL OR info = '' 
                THEN :text
                ELSE info || :text
            END
        WHERE CAST(nummer AS INTEGER) = :nummer
    ");
    $stmt->bindValue(':text', "\nUmbau des Löschers ist nötig - Kundenentscheidung erforderlich");
    $stmt->bindValue(':nummer', $nummerSafe);
    $stmt->execute();

    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    $message = "&#9888; Hinweis gesetzt (Umbau nötig)!";
    $statusType = "warning";
    $soundType = "warning";
}

// =====================
// DEFEKT SETZEN
// =====================
if (isset($_POST['setDefekt']) && $nummer) {
    $nummerSafe = (int) $nummer;

    $db->exec("
        UPDATE loescher
        SET defekt = CASE WHEN defekt = 1 THEN 0 ELSE 1 END
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");

    $result = $db->query("
        SELECT * FROM loescher
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");
    $eintrag = $result->fetchArray();

    if ($eintrag['defekt']) {
        $message = "&#9940; Datensatz als defekt markiert!";
        $statusType = "error";
        $soundType = "warning";
    } else {
        $message = "&#9989; Defekt zurückgesetzt!";
        $statusType = "success";
        $soundType = "success";
    }
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

    <style>
        body.flash-success {
            background-color: #d4edda !important;
        }

        body.flash-error {
            background-color: #f8d7da !important;
        }

        body.flash-warning {
            background-color: #fff3cd !important;
        }

        @keyframes blink {
            0% {
                background-color: #f8d7da;
            }

            50% {
                background-color: #ffffff;
            }

            100% {
                background-color: #f8d7da;
            }
        }

        .flash-error {
            animation: blink 0.4s ease-in-out 2;
        }
    </style>
</head>

<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <span class="navbar-brand">
                <img src="./images/Feuerlöscher.ico" alt="Feuerlöscher" width="24" height="24" class="me-2">
                &#128293; Feuerlöscher Software - &#128230; &#129514; Abhol- / Prüfstation
            </span>

            <div class="d-flex gap-2">
                <a href="index.php" class="btn btn-outline-light btn-sm">
                    Start
                </a>
                <a href="?logout=1" class="btn btn-danger btn-sm">
                    Abmelden
                </a>
            </div>
        </div>
    </nav>

    <div class="container mt-4">

        <div class="row">

            <div class="col-md-3">

                <form method="post" id="mainForm" class="card shadow p-3 mb-4">

                    <div class="mb-2">
                        <label class="form-label small">Station</label>
                        <select name="modus" class="form-select form-select-sm" onchange="submitForm()">
                            <option value="abholen" <?= $modus === 'abholen' ? 'selected' : '' ?>>
                                &#128230; Abholstation
                            </option>
                            <option value="pruefen" <?= $modus === 'pruefen' ? 'selected' : '' ?>>
                                &#129514; Prüfstation
                            </option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">Modus</label>
                        <select name="bedienmodus" id="bedienmodus" class="form-select form-select-sm"
                            onchange="submitForm()">
                            <option value="scanner" <?= $bedienmodus === 'scanner' ? 'selected' : '' ?>>
                                &#9889; Scanner
                            </option>
                            <option value="manuell" <?= $bedienmodus === 'manuell' ? 'selected' : '' ?>>
                                &#9000; Manuell
                            </option>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label small">&#128269; Nummer</label>
                        <input type="text" name="nummer" id="nummerInput" class="form-control form-control-sm"
                            inputmode="numeric" value="<?= htmlspecialchars($nummer ?? '') ?>">
                    </div>

                    <div class="d-grid gap-2 mt-2">
                        <button type="button" id="loadDataBtn" class="btn btn-primary btn-sm">
                            &#128260; Neu laden
                        </button>

                        <button type="button" id="clearBtn" class="btn btn-outline-secondary btn-sm">
                            &#128465; Formular leeren
                        </button>

                    </div>

                </form>

                <?php if ($message): ?>
                    <div
                        class="alert alert-<?= $statusType === 'error' ? 'danger' : ($statusType === 'success' ? 'success' : 'warning') ?> p-2 small">
                        <?= $message ?>
                    </div>
                <?php endif; ?>

            </div>


            <!-- RECHTE SPALTE (groß) -->
            <div class="col-md-9">

                <?php if ($eintrag && $modus): ?>
                    <div class="card p-4">

                        <h3 class="mb-3">
                            Details - <strong><?= htmlspecialchars($eintrag['nummer']) ?></strong>

                            <?php if (!$eintrag['active']): ?>
                                <span class="badge bg-danger ms-2">&#128465; GELÖSCHT - KEINE ÄNDERUNG MÖGLICH</span>
                            <?php endif; ?>
                            <?php if ($modus === "abholen" && !$eintrag['bezahlt'] && !$eintrag['defekt'] && $eintrag['active']): ?>
                                <span class="badge bg-danger ms-2">&#128176; NICHT BEZAHLT → Zur Kassa</span>
                            <?php endif; ?>
                        </h3>

                        <h5><strong>Name:</strong> <?= htmlspecialchars($eintrag['name']) ?></h5>

                        <h5><strong>Erstellt:</strong>
                            <span>
                                <?= !empty($eintrag['zeitstempel'])
                                    ? date('d.m.Y H:i', strtotime($eintrag['zeitstempel']))
                                    : '-' ?>
                            </span>
                        </h5>
                        <hr>

                        <div id="paymentStatusBox">
                            <?php if ($modus === "abholen" && $eintrag['active'] && !$eintrag['bezahlt'] && !$eintrag['defekt']): ?>
                                <div class="alert alert-danger">
                                    <h4>&#128176; NICHT BEZAHLT → Zur Kassa</h4>
                                    <div>Als OK geprüft, muss somit bezahlt werden!</div>
                                </div>
                            <?php elseif ($modus === "abholen" && $eintrag['active'] && $eintrag['bezahlt'] && $eintrag['defekt']): ?>
                                <div class="alert alert-warning">
                                    <h4>&#9888; Defekt – Kunde bekommt Geld retour (Kassa) ODER Enstsorgung!</h4>
                                </div>
                            <?php endif; ?>
                        </div>
                        <br>
                        <?php if ($eintrag['active']): ?>
                            <h4 class="d-flex mb-2">
                                <span class="me-2" style="width:160px;">Prüfstatus:</span>

                                <span id="pruefStatusBox">
                                    <?php
                                    if ($eintrag['geprueft']) {
                                        if (!empty($eintrag['defekt'])) {
                                            $status1 = '<span class="badge border border-success text-success bg-transparent">Geprüft</span>';
                                        } else {
                                            $status1 = '<span class="badge bg-success">Geprüft</span>';
                                        }
                                    } else {
                                        $status1 = '<span class="badge bg-warning text-dark">Nicht geprüft</span>';
                                    }

                                    $status2 = empty($eintrag['defekt'])
                                        ? '<span class="badge bg-success">OK</span>'
                                        : '<span class="badge bg-danger">DEFEKT</span>';

                                    if ($eintrag['geprueft']) {
                                        echo $status1 . '&nbsp;-&nbsp;' . $status2;
                                    } else {
                                        echo $status1;
                                    }
                                    ?>
                                </span>
                            </h4>
                            <span id="loescherStatusBox" style="display:none;"></span>
                            <br>

                            <h4 class="d-flex mb-2">
                                <span class="me-2" style="width:160px;">Abholstatus:</span>

                                <span id="lagerStatusBox">
                                    <?= $eintrag['abgeholt']
                                        ? '<span class="badge bg-success">Abgeholt</span>'
                                        : '<span class="badge bg-warning text-dark">Nicht abgeholt</span>' ?>
                                </span>
                            </h4>

                            <hr>
                            <div id="infoBox">
                                <?php if (!empty($eintrag['info'])): ?>
                                    <div class="alert alert-warning mt-3">
                                        <strong>&#9888; Hinweis:</strong><br>
                                        <h4><?= nl2br(htmlspecialchars($eintrag['info'])) ?></h4>
                                    </div>
                                <?php endif; ?>
                            </div>


                            <!-- BUTTONS -->
                            <?php if ($modus === "pruefen"): ?>
                                <div class="row gap-1">
                                    <div class="col">
                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                                            <input type="hidden" name="modus" value="<?= $modus ?>">
                                            <input type="hidden" name="bedienmodus" value="<?= $bedienmodus ?>">

                                            <button type="submit" name="setInfo" class="btn btn-warning w-100">
                                                <strong>&#9888; Schaummittel tauschen</strong>
                                            </button>
                                        </form>
                                    </div>

                                    <div class="col">
                                        <form method="post" class="mt-3">
                                            <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                                            <input type="hidden" name="modus" value="<?= $modus ?>">
                                            <input type="hidden" name="bedienmodus" value="<?= $bedienmodus ?>">

                                            <button type="submit" name="setUmbau" class="btn btn-warning w-100">
                                                <strong>&#128295; Umbau nötig</strong>
                                            </button>
                                        </form>
                                    </div>

                                    <?php if ($eintrag['geprueft']): ?>
                                        <div class="col">
                                            <form method="post" class="mt-3">
                                                <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                                                <input type="hidden" name="modus" value="<?= $modus ?>">
                                                <input type="hidden" name="bedienmodus" value="<?= $bedienmodus ?>">

                                                <button id="defektBtn" type="submit" name="setDefekt"
                                                    class="btn w-100 <?= $eintrag['defekt'] ? 'btn-secondary' : 'btn-danger' ?>">

                                                    <strong><?= $eintrag['defekt']
                                                        ? '&#128295; Defekt zurücksetzen'
                                                        : '&#9940; Löscher defekt' ?></strong>
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>


                            <?php if ($bedienmodus === "manuell"): ?>
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="nummer" value="<?= $eintrag['nummer'] ?>">
                                    <input type="hidden" name="modus" value="<?= $modus ?>">
                                    <input type="hidden" name="bedienmodus" value="manuell">

                                    <button id="actionBtn" type="submit" name="aktion" value="1"
                                        class="btn btn-success btn-lg w-100" style="font-size: 1.5rem; padding: 1rem;"
                                        <?= (!$eintrag['active'] || ($modus === "abholen" && !$eintrag['bezahlt'] && !$eintrag['defekt'])) ? 'disabled' : '' ?>>

                                        <div>
                                            <strong>
                                                &#128260;
                                                <?= $modus === "pruefen"
                                                    ? "Prüfstatus ändern"
                                                    : "Abholstatus ändern" ?>
                                            </strong>
                                        </div>

                                        <div style="font-size: 0.9rem; opacity: 0.85;">
                                            (oder Leertaste drücken)
                                        </div>
                                    </button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                <?php endif; ?>

            </div>

        </div>


        <script>
            const input = document.getElementById("nummerInput");
            const form = document.getElementById("mainForm");
            const bedienmodus = document.getElementById("bedienmodus");
            const clearBtn = document.getElementById("clearBtn");

            if (clearBtn) {
                clearBtn.addEventListener("click", function () {
                    // 1. Eingabefeld leeren
                    input.value = "";

                    // 2. Formular absenden (löst den PHP-Reset aus)
                    form.submit();
                });
            }

            function submitForm() {
                form.submit();
            }

            input.addEventListener("input", function () {
                this.value = this.value.replace(/\D/g, '');
            });

            input.addEventListener("keypress", function (e) {
                if (e.key === "Enter") {

                    if (bedienmodus.value !== "scanner") return;
                    e.preventDefault();

                    if (input.value.trim() === "") return;

                    let hidden = document.createElement("input");
                    hidden.type = "hidden";
                    hidden.name = "aktion";
                    hidden.value = "1";
                    form.appendChild(hidden);
                    form.submit();
                }
            });
            const loadDataBtn = document.getElementById("loadDataBtn");

            if (loadDataBtn) {
                loadDataBtn.addEventListener("click", function () {
                    if (input.value.trim() === "") return;
                    form.submit();
                });
            }

            document.addEventListener("keydown", function (e) {

                // =====================
                // ESC = Formular leeren + Fokus
                // =====================
                if (e.key === "Escape") {
                    e.preventDefault();

                    // gleich wie "Formular leeren"-Button
                    input.value = "";

                    // Fokus + Auswahl direkt setzen
                    input.focus();
                    input.select();

                    form.submit();
                }

                // =====================
                // SPACE = Aktion
                // =====================
                if (e.code === "Space") {
                    e.preventDefault(); // verhindert Scrollen!
                    if (input.value.trim() === "") return;

                    let hidden = document.createElement("input");
                    hidden.type = "hidden";
                    hidden.name = "aktion";
                    hidden.value = "1";
                    form.appendChild(hidden);

                    form.submit();
                }
            });


            const number = <?= isset($nummerSafe) ? $nummerSafe : 'null' ?>;

            window.onload = function () {
                const soundType = "<?= $soundType ?>";

                if (soundType === "success") {
                    document.body.classList.add("flash-success");
                    playTone("success");
                }

                if (soundType === "error") {
                    document.body.classList.add("flash-error");
                    playTone("error");
                }

                if (soundType === "warning") {
                    document.body.classList.add("flash-warning");
                    playTone("warning");
                }

                setTimeout(() => {
                    document.body.classList.remove("flash-success", "flash-error", "flash-warning");
                }, 600);

                input.focus();
                input.select();
            };

            // Web Audio API Töne
            function playTone(type) {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = ctx.createOscillator();
                const gainNode = ctx.createGain();

                oscillator.connect(gainNode);
                gainNode.connect(ctx.destination);

                oscillator.type = 'sine';
                gainNode.gain.value = 0.2;

                switch (type) {
                    case 'success':
                        oscillator.frequency.value = 880; // hoher Ton
                        break;
                    case 'error':
                        oscillator.frequency.value = 220; // tiefer Ton
                        break;
                    case 'warning':
                        oscillator.frequency.value = 440; // mittlerer Ton
                        break;
                }

                oscillator.start();
                setTimeout(() => oscillator.stop(), 150);
            }

            function loadStatus() {
                if (!number) return;

                fetch("abhol_or_pruefung.php?nummer=" + number + "&ajax")
                    .then(res => res.json())
                    .then(data => {
                        if (!data || data.error) return;

                        updateUI(data);
                    })
                    .catch(err => console.error(err));
            }

            // alle 2 Sekunden aktualisieren
            setInterval(loadStatus, 2000);

            function updateUI(data) {

                // =====================
                // PRÜFSTATUS
                // =====================
                let status1 = "";
                let status2 = "";

                if (data.geprueft == 1) {
                    if (data.defekt == 1) {
                        status1 = '<span class="badge border border-success text-success bg-transparent">Geprüft</span>';
                    } else {
                        status1 = '<span class="badge bg-success">Geprüft</span>';
                    }
                } else {
                    status1 = '<span class="badge bg-warning text-dark">Nicht geprüft</span>';
                }

                status2 = data.defekt == 1
                    ? '<span class="badge bg-danger">DEFEKT</span>'
                    : '<span class="badge bg-success">OK</span>';

                let pruefStatusHTML = data.geprueft == 1
                    ? status1 + '&nbsp;-&nbsp;' + status2
                    : status1;

                const pruefStatusBox = document.getElementById("pruefStatusBox");
                if (pruefStatusBox) {
                    pruefStatusBox.innerHTML = pruefStatusHTML;
                }


                // =====================
                // LÖSCHERSTATUS (intern)
                // =====================
                const loescherBox = document.getElementById("loescherStatusBox");
                if (loescherBox) {
                    loescherBox.innerHTML = status2;
                }


                // =====================
                // ABHOLSTATUS
                // =====================
                let lagerHTML = data.abgeholt == 1
                    ? '<span class="badge bg-success">Abgeholt</span>'
                    : '<span class="badge bg-warning text-dark">Nicht abgeholt</span>';

                const lagerBox = document.getElementById("lagerStatusBox");
                if (lagerBox) {
                    lagerBox.innerHTML = lagerHTML;
                }


                // =====================
                // PAYMENT BOX
                // =====================
                const paymentBox = document.getElementById("paymentStatusBox");
                if (paymentBox && "<?= $modus ?>" === "abholen") {
                    let paymentHTML = '';

                    if (data.active == 1 && data.bezahlt == 0 && data.defekt == 0) {
                        paymentHTML = '<div class="alert alert-danger"><h4>&#128176; NICHT BEZAHLT → Zur Kassa</h4><div>Als OK geprüft, muss somit bezahlt werden!</div></div>';
                    } else if (data.active == 1 && data.bezahlt == 1 && data.defekt == 1) {
                        paymentHTML = '<div class="alert alert-warning"><h4>&#9888; Defekt – Kunde bekommt Geld retour (Kassa)!</h4></div>';
                    }

                    paymentBox.innerHTML = paymentHTML;
                }


                // =====================
                // INFO
                // =====================
                const infoBox = document.getElementById("infoBox");
                if (infoBox) {
                    if (data.info) {
                        let formattedInfo = data.info.replace(/\n/g, '<br>');
                        infoBox.innerHTML =
                            '<div class="alert alert-warning mt-3"><strong>&#9888; Hinweis:</strong><br><h4>' +
                            formattedInfo +
                            '</h4></div>';
                    } else {
                        infoBox.innerHTML = '';
                    }
                }

                // =====================
                // BUTTONS UPDATE
                // =====================

                // Defekt-Button
                const defektBtn = document.getElementById("defektBtn");
                if (defektBtn) {

                    if (data.defekt == 1) {
                        defektBtn.classList.remove("btn-danger");
                        defektBtn.classList.add("btn-secondary");
                        defektBtn.innerHTML = "<strong>&#128295; Defekt zurücksetzen</strong>";
                    } else {
                        defektBtn.classList.remove("btn-secondary");
                        defektBtn.classList.add("btn-danger");
                        defektBtn.innerHTML = "<strong>&#9940; Löscher defekt</strong>";
                    }
                }
                if (defektBtn) {
                    if (data.geprueft == 1) {
                        defektBtn.closest("form").style.display = "block";
                    } else {
                        defektBtn.closest("form").style.display = "none";
                    }
                }


                // Status-Button (Abholen / Prüfen)
                const actionBtn = document.getElementById("actionBtn");
                if (actionBtn) {

                    let disable = false;

                    // gleiche Logik wie PHP!
                    if (data.active == 0) {
                        disable = true;
                    }

                    if (data.active == 1 && data.bezahlt == 0 && data.defekt == 0 && "<?= $modus ?>" === "abholen") {
                        disable = true;
                    }

                    actionBtn.disabled = disable;
                }
            }
            // ALLE Meldungen nach x Sekunden schließen
            document.addEventListener('DOMContentLoaded', function () {
                const allAlerts = document.querySelectorAll('.alert');

                allAlerts.forEach(function (alert) {
                    setTimeout(function () {
                        if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                            let bsAlert = new bootstrap.Alert(alert);
                            bsAlert.close();
                        } else {
                            alert.style.display = 'none';
                        }
                    }, 3000);
                });
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
    $nummer = $_GET['nummer'] ?? null;

    if (!$nummer) {
        header('Content-Type: application/json');
        echo json_encode(["error" => "keine nummer"]);
        exit;
    }

    $nummerSafe = (int) $nummer;

    // Daten abrufen
    $result = $db->query("
        SELECT * FROM loescher 
        WHERE CAST(TRIM(nummer) AS INTEGER) = $nummerSafe
    ");

    $eintrag = $result ? $result->fetchArray(SQLITE3_ASSOC) : null;

    header('Content-Type: application/json');

    if ($eintrag) {
        echo json_encode($eintrag);
    } else {
        echo json_encode(["error" => "nicht gefunden"]);
    }
}