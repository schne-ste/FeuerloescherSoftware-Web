<?php
require_once('tcpdf/tcpdf.php');

class BrandschutzFlyer extends TCPDF {
    public function Header() {
        $this->SetFillColor(190, 0, 0); 
        $this->Rect(0, 0, 210, 28, 'F');
        
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('helvetica', 'B', 16);
        $this->SetXY(15, 7);
        $this->Cell(0, 8, 'INFOBLATT - FEUERLÖSCHER', 0, 1, 'L');
        
        $this->SetFont('helvetica', '', 9);
        $this->SetXY(15, 14);
        $this->Cell(0, 8, 'Wichtige Informationen zu Ihrer Sicherheit', 0, 1, 'L');
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 7);
        $this->SetTextColor(120);
        $this->Cell(0, 10, 'Infoblatt Feuerlöscherüberprüfung. Alle Angaben ohne Gewähr. | Stand: ' . date('d.m.Y'), 0, 0, 'C');
    }
}

$pdf = new BrandschutzFlyer(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
$pdf->SetCreator('Feuerlöscher Software');
$pdf->SetTitle('Flyer ÖNORM F 1053');

// Ränder
$pdf->SetMargins(15, 32, 15); 
$pdf->SetHeaderMargin(0);
$pdf->SetFooterMargin(15);
$pdf->SetAutoPageBreak(TRUE, 15);

$pdf->AddPage();

// Logo Positionierung
if (file_exists('images/Logo.png')) {
    $pdf->Image('images/Logo.png', 165, 5, 28);
}

// --- TITELBEREICH (Korrekt eingerückt) ---
$pdf->SetXY(15, 35); // Startpunkt unter dem Header
$pdf->SetTextColor(190, 0, 0);
$pdf->SetFont('helvetica', 'B', 18);
$pdf->Cell(0, 10, 'ÖNORM F 1053', 0, 1, 'L');

$pdf->SetX(15); // Sicherstellen der Einrückung
$pdf->SetTextColor(0, 0, 0);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->Cell(0, 6, 'Vorschriften zur Überprüfung tragbarer Feuerlöscher', 0, 1, 'L');

$pdf->Ln(4);

// HTML-Content mit optimierten Abständen
$html = '
<style>
    h2 { 
        color: #be0000; 
        font-size: 11pt; 
        font-weight: bold; 
        border-bottom: 0.5px solid #cccccc; /* Trennungslinie */
        line-height: 1.5;
    }
    p { font-size: 9pt; line-height: 1.3; color: #333; }
    ul { font-size: 9pt; color: #333; }
    li { margin-bottom: 3px; }
    .spacer { line-height: 0.5; font-size: 5pt; } /* Kleiner Platzhalter */
</style>

<h2>Was regelt die ÖNORM F 1053?</h2>
<p>Zentrale Vorschrift für die regelmäßige Prüfung und Wartung tragbarer Feuerlöscher in Österreich. Sie stellt die Funktionsfähigkeit im Notfall sicher und betrifft alle Unternehmen und öffentlichen Einrichtungen.</p>

<div class="spacer">&nbsp;</div>

<h2>Warum ist die ÖNORM F 1053 wichtig?</h2>
<ul>
    <li><b>Funktion:</b> Ein intakter Löscher verhindert Katastrophen.</li>
    <li><b>Gesetz:</b> Grundlage für behördliche Brandschutzkontrollen.</li>
    <li><b>Haftung:</b> Schutz vor rechtlichen Folgen und Versicherungsverlust.</li>
</ul>

<div class="spacer">&nbsp;</div>

<h2>Prüfintervalle auf einen Blick</h2>
<ul>
    <li><b>Alle 2 Jahre:</b> Gesetzliche Überprüfung durch Fachkraft (Pflicht).</li>
    <li><b>Nach Nutzung:</b> Sofortige Kontrolle und Wiederbefüllung.</li>
    <li><b>Alle 10 Jahre:</b> Umfassende Druckprüfung des Behälters.</li>
</ul>

<div class="spacer">&nbsp;</div>

<h2>Wer darf die Prüfung durchführen?</h2>
<p>Ausschließlich zertifizierte Fachfirmen oder befugte Sachkundige. Unsachgemäße Prüfungen führen zu Sicherheitsrisiken und Haftungsproblemen.</p>

<div class="spacer">&nbsp;</div>

<h2>Dokumentation & Sanktionen</h2>
<p>Jede Prüfung muss protokolliert werden. Ohne gültige Plakette riskieren Betriebe Geldstrafen und den Verlust des Versicherungsschutzes im Brandfall.</p>

<div class="spacer">&nbsp;</div>

<h2>Wichtige Tipps zur Umsetzung</h2>
<ul>
    <li>Löscher gut sichtbar und frei zugänglich platzieren.</li>
    <li>Prüftermine frühzeitig planen und Fachfirma beauftragen.</li>
    <li>Mitarbeiter regelmäßig im Umgang mit Löschern schulen.</li>
    <li>Brandklassen beachten (A, B, C, D, F).</li>
</ul>
';

$pdf->writeHTML($html, true, false, true, false, '');

$pdf->Output('Flyer_OENORM_F1053.pdf', 'I');