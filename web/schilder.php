<?php
require_once('tcpdf/tcpdf.php');

if (file_exists('config.php')) {
    include('config.php');
} else {
    define('PREIS_STANDARD', 0);
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
 * Funktion für ID-Schilder
 */
function addIDPage($pdf, $color, $value) {
    $pdf->AddPage();
    $pdf->SetLineWidth(6);
    $pdf->SetDrawColor($color[0], $color[1], $color[2]);
    $pdf->Rect(10, 10, 400, 277);

    // "ID" direkt unter dem oberen Rahmen
    $pdf->SetFont('helvetica', 'B', 220);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY(10, 12);
    $pdf->Cell(400, 80, 'ID', 0, 1, 'C');

    $fontSize = (strlen($value) > 5) ? 150 : 180;
    $pdf->SetFont('helvetica', 'B', $fontSize);
    $pdf->SetXY(10, 115);
    $pdf->Cell(400, 80, $value, 0, 1, 'C');
}

/**
 * Funktion für Annahme/Abholung mit Pfeilen (Pfeile in Schwarz)
 */
function addActionPages($pdf, $title, $color, $icon) {
    $arrows = array("⬇", "⬆", "⬅", "➡");
    foreach ($arrows as $symbol) {
        $pdf->AddPage();
        $pdf->SetLineWidth(6);
        $pdf->SetDrawColor($color[0], $color[1], $color[2]);
        $pdf->Rect(10, 10, 400, 277);
        
        $pdf->SetFont('helvetica', 'B', 220);
        $pdf->SetXY(10, 12);
        $pdf->Cell(400, 80, $title, 0, 1, 'C');
        
        if (file_exists($icon)) {
            $pdf->Image($icon, 150, 110, 110, 110, 'PNG', '', 'T', false, 300, 'C');
        }
        
        $pdf->SetFont('dejavusans', 'B', 180);
        // Pfeilfarbe Schwarz (0, 0, 0)
        $pdf->SetTextColor(0, 0, 0);
        //$pdf->SetTextColor($color[0], $color[1], $color[2]);
        $pdf->SetXY(10, 210);
        $pdf->Cell(400, 60, $symbol, 0, 1, 'C');
    }
}

// --- LOGIK ---
if (isset($_GET['id'])) {
    $rawInput = trim($_GET['id']);
    
    // 1. Operator mit Zahl (z.B. "<300", ">=50", "> 12")
    if (preg_match('/^(<|>|<=|>=|=)\s*(\d+)$/', $rawInput, $matches)) {
        $operator = $matches[1];
        $number   = str_pad(intval($matches[2]), 3, '0', STR_PAD_LEFT);
        $displayText = $operator . ' ' . $number;
        
    // 2. Numerischer Bereich (z.B. "1-10" oder "001-010")
    } elseif (preg_match('/^(\d+)\s*-\s*(\d+)$/', $rawInput, $matches)) {
        $from = str_pad(intval($matches[1]), 3, '0', STR_PAD_LEFT);
        $to   = str_pad(intval($matches[2]), 3, '0', STR_PAD_LEFT);
        $displayText = $from . ' - ' . $to;
        
    // 3. Reine einzelne Zahl (z.B. "5")
    } elseif (ctype_digit($rawInput)) {
        $displayText = str_pad(intval($rawInput), 3, '0', STR_PAD_LEFT);
        
    // 4. Alle anderen Freitexte (z.B. "bis 25", "über 12")
    } else {
        $displayText = htmlspecialchars($rawInput, ENT_QUOTES, 'UTF-8');
    }
    
    addIDPage($pdf, $colorGreen, $displayText);
    
    // Dateinamen für den Browser bereinigen
    $cleanFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', $rawInput);
    $filename = "ID_Schild_" . $cleanFilename . ".pdf";
} else {
    // 1. Kosten
    $pdf->AddPage();
    $pdf->SetLineWidth(6);
    $pdf->SetDrawColor($colorBlue[0], $colorBlue[1], $colorBlue[2]);
    $pdf->Rect(10, 10, 400, 277);
    $pdf->SetFont('helvetica', 'B', 85);
    $pdf->SetXY(10, 35);
    $pdf->Cell(400, 40, 'Überprüfungskosten ' . date('Y'), 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 60);
    $html = (defined('PREIS_STANDARD') && floatval(PREIS_STANDARD) > 0) 
        ? '<div style="text-align:center;"><b>' . number_format(PREIS_STANDARD, 2, ',', '.') . ' €</b> je Löscher</div>' 
        : '';
    $pdf->writeHTMLCell(400, 40, 10, 85, $html, 0, 1, 0, true, 'C', true);
    
    if (file_exists($iconPath)) {
        $pdf->Image($iconPath, 155, 135, 110, 110, 'PNG', '', 'T', false, 300, 'C');
    }

    // 2. Annahme & Abholung
    addActionPages($pdf, 'Annahme', $colorRed, $iconPath);
    addActionPages($pdf, 'Abholung', $colorRed, $iconPath);

    // 3. Leer-ID
    addIDPage($pdf, $colorGreen, '_');
    $filename = "Feuerloescher_SchilderSet_A3.pdf";
}

$pdf->Output($filename, 'I');
?>