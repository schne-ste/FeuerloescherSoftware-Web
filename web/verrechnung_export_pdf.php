<?php
require 'config.php';
require_once('tcpdf/tcpdf.php');

// Prüfen, ob die Liste ausgeblendet werden soll
$hideList = isset($_GET['hide_list']) && $_GET['hide_list'] == 1;

class MyPDF extends TCPDF {

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 8);

        // Links: Erstellt am
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
$pdf->SetAutoPageBreak(true, 20);
$pdf->AddPage();

// =====================
// HEADER
// =====================
$pageWidth = $pdf->getPageWidth();

// Logo rechts
$pdf->Image(__DIR__.'/images/Logo.png', $pageWidth - 45, 15, 30);

// Datenbankname ohne Pfad und ohne .db extrahieren
$dbNameOnly = pathinfo(DB_FILE, PATHINFO_FILENAME); // Holt z.B. "Test1" aus "databases/Test1.db"

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
// DATEN LADEN (nur verrechenbare)
// =====================
$db = getDB();
$result = $db->query("SELECT * FROM loescher WHERE active=1");

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
        'preis' => number_format($vollpreis, 2, ',', '.') . ' €',
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
$pdf->Cell(0, 8, 'Prüf- und Abrechnungsbestätigung', 0, 1);

$pdf->Ln(2);

// =====================
// STATISTIK TABELLE
// =====================
$pdf->SetFont('helvetica', '', 11);

$statData = [
    ['Löscher Gesamt', $stats['gesamt']],
    ['Gesamtbetrag', number_format($gesamtGewinnFirma, 2, ',', '.') . ' €'],
];

foreach ($statData as $row) {
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(110, 8, $row[0], 1, 0, 'L', true);
    $pdf->Cell(70, 8, $row[1], 1, 1, 'R');
}

$pdf->Ln(6);

// =====================
// LISTE (Wird nur ausgegeben, wenn nicht ausgeblendet)
// =====================
if (!$hideList) {
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Liste der Löscher', 0, 1);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200, 200, 200);

    $pdf->Cell(20, 8, 'Nr', 1, 0, 'C', true);
    $pdf->Cell(80, 8, 'Preis', 1, 0, 'C', true);
    $pdf->Cell(80, 8, 'Status', 1, 1, 'C', true);

    // Daten
    $pdf->SetFont('helvetica', '', 10);

    $fill = false;
    foreach ($rows as $r) {
        // Hintergrundfarbe umschalten (Wechselnd weiß und hellgrau)
        $color = $fill ? 245 : 255;
        $pdf->SetFillColor($color, $color, $color);

        $pdf->Cell(20, 7, $r['nummer'], 1, 0, 'C', true);
        $pdf->Cell(80, 7, $r['preis'], 1, 0, 'R', true);
        $pdf->Cell(80, 7, $r['status'], 1, 1, 'C', true);

        $fill = !$fill;
    }
}

// =====================
// ABRECHNUNGSHINWEIS & FRISTSETZUNG (HTML)
// =====================
$pdf->Ln(4);

// Stichtag festlegen: 31.12. des laufenden Kalenderjahres
$fristDatum = date('31.12.Y'); 

$htmlHinweis = '
<div style="font-size: 8.5pt; line-height: 1.35; color: #111111;">
    <b style="font-size: 10pt; color: #000000;">Vereinbarung zur Rechnungslegung &amp; Ausschlussfrist:</b><br><br>
    
    <b>1. Rechnungslegungsfrist:</b>
    <div style="padding-left: 12px; margin-top: 2px; margin-bottom: 6px;">
        Der Auftragnehmer bzw. die ausführende Prüffirma wird hiermit aufgefordert, die der gegenständlichen Feuerlöscherüberprüfung<br>  zugrundeliegende Honorar- bzw. Rechnungsforderung bis spätestens <b>' . $fristDatum . '</b> (eintreffend) ordnungsgemäß<br>   und prüffähig einzureichen.
    </div>
    <br>
    <b>2. Vertragliche Ausschlussfrist (Abrechnungsschluss):</b>
    <div style="padding-left: 12px; margin-top: 2px; margin-bottom: 6px;">
        Mit Ablauf des genannten Stichtags gilt die finale Abrechnung für die Überprüfungsaktion des laufenden Kalenderjahres als<br>  einvernehmlich und endgültig abgeschlossen. <br>  Die fristgerechte Einreichung stellt einen wesentlichen Bestandteil der Vereinbarung dar.
    </div>
    <br>
    <b>3. Rechtsfolgen bei Fristversäumnis (Verfall):</b>
    <div style="padding-left: 12px; margin-top: 2px;">
        Erfolgt bis zum Stichtag keine ordnungsgemäße Rechnungsstellung, erlischt der Anspruch auf Vergütung bzw. Aufwendungsersatz<br>  der Prüffirma nach Ablauf dieser vereinbarten Präklusivfrist vollumfänglich. Eine nachträgliche Geltendmachung ist ab diesem<br>  Zeitpunkt ausdrücklich ausgeschlossen und wird mangels fristgerechter Einreichung im Sinne der vertraglichen Vereinbarungen<br>   (§§ 863, 914 ABGB) nicht mehr anerkannt.
    </div>
</div>';

// HTML-Text im PDF ausgeben
$pdf->writeHTML($htmlHinweis, true, false, true, false, '');

// =====================
// UNTERSCHRIFT & BESTÄTIGUNG (2-spaltig exakt ausgerichtet)
// =====================
$pdf->Ln(4);

$htmlUnterschriften = '
<table border="0" cellspacing="0" cellpadding="0" style="width: 100%; font-size: 9.5pt; line-height: 1.3;">
    <tr>
        <td colspan="3" style="padding-bottom: 25px; font-size: 10pt;">
            Beide Parteien bestätigen hiermit die obige Auswertung sowie die vereinbarten Abrechnungsbedingungen<br>  als korrekt und verbindlich.<br>
        </td>
    </tr>
    <tr>
        <td style="width: 48%; vertical-align: top; height: 35px;">
            <b>Für die ausführende Firma:</b>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 48%; vertical-align: top; height: 35px;">
            <b>Für den Veranstalter (' . htmlspecialchars(FIRMA_NAME) . '):</b>
        </td>
    </tr>
    <tr>
        <td style="width: 48%; vertical-align: bottom;">
            ____________________________________<br>
            <span style="font-size: 8.5pt; color: #444444;">Unterschrift / Stempel</span>
        </td>
        <td style="width: 4%;"></td>
        <td style="width: 48%; vertical-align: bottom;">
            ____________________________________<br>
            <span style="font-size: 8.5pt; color: #444444;">Unterschrift</span>
        </td>
    </tr>
</table>';

$pdf->writeHTML($htmlUnterschriften, true, false, true, false, '');

// =====================
// OUTPUT
// =====================
$pdf->Output('feuerloescher_verrechnung.pdf', 'I');