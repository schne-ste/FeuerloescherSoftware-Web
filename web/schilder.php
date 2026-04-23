<?php
require_once('tcpdf/tcpdf.php');

if (file_exists('config.php')) {
    include('config.php');
} else {
    define('PREIS_STANDARD', 15);
}

$pdf = new TCPDF('L', 'mm', 'A3', true, 'UTF-8', false);
$pdf->SetCreator('System');
$pdf->SetAuthor('Feuerlöscher Service');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(false);

$iconPath = 'images/Feuerloescher.png';
$colorBlue  = array(0, 102, 204);
$colorRed   = array(210, 30, 30);
$colorGreen = array(0, 150, 70);

/**
 * Funktion für das ID-Schild
 */
function addIDPage($pdf, $color, $value) {
    $pdf->AddPage();
    $pdf->SetLineWidth(6);
    $pdf->SetDrawColor($color[0], $color[1], $color[2]);
    $pdf->Rect(10, 10, 400, 277);

    $pdf->SetFont('helvetica', 'B', 220);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(10, 45);
    $pdf->Cell(400, 80, 'ID', 0, 1, 'C');

    // Schriftgröße für den Bereich leicht reduzieren, falls der Text lang ist
    $fontSize = (strlen($value) > 5) ? 150 : 180;
    $pdf->SetFont('helvetica', 'B', $fontSize);
    $pdf->SetXY(10, 135);
    $pdf->Cell(400, 80, $value, 0, 1, 'C');
}

/**
 * Logik für URL-Parameter &id=1-30
 */
if (isset($_GET['id'])) {
    $range = explode('-', $_GET['id']);
    
    if (count($range) == 2) {
        // Bereich formatieren: z.B. 001 - 030
        $start = str_pad(intval($range[0]), 3, '0', STR_PAD_LEFT);
        $end   = str_pad(intval($range[1]), 3, '0', STR_PAD_LEFT);
        $displayText = $start . ' - ' . $end;
    } else {
        // Einzelne ID: z.B. 005
        $displayText = str_pad(intval($range[0]), 3, '0', STR_PAD_LEFT);
    }

    addIDPage($pdf, $colorGreen, $displayText);
    $filename = "ID_Schild_" . $_GET['id'] . ".pdf";

} else {
    // --- NORMALES SET GENERIEREN (Kosten, Annahme, Abholung) ---
    
    // Seite 1: Kosten
    $pdf->AddPage();
    $pdf->SetLineWidth(6);
    $pdf->SetDrawColor($colorBlue[0], $colorBlue[1], $colorBlue[2]);
    $pdf->Rect(10, 10, 400, 277);
    $pdf->SetFont('helvetica', 'B', 85);
    $pdf->SetXY(10, 35);
    $pdf->Cell(400, 40, 'Überprüfungskosten ' . date('Y'), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 60);
    $betrag = number_format(PREIS_STANDARD, 2, ',', '.');
    $html = '<div style="text-align:center;"><b>' . $betrag . ' €</b> je Löscher</div>';
    $pdf->writeHTMLCell(400, 40, 10, 85, $html, 0, 1, 0, true, 'C', true);
    
    if (file_exists($iconPath)) {
        $pdf->Image($iconPath, 155, 135, 110, 110, 'PNG', '', 'T', false, 300, 'C');
    }

    // Seite 2 & 3: Annahme / Abholung
    function addActionPage($pdf, $title, $color, $icon) {
        $pdf->AddPage();
        $pdf->SetLineWidth(6);
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->Rect(10, 10, 400, 277);
        $pdf->SetFont('helvetica', 'B', 140);
        $pdf->SetXY(10, 40);
        $pdf->Cell(400, 50, $title, 0, 1, 'C');
        if (file_exists($icon)) {
            $pdf->Image($icon, 155, 110, 110, 110, 'PNG', '', 'T', false, 300, 'C');
        }
        $pdf->SetFont('dejavusans', 'B', 80);
        $pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY(10, 225);
        $symbol = ($title == 'Annahme') ? "⬇" : "⬆";
        $pdf->Cell(400, 40, $symbol, 0, 1, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }
    
    addActionPage($pdf, 'Annahme', $colorRed, $iconPath);
    addActionPage($pdf, 'Abholung', $colorRed, $iconPath);

    // Seite 4: Standard Leer-ID
    addIDPage($pdf, $colorGreen, '_');
    $filename = "Feuerloescher_SchilderSet_A3.pdf";
}

$pdf->Output($filename, 'I');