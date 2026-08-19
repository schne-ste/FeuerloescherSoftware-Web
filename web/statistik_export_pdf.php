<?php
require 'config.php';
require_once('tcpdf/tcpdf.php');

if (!isset($_SESSION['logged_in'])) {
    header("Location: login.php");
    exit;
}

class MyPDF extends TCPDF {

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);

        // Links: Gedruckt am
        $this->Cell(0, 5,
            'Erstellt am: '.date('d.m.Y').' um '.date('H:i'),
            0, 0, 'L'
        );

        // Rechts: Seite X/Y
        $this->Cell(0, 5,
            'Seite '.$this->getAliasNumPage().'/'.$this->getAliasNbPages(),
            0, 0, 'R'
        );
    }
}

// =====================
// INIT
// =====================
$pdf = new MyPDF();
$pdf->SetMargins(15, 20, 15);
$pdf->AddPage();

// =====================
// HEADER
// =====================
$pageWidth = $pdf->getPageWidth();

// Logo rechts
$pdf->Image(__DIR__.'/images/Logo.png', $pageWidth - 45, 15, 30);

// Datenbankname ohne Pfad und ohne .db extrahieren
$dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME);

// Titel mit dynamischem Datenbanknamen
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetXY(15, 12);
$pdf->Cell(0, 10, 'Feuerlöscherüberprüfung ' . $dbNameOnly, 0, 1);

// Feuerwehrdaten
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 11);

$pdf->Cell(0, 6, FIRMA_NAME, 0, 1);
$pdf->Cell(0, 6, FIRMA_ADRESSE, 0, 1);
$pdf->Cell(0, 6, FIRMA_PLZORT, 0, 1);
$pdf->Cell(0, 6, FIRMA_WEB, 0, 1);

// Trennlinie
$pdf->Ln(2);
$pdf->SetLineWidth(0.2);
$pdf->Line(15, $pdf->GetY(), $pageWidth - 15, $pdf->GetY());

$pdf->Ln(8);

// =====================
// FILTER & ANSICHT PARAMETER
// =====================
$statusFilter = $_GET['status'] ?? 'alle';
$ansicht = $_GET['ansicht'] ?? 'liste';

if ($ansicht === 'uebersicht') {
    $statusFilter = 'alle';
}

// =====================
// DATEN LADEN
// =====================
$db = getDB();
$result = $db->query("SELECT * FROM loescher WHERE active=1");

$stats = [
    'gesamt' => 0,
    'verrechenbar' => 0,
    'nicht_verrechenbar' => 0,
    'ok' => 0,
    'defekt' => 0,
    'nicht_geprueft' => 0
];

$gesamtVollerPreis = 0;
$gesamtGewinnFirma = 0;
$gesamtGewinnFF = 0;

$anzahlEntsorgung = 0;
$gesamtEntsorgungskosten = 0;

// =====================
// HELPER
// =====================
function getPreis($l) {
    // Ignoriere die Datenbank-Spalte 'preis' und verwende nur noch die Config-Werte
    switch ($l['typ']) {
        case 'Standard': return PREIS_STANDARD; // Nutzt den Wert aus config.php
        case 'Rabatt':   return PREIS_RABATT;   // Nutzt den Wert aus config.php
        case 'Gratis':   return 0.0;
        default:         return 0.0;
    }
}
$rows = [];

while ($l = $result->fetchArray(SQLITE3_ASSOC)) {

    if ($l['defekt']) {
        $status = 'defekt';
        $statusText = 'Defekt';
    } elseif (!$l['geprueft']) {
        $status = 'nicht';
        $statusText = 'Nicht geprüft';
    } else {
        $status = 'ok';
        $statusText = 'OK';
    }

    if ($statusFilter === 'nicht_abgeholt') {
        if ($l['abgeholt']) {
            continue;
        }
    } elseif ($statusFilter !== 'alle' && $statusFilter !== $status) {
        continue;
    }

    $dbPreis = getPreis($l);

    $anteilFirma = 0;
    $anteilFF = 0;

    if ($l['bezahlt'] && $l['typ'] !== 'Gratis') {
        if ($l['defekt']) {
            $anteilFirma = 0;
            $anteilFF = $dbPreis;
        } elseif ($l['geprueft']) {
            if ($l['typ'] === 'Standard') {
                $anteilFirma = PREIS_RABATT;
                $anteilFF = $dbPreis - PREIS_RABATT;
            } elseif ($l['typ'] === 'Rabatt') {
                $anteilFirma = $dbPreis;
                $anteilFF = 0;
            }
        }
    }

    // Entsorgungskosten erfassen (Defekt UND Bezahlt)
    if ($l['defekt'] && $l['bezahlt'] && !$l["abgeholt"]) {
        $anzahlEntsorgung++;
        $gesamtEntsorgungskosten += $dbPreis;
    }

    // Verrechenbarkeits-Prüfung für Statistik (NUR wenn nicht defekt, geprüft, bezahlt und nicht gratis)
    $istVerrechenbar = (
        !$l['defekt'] && 
        $l['geprueft'] && 
        $l['bezahlt'] && 
        $l['typ'] !== 'Gratis'
    );

    if ($istVerrechenbar) {
        $stats['verrechenbar']++;
        $gesamtVollerPreis += $dbPreis;
        $gesamtGewinnFirma += $anteilFirma;
        $gesamtGewinnFF += $anteilFF;
    } else {
        $stats['nicht_verrechenbar']++;
    }

    $stats['gesamt']++;
    if ($status === 'defekt') $stats['defekt']++;
    elseif ($status === 'ok') $stats['ok']++;
    elseif ($status === 'nicht') $stats['nicht_geprueft']++;

    $rows[] = [
        'nummer' => $l['nummer'],
        'name' => $l['name'],
        'preis' => number_format($dbPreis, 2).' €',
        'bezahlt' => $l['bezahlt'] == 1 ? 'Ja' : 'Nein',
        'statusText' => $statusText,
        'abgeholt' => $l['abgeholt'] == 1 ? 'Ja' : 'Nein',
        'status' => $status,
        // Rohdaten für die neue Farbgebungslogik
        'isGeprueft' => (bool)$l['geprueft'],
        'isBezahlt'  => (bool)$l['bezahlt'],
        'isDefekt'   => (bool)$l['defekt'],
        'isAbgeholt' => (bool)$l['abgeholt']
    ];
}

// =====================
// ÜBERSCHRIFT & FILTERANZEIGE
// =====================
$pdf->SetFont('helvetica', 'B', 14);

$filterErgaenzung = '';
if ($statusFilter !== 'alle') {
    $filterLabel = $statusFilter;
    if ($statusFilter === 'nicht_abgeholt') $filterLabel = 'Nicht abgeholt';
    if ($statusFilter === 'nicht') $filterLabel = 'Nicht geprüft';
    if ($statusFilter === 'ok') $filterLabel = 'OK';
    if ($statusFilter === 'defekt') $filterLabel = 'Defekt';
    
    $filterErgaenzung = ' (Filter: ' . $filterLabel . ')';
}

$pdf->Cell(0, 8, 'Übersicht' . $filterErgaenzung, 0, 1);
$pdf->Ln(2);

// =====================
// STATISTIK TABELLE
// =====================
$pdf->SetFont('helvetica', '', 11);

$statData = [
    ['Gesamt', $stats['gesamt'] . ' Stück'],
    ['Verrechenbar', $stats['verrechenbar'] . ' Stück'],
    ['Nicht verrechenbar', $stats['nicht_verrechenbar'] . ' Stück'],
    ['Nicht geprüft', $stats['nicht_geprueft'] . ' Stück'],
    ['OK', $stats['ok'] . ' Stück'],
    ['Defekt', $stats['defekt'] . ' Stück'],
    ['Entsorgung (Defekt & Bezahlt)', $anzahlEntsorgung . ' Stück'],
    ['Entsorgungskosten gesamt', number_format($gesamtEntsorgungskosten, 2).' €'],
    ['Geld gesamt', number_format($gesamtVollerPreis, 2).' €'],
    ['Gewinn Firma', number_format($gesamtGewinnFirma, 2).' €'],
    ['Gewinn FF', number_format($gesamtGewinnFF, 2).' €'],
];

foreach ($statData as $row) {

    $bgColor = [240, 240, 240];

    if ($row[0] === 'OK') {
        $bgColor = [198, 239, 206]; // grün
    } elseif ($row[0] === 'Defekt') {
        $bgColor = [255, 199, 206]; // rot
    } elseif ($row[0] === 'Nicht verrechenbar') {
        $bgColor = [255, 235, 156]; // orange
    } elseif ($row[0] === 'Verrechenbar') {
        $bgColor = [198, 239, 206]; // grün
    } elseif (strpos($row[0], 'Entsorgung') !== false) {
        $bgColor = [228, 237, 212]; // Passend zu #e4edd4
    }

    $pdf->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);

    $pdf->Cell(90, 8, $row[0], 1, 0, 'L', true);
    $pdf->Cell(90, 8, $row[1], 1, 1, 'R', true);
}

$pdf->Ln(6);

// =====================
// LISTE
// =====================
if ($ansicht !== 'uebersicht') {
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Liste der Löscher', 0, 1);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200,200,200);

    // Tabellenkopf
    $pdf->Cell(15, 8, 'Nr', 1, 0, 'C', true);
    $pdf->Cell(60, 8, 'Name', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Preis', 1, 0, 'C', true);
    $pdf->Cell(20, 8, 'Bezahlt', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Status', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Abgeholt', 1, 1, 'C', true);

    // Daten
    $pdf->SetFont('helvetica', '', 10);

    foreach ($rows as $r) {

        // 1. Nicht geprüft -> Orange (#fff3cd)
        if (!$r['isGeprueft']) {
            $bgColor = [255, 243, 205]; 
        } 
        // 2. Bezahlt, defekt, nicht abgeholt -> Hellgrün (#e4edd4)
        elseif ($r['isBezahlt'] && $r['isDefekt'] && !$r['isAbgeholt']) {
            $bgColor = [228, 237, 212]; 
        } 
        // 3. Nicht bezahlt, defekt, abgeholt -> Hellgrün (#e4edd4)
        elseif (!$r['isBezahlt'] && $r['isDefekt'] && $r['isAbgeholt']) {
            $bgColor = [228, 237, 212]; 
        } 
        // 4. Bezahlt, ok (nicht defekt), abgeholt -> Grün (#d4edda)
        elseif ($r['isBezahlt'] && !$r['isDefekt'] && $r['isAbgeholt']) {
            $bgColor = [212, 237, 218]; 
        } 
        // 5. Alles andere -> Rot (#f8d7da)
        else {
            $bgColor = [248, 215, 218]; 
        }

        $pdf->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);

        $pdf->Cell(15, 7, sprintf("%03d", $r['nummer']), 1, 0, 'C', true);
        $pdf->Cell(60, 7, $r['name'], 1, 0, 'L', true);
        $pdf->Cell(25, 7, $r['preis'], 1, 0, 'R', true);
        $pdf->Cell(20, 7, $r['bezahlt'], 1, 0, 'C', true);
        $pdf->Cell(35, 7, $r['statusText'], 1, 0, 'C', true);
        $pdf->Cell(25, 7, $r['abgeholt'], 1, 1, 'C', true);
    }
}

// =====================
// OUTPUT
// =====================
$pdf->Output('feuerloescher_statistik.pdf', 'I');
?>