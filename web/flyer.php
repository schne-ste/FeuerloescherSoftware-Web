<?php
require_once('config.php');
require_once('tcpdf/tcpdf.php');

class KundenInfoFlyer extends TCPDF {
    public function Header() {
        $this->SetFillColor(190, 0, 0); 
        $this->Rect(0, 0, 297, 40, 'F'); // A3 Breite ist 297mm
        
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 22);
        $this->SetXY(20, 10);
        $this->Cell(0, 10, 'KUNDENINFORMATION - FEUERLÖSCHER', 0, 1, 'L');
        
        $this->SetFont('helvetica', '', 13);
        $this->SetXY(20, 21);
        $this->Cell(0, 8, 'Ablauf & wichtige Hinweise zu Ihrer Überprüfung', 0, 1, 'L');
    }

    public function Footer() {
        $this->SetY(-20);
        $this->SetFont('helvetica', 'I', 10);
        $this->SetTextColor(120);
        $this->Cell(0, 10, 'Infoblatt Feuerlöscherüberprüfung | Stand: ' . date('d.m.Y'), 0, 0, 'C');
    }
}

// A3-Format
$pdf = new KundenInfoFlyer('P', 'mm', 'A3', true, 'UTF-8', false);
$pdf->SetCreator('Feuerlöscher Software');
$pdf->SetTitle('Ablauf Feuerlöscherüberprüfung');

// Ränder für A3 angepasst
$pdf->SetMargins(20, 48, 20); 
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(20);
$pdf->SetAutoPageBreak(TRUE, 20);

$pdf->AddPage();

// Logo Positionierung für A3 (rechts oben)
if (file_exists('images/Logo.png')) {
    $pdf->Image('images/Logo.png', 240, 8, 38);
}

// --- TITELBEREICH ---
$pdf->SetXY(20, 48);
$pdf->SetTextColor(190, 0, 0);
$pdf->SetFont('helvetica', 'B', 24);
$pdf->Cell(0, 10, 'Ablauf der Feuerlöscherüberprüfung', 0, 1, 'L');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'Transparente Informationen zu Annahme, Prüfung & Rückgabe', 0, 1, 'L');
$pdf->Ln(6);

// Hilfsfunktion für Abschnitte
function drawSectionHeader($pdf, $title) {
    $pdf->SetTextColor(190, 0, 0);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 9, $title, 0, 1, 'L');
    $pdf->SetDrawColor(204, 204, 204);
    $pdf->Line(20, $pdf->GetY(), 277, $pdf->GetY());
    $pdf->Ln(4);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->SetFont('helvetica', '', 13);
}

// Hilfsfunktion für Listenpunkte
function drawBulletPoint($pdf, $title, $text) {
    $startX = 20;
    $bulletWidth = 8;
    $textWidth = 249; // A3 nutzbare Breite (297 - 48 Ränder)
    
    $pdf->SetX($startX);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell($bulletWidth, 7, '•', 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Write(7, $title . "\n");
    
    $pdf->SetX($startX + $bulletWidth);
    $pdf->SetFont('helvetica', '', 13);
    $pdf->MultiCell($textWidth, 7, $text, 0, 'L');
    $pdf->Ln(4);
}

$preisText = defined('PREIS_STANDARD') && PREIS_STANDARD 
    ? ' (' . number_format(PREIS_STANDARD, 2, ',', '') . ' € je Feuerlöscher)' 
    : '';

$annahmeText = "Um einen zügigen und reibungslosen Ablauf bei der Annahme und Abholung zu gewährleisten, werden die <br><strong>Prüfungskosten{$preisText} direkt bei der Annahme kassiert</strong>.<br><br>Für Rückfragen notieren wir Ihre Telefonnummer.<br><br>Sie erhalten einen Abholschein als Beleg für Ihre Abgabe.<br><strong>Den Abholschein bitte bei der Abholung mitbringen</strong> (Löschernummer).";

// SumUp / Kartenzahlung prüfen
$sumUpAvailable = defined('SumUp_AVALIABLE') && (strtoupper((string)SumUp_AVALIABLE) === 'TRUE' || SumUp_AVALIABLE === true);

if ($sumUpAvailable) {
    $faktor = defined('SumUp_PRICE_FAKTOR') ? floatval(SumUp_PRICE_FAKTOR) : 1.0;
    $prozentVal = round(($faktor - 1) * 100, 2);
    $prozentText = ($prozentVal == intval($prozentVal)) ? intval($prozentVal) . '%' : number_format($prozentVal, 2, ',', '') . '%';
    
    $annahmeText .= "<br><br>Die Prüfungskosten können auch bequem per Kartenzahlung bezahlt werden.<br><strong>Hierfür werden jedoch " . $prozentText . ' Transaktionsgebühr verrechnet (externer Betreiber).</strong>';
}

// 1. Annahme
drawSectionHeader($pdf, '1. Annahme & Bezahlung der Prüfung');
$pdf->writeHTMLCell(257, 0, '', '', $annahmeText, 0, 1);
$pdf->Ln(6);

// 2. Überprüfung
drawSectionHeader($pdf, '2. Fachgerechte Überprüfung');
$pruefungText = 'Ihr Feuerlöscher wird von einer zertifizierten Fachkraft gründlich nach den geltenden Normen und Vorschriften<br>(ÖNORM F 1053) auf seine Funktionsfähigkeit und Sicherheit überprüft.';
$pdf->writeHTMLCell(257, 0, '', '', $pruefungText, 0, 1);
$pdf->Ln(6);

// 3. Ergebnis
drawSectionHeader($pdf, '3. Ergebnis der Überprüfung');
drawBulletPoint($pdf, 'Feuerlöscher einsatzbereit (Plakette erteilt):', 'Ihr Löscher erhält eine neue Prüfplakette (gültig für 2 Jahre) und kann unter Vorweisung des Abholscheins direkt wieder mitgenommen werden.');
drawBulletPoint($pdf, 'Feuerlöscher defekt / nicht mehr zulässig:', 'Sollte Ihr Löscher die technische Prüfung nicht bestehen oder das zulässige Höchstalter überschritten haben, darf aus Sicherheitsgründen keine Prüfplakette mehr erteilt werden.');
$pdf->SetX(28);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->MultiCell(249, 7, 'Ist ein Löscher defekt, darf dieser nicht mehr verwendet werden!', 0, 'L');
$pdf->Ln(6);

// 4. Defekt-Regelung
drawSectionHeader($pdf, '4. Wichtige Regelung bei DEFEKTEN Löschern');
$pdf->SetFont('helvetica', 'B', 13);
$pdf->MultiCell(257, 7, 'Falls Ihr Feuerlöscher als defekt eingestuft wird, stehen Ihnen zwei Möglichkeiten zur Auswahl:', 0, 'L');
$pdf->Ln(4);

// --- DYNAMISCH AUSGERICHTETE BOX ---
$boxStartY = $pdf->GetY();

$pdf->SetXY(30, $boxStartY + 6);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Write(7, "Option A: Sie nehmen den defekten Löscher selbst wieder mit\n");

$pdf->SetX(30);
$pdf->SetFont('helvetica', '', 12.5);
$pdf->MultiCell(237, 6.5, "Wenn Sie Ihren defekten Feuerlöscher wieder mitnehmen und sich selbst um die fachgerechte Entsorgung kümmern, erhalten Sie die im Voraus bezahlten Prüfungskosten in voller Höhe retour erstattet.", 0, 'L');

$pdf->Ln(4);
$pdf->SetX(30);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Write(7, "Option B: Der defekte Löscher verbleibt beim Veranstalter\n");

$pdf->SetX(30);
$pdf->SetFont('helvetica', '', 12.5);
$pdf->MultiCell(237, 6.5, "Möchten Sie den defekten Löscher nicht mehr mitnehmen, übernimmt der Veranstalter für Sie die ordnungsgemäße Entsorgung. In diesem Fall werden die eingehobenen Prüfungskosten als Entsorgungs- und Aufwandsgebühr einbehalten. Eine Rückerstattung des Betrags erfolgt nicht.", 0, 'L');

$boxEndY = $pdf->GetY() + 6;
$boxHeight = $boxEndY - $boxStartY;

// Grauer Hintergrund & Roter Rand (angepasst auf A3-Breite 257mm)
$pdf->SetFillColor(248, 249, 250);
$pdf->Rect(20, $boxStartY, 257, $boxHeight, 'DF', array('width' => 0, 'color' => array(248, 249, 250)));
$pdf->SetFillColor(190, 0, 0);
$pdf->Rect(20, $boxStartY, 4, $boxHeight, 'F');

// Text nochmals sauber in der Box positionieren
$pdf->SetXY(30, $boxStartY + 6);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Write(7, "Option A: Sie nehmen den defekten Löscher selbst wieder mit\n");

$pdf->SetX(30);
$pdf->SetFont('helvetica', '', 12.5);
$pdf->MultiCell(237, 6.5, "Wenn Sie Ihren defekten Feuerlöscher wieder mitnehmen und sich selbst um die fachgerechte Entsorgung kümmern, erhalten Sie die im Voraus bezahlten Prüfungskosten in voller Höhe retour erstattet.", 0, 'L');

$pdf->Ln(4);
$pdf->SetX(30);
$pdf->SetFont('helvetica', 'B', 13);
$pdf->Write(7, "Option B: Der defekte Löscher verbleibt beim Veranstalter\n");

$pdf->SetX(30);
$pdf->SetFont('helvetica', '', 12.5);
$pdf->MultiCell(237, 6.5, "Möchten Sie den defekten Löscher nicht mehr mitnehmen, übernimmt der Veranstalter für Sie die ordnungsgemäße Entsorgung. In diesem Fall werden die eingehobenen Prüfungskosten als Entsorgungs- und Aufwandsgebühr einbehalten. Eine Rückerstattung des Betrags erfolgt nicht.", 0, 'L');

$pdf->SetY($boxEndY + 8);

$pdf->Output('Flyer_Loescherpruefung_A3.pdf', 'I');