Imports System.Runtime.InteropServices
Imports System.IO

Public Class ZPLRawPrinterHelper
    ' Strukturen für die Windows API
    <StructLayout(LayoutKind.Sequential, CharSet:=CharSet.Ansi)>
    Private Structure DOCINFOA
        <MarshalAs(UnmanagedType.LPStr)> Public pDocName As String
        <MarshalAs(UnmanagedType.LPStr)> Public pOutputFile As String
        <MarshalAs(UnmanagedType.LPStr)> Public pDataType As String
    End Structure

    ' API Deklarationen
    <DllImport("winspool.drv", EntryPoint:="OpenPrinterA", SetLastError:=True, CharSet:=CharSet.Ansi, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function OpenPrinter(ByVal pPrinterName As String, ByRef phPrinter As IntPtr, ByVal pDefault As IntPtr) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="StartDocPrinterA", SetLastError:=True, CharSet:=CharSet.Ansi, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function StartDocPrinter(ByVal hPrinter As IntPtr, ByVal level As Int32, ByRef pDocInfo As DOCINFOA) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="StartPagePrinter", SetLastError:=True, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function StartPagePrinter(ByVal hPrinter As IntPtr) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="WritePrinter", SetLastError:=True, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function WritePrinter(ByVal hPrinter As IntPtr, ByVal pBytes As IntPtr, ByVal dwCount As Int32, ByRef dwWritten As Int32) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="EndPagePrinter", SetLastError:=True, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function EndPagePrinter(ByVal hPrinter As IntPtr) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="EndDocPrinter", SetLastError:=True, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function EndDocPrinter(ByVal hPrinter As IntPtr) As Boolean
    End Function

    <DllImport("winspool.drv", EntryPoint:="ClosePrinter", SetLastError:=True, ExactSpelling:=True, CallingConvention:=CallingConvention.StdCall)>
    Private Shared Function ClosePrinter(ByVal hPrinter As IntPtr) As Boolean
    End Function

    ''' <summary>
    ''' Sendet einen ZPL-String direkt an den Drucker.
    ''' </summary>
    Public Shared Function SendStringToPrinter(ByVal printerName As String, ByVal zplString As String) As Boolean
        Dim pBytes As IntPtr
        Dim dwCount As Int32
        ' String in ANSI-Bytes umwandeln (Zebra nutzt Standard-ANSI für ZPL)
        dwCount = zplString.Length
        pBytes = Marshal.StringToCoTaskMemAnsi(zplString)

        Dim success As Boolean = SendBytesToPrinter(printerName, pBytes, dwCount)

        Marshal.FreeCoTaskMem(pBytes)
        Return success
    End Function

    ''' <summary>
    ''' Sendet rohe Bytes an den Drucker.
    ''' </summary>
    Public Shared Function SendBytesToPrinter(ByVal printerName As String, ByVal pBytes As IntPtr, ByVal dwCount As Int32) As Boolean
        Dim hPrinter As New IntPtr(0)
        Dim di As New DOCINFOA()
        Dim dwWritten As Int32 = 0
        Dim success As Boolean = False

        di.pDocName = "Zebra Label Job"
        di.pDataType = "RAW"

        ' Drucker öffnen
        If OpenPrinter(printerName.Normalize(), hPrinter, IntPtr.Zero) Then
            ' Dokument starten
            If StartDocPrinter(hPrinter, 1, di) Then
                ' Seite starten
                If StartPagePrinter(hPrinter) Then
                    ' Bytes schreiben
                    success = WritePrinter(hPrinter, pBytes, dwCount, dwWritten)
                    EndPagePrinter(hPrinter)
                End If
                EndDocPrinter(hPrinter)
            End If
            ClosePrinter(hPrinter)
        End If

        Return success
    End Function
End Class
