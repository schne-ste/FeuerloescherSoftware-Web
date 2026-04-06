<?php
require 'config.php';
require_once('tcpdf/tcpdf.php');

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
// DATEN LADEN (nur verrechenbare)
// =====================
$db = getDB();
$result = $db->query("SELECT * FROM loescher");

$stats = [
    'gesamt' => 0,
    'ok' => 0,
    'defekt' => 0,
    'nicht_geprueft' => 0
];

$gesamtVollerPreis = 0;

function getPreis($typ) {
    switch ($typ) {
        case 'Standard': return PREIS_RABATT;
        case 'Rabatt': return PREIS_RABATT;
        default: return 0;
    }
}

$rows = [];

while ($l = $result->fetchArray(SQLITE3_ASSOC)) {

    // Filter nur verrechenbare Löscher
    $istVerrechenbar = (
        !$l['defekt'] &&
        $l['bezahlt'] &&
        $l['geprueft'] &&
        $l['typ'] !== 'Gratis'
    );
    if (!$istVerrechenbar) {
        continue;
    }

    // Status bestimmen (für die Liste und Statistik)
    if ($l['defekt']) {
        $status = 'Defekt';
        $stats['defekt']++;
    } elseif (!$l['geprueft']) {
        $status = 'Nicht geprüft';
        $stats['nicht_geprueft']++;
    } else {
        $status = 'OK';
        $stats['ok']++;
    }

    $vollpreis = getPreis($l['typ']);

    $stats['gesamt']++;
    $gesamtVollerPreis += $vollpreis;

    $rows[] = [
        'nummer' => $l['nummer'],
        'name' => $l['name'],
        'typ' => $l['typ'],
        'preis' => number_format($vollpreis,2).' €',
        'status' => $status
    ];
}

// Gewinn (Firma bekommt Rabatt, FF bekommt Differenz)
$gesamtGewinnFirma = $stats['gesamt'] * PREIS_RABATT;
$gesamtGewinnFF = $gesamtVollerPreis - $gesamtGewinnFirma;

// =====================
// ÜBERSCHRIFT
// =====================
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'Übersicht', 0, 1);

$pdf->Ln(2);

// =====================
// STATISTIK TABELLE
// =====================
$pdf->SetFont('helvetica', '', 11);

$statData = [
    ['Löscher Gesamt', $stats['gesamt']],
    ['Gesamtbetrag', number_format($gesamtGewinnFirma,2).' €'],
];

foreach ($statData as $row) {
    $pdf->SetFillColor(240,240,240);
    $pdf->Cell(110, 8, $row[0], 1, 0, 'L', true);
    $pdf->Cell(70, 8, $row[1], 1, 1, 'R');
}

$pdf->Ln(6);

// =====================
// LISTE
// =====================
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Cell(0, 8, 'Liste der Löscher', 0, 1);

$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetFillColor(200,200,200);

$pdf->Cell(20, 8, 'Nr', 1, 0, 'C', true);
$pdf->Cell(80, 8, 'Preis', 1, 0, 'C', true);
$pdf->Cell(80, 8, 'Status', 1, 1, 'C', true);

// Daten
$pdf->SetFont('helvetica', '', 10);

$fill = false;
foreach ($rows as $r) {
    $pdf->SetFillColor($fill ? 245 : 255, $fill ? 245 : 255, $fill ? 245 : 255);

    $pdf->Cell(20, 7, $r['nummer'], 1, 0, 'C', true);
    $pdf->Cell(80, 7, $r['preis'], 1, 0, 'R', true);
    $pdf->Cell(80, 7, $r['status'], 1, 1, 'C', true);

    $fill = !$fill;
}

// =====================
// Unterschrift & Bestätigung
// =====================
$pdf->Ln(15);
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 10, "Die Firma bestätigt hiermit die Auswertung als korrekt.\n\n\n\n\n______________________________\nUnterschrift", 0, 'L');


// =====================
// OUTPUT
// =====================
$pdf->Output('feuerloescher_verrechnung.pdf', 'I');