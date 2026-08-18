Imports System
Imports System.Collections.Generic
Imports System.Diagnostics
Imports System.Drawing
Imports System.Drawing.Drawing2D
Imports System.Drawing.Imaging
Imports System.Drawing.Printing
Imports System.IO
Imports System.Net
Imports System.Net.Http
Imports System.Text
Imports System.Threading.Tasks
Imports System.Windows.Forms
Imports Druckservice.BonPrinterUtilities
Imports Newtonsoft.Json

Public Class Druckservice
    Private debugMode As Boolean = False
    Private gedruckt As String = Format(Now, "dd.MM.yyyy - HH:mm:ss")
    Private WithEvents apiTimer As New Timer()
    Private apiUrl As String
    Private apiToken As String
    Private druckername_bon As String = My.Settings.BonDrucker 'Ini.ReadValue("Drucker", "BondruckerName", "", Application.StartupPath & "\config.ini")
    Private druckername_etikett As String = My.Settings.EtiDrucker 'Ini.ReadValue("Drucker", "EtikettenDruckerName", "", Application.StartupPath & "\config.ini")
    Private pollinginterval As Integer = Ini.ReadValue("Administrator", "PollingInterval", "", Application.StartupPath & "\config.ini")
    Private logFile As String = Path.Combine(Application.StartupPath, "log.txt")

    ' Globale Variablen (Klassenweit verfügbar)
    Private ConfigData As Dictionary(Of String, String)
    Private firma_name As String = ""
    Private firma_adresse As String = ""
    Private firma_plzort As String = ""
    Private firma_web As String = ""
    Private bank_name As String = ""
    Private bank_iban As String = ""
    Private bank_empfaenger As String = ""

    ' ===== Log-Methode (Konsole + Datei) =====
    Private Sub Log(msg As String)
        Dim line As String = $"[{Format(Now, "yyyy-MM-dd HH:mm:ss")}] {msg}"
        'Console.WriteLine(line)
        Try
            If tb_debug.InvokeRequired Then
                tb_debug.Invoke(Sub()
                                    tb_debug.AppendText(line & Environment.NewLine)
                                    tb_debug.ScrollToCaret()
                                    'File.AppendAllText(logFile, line & Environment.NewLine)
                                End Sub)
            Else
                tb_debug.AppendText(line & Environment.NewLine)
                tb_debug.ScrollToCaret()
                'File.AppendAllText(logFile, line & Environment.NewLine)
            End If
        Catch ex As Exception
            Console.WriteLine("FEHLER beim Schreiben in UI: " & ex.Message)
            'File.AppendAllText(logFile, line & Environment.NewLine)
        End Try
    End Sub

    ' ===== Form Load =====
    Private Sub Druckservice_Load(sender As Object, e As EventArgs) Handles MyBase.Load
        Log("Printservice wird gestartet...")
        tb_copyright.Text = "© " & DateTime.Now.Year & " - Schneebauer Stefan - Alle Rechte vorbehalten."
        ' Drucker in Comboboxen laden
        'Ini.WriteValue("Drucker", "EtikettenDruckerName", "MeinDruckerName", Application.StartupPath & "\config.ini")
        tb_printerEti.Items.Clear()
        tb_printerEti.Items.Add(">>> Bitte Drucker auswählen <<<")
        tb_printerEti.Items.AddRange(PrinterSettings.InstalledPrinters.Cast(Of String).ToArray())
        tb_printerBon.Items.Clear()
        tb_printerBon.Items.Add(">>> Bitte Drucker auswählen <<<")
        tb_printerBon.Items.AddRange(PrinterSettings.InstalledPrinters.Cast(Of String).ToArray())
        ' Vorbelegen, falls gespeichert
        If Not String.IsNullOrEmpty(My.Settings.EtiDrucker) AndAlso tb_printerEti.Items.Contains(My.Settings.EtiDrucker) Then
            tb_printerEti.SelectedItem = My.Settings.EtiDrucker
        Else
            tb_printerEti.SelectedIndex = 0
        End If

        If Not String.IsNullOrEmpty(My.Settings.EtiDruckerTyp) AndAlso tb_printerEti_typ.Items.Contains(My.Settings.EtiDruckerTyp) Then
            tb_printerEti_typ.SelectedItem = My.Settings.EtiDruckerTyp
        Else
            tb_printerEti_typ.SelectedIndex = 0
        End If

        If Not String.IsNullOrEmpty(My.Settings.BonDrucker) AndAlso tb_printerBon.Items.Contains(My.Settings.BonDrucker) Then
            tb_printerBon.SelectedItem = My.Settings.BonDrucker
        Else
            tb_printerBon.SelectedIndex = 0
        End If

        ' 1. Werte aus den Einstellungen laden
        tb_apiUrl.Text = My.Settings.apiUrl
        tb_apiToken.Text = My.Settings.apiToken

        ' 2. URL einlesen und bereinigen
        Dim inputUrl As String = tb_apiUrl.Text.Trim()

        If Not String.IsNullOrEmpty(inputUrl) Then

            ' --- SCHRITT A: Protokoll prüfen ---
            ' Standardmäßig http:// voranstellen, falls kein Protokoll angegeben wurde
            If Not inputUrl.StartsWith("http://", StringComparison.OrdinalIgnoreCase) AndAlso
       Not inputUrl.StartsWith("https://", StringComparison.OrdinalIgnoreCase) Then
                inputUrl = "http://" & inputUrl
            End If

            ' --- SCHRITT B: Pfad prüfen (/api) ---
            ' Alle Slashes am Ende entfernen für eine saubere Basis
            inputUrl = inputUrl.TrimEnd("/"c)

            ' Prüfen, ob die URL auf /api endet (oder index.php falls doch vorhanden)
            ' Wir prüfen primär auf /api, da dies laut deiner Anforderung das Wichtigste ist.
            If Not inputUrl.EndsWith("/api", StringComparison.OrdinalIgnoreCase) AndAlso
       Not inputUrl.EndsWith("/api/index.php", StringComparison.OrdinalIgnoreCase) Then

                ' Falls die URL auf /index.php ohne /api endet (unwahrscheinlich, aber sicherheitshalber)
                If inputUrl.EndsWith("/index.php", StringComparison.OrdinalIgnoreCase) Then
                    ' Ersetzt /index.php durch /api/index.php
                    inputUrl = inputUrl.Substring(0, inputUrl.Length - 10) & "/api/index.php"
                Else
                    ' Standardfall: /api einfach anhängen
                    inputUrl &= "/api"
                End If
            End If

            ' --- SCHRITT C: Speichern ---
            If My.Settings.apiUrl <> inputUrl Then
                My.Settings.apiUrl = inputUrl
                My.Settings.Save()
            End If
        End If

        ' 3. Ergebnis zurückschreiben
        apiUrl = inputUrl
        tb_apiUrl.Text = inputUrl
        apiToken = tb_apiToken.Text.Trim()

        ' Debug mode aus config.ini auslesen
        Try
            Dim dbg As String = Ini.ReadValue("Debug", "Mode", "", Application.StartupPath & "\config.ini")
            debugMode = (dbg.ToLower() = "true")
            Log("DEBUG MODE: " & debugMode)
        Catch ex As Exception
            Log("Konnte Debug-Status nicht lesen: " & ex.Message)
            debugMode = False
        End Try

        Call LoadConfiguration()

        btn_StartStop.Text = "Druckservice Starten"
        btn_StartStop.BackColor = Color.LightSalmon

        Log("Printservice bereit. Bitte Start drücken...")
        Log($"-------------------------------------------------------------------------------------------")
    End Sub

    Private Sub btn_StartStop_Click(sender As Object, e As EventArgs) Handles btn_StartStop.Click
        If apiTimer.Enabled Then
            ' Timer stoppen
            apiTimer.Stop()
            btn_StartStop.Text = "Druckservice Starten"
            btn_StartStop.BackColor = Color.LightSalmon
            Log(">>> Druckservice durch Benutzer GESTOPPT.")
        Else
            ' Validierung vor dem Start
            If tb_apiToken.Text.Trim() = "" Or tb_apiUrl.Text.Trim() = "" Then
                MessageBox.Show("Bitte API URL und Token eingeben!", "Fehlende Daten", MessageBoxButtons.OK, MessageBoxIcon.Warning)
                Return
            End If

            ' Timer starten
            apiTimer.Interval = pollinginterval * 1000
            apiTimer.Start()
            btn_StartStop.Text = "Druckservice Stoppen"
            btn_StartStop.BackColor = Color.LightGreen
            Log(">>> Druckservice durch Benutzer GESTARTET.")
        End If
    End Sub

    ' ===== Timer Tick =====
    Private Async Sub apiTimer_Tick(sender As Object, e As EventArgs) Handles apiTimer.Tick
        apiTimer.Stop()

        Log("Timer gestartet - prüfe API...")
        If tb_apiToken.Text.Trim() = "" Or tb_apiUrl.Text.Trim() = "" Then
            Log(">>> API URL oder Token ist leer, bitte Werte eingeben.")
            apiTimer.Start()
            Return
        End If
        If tb_printerBon.SelectedIndex <= 0 Or tb_printerEti.SelectedIndex <= 0 Then
            Log(">>> Bitte Drucker für Etiketten und Bons auswählen.")
            apiTimer.Start()
            Return
        End If

        Try
            Await CheckEtiketten()
            Await CheckAbholschein()
            Await CheckRechnungen()
        Catch ex As Exception
            Log("FEHLER bei API-Abfrage: " & ex.Message)
        End Try

        Dim sec As Integer = apiTimer.Interval \ 1000
        Log($"Timer beendet - nächster Lauf in {sec} Sekunden.")
        Log($"-------------------------------------------------------------------------------------------")

        ' Nur neu starten, wenn der Benutzer nicht zwischendurch auf "Stopp" gedrückt hat
        If Not btn_StartStop.Text.Contains("Starten") Then
            apiTimer.Start()
        End If
    End Sub

    ' ===== API Calls =====
    Private Async Function CheckEtiketten() As Task
        Dim url As String = apiUrl & "?route=/loescher&etikett_gedruckt=0&token=" & apiToken
        'Log("API CheckEtiketten: " & url)

        Dim json As String = Await New WebClient().DownloadStringTaskAsync(url)
        Dim data = JsonConvert.DeserializeObject(Of List(Of Dictionary(Of String, Object)))(json)
        Log($"Gefundene Etiketten: {data.Count}")

        For Each item In data
            Dim nummer = item("nummer").ToString()
            Dim name = SafeStr(item, "name")

            Log($"Drucke Etikett: {nummer} / Kunde: {name}")
            Print_Etikett(name, nummer, druckername_etikett)

            Log($"Markiere Etikett {nummer} als gedruckt")
            Await SetEtikettGedruckt(nummer)
        Next
    End Function

    Private Function LoadConfiguration() As Task
        Dim url As String = apiUrl & "?route=/config&token=" & apiToken

        Try
            Using client As New HttpClient()
                Log("Lade Konfiguration von API...")

                Dim json As String = client.GetStringAsync(url).Result
                ConfigData = JsonConvert.DeserializeObject(Of Dictionary(Of String, String))(json)

                If ConfigData IsNot Nothing Then
                    ' Werte global zuweisen
                    firma_name = GetVal("FIRMA_NAME")
                    firma_adresse = GetVal("FIRMA_ADRESSE")
                    firma_plzort = GetVal("FIRMA_PLZORT")
                    firma_web = GetVal("FIRMA_WEB")
                    bank_name = GetVal("BANK_NAME")
                    bank_iban = GetVal("BANK_IBAN")
                    bank_empfaenger = GetVal("BANK_EMPFAENGER")


                    'Log("------------------------- Konfiguration geladen ----------------------------")
                    Log($"Firma: {firma_name}")
                    Log($"Adresse: {firma_adresse}")
                    Log($"PLZ/Ort: {firma_plzort}")
                    Log($"Web: {firma_web}")
                    Log($"-------------------------------------------------------------------------------------------")
                End If
            End Using
        Catch ex As Exception
            Log($"Fehler beim Laden der Konfiguration: {ex.Message}")
        End Try
    End Function

    ' Hilfsfunktion um Abstürze bei fehlenden Keys zu vermeiden
    Private Function GetVal(key As String) As String
        If ConfigData IsNot Nothing AndAlso ConfigData.ContainsKey(key) Then
            Return ConfigData(key).ToString()
        End If
        Return ""
    End Function

    Private Async Function CheckAbholschein() As Task
        Dim url As String = apiUrl & "?route=/loescher&abholschein_gedruckt=0&token=" & apiToken
        'Log("API CheckAbholscheine: " & url)

        Dim json As String = Await New WebClient().DownloadStringTaskAsync(url)
        Dim data = JsonConvert.DeserializeObject(Of List(Of Dictionary(Of String, Object)))(json)
        Log($"Gefundene Abholscheine: {data.Count}")

        For Each item In data
            Dim nummer = item("nummer").ToString()
            Dim name As String = SafeStr(item, "name")
            Dim typ As String = SafeStr(item, "typ")
            Dim presnummer As Double = CDbl(item("preis"))
            Dim preis As String = presnummer.ToString("F2") & " €"
            Dim bezahlt As Boolean = (SafeStr(item, "bezahlt") = "1")
            Dim defekt As Boolean = (SafeStr(item, "defekt") = "1")
            Dim abgegeben = item("zeitstempel").ToString()

            Log($"Drucke Abholschein: {nummer} / Kunde: {name} / Typ: {typ} / Preis: {preis}€")
            Print_Abholschein(name, nummer, typ, preis, bezahlt, defekt, abgegeben, druckername_bon)

            Log($"Markiere Abholschein {nummer} als gedruckt")
            Await SetAbholscheinGedruckt(nummer)
        Next
    End Function

    Private Async Function CheckRechnungen() As Task
        Dim url As String = apiUrl & "?route=/rechnungen&rechnung_gedruckt=0&token=" & apiToken

        Dim json As String = Await New WebClient().DownloadStringTaskAsync(url)
        Dim data = JsonConvert.DeserializeObject(Of List(Of Dictionary(Of String, Object)))(json)
        Log($"Gefundene Rechnungen: {data.Count}")

        For Each item In data
            If item("rechnung_gedruckt").ToString() = "1" Then
                Log($"Rechnung {item("rechnungsnummer")} bereits gedruckt, überspringe.")
                Continue For
            End If

            Dim name As String = SafeStr(item, "name")
            Dim adresse As String = SafeStr(item, "adresse")
            Dim plz As String = SafeStr(item, "plz")
            Dim ort As String = SafeStr(item, "ort")
            Dim plzort As String = (plz & " " & ort).Trim()

            Dim anzahl As Integer = SafeInt(item, "anzahl_loescher")
            Dim loescherText As String = SafeStr(item, "loescher_text")
            Dim rnr As String = SafeStr(item, "rechnungsnummer")

            ' Preisdetails auslesen (Liste von Objekten mit anzahl und preis_pro_loescher)
            Dim preisDetails As New List(Of KeyValuePair(Of Integer, Double))()
            If item.ContainsKey("preis_pro_loescher") AndAlso item("preis_pro_loescher") IsNot Nothing Then
                Try
                    Dim jsonToken = Newtonsoft.Json.Linq.JToken.FromObject(item("preis_pro_loescher"))
                    If jsonToken.Type = Newtonsoft.Json.Linq.JTokenType.Array Then
                        For Each detail In jsonToken
                            Dim dAnzahl As Integer = 0
                            Dim dPreis As Double = 0.0

                            If detail("anzahl") IsNot Nothing Then Integer.TryParse(detail("anzahl").ToString(), dAnzahl)
                            If detail("preis_pro_loescher") IsNot Nothing Then Double.TryParse(detail("preis_pro_loescher").ToString().Replace(".", ","), dPreis)

                            preisDetails.Add(New KeyValuePair(Of Integer, Double)(dAnzahl, dPreis))
                        Next
                    End If
                Catch ex As Exception
                    Log("FEHLER beim Parsen von preis_pro_loescher: " & ex.Message)
                End Try
            End If

            ' Fallback falls preisDetails leer ist
            If preisDetails.Count = 0 Then
                preisDetails.Add(New KeyValuePair(Of Integer, Double)(anzahl, 0.0))
            End If

            Log($"Drucke Rechnung: {rnr} / Kunde: {name} / Anzahl: {anzahl}")
            Print_Rechnung(name, anzahl, loescherText, preisDetails, rnr, druckername_bon, adresse, plzort)

            Log($"Markiere Rechnung {rnr} als gedruckt")
            Await SetRechnungGedruckt(rnr)
        Next
    End Function

    Private Function SafeStr(dict As Dictionary(Of String, Object), key As String) As String
        If dict IsNot Nothing AndAlso dict.ContainsKey(key) AndAlso dict(key) IsNot Nothing Then
            Return dict(key).ToString()
        End If
        Return ""
    End Function

    Private Function SafeInt(dict As Dictionary(Of String, Object), key As String) As Integer
        If dict IsNot Nothing AndAlso dict.ContainsKey(key) AndAlso dict(key) IsNot Nothing Then
            Dim val As Integer
            If Integer.TryParse(dict(key).ToString(), val) Then Return val
        End If
        Return 0
    End Function

    Private Function SafeDouble(dict As Dictionary(Of String, Object), key As String) As Double
        If dict IsNot Nothing AndAlso dict.ContainsKey(key) AndAlso dict(key) IsNot Nothing Then
            Dim val As Double
            If Double.TryParse(dict(key).ToString().Replace(",", "."), Globalization.NumberStyles.Any, Globalization.CultureInfo.InvariantCulture, val) Then
                Return val
            End If
        End If
        Return 0
    End Function

    Private Async Function SetEtikettGedruckt(nummer As String) As Task
        Dim url As String = apiUrl & "?route=/loescher/" & nummer & "/etikett&token=" & apiToken

        Using client As New WebClient()
            client.Headers(HttpRequestHeader.ContentType) = "application/json"
            Await client.UploadStringTaskAsync(url, "PUT", "{}")
        End Using
    End Function


    Private Async Function SetAbholscheinGedruckt(nummer As String) As Task
        Dim url As String = apiUrl & "?route=/loescher/" & nummer & "/abholschein&token=" & apiToken

        Using client As New WebClient()
            client.Headers(HttpRequestHeader.ContentType) = "application/json"
            Await client.UploadStringTaskAsync(url, "PUT", "{}")
        End Using
    End Function

    Private Async Function SetRechnungGedruckt(rnr As String) As Task
        Dim url As String = apiUrl & "?route=/rechnungen/" & rnr & "/gedruckt&token=" & apiToken

        Using client As New WebClient()
            client.Headers(HttpRequestHeader.ContentType) = "application/json"
            Await client.UploadStringTaskAsync(url, "PUT", "{}")
        End Using
    End Function

    ' ===== Druckmethoden =====
    Public Sub Print_Etikett(name As String, loescher_id As String, druckername As String)
        Log($"Starte Etikettendruck: {loescher_id}")

        If debugMode Then
            Log("DEBUG: Etikett NICHT gedruckt (Debug Mode aktiv).")
            Exit Sub
        End If


        If tb_printerEti_typ.SelectedIndex = 0 Then 'Brother
            Print_Etikett_Brother(name, loescher_id, druckername)

        ElseIf tb_printerEti_typ.SelectedIndex = 1 Then 'Zebra
            Print_Etikett_Zebra(name, loescher_id, druckername)
        End If
    End Sub

    Private Sub Print_Etikett_Brother(name As String, loescher_id As String, druckername As String)
        Try
            Dim sPath As String = Application.StartupPath & "\Brother QL Serie\Feuerloescher.lbx"
            Dim objDoc As bpac.Document = CreateObject("bpac.Document")
            Dim id As Integer

            If objDoc.Open(sPath) <> False Then
                objDoc.SetPrinter(druckername, True)
                objDoc.GetObject("objName").Text = name

                Dim displayID As String = If(Integer.TryParse(loescher_id, id), id.ToString("000"), loescher_id) & " - " & Format(Now, "yy")
                objDoc.GetObject("objID").Text = displayID
                objDoc.GetObject("objBarcode").Text = CStr(loescher_id)

                objDoc.StartPrint("", bpac.PrintOptionConstants.bpoDefault)
                objDoc.PrintOut(1, bpac.PrintOptionConstants.bpoDefault)
                objDoc.EndPrint()
                objDoc.Close()
                Log($"Brother Etikett gedruckt: {loescher_id}")
            End If
        Catch ex As Exception
            Log("FEHLER Brother Druck: " & ex.Message)
        End Try
    End Sub

    Private Function mm(ByVal millimeter As Double) As Integer
        ' 203 DPI / 25.4 mm = 7.9921... also ca. 8 Dots pro mm
        Return CInt(Math.Round(millimeter * 8))
    End Function

    Private Sub Print_Etikett_Zebra(name As String, loescher_id As String, druckername As String)
        Try
            Dim id As Integer
            Dim shortID As String = If(Integer.TryParse(loescher_id, id), id.ToString("000"), loescher_id)
            Dim displayYear As String = shortID & " - " & Format(Now, "yy")
            Dim zpl As String = ""

            Dim hoehe As Integer = CInt(Ini.ReadValue("Drucker", "ZEBRA_ETI_HOEHE", "", Application.StartupPath & "\config.ini"))
            Dim breite As Integer = CInt(Ini.ReadValue("Drucker", "ZEBRA_ETI_BREITE", "", Application.StartupPath & "\config.ini"))

            ' Standardwerte für 50x25
            Dim barcodeX As Integer = 10
            Dim barcodeY As Integer = 18

            ' Anpassung falls es das große 57x32 Etikett ist
            If breite > 50 Then
                barcodeX = 13 ' Weiter rechts für 57mm
                barcodeY = 22 ' Tiefer für 32mm
            End If

            ' ZPL Code
            ' Erklärung: GB = Schwarzer Balken, FR = Text invertieren (weiß auf schwarz), FB = Zentrieren
            ' ZPL Code angepasst
            'zpl = "^XA" &
            '  "^CI28" &
            '  "^LT0" &
            '  "^PW" & mm(breite) &
            '  "^LL" & mm(hoehe) &
            '  "^LS0" &
            '  "^FO" & mm(3) & "," & mm(2) & "^GB" & mm(51) & "," & mm(10) & "," & mm(10) & "^FS" &
            '  "^FO" & mm(3) & "," & mm(4) & "^A0N," & mm(8) & "," & mm(8) & "^FB" & mm(51) & ",1,0,C^FR^FD" & displayYear & "^FS" &
            '  "^FO" & mm(3) & "," & mm(14) & "^A0N," & mm(5) & "," & mm(5) & "^FB" & mm(51) & ",1,0,C^FD" & name & "^FS" &
            '  "^BY3,3" &
            '  "^FO" & mm(barcodeX) & "," & mm(barcodeY) &
            '  "^B3N,N," & mm(7) & ",N,N" &' B3N,N,mm(x),N,N -> Das DRITTE N deaktiviert den Text unter dem Barcode
            '  "^FD" & loescher_id & "^FS" &
            '  "^XZ"

            ' ZPL dynamisch berechnet (Faktor 8 für 203 dpi)
            ' Berechnung der Y-Position für den Namen: 
            ' Wenn Höhe 25, dann etwas höher (104), sonst etwas tiefer (128)
            Dim nameY As Integer = If(hoehe <= 25, 104, 128)

            ' Text-Umlaute in UTF-8 Hex-Werte für ZPL (^FH) konvertieren
            Dim safeName As String = name _
                .Replace("ä", "_c3_a4") _
                .Replace("ö", "_c3_b6") _
                .Replace("ü", "_c3_bc") _
                .Replace("Ä", "_c3_84") _
                .Replace("Ö", "_c3_96") _
                .Replace("Ü", "_c3_9c") _
                .Replace("ß", "_c3_9f")

            zpl = "^XA^CI28^LT0^PW" & (breite * 8) & "^LL" & (hoehe * 8) & "^LS0" &
              "^FO16,16^GB" & ((breite - 4) * 8) & ",80,80^FS" &
              "^FO16,32^A0N,64,64^FB" & ((breite - 4) * 8) & ",1,0,C^FR^FD" & displayYear & "^FS" &
              "^FO16," & nameY & "^A0N,40,40^FB" & ((breite - 4) * 8) & ",1,0,C^FH^FD" & safeName & "^FS" &
              "^BY3,3^FO" & (barcodeX * 8) & "," & (barcodeY * 8) & "^B3N,N,56,N,N^FD" & loescher_id & "^FS^XZ"

            ' Senden über die RawPrinterHelper Klasse
            If ZPLRawPrinterHelper.SendStringToPrinter(druckername, zpl) Then
                Log($"Zebra Etikett gedruckt (ZPL): {loescher_id}")
                zpl = ""
            Else
                Log("FEHLER: Zebra Druck konnte nicht gestartet werden.")
            End If
        Catch ex As Exception
            Log("FEHLER Zebra Druck: " & ex.Message)
        End Try
    End Sub

    Public Sub Print_Abholschein(name As String, loescher_id As String, typ As String, preis As String, bezahlt As Boolean, defekt As Boolean, zeitstempel As String, druckername As String)
        Log($"Starte Druck Abholschein: {loescher_id}")
        RunPrintMethod(Sub(p) Print_Thermal_Abholschein(p, name, loescher_id, typ, preis, bezahlt, defekt, zeitstempel), druckername)
        Log($"Druck Abholschein abgeschlossen: {loescher_id}")
    End Sub

    Public Sub Print_Rechnung(name As String, anzahl As Integer, loescherText As String, preisDetails As List(Of KeyValuePair(Of Integer, Double)), rnummer As String, druckername As String, Optional adresse As String = "", Optional plzort As String = "")
        Log($"Starte Druck Rechnung: {rnummer}")
        RunPrintMethod(Sub(p) Print_Thermal_Rechnung(p, name, anzahl, loescherText, preisDetails, rnummer, adresse, plzort), druckername)
        Log($"Druck Rechnung abgeschlossen: {rnummer}")
    End Sub

    ' ===== Simulation Pfad =====
    Private Function GetSimPath() As String
        Dim p = Application.StartupPath & "\Simulationsdrucke"
        If Not Directory.Exists(p) Then Directory.CreateDirectory(p)
        Return p
    End Function

    ' ===== Simulation in Datei schreiben =====
    Private Sub WriteSimulationFile(filename As String, content As String)
        Try
            Dim fullpath = Path.Combine(GetSimPath(), filename)
            File.WriteAllText(fullpath, content, Encoding.UTF8)
            Log("SIMULATION gespeichert: " & fullpath)
            Log("SIM-PFAD: " & fullpath)
            Log("Ordner existiert: " & Directory.Exists(fullpath))
        Catch ex As Exception
            Log("FEHLER beim Schreiben der Simulation: " & ex.Message)
        End Try
    End Sub

    Private Sub RunPrintMethod(method As Action(Of EscPosPrinter), druckername As String)
        Dim printer As New EscPosPrinter()

        If Ini.ReadValue("Drucker", "Epson", "", Application.StartupPath & "\config.ini") = True Then
            printer.SetCodepage(EscPosPrinter.PrinterCodepage.Cp1252)
        Else
            printer.SetCodepage(EscPosPrinter.PrinterCodepage.Cp1252_Munbyn)
        End If

        ' Text/Bild erzeugen
        method(printer)

        ' Abschluss
        printer.WriteLine()
        printer.WriteLine()
        printer.WriteLine()
        printer.WriteLine()
        printer.CutPaper(True)

        ' ESC/POS Buffer holen
        Dim bytes As Byte() = printer.GetCurrentBuffer()

        ' ===== DEBUG → Simulation =====
        If debugMode Then
            Log("DEBUG: Simulation statt echtem Druck.")
            Exit Sub
        End If

        ' ===== ECHTER DRUCK =====
        Using p As New RawPrinter(druckername)
            Using doc As RawPrinter.RawDocumentStream = p.CreateDocument("Druck")
                doc.Write(bytes, 0, bytes.Length)
            End Using
        End Using
    End Sub

    ' ===== ESC/POS Methoden =====
    Private Sub Print_Thermal_Abholschein(p As EscPosPrinter, name As String, loescher_id As String, typ As String, preis As String, bezahlt As Boolean, defekt As Boolean, zeitstempel As String)
        Dim info As String = ""
        If typ = "Voller Preis" Then info = ">>> BEZAHLT <<<"
        If typ = "Gratis" Then info = ">>> GRATIS <<<"
        If defekt = True Then info = ">>> DEFEKT <<<"
        If typ = "Rabatt" Then info = ">>> RABATT <<<"
        If (defekt = False And Not typ = "Gratis" And bezahlt = False) Then info = ">>> NICHT BEZAHLT <<<"

        Try
            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine()
            p.SetFontSize(1, 1)
            p.SetUnderline(True, True)
            p.WriteLine("==== ABHOLSCHEIN ====")
            p.WriteLine()
            p.SetUnderline(False, False)
            p.SetFontSize(0, 0)
            p.SetUnderline(True, True)
            p.SetBold(True)
            p.WriteLine("Schein muss beim Abholen abgegeben werden!")
            p.SetBold(False)
            p.SetUnderline(False, False)

            If Ini.ReadValue("Drucker", "LogoAufBon", "", Application.StartupPath & "\config.ini") = True Then
                If Ini.ReadValue("Drucker", "LogoName", "", Application.StartupPath & "\config.ini") <> "" Then
                    Try
                        p.WriteLine()
                        Dim image As Image = Image.FromFile(Application.StartupPath & "\Images\" & Ini.ReadValue("Drucker", "LogoName", "", Application.StartupPath & "\config.ini"))
                        Log("Logo gefunden, versuche zu drucken: " & Application.StartupPath & "\Images\" & Ini.ReadValue("Drucker", "LogoName", "", Application.StartupPath & "\config.ini"))
                        Dim bitmap As New Bitmap(image)
                        Dim newbitmap As Bitmap = ScaleImage(bitmap, 180, 500)
                        p.PrintImage(newbitmap)
                    Catch ex As Exception
                        Log("FEHLER beim Drucken des Logos auf Abholschein: " & ex.Message)
                    End Try
                End If
            End If

            p.WriteLine()
            p.WriteLine("Feuerlöscherüberprüfung " + Format(Now, "yyyy"))
            p.WriteLine()
            p.WriteLine(firma_name)
            p.WriteLine(firma_adresse)
            p.WriteLine(firma_plzort)
            p.WriteLine(firma_web)

            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            p.SetFontSize(2, 2)
            p.WriteLine(loescher_id)
            p.SetFontSize(0, 0)

            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine()
            p.SetBarcodeHeight(130)
            p.PrintBarcode(loescher_id)

            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))

            p.SetAlignment(EscPosPrinter.Alignment.Left)
            p.SetBold(True)
            p.Write("    Kunde: ")
            p.SetBold(False)
            p.WriteLine(name)

            p.SetBold(True)
            p.Write("    Betrag: ")
            p.SetBold(False)
            p.WriteLine(preis)

            p.SetBold(True)
            p.Write("    Abgegeben: ")
            p.SetBold(False)
            p.WriteLine(zeitstempel)

            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            If info <> "" Then
                p.SetFontSize(1, 1)
                p.WriteLine(info)
                p.SetFontSize(0, 0)
            End If

            p.WriteLine()
            p.WriteLine("=".PadRight(42, "="))
            p.WriteLine()

            p.SetBold(True)
            p.Write("Gedruckt: ")
            p.SetBold(False)
            p.WriteLine(Format(Now, "dd.MM.yyyy - HH:mm:ss"))

            p.WriteLine()
            p.SetUnderline(True, True)
            p.SetBold(True)
            p.WriteLine("Schein muss beim Abholen abgegeben werden!")
            p.SetBold(False)
            p.SetUnderline(False, False)
            p.WriteLine()
        Catch ex As Exception
            MessageBox.Show(ex.Message, "Fehler...", MessageBoxButtons.OK, MessageBoxIcon.Error)
            Log("FEHLER beim Drucken Abholschein: " & ex.Message)
        End Try
    End Sub

    Private Sub Print_Thermal_Rechnung(p As EscPosPrinter, name As String, anzahl As Integer, loescherText As String, preisDetails As List(Of KeyValuePair(Of Integer, Double)), rnummer As String, Optional adresse As String = "", Optional plzort As String = "")
        ' Gesamtsumme dynamisch über alle Preisgruppen berechnen
        Dim preisGes As Double = 0.0
        For Each detail In preisDetails
            preisGes += (detail.Key * detail.Value)
        Next

        Try
            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine()
            p.SetFontSize(1, 1)
            p.SetUnderline(True, True)
            p.WriteLine("RECHNUNG")
            p.WriteLine()
            p.SetUnderline(False, False)
            p.SetFontSize(0, 0)

            If Ini.ReadValue("Drucker", "LogoAufRechnung", "", Application.StartupPath & "\config.ini") = True Then
                If Ini.ReadValue("Drucker", "LogoName", "", Application.StartupPath & "\config.ini") <> "" Then
                    Try
                        p.WriteLine()
                        Dim image As Image = Image.FromFile(Application.StartupPath & "\Images\" & Ini.ReadValue("Drucker", "LogoName", "", Application.StartupPath & "\config.ini"))
                        Dim bitmap As New Bitmap(image)
                        Dim newbitmap As Bitmap = ScaleImage(bitmap, 180, 500)
                        p.PrintImage(newbitmap)
                        p.WriteLine()
                    Catch
                    End Try
                End If
            End If

            p.WriteLine("Feuerlöscherüberprüfung " + Format(Now, "yyyy"))
            p.WriteLine()
            p.WriteLine(firma_name)
            p.WriteLine(firma_adresse)
            p.WriteLine(firma_plzort)
            p.WriteLine(firma_web)

            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            p.SetAlignment(EscPosPrinter.Alignment.Left)
            p.SetBold(True)
            p.Write("   Rechnungsnummer: ")
            p.SetBold(False)
            p.WriteLine(CStr(rnummer))

            p.SetBold(True)
            p.Write("   Kunde: ")
            p.SetBold(False)
            p.WriteLine(name)

            If adresse <> "" Then
                p.SetBold(True)
                p.Write("   Adresse: ")
                p.SetBold(False)
                p.WriteLine(adresse)
                p.SetBold(True)
                p.Write("   Ort: ")
                p.SetBold(False)
                p.WriteLine(plzort)
            End If

            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            p.SetBold(True)
            p.Write("Anzahl Löscher Gesamt: ")
            p.SetBold(False)
            p.WriteLine(anzahl & " Stück")
            p.WriteLine()

            p.SetBold(True)
            p.WriteLine("Preisdetails:")
            p.SetBold(False)

            ' Mehrzeiligen Löscher-Text (inkl. gemischter Preisgruppen) ausgeben
            If Not String.IsNullOrEmpty(loescherText) Then
                For Each line As String In loescherText.Split(New Char() {ControlChars.Lf, ControlChars.Cr}, StringSplitOptions.RemoveEmptyEntries)
                    p.WriteLine("   " & line.Trim())
                Next
            End If

            p.WriteLine()
            p.SetFontSize(1, 0)
            p.Write("Gesamtpreis: ")
            p.WriteLine(preisGes.ToString("###0.00") + "€")
            p.SetFontSize(0, 0)

            p.WriteLine()
            p.WriteLine("=".PadRight(42, "="))
            p.WriteLine()
            p.WriteLine("Betrag dankend erhalten!")
            p.WriteLine("Gedruckt: " + gedruckt)
            p.WriteLine()
            p.WriteLine("Danke für Ihren Besuch!")
            p.WriteLine()
        Catch ex As Exception
            MessageBox.Show(ex.Message, "Fehler...", MessageBoxButtons.OK, MessageBoxIcon.Error)
            Log("FEHLER beim Drucken Rechnung: " & ex.Message)
        End Try
    End Sub

    Private Sub tb_printerEti_SelectedIndexChanged(sender As Object, e As EventArgs) Handles tb_printerEti.SelectedIndexChanged
        If tb_printerEti.SelectedItem IsNot Nothing Then
            My.Settings.EtiDrucker = tb_printerEti.SelectedItem.ToString()
            My.Settings.Save()
        End If
    End Sub
    Private Sub tb_printerEtiTyp_SelectedIndexChanged(sender As Object, e As EventArgs) Handles tb_printerEti_typ.SelectedIndexChanged
        If tb_printerEti_typ.SelectedItem IsNot Nothing Then
            My.Settings.EtiDruckerTyp = tb_printerEti_typ.SelectedItem.ToString()
            My.Settings.Save()
        End If
    End Sub
    Private Sub tb_printerBon_SelectedIndexChanged(sender As Object, e As EventArgs) Handles tb_printerBon.SelectedIndexChanged
        If tb_printerBon.SelectedItem IsNot Nothing Then
            My.Settings.BonDrucker = tb_printerBon.SelectedItem.ToString()
            My.Settings.Save()
        End If
    End Sub

    ' ===== Hilfsmethoden =====
    Public Function ScaleImage(ByVal OldImage As Image, ByVal TargetHeight As Integer, ByVal TargetWidth As Integer) As Image
        Dim NewHeight As Integer = TargetHeight
        Dim NewWidth As Integer = NewHeight / OldImage.Height * OldImage.Width

        If NewWidth > TargetWidth Then
            NewWidth = TargetWidth
            NewHeight = NewWidth / OldImage.Width * OldImage.Height
        End If

        Dim adjustedHeight As Integer = NewHeight
        While adjustedHeight Mod 24 <> 0
            adjustedHeight += 1
        End While

        If NewWidth > &H3FF Then
            NewWidth = &H3FF
            adjustedHeight = NewWidth / OldImage.Width * OldImage.Height
        End If

        Return New Bitmap(OldImage, NewWidth, adjustedHeight)
    End Function

    Private Sub tb_apiUrl_Leave(sender As Object, e As EventArgs) Handles tb_apiUrl.Leave
        My.Settings.apiUrl = tb_apiUrl.Text.Trim()
        apiUrl = If(tb_apiUrl.Text, "")
    End Sub
    Private Sub tb_apiToken_Leave(sender As Object, e As EventArgs) Handles tb_apiToken.Leave
        My.Settings.apiToken = tb_apiToken.Text.Trim()
        apiToken = If(tb_apiToken.Text, "")
    End Sub

    Private Sub bt_rechnungen_Click(sender As Object, e As EventArgs) Handles bt_rechnungen.Click
        Process.Start("explorer.exe", Application.StartupPath & "\config.ini")
    End Sub

    Private Sub bt_restart_Click(sender As Object, e As EventArgs) Handles bt_restart.Click
        Application.Restart()
    End Sub
End Class