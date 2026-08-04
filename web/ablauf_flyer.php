<?php
require_once('config.php');
require_once('tcpdf/tcpdf.php');

class KundenInfoFlyer extends TCPDF {
    public function Header() {
        $this->SetFillColor(190, 0, 0); 
        $this->Rect(0, 0, 210, 28, 'F');
        
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY(15, 7);
        $this->Cell(0, 8, 'KUNDENINFORMATION - FEUERLÖSCHER', 0, 1, 'L');
        
        $this->SetFont('helvetica', '', 9.5);
        $this->SetXY(15, 14);
        $this->Cell(0, 8, 'Ablauf & wichtige Hinweise zu Ihrer Überprüfung', 0, 1, 'L');
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7.5);
        $this->SetTextColor(120);
        $this->Cell(0, 10, 'Infoblatt Feuerlöscherüberprüfung | Stand: ' . date('d.m.Y'), 0, 0, 'C');
    }
}

$pdf = new KundenInfoFlyer(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Feuerlöscher Software');
$pdf->SetTitle('Ablauf Feuerlöscherüberprüfung');

// Ränder
$pdf->SetMargins(15, 32, 15); 
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(TRUE, 15);

$pdf->AddPage();

// Logo Positionierung (falls vorhanden)
if (file_exists('images/Logo.png')) {
    $pdf->Image('images/Logo.png', 165, 4, 28);
}

// --- TITELBEREICH ---
$pdf->SetXY(15, 34);
$pdf->SetTextColor(190, 0, 0);
$pdf->SetFont('helvetica', 'B', 17);
$pdf->Cell(0, 8, 'Ablauf der Feuerlöscherüberprüfung', 0, 1, 'L');

$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 10.5);
$pdf->Cell(0, 6, 'Transparente Informationen zu Annahme, Prüfung & Rückgabe', 0, 1, 'L');
$pdf->Ln(4);

// Hilfsfunktion für Abschnitte
function drawSectionHeader($pdf, $title) {
    $pdf->SetTextColor(190, 0, 0);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 7, $title, 0, 1, 'L');
    $pdf->SetDrawColor(204, 204, 204);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->SetFont('helvetica', '', 10);
}

// Hilfsfunktion für Listenpunkte
function drawBulletPoint($pdf, $title, $text) {
    $startX = 15;
    $bulletWidth = 6;
    $textWidth = 174;
    
    $pdf->SetX($startX);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell($bulletWidth, 5, '•', 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Write(5, $title . "\n");
    
    $pdf->SetX($startX + $bulletWidth);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->MultiCell($textWidth, 5.2, $text, 0, 'L');
    $pdf->Ln(3);
}

// Dynamischer Text für Annahme & Bezahlung
$annahmeText = "Um einen zügigen und reibungslosen Ablauf bei der Annahme und Abholung zu gewährleisten, werden die Prüfungskosten direkt bei der Annahme Ihres Feuerlöschers kassiert.\n\nFür Rückfragen notieren wir Ihre Telefonnummer.\n\nSie erhalten hierbei einen Abholschein als Beleg für Ihre Abgabe. Diesen bitte bei der Abholung mitbringen (Löschernummer).";

// SumUp / Kartenzahlung prüfen
$sumUpAvailable = defined('SumUp_AVALIABLE') && (strtoupper((string)SumUp_AVALIABLE) === 'TRUE' || SumUp_AVALIABLE === true);

if ($sumUpAvailable) {
    $faktor = defined('SumUp_PRICE_FAKTOR') ? floatval(SumUp_PRICE_FAKTOR) : 1.0;
    $prozentVal = round(($faktor - 1) * 100, 2);
    // Formatierung (z.B. "2%" statt "2.00%")
    $prozentText = ($prozentVal == intval($prozentVal)) ? intval($prozentVal) . '%' : number_format($prozentVal, 2, ',', '') . '%';
    
    $annahmeText .= "\n\nDie Prüfungskosten können auch bequem per Kartenzahlung bezahlt werden.\nHierfür werden jedoch " . $prozentText . ' Transaktionsgebühr verrechnet (externer Betreiber).';
}

// 1. Annahme
drawSectionHeader($pdf, '1. Annahme & Bezahlung der Prüfung');
$pdf->MultiCell(180, 5.2, $annahmeText, 0, 'L');
$pdf->Ln(5);

// 2. Überprüfung
drawSectionHeader($pdf, '2. Fachgerechte Überprüfung');
$pdf->MultiCell(180, 5.2, 'Ihr Feuerlöscher wird von einer zertifizierten Fachkraft gründlich nach den geltenden Normen und Vorschriften (ÖNORM F 1053) auf seine Funktionsfähigkeit und Sicherheit überprüft.', 0, 'L');
$pdf->Ln(5);

// 3. Ergebnis
drawSectionHeader($pdf, '3. Ergebnis der Überprüfung');
drawBulletPoint($pdf, 'Feuerlöscher einsatzbereit (Plakette erteilt):', 'Ihr Löscher erhält eine neue Prüfplakette (gültig für 2 Jahre) und kann unter Vorweisung des Abholscheins direkt wieder mitgenommen werden.');
drawBulletPoint($pdf, 'Feuerlöscher defekt / nicht mehr zulässig:', 'Sollte Ihr Löscher die technische Prüfung nicht bestehen oder das zulässige Höchstalter überschritten haben, darf aus Sicherheitsgründen keine Prüfplakette mehr erteilt werden.');
$pdf->Ln(3);

// 4. Defekt-Regelung
drawSectionHeader($pdf, '4. Wichtige Regelung bei DEFEKTEN Löschern');
$pdf->MultiCell(180, 5.2, 'Falls Ihr Feuerlöscher als defekt eingestuft wird, stehen Ihnen zwei Möglichkeiten zur Auswahl:', 0, 'L');
$pdf->Ln(3);

// --- DYNAMISCH AUSGERICHTETE KISTE (BOX) ---
$boxStartY = $pdf->GetY();

// Text vorab messen / schreiben, um die genaue Höhe zu bestimmen
$pdf->SetXY(21, $boxStartY + 4);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Write(5, "Option A: Sie nehmen den defekten Löscher selbst wieder mit\n");

$pdf->SetX(21);
$pdf->SetFont('helvetica', '', 9.5);
$pdf->MultiCell(168, 4.8, "Wenn Sie Ihren defekten Feuerlöscher wieder mitnehmen und sich selbst um die fachgerechte Entsorgung kümmern, erhalten Sie die im Voraus bezahlten Prüfungskosten in voller Höhe retour erstattet.", 0, 'L');

$pdf->Ln(3);
$pdf->SetX(21);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Write(5, "Option B: Der defekte Löscher verbleibt beim Veranstalter\n");

$pdf->SetX(21);
$pdf->SetFont('helvetica', '', 9.5);
$pdf->MultiCell(168, 4.8, "Möchten Sie den defekten Löscher nicht mehr mitnehmen, übernimmt der Veranstalter für Sie die ordnungsgemäße Entsorgung. In diesem Fall werden die eingehobenen Prüfungskosten als Entsorgungs- und Aufwandsgebühr einbehalten. Eine Rückerstattung des Betrags erfolgt nicht.", 0, 'L');

$boxEndY = $pdf->GetY() + 3;
$boxHeight = $boxEndY - $boxStartY;

// Grauer Hintergrund & Roter Rand (über die berechnete Höhe zeichnen)
$pdf->SetFillColor(248, 249, 250);
$pdf->Rect(15, $boxStartY, 180, $boxHeight, 'DF', array('width' => 0, 'color' => array(248, 249, 250)));
$pdf->SetFillColor(190, 0, 0);
$pdf->Rect(15, $boxStartY, 3, $boxHeight, 'F');

// Text nochmals sauber positionieren
$pdf->SetXY(21, $boxStartY + 4);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Write(5, "Option A: Sie nehmen den defekten Löscher selbst wieder mit\n");

$pdf->SetX(21);
$pdf->SetFont('helvetica', '', 9.5);
$pdf->MultiCell(168, 4.8, "Wenn Sie Ihren defekten Feuerlöscher wieder mitnehmen und sich selbst um die fachgerechte Entsorgung kümmern, erhalten Sie die im Voraus bezahlten Prüfungskosten in voller Höhe retour erstattet.", 0, 'L');

$pdf->Ln(3);
$pdf->SetX(21);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->Write(5, "Option B: Der defekte Löscher verbleibt beim Veranstalter\n");

$pdf->SetX(21);
$pdf->SetFont('helvetica', '', 9.5);
$pdf->MultiCell(168, 4.8, "Möchten Sie den defekten Löscher nicht mehr mitnehmen, übernimmt der Veranstalter für Sie die ordnungsgemäße Entsorgung. In diesem Fall werden die eingehobenen Prüfungskosten als Entsorgungs- und Aufwandsgebühr einbehalten. Eine Rückerstattung des Betrags erfolgt nicht.", 0, 'L');

$pdf->SetY($boxEndY + 6);

// Vorteile
drawSectionHeader($pdf, 'Ihre Vorteile auf einen Blick');
drawBulletPoint($pdf, 'Höchste Sicherheit', 'durch geprüfte Qualität für Ihr Zuhause oder Ihren Betrieb.');
drawBulletPoint($pdf, 'Komfortable Entsorgung', '– Möglichkeit der unkomplizierten Entsorgung direkt vor Ort.');

$pdf->Output('Flyer_Ablauf_Loescherpruefung.pdf', 'I');