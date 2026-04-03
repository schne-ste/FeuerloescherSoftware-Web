Imports System.Drawing
Imports System.Windows.Forms
Imports FeuerlöscherSoftware_Web.BonPrinterUtilities
Imports System.Net
Imports System.IO
Imports Newtonsoft.Json 'NuGet Package: Newtonsoft.Json

Public Class Printservice
    Private gedruckt As String = Format(Now, "dd.MM.yyyy - HH:mm:ss")

    Private Sub Printservice_Load(sender As Object, e As EventArgs) Handles MyBase.Load

    End Sub

    Public Sub Print_Etikett(name As String, loescher_id As String, druckername As String)
        Dim sPath As String
        sPath = (Application.StartupPath & "\Brother QL Serie\Feuerloescher.lbx")
        Dim DRUCKER As Boolean
        Dim objDoc As bpac.Document
        Dim id As Integer

        objDoc = CreateObject("bpac.Document")
        If objDoc.Open(sPath) <> False Then

            DRUCKER = objDoc.SetPrinter(druckername, True)

            objDoc.GetObject("objName").Text = name
            objDoc.GetObject("objID").Text = If(Integer.TryParse(loescher_id, id), id.ToString("000"), loescher_id)

            objDoc.GetObject("objBarcode").Text = CStr(loescher_id)
            objDoc.GetObject("objID").Text = If(Integer.TryParse(loescher_id, id), id.ToString("000"), loescher_id) & " - " & Format(Now, "yy")

            objDoc.StartPrint("", bpac.PrintOptionConstants.bpoDefault)
            objDoc.PrintOut(1, bpac.PrintOptionConstants.bpoDefault)
            objDoc.EndPrint()
            objDoc.Close()
        End If
    End Sub

    ' ✅ HIER: alle Parameter ergänzt
    Public Sub Print_Abholschein(name As String, loescher_id As String, typ As String, preis As String, bezahlt As Boolean, defekt As Boolean, zeitstempel As String, druckername As String)
        RunPrintMethod(Sub(p) Print_Thermal_Abholschein(p, name, loescher_id, typ, preis, bezahlt, defekt, zeitstempel), druckername)
    End Sub

    ' ✅ HIER: alle Parameter ergänzt
    Public Sub Print_Rechnung(typ As String, name As String, preisjeloescher As Double, anzahl As Integer, rnummer As String, druckername As String, Optional adresse As String = "", Optional plzort As String = "")
        RunPrintMethod(Sub(p) Print_Thermal_Rechnung(p, typ, name, preisjeloescher, anzahl, rnummer, adresse, plzort), druckername)
    End Sub

    Private Shared Sub RunPrintMethod(method As Action(Of EscPosPrinter), druckername As String)

        Dim printer As New EscPosPrinter()

        If Ini.ReadValue("Drucker", "Epson", "", Application.StartupPath & "\config.ini") = True Then
            printer.SetCodepage(EscPosPrinter.PrinterCodepage.Cp1252)
        Else
            printer.SetCodepage(EscPosPrinter.PrinterCodepage.Cp1252_Munbyn)
        End If

        method(printer)

        printer.WriteLine()
        printer.WriteLine()
        printer.WriteLine()
        printer.WriteLine()
        printer.CutPaper(True)

        Dim bytes As Byte() = printer.GetCurrentBuffer()

        Using p As New RawPrinter(druckername)
            Using doc As RawPrinter.RawDocumentStream = p.CreateDocument("Abholschein")
                doc.Write(bytes, 0, bytes.Length)
            End Using
        End Using
    End Sub

    Private Sub Print_Thermal_Abholschein(p As EscPosPrinter, name As String, loescher_id As String, typ As String, preis As String, bezahlt As Boolean, defekt As Boolean, zeitstempel As String)

        Dim info As String = ""
        If typ = "VollerPreis" Then info = ">>> BEZAHLT <<<"
        If typ = "Gratis" Then info = ">>> MITARBEITER <<<"
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
                        Dim bitmap As New Bitmap(image)
                        Dim newbitmap As Bitmap = ScaleImage(bitmap, 180, 500)
                        p.PrintImage(newbitmap)
                    Catch
                    End Try
                End If
            End If

            p.WriteLine()
            p.WriteLine("Feuerlöscherüberprüfung " + Format(Now, "yyyy"))
            p.WriteLine()
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Name", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Adresse", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "PLZOrt", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Website", "", Application.StartupPath & "\config.ini"))

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

            p.SetFontSize(1, 1)
            p.WriteLine(info)
            p.SetFontSize(0, 0)

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
        End Try
    End Sub

    Private Sub Print_Thermal_Rechnung(p As EscPosPrinter, typ As String, name As String, preisjeloescher As Double, anzahl As Integer, rnummer As String, Optional adresse As String = "", Optional plzort As String = "")

        Dim PreisGes As Double = anzahl * preisjeloescher

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
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Name", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Adresse", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "PLZOrt", "", Application.StartupPath & "\config.ini"))
            p.WriteLine(Ini.ReadValue("Feuerwehr", "Website", "", Application.StartupPath & "\config.ini"))

            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            p.SetAlignment(EscPosPrinter.Alignment.Left)
            p.SetBold(True)
            p.Write("      Rechnungsnummer: ")
            p.SetBold(False)
            p.WriteLine(CStr(rnummer))

            p.SetBold(True)
            p.Write("      Kunde: ")
            p.SetBold(False)
            p.WriteLine(name)

            If adresse <> "" Then
                p.SetBold(True)
                p.Write("      Adresse: ")
                p.SetBold(False)
                p.WriteLine(adresse)
                p.WriteLine(plzort)
            End If

            p.SetAlignment(EscPosPrinter.Alignment.Center)
            p.WriteLine()
            p.WriteLine("-".PadRight(42, "-"))
            p.WriteLine()

            p.SetBold(True)
            p.Write("Anzahl Löscher: ")
            p.SetBold(False)
            p.WriteLine(anzahl & " Stück")

            If typ = "Rabatt" Or typ = "Gratis" Then
                p.SetBold(True)
                p.Write("Preis je Löscher (Rabatt): ")
                p.SetBold(False)
                p.WriteLine(preisjeloescher.ToString("###0.00") + "€")
            Else
                p.SetBold(True)
                p.Write("Preis je Löscher: ")
                p.SetBold(False)
                p.WriteLine(preisjeloescher.ToString("###0.00") + "€")
            End If

            p.WriteLine()
            p.SetFontSize(1, 0)
            p.Write("Gesamtpreis: ")
            p.WriteLine(PreisGes.ToString("###0.00") + "€")
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
        End Try
    End Sub

    Public Function ScaleImage(ByVal OldImage As Image, ByVal TargetHeight As Integer, ByVal TargetWidth As Integer) As System.Drawing.Image
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


End Class