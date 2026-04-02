Option Strict On
Imports System
Imports System.IO
Imports System.Text
Imports System.Drawing
 
 
Namespace BonPrinterUtilities
  ''' <summary>
  ''' Stellt einen zustandsbehafteten ESC/POS-Drucker dar.
  ''' </summary>
  ''' <remarks>
  ''' Diese Klasse bietet Methoden, die die ESC/POS-Befehle kapseln.
  ''' Zum Schreiben eines Strings können Sie die Write(String)-Methode verwenden.
  ''' 
  ''' Bei jedem Aufruf einer Methode werden dabei die Kommandos in einen internen 
  ''' Puffer geschrieben, der am Schluss mit GetCurrentPuffer() geholt werden 
  ''' kann, um das Bytearray an den Drucker zu senden.
  ''' </remarks>
  Public Class EscPosPrinter
 
    Public Enum Alignment
      Left = 0
      Center
      Right
    End Enum
 
    Public Class PrinterCodepage
            Public Shared ReadOnly _
        Cp437 As New PrinterCodepage(0, Encoding.GetEncoding(437)),
        Cp852 As New PrinterCodepage(18, Encoding.GetEncoding(852)),
        Cp866 As New PrinterCodepage(17, Encoding.GetEncoding(866)),
        Cp1252 As New PrinterCodepage(16, Encoding.GetEncoding(1252)),
        Cp1252_Munbyn As New PrinterCodepage(11, Encoding.GetEncoding(1252))

            Private ReadOnly _pageNumber As Byte
      Private ReadOnly _encoding As Encoding
 
      Private Sub New(ByVal pageNumber As Byte, ByVal encoding As Encoding)
        Me._pageNumber = pageNumber
        Me._encoding = encoding
      End Sub
 
      Public ReadOnly Property PageNumber() As Byte
        Get
          Return _pageNumber
        End Get
      End Property
 
      Public ReadOnly Property Encoding() As Encoding
        Get
          Return _encoding
        End Get
      End Property
    End Class
 
    ' Steuercodes für neue Zeile
    Private Shared ReadOnly newLineBytes As Byte() = {&HA}
 
    ' Interner Buffer
    Private memstr As New MemoryStream()
 
    ' Zustand des Druckers
    Private printerEnc As PrinterCodepage = PrinterCodepage.Cp437
    Private underline As Boolean = False
    Private underlineDouble As Boolean = False
    Private emphasized As Boolean = False
    Private redColor As Boolean = False
    Private currentFontX As Integer = 0
    Private currentFontY As Integer = 0
    Private panelButtonsEnabled As Boolean = True
    Private _alignment As Alignment = Alignment.Left
 
    ''' <summary>
    ''' Erstellt eine neue Instanz. Dadurch wird automatisch ein 
    ''' "Initialize Printer"-Command (ESC '@') in den Puffer geschrieben, der 
    ''' den Drucker auf die Standardwerte zurücksetzt, damit der Zustand des 
    ''' Druckers dem Zustand dieses Objektes entspricht.
    ''' </summary>
    Public Sub New()
      ' Initialize Printer Command senden
      Dim buf As Byte() = New Byte() {&H1B, &H40}
      memstr.Write(buf, 0, buf.Length)
    End Sub
 
    ''' <summary>
    ''' Schreibt den angegebenen String.
    ''' </summary>
    ''' <remarks>
    ''' Bei diesem Vorgang werden automatisch
    ''' Bytes, die in den ASCII-Steuerzeichenbereich fallen (0x00-0x1F sowie 0x7F), 
    ''' durch Leerzeichen ersetzt. So wird verhindert, dass Strings aus fremden 
    ''' Quellen den Zustand des Druckers verändern können.
    ''' 
    ''' Dies betrifft auch Zeilenumbrüche. Um einen Zeilenumbruch zu schreiben, 
    ''' verwenden Sie WriteLine() oder WriteLine(String).
    ''' 
    ''' Beachten Sie, dass bei den meisten Bondruckern eine Zeile erst gedruckt 
    ''' wird, sobald diese mit einem Zeilenumbruch abgeschlossen wird (auch wenn 
    ''' die Zeile voll ist, also so viele Zeichen belegt, wie der Drucker in eine 
    ''' Zeile drucken kann).
    ''' 
    ''' Beachten Sie, dass ESC/POS-Drucker standardmäßig die DOS-Codepage 437 
    ''' verwenden, die u. a.Rahmenzeichen, einige griechische Zeichen und 
    ''' mathematischen Symbole enthält. In den meisten Fällen empfiehlt sich für 
    ''' Textdruck (v.a. europäische Texte) aber ein Umschalten auf die Windows-
    ''' Codepage 1252 (Methode SetCodepage(PrinterCodepage)), da hier mehr
    ''' unterschiedliche Buchstaben (z.B. "ß"), typographische Satzzeichen und 
    ''' auch das €-Zeichen enthalten sind.
    ''' 
    ''' Für Texte in anderen europäischen Sprachen empfehlen sich noch die 
    ''' Codepage 852, die Zeichen  für mitteleuropäische Sprachen enthält, sowie 
    ''' die Codepage 866 mit kyrillischen Zeichen.
    ''' </remarks>
    ''' <param name="str">der String, der ausgegeben werden soll</param>
    Public Sub Write(ByVal str As String)
      Dim buf As Byte() = printerEnc.Encoding.GetBytes(str)
 
      ' Aufpassen, dass kein ASCII-Steuerzeichen vorkommt.
      For i As Integer = 0 To buf.Length - 1
        Dim c As Byte = buf(i)
        If Not (c >= &H20 AndAlso c <> &H7F) Then
          buf(i) = CByte(AscW(" "c))
        End If
      Next
 
      memstr.Write(buf, 0, buf.Length)
    End Sub
 
    ''' <summary>
    ''' Schreibt einen Zeilenumbruch (LF) zum Abschließen der aktuellen Zeile.
    ''' </summary>
    Public Sub WriteLine()
      memstr.Write(newLineBytes, 0, newLineBytes.Length)
    End Sub
 
    ''' <summary>
    ''' Schreibt den angegebenen String und danach einen Zeilenumbruch (LF). 
    ''' Siehe Dokumentation zur Write(String)-Methode.
    ''' </summary>
    ''' <param name="str">der String, der ausgegeben werden soll</param>
    Public Sub WriteLine(ByVal str As String)
      Write(str)
      WriteLine()
    End Sub
 
    ''' <summary>
    ''' ESC '*' 33 nL nH d1…dk: 
    ''' Druckt das angegebene Bild in Schwarz/Weiß. Die Höhe muss hierbei ein 
    ''' Vielfaches von 24 sein, da das Bild wie Textzeilen ohne Zeilenabstand 
    ''' gedruckt wird und eine Zeile (Font A) aus 24 Pixeln besteht. Die Breite 
    ''' sollte der Druckerauflösung entsprechen (z.B. 384 Pixel beim PRP-058-
    ''' Drucker mit 32 Zeichen pro Zeile).
    ''' </summary>
    ''' <remarks>
    ''' Die Druckauflösung hängt vom Druckermodell ab und beträgt normalerweise
    ''' 203,2 dpi (z.B. PRP-058) oder 180 dpi.
    ''' </remarks>
    ''' <param name="bmp">Die zu druckende Bitmap</param>
    Public Sub PrintImage(ByVal bmp As Bitmap)
      If bmp.Height Mod 24 <> 0 Then
        Throw New ArgumentException( _
          "Die Bildhöhe muss ein Vielfaches von 24 sein.")
      End If
      If bmp.Width > &H3FF Then
        Throw New ArgumentException( _
          "Die Bildbreite darf nicht größer als 1023 sein.")
      End If
 
      Dim buf As Byte()
 
      Dim zeilenAnfang As Byte() = New Byte() {&H1B, &H2A, 33, _
        CByte(bmp.Width And &HFF), CByte((bmp.Width >> 8) And &HFF)}
      Dim bildBuf As Byte() = New Byte(bmp.Width * 3 - 1) {}
 
      For i As Integer = 0 To bmp.Height \ 24 - 1
        ' Durch die einzelnen Zeilen gehen
        buf = zeilenAnfang
        memstr.Write(buf, 0, buf.Length)
 
        buf = bildBuf
        Array.Clear(buf, 0, buf.Length)
 
        For x As Integer = 0 To bmp.Width - 1
          For y As Integer = 0 To 23
            Dim byteIdx As Integer = y \ 8 + x * 3
            Dim c As Color = bmp.GetPixel(x, i * 24 + y)
            Dim bit As Boolean = c.GetBrightness() < 0.5F
            If bit Then
              buf(byteIdx) = buf(byteIdx) Or CByte(1 << (7 - (y Mod 8)))
            End If
          Next
        Next
 
        memstr.Write(buf, 0, buf.Length)
 
        If i <> bmp.Height \ 24 - 1 Then
          ' ESC J n für Paper Feed (n (hier 0) müsste eigentlich 48 sein für eine 
          ' Zeile, aber geht mit kleineren Werten auch)
          ' Nicht LF verwenden, weil bei LF der normale Zeilenabstand verwendet 
          ' wird!
          buf = New Byte() {&H1B, &H4A, 0}
        Else
          ' Am Schluss normaler Zeilenabstand nach unten.
          buf = New Byte() {&HA}
        End If
        memstr.Write(buf, 0, buf.Length)
      Next
 
    End Sub
 
    ''' <summary>
    ''' ESC '-' n:
    ''' Aktiviert oder deaktiviert den Underline-Modus.
    ''' </summary>
    ''' <param name="value">true, wenn der Underline-Modus aktiviert werden soll, 
    ''' sonst false</param>
    ''' <param name="doubleThickness">true, wenn die Linie 2 Pixel statt 1 Pixel 
    ''' dick sein soll</param>
    Public Sub SetUnderline(ByVal value As Boolean, ByVal doubleThickness As Boolean)
      If value <> underline OrElse (value And doubleThickness <> underlineDouble) Then
        underline = value
        underlineDouble = doubleThickness
        Dim buf As Byte() = New Byte() {&H1B, &H2D, _
          CByte(If(value, If(doubleThickness, 2, 1), 0))}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub

        ''' <summary>
        ''' ESC 'E' n:
        ''' Aktiviert oder deaktiviert den Fettschrift-Modus.
        ''' </summary>
        ''' <param name="value"></param>
        Public Sub SetBold(ByVal value As Boolean)
            If value <> emphasized Then
                emphasized = value
                Dim buf As Byte() = New Byte() {&H1B, &H45, CByte(If(value, 1, 0))}
                memstr.Write(buf, 0, buf.Length)
            End If
        End Sub

        ''' <summary>
        ''' ESC 'c' '5' n:
        ''' Aktiviert oder deaktiviert die Panel-Buttons am Drucker 
        ''' (z.B. den Feed-Button).
        ''' </summary>
        ''' <param name="value"></param>
        Public Sub SetPanelButtons(ByVal value As Boolean)
      If value <> panelButtonsEnabled Then
        panelButtonsEnabled = value
        Dim buf As Byte() = New Byte() {&H1B, &H63, &H35, CByte(If(value, 0, 1))}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub
 
    ''' <summary>
    ''' ESC 'r' n:
    ''' Setzt die Druckfarbe auf rot oder schwarz (nur bei Modellen, 
    ''' die dies unterstützen).
    ''' </summary>
    ''' <param name="red">true, wenn mit roter statt schwarzer Farbe gedruckt 
    ''' werden soll</param>
    Public Sub SetColor(ByVal red As Boolean)
      If red <> redColor Then
        redColor = red
        Dim buf As Byte() = New Byte() {&H1B, &H72, CByte(If(red, 1, 0))}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub
 
    ''' <summary>
    ''' GS '!' n:
    ''' Ändert die Font-Größe (Parameter jeweils von 0-7).
    ''' Standardwerte: x = 0, y = 0
    ''' </summary>
    ''' <param name="x">Horizontale Größe</param>
    ''' <param name="y">Vertikale Größe</param>
    Public Sub SetFontSize(ByVal x As Integer, ByVal y As Integer)
      If (x < 0 OrElse x > 7) OrElse (y < 0 OrElse y > 7) Then
        Throw New ArgumentException("x und y müssen im Bereich 0-7 liegen.")
      End If
 
      If x <> currentFontX OrElse y <> currentFontY Then
        currentFontX = x
        currentFontY = y
 
        Dim buf As Byte() = New Byte() {&H1D, &H21, CByte((x << 4) Or y)}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub
 
    ''' <summary>
    ''' ESC 't' n:
    ''' Schaltet auf die angegebene Codepage um.
    ''' </summary>
    Public Sub SetCodepage(ByVal codepage As PrinterCodepage)
      If printerEnc IsNot codepage Then
        printerEnc = codepage
        Dim buf As Byte() = New Byte() {&H1B, &H74, codepage.PageNumber}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub
 
    ''' <summary>
    ''' ESC 'a' n:
    ''' Setzt die angegebene Textausrichtung.
    ''' </summary>
    ''' <param name="alignment"></param>
    Public Sub SetAlignment(ByVal alignment As Alignment)
      If _alignment <> alignment Then
        _alignment = alignment
        Dim buf As Byte() = New Byte() {&H1B, &H61, CByte(alignment)}
        memstr.Write(buf, 0, buf.Length)
      End If
    End Sub
 
    ''' <summary>
    ''' ESC 'p' m t1 t2:
    ''' Erzeugt einen Pulse, um eine am Drucker angeschlossene Kassenlade 
    ''' zu öffnen.
    ''' </summary>
    ''' <param name="p5">false, wenn Pin #2 verwendet werden soll (m = 0);
    ''' true, wenn Pin #5 verwendet werden soll (m = 1)</param>
    ''' <param name="onTime">On Time: wert * 2 ms</param>
    ''' <param name="offTime">Off Time: wert * 2 ms</param>
    Public Sub SendDrawerKickoutPulse(ByVal p5 As Boolean, _
      ByVal onTime As Byte, ByVal offTime As Byte)
      Dim buf As Byte() = New Byte() {&H1B, &H70, CByte(If(p5, 1, 0)), _
        onTime, offTime}
      memstr.Write(buf, 0, buf.Length)
    End Sub
 
    ''' <summary>
    ''' GS 'V' m:
    ''' Schneidet das Papier (nur bei Modellen mit einer Auto-Cut-Funktion).
    ''' </summary>
    ''' <param name="fullCut">true, wenn ein voller Schnitt durchgeführt 
    ''' werden soll; false, wenn ein kleines Stück freigelassen 
    ''' werden soll</param>
    Public Sub CutPaper(ByVal fullCut As Boolean)
      Dim buf As Byte() = New Byte() {&H1D, &H56, CByte(If(fullCut, 0, 1))}
      memstr.Write(buf, 0, buf.Length)
    End Sub
 
    ''' <summary>
    ''' GS 'h' n:
    ''' Legt die Höhe von 2D-Barcodes (z.B. EAN-13 und EAN-8) fest.
    ''' Der Standardwert ist 162.
    ''' </summary>
    ''' <param name="height">die Höhe der Barcodes (zwischen 1 und 255)</param>
    Public Sub SetBarcodeHeight(ByVal height As Byte)
      If height = 0 Then Throw New ArgumentException("height darf nicht 0 sein.")
 
      Dim buf As Byte() = New Byte() {&H1D, &H68, height}
      memstr.Write(buf, 0, buf.Length)
    End Sub

        ''' <summary>
        ''' GS 'k' 67/68 12/7 d1…dk:
        ''' Druckt einen EAN-13- oder EAN-8-Barcode.
        ''' </summary>
        ''' <param name="ean">Bei EAN-13: 12- oder 13-stelliger Barcode; 
        ''' bei EAN-8: 7- oder 8-stelliger Barcode.
        ''' Bei 8 bzw. 13 Stellen wird die Prüfziffer ignoriert;
        ''' sie wird in allen Fällen vom Drucker berechnet.</param>
        Public Sub PrintEanBarcode(ByVal ean As String)
            If Not (ean.Length = 7 OrElse ean.Length = 8 OrElse
        ean.Length = 12 OrElse ean.Length = 13) Then
                Throw New ArgumentException("Ein EAN-13-Barcode muss aus 12 oder 13 " &
          "Zeichen bestehen; ein EAN-8-Barcode aus 7 oder 8 Zeichen.")
            End If

            Dim ean8 As Boolean = ean.Length = 7 OrElse ean.Length = 8
            For i As Integer = 0 To ean.Length - 1
                If ean(i) < "0"c OrElse ean(i) > "9"c Then
                    Throw New ArgumentException("Es dürfen nur Ziffern von " &
            "0-9 verwendet werden.")
                End If
            Next

            Dim buf As Byte() = New Byte(If(ean8, 11, 16) - 1) {}
            buf(0) = &H1D
            buf(1) = &H6B
            buf(2) = CByte(If(ean8, 68, 67))
            buf(3) = CByte(If(ean8, 7, 12))
            For i As Integer = 0 To (If(ean8, 7, 12)) - 1
                buf(4 + i) = CByte(AscW(ean(i)))
            Next

            memstr.Write(buf, 0, buf.Length)
        End Sub

        ''' <summary>
        ''' GS 'k' 69 1-255 d1…dk:
        ''' Druckt einen Code39-Barcode.
        ''' </summary>
        ''' <param name="ean">Bei Code39;
        ''' sie wird in allen Fällen vom Drucker berechnet.</param>
        Public Sub PrintBarcode(ByVal ean As String)

            Dim buf As Byte() = New Byte(ean.Length + 4) {}
            buf(0) = &H1D
            buf(1) = &H6B
            buf(2) = CByte(69)
            buf(3) = CByte(ean.Length)
            For i As Integer = 0 To (ean.Length) - 1
                buf(4 + i) = CByte(AscW(ean(i)))
            Next

            memstr.Write(buf, 0, buf.Length)
        End Sub

        ''' <summary>
        ''' Druckt gespeichertes Logo.
        ''' FS p 'n' 'm'
        ''' n = Bildnummer
        ''' m = Modus (Norm, Doublewidth, Doubleheight,Quadruple)
        ''' </summary>
        ''' <param name="number"></param>
        Public Sub PrintLogo(ByVal number As Integer)
            Dim buf As Byte() = New Byte() {&H1C, &H70, CByte(number), CByte(0)}
            memstr.Write(buf, 0, buf.Length)
        End Sub

        ''' <summary>
        ''' Gibt den aktuellen Pufferinhalt zurück und leert diesen anschließend.
        ''' </summary>
        ''' <returns></returns>
        Public Function GetCurrentBuffer() As Byte()
      Dim buf As Byte() = memstr.ToArray()
      memstr.Close()
      memstr = New MemoryStream()
      Return buf
    End Function
 
  End Class
 
End Namespace