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

// Titel
$pdf->SetFont('helvetica', 'B', 20);
$pdf->SetXY(15, 12);
$pdf->Cell(0, 10, 'Feuerlöscherüberprüfung '.date('Y'), 0, 1);

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
$ansicht = $_GET['ansicht'] ?? 'liste'; // 'liste' oder 'uebersicht'

// Wenn "Nur Übersicht" gewählt ist, wird der Filter ignoriert (immer alle anzeigen)
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

function getPreis($typ) {
    switch ($typ) {
        case 'Standard': return PREIS_STANDARD;
        case 'Rabatt': return PREIS_RABATT;
        default: return 0;
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

    // FILTER GREIFT HIER (Bei Uebersicht ist $statusFilter durch die obige Bedingung immer 'alle')
    if ($statusFilter === 'nicht_abgeholt') {
        if ($l['abgeholt']) {
            continue;
        }
    } elseif ($statusFilter !== 'alle' && $statusFilter !== $status) {
        continue;
    }

    $vollpreis = getPreis($l['typ']);

    if (!$l['defekt'] && $l['bezahlt'] && $l['geprueft'] && $l['typ'] !== 'Gratis') {
        $stats['verrechenbar']++;
        $gesamtVollerPreis += $vollpreis;
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
        'abgeholt' => $l['abgeholt'] == 1 ? 'Ja' : 'Nein',
        'preis' => number_format($vollpreis,2).' €',
        'statusText' => $statusText,
        'status' => $status
    ];
}

// Gewinn
$gesamtGewinnFirma = $stats['verrechenbar'] * PREIS_RABATT;
$gesamtGewinnFF = $gesamtVollerPreis - $gesamtGewinnFirma;

// =====================
// ÜBERSCHRIFT & FILTERANZEIGE
// =====================
$pdf->SetFont('helvetica', 'B', 14);

// Filter-Text bestimmen, falls ungleich 'alle'
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
    ['Gesamt', $stats['gesamt']],
    ['Verrechenbar', $stats['verrechenbar']],
    ['Nicht verrechenbar', $stats['nicht_verrechenbar']],
    ['Nicht geprüft', $stats['nicht_geprueft']],
    ['OK', $stats['ok']],
    ['Defekt', $stats['defekt']],
    ['Geld gesamt', number_format($gesamtVollerPreis,2).' €'],
    ['Gewinn Firma', number_format($gesamtGewinnFirma,2).' €'],
    ['Gewinn FF', number_format($gesamtGewinnFF,2).' €'],
];

foreach ($statData as $row) {

    // Standard grau
    $bgColor = [240, 240, 240];

    if ($row[0] === 'OK') {
        $bgColor = [198, 239, 206]; // grün
    } elseif ($row[0] === 'Defekt') {
        $bgColor = [255, 199, 206]; // rot
    } elseif ($row[0] === 'Nicht verrechenbar') {
        $bgColor = [255, 235, 156]; // orange
    } elseif ($row[0] === 'Verrechenbar') {
        $bgColor = [198, 239, 206]; // grün
    }

    $pdf->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);

    $pdf->Cell(90, 8, $row[0], 1, 0, 'L', true);
    $pdf->Cell(90, 8, $row[1], 1, 1, 'R', true);
}

$pdf->Ln(6);

// =====================
// LISTE (NUR GENERIEREN WENN ANSICHT NICHT UEBERSICHT IST)
// =====================
if ($ansicht !== 'uebersicht') {
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Liste der Löscher', 0, 1);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200,200,200);

    $pdf->Cell(15, 8, 'Nr', 1, 0, 'C', true);
    $pdf->Cell(80, 8, 'Name', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Abgeholt', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Preis', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Status', 1, 1, 'C', true);

    // Daten
    $pdf->SetFont('helvetica', '', 10);

    foreach ($rows as $r) {

        // Standardfarbe (weiß)
        $bgColor = [255, 255, 255];

        if ($r['status'] === 'ok') {
            $bgColor = [198, 239, 206]; // grün
        } elseif ($r['status'] === 'defekt') {
            $bgColor = [255, 199, 206]; // rot
        } elseif ($r['status'] === 'nicht') {
            $bgColor = [255, 235, 156]; // orange/gelb
        }

        $pdf->SetFillColor($bgColor[0], $bgColor[1], $bgColor[2]);

        $pdf->Cell(15, 7, sprintf("%03d", $r['nummer']), 1, 0, 'C', true);
        $pdf->Cell(80, 7, $r['name'], 1, 0, 'L', true);
        $pdf->Cell(25, 7, $r['abgeholt'], 1, 0, 'L', true); // Linksbündig
        $pdf->Cell(30, 7, $r['preis'], 1, 0, 'R', true);
        $pdf->Cell(30, 7, $r['statusText'], 1, 1, 'C', true);
    }
}

// =====================
// OUTPUT
// =====================
$pdf->Output('feuerloescher_statistik.pdf', 'I');
?>