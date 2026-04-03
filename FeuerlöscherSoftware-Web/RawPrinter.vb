Option Strict On
Imports System
Imports System.Runtime.InteropServices
Imports System.IO
 
Namespace BonPrinterUtilities
 
  ''' <summary>
  ''' Eine Klasse zum Kapseln eines Druckers. Mit der Methode
  ''' <see cref="RawPrinter.CreateDocument">CreateDocument(String)</see> 
  ''' kann ein neues Dokument im RAW-Format erstellt werden.
  ''' 
  ''' Pro RawPrinter-Instanz kann immer nur eine RawDocumentStream-Instanz 
  ''' gleichzeitig aktiv sein. Nach der Freigabe einer RawDocumentStream-
  ''' Instanz kann wieder ein neues Dokument erstellt werden.
  ''' </summary>
  ''' <remarks>
  ''' Diese Klasse ist nicht threadsicher. Wenn verschiedene Threads drucken
  ''' wollen, muss für jeden Thread ein eigenens RawPrinter-Objekt 
  ''' erstellt werden.
  ''' </remarks>
  Public Class RawPrinter
    Implements IDisposable
 
    ' API-Deklarationen
    <DllImport("winspool.Drv", EntryPoint:="OpenPrinterW", SetLastError:=True, _
    CharSet:=CharSet.Unicode, ExactSpelling:=True, _
    CallingConvention:=CallingConvention.Winapi)> _
    Private Shared Function OpenPrinter(ByVal szPrinter As String, _
      ByRef hPrinter As IntPtr, ByVal pd As IntPtr) As Boolean
    End Function
 
    <DllImport("winspool.Drv", EntryPoint:="ClosePrinter", SetLastError:=True, _
    ExactSpelling:=True, CallingConvention:=CallingConvention.Winapi)> _
    Private Shared Function ClosePrinter(ByVal hPrinter As IntPtr) As Boolean
    End Function
 
    <DllImport("winspool.drv", EntryPoint:="GetPrinterDriver2W", _
    SetLastError:=True, CharSet:=CharSet.Unicode, ExactSpelling:=True, _
    CallingConvention:=CallingConvention.Winapi)> _
    Private Shared Function GetPrinterDriver2(ByVal hWnd As IntPtr, _
      ByVal hPrinter As IntPtr, ByVal pEnvironment As String, _
      ByVal Level As Integer, ByVal pDriverInfo As IntPtr, _
      ByVal cbBuf As Integer, ByRef pcbNeeded As Integer) As Boolean
    End Function
 
    Private Const ERROR_INSUFFICIENT_BUFFER As Integer = 122
    Private Const PRINTER_DRIVER_XPS As Integer = &H2
 
    <StructLayout(LayoutKind.Sequential, CharSet:=CharSet.Unicode)> _
    Private Class DRIVER_INFO_8
      Public cVersion As UInteger
      Public pName As String
      Public pEnvironment As String
      Public pDriverPath As String
      Public pDataFile As String
      Public pConfigFile As String
      Public pHelpFile As String
      Public pDependentFiles As String
      Public pMonitorName As String
      Public pDefaultDataType As String
      Public pszzPreviousNames As String
      Public ftDriverDate As ComTypes.FILETIME
      Public dwlDriverVersion As ULong
      Public pszMfgName As String
      Public pszOEMUrl As String
      Public pszHardwareID As String
      Public pszProvider As String
      Public pszPrintProcessor As String
      Public pszVendorSetup As String
      Public pszzColorProfiles As String
      Public pszInfPath As String
      Public dwPrinterDriverAttributes As UInteger
      Public pszzCoreDriverDependencies As String
      Public ftMinInboxDriverVerDate As ComTypes.FILETIME
      Public dwlMinInboxDriverVerVersion As ULong
    End Class
 
    ' Instanz-Variablen
    Private ReadOnly hPrinter As IntPtr = IntPtr.Zero
    Private ReadOnly xpsDriver As Boolean
    Private currentDoc As RawDocumentStream = Nothing
    Private disposed As Boolean = False
 
    Private ReadOnly Property HandlePrinter() As IntPtr
      Get
        If disposed Then Throw New ObjectDisposedException("RawPrinter")
 
        Return hPrinter
      End Get
    End Property
 
    ''' <summary>
    ''' Erstellt eine neue Instanz.
    ''' </summary>
    ''' <param name="printerName">Der Name des zu verwendenden Druckers</param>
    ''' <exception cref="System.ComponentModel.Win32Exception">Beim Aufruf einer
    ''' Win32-API trat ein Fehler auf.</exception>
    Public Sub New(ByVal printerName As String)
      CheckApiCall(OpenPrinter(printerName, hPrinter, IntPtr.Zero))
 
      xpsDriver = IsXpsDriver()
    End Sub
 
    ''' <summary>
    ''' Überprüft den Rückgabewert einer Win32-API-Funktion und wirft bei Bedarf
    ''' eine Exception.
    ''' </summary>
    ''' <param name="retVal"></param>
    Private Shared Sub CheckApiCall(ByVal retVal As Boolean)
      If Not retVal Then
        Throw New System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error())
      End If
    End Sub
 
    ''' <summary>
    ''' Gibt die nativen Resourcen dieses Objekts frei.
    ''' </summary>
    ''' <exception cref="System.ComponentModel.Win32Exception">Beim Aufruf einer
    ''' Win32-API trat ein Fehler auf.</exception>
    Public Sub Dispose() Implements IDisposable.Dispose
      Dispose(True)
      GC.SuppressFinalize(Me)
    End Sub
 
    Protected Overridable Sub Dispose(ByVal disposing As Boolean)
      If Not disposed Then
        ClosePrinter(HandlePrinter)
        disposed = True
      End If
    End Sub
 
    Protected Overrides Sub Finalize()
      Try
        ' Im Destruktor disposen
        Dispose(False)
      Finally
        MyBase.Finalize()
      End Try
    End Sub
 
 
    Private canCreateDocument As Boolean = False
    ''' <summary>
    ''' Erstellt ein neues RawPrintDocument.
    ''' </summary>
    ''' <param name="docName">Der Name des Dokuments</param>
    ''' <returns>Das neue Dokument</returns>
    ''' <exception cref="System.ComponentModel.Win32Exception">Beim Aufruf einer
    ''' Win32-API trat ein Fehler auf.</exception>
    ''' <exception cref="InvalidOperationException">Es wurde bereits ein Dokument
    ''' für diesen Drucker erstellt und noch nicht freigegeben.</exception>
    Public Function CreateDocument(ByVal docName As String) As RawDocumentStream
      If disposed Then
        Throw New ObjectDisposedException("RawPrinter")
      End If
 
      If currentDoc IsNot Nothing Then
        Throw New InvalidOperationException("Pro RawPrinter kann immer nur " & _
          "1 RawDocumentStream-Objekt erzeugt werden.")
      End If
 
      canCreateDocument = True
      Try
        currentDoc = New RawDocumentStream(Me, docName)
        Return currentDoc
      Finally
        canCreateDocument = False
      End Try
    End Function
 
    ''' <summary>
    ''' Überprüft, ob es sich bei dem Treiber dieses Druckers um einen 
    ''' XPS-Treiber handelt.
    ''' </summary>
    ''' <remarks>
    ''' Bei Windows-Versionen kleiner als Windows 8 (NT 6.2), also 
    ''' Windows Vista und Windows 7, wird immer
    ''' false zurückgegeben.
    ''' </remarks>
    ''' <returns>true, wenn es sich um einen XPS-Treiber handelt</returns>
    Private Function IsXpsDriver() As Boolean
      Dim level As Integer = 8
 
      ' Nachschauen, wieviel Bytes als Buffer reserviert werden müssen.
      ' In diesem Fall muss GetPrinterDriver2 mit ERROR_INSUFFICIENT_BUFFER 
      ' failen.
      Dim bytesNeeded As Integer
      GetPrinterDriver2(IntPtr.Zero, HandlePrinter, Nothing, level, _
        IntPtr.Zero, 0, bytesNeeded)
      If Marshal.GetLastWin32Error() <> ERROR_INSUFFICIENT_BUFFER Then
        CheckApiCall(False)
      End If
 
      Dim driverInf As DRIVER_INFO_8
 
      ' Nativen Speicher mit geforderter Größe erstellen
      Dim pBytesLength As Integer = Math.Max(bytesNeeded, _
        Marshal.SizeOf(GetType(DRIVER_INFO_8)))
      Dim pBytes As IntPtr = Marshal.AllocHGlobal(pBytesLength)
      Try
 
        CheckApiCall(GetPrinterDriver2(IntPtr.Zero, HandlePrinter, Nothing, _
          level, pBytes, pBytesLength, bytesNeeded))
 
        ' Der Anfang des Bytesarrays enthält die Structure; der hintere Teil kann
        ' die Strings enthalten, auf die die Pointer in der Structure zeigen.
        ' Hier jetzt die Structure in ein verwaltetes Objekt marshallen.
        driverInf = DirectCast(Marshal.PtrToStructure(pBytes, _
        GetType(DRIVER_INFO_8)), DRIVER_INFO_8)
 
      Finally
        Marshal.FreeHGlobal(pBytes)
        pBytes = IntPtr.Zero ' Pointer clearen
      End Try
 
      ' Prüfen, ob das Flag PRINTER_DRIVER_XPS gesetzt ist
      Return (driverInf.dwPrinterDriverAttributes And PRINTER_DRIVER_XPS) <> 0
    End Function
 
    ''' <summary>
    ''' Ermöglicht das Senden von RAW-Dokumenten (Bytes) an den Drucker.
    ''' </summary>
    ''' <remarks>
    ''' Die Methoden StartPage() und EndPage() dienen zum Unterteilen des 
    ''' Druckauftrags in Seiten (wichtig, wenn der Windows Spooler verwendet 
    ''' wird und man Dokumente senden will, die lange zum Erstellen brauchen, 
    ''' da dieser bis zur Fertigestellung einer Seite wartet, bevor diese 
    ''' tatsächlich zum Drucker gesendet wird).
    ''' </remarks>
    Public Class RawDocumentStream
      Inherits Stream
 
      <StructLayout(LayoutKind.Sequential, CharSet:=CharSet.Unicode)> _
      Private Class DOCINFO
        Public pDocName As String
        Public pOutputFile As String
        Public pDataType As String
      End Class
 
      ' API-Deklarationen
      <DllImport("winspool.Drv", EntryPoint:="StartDocPrinterW", _
      SetLastError:=True, CharSet:=CharSet.Unicode, ExactSpelling:=True, _
      CallingConvention:=CallingConvention.Winapi)> _
      Private Shared Function StartDocPrinter(ByVal hPrinter As IntPtr, _
        ByVal level As Int32, <[In](), _
        MarshalAs(UnmanagedType.LPStruct)> ByVal di As DOCINFO) As Boolean
      End Function
 
      <DllImport("winspool.Drv", EntryPoint:="EndDocPrinter", _
      SetLastError:=True, ExactSpelling:=True, _
      CallingConvention:=CallingConvention.Winapi)> _
      Private Shared Function EndDocPrinter(ByVal hPrinter As IntPtr) As Boolean
      End Function
 
      <DllImport("winspool.Drv", EntryPoint:="StartPagePrinter", _
      SetLastError:=True, ExactSpelling:=True, _
      CallingConvention:=CallingConvention.Winapi)> _
      Private Shared Function StartPagePrinter(ByVal hPrinter As IntPtr) As Boolean
      End Function
 
      <DllImport("winspool.Drv", EntryPoint:="EndPagePrinter", _
      SetLastError:=True, ExactSpelling:=True, _
      CallingConvention:=CallingConvention.Winapi)> _
      Private Shared Function EndPagePrinter(ByVal hPrinter As IntPtr) As Boolean
      End Function
 
      <DllImport("winspool.Drv", EntryPoint:="WritePrinter", _
      SetLastError:=True, ExactSpelling:=True, _
      CallingConvention:=CallingConvention.Winapi)> _
      Private Shared Function WritePrinter(ByVal hPrinter As IntPtr, _
        ByVal pBytes As IntPtr, ByVal dwCount As Integer, _
        ByRef dwWritten As Integer) As Boolean
      End Function
 
      Private ReadOnly printer As RawPrinter
      Private pageStarted As Boolean = False
      Private disposed As Boolean = False
 
      Friend Sub New(ByVal printer As RawPrinter, ByVal docName As String)
        If Not printer.canCreateDocument Then
          ' Schauen, dass das über den Printer erstellt wird
          Throw New InvalidOperationException()
        End If
 
        Me.printer = printer
 
        Dim di As New DOCINFO()
        ' Wenn es sich um einen v4-Treiber (XPS-basiert, eingeführt mit 
        ' Windows 8) handelt, muss "XPS_PASS" statt "RAW" verwendet werden.
        ' Siehe: http://support.microsoft.com/kb/2779300
                di.pDataType = If(printer.xpsDriver, "XPS_PASS", "RAW")
        di.pDocName = docName
 
        CheckApiCall(StartDocPrinter(printer.HandlePrinter, 1, di))
      End Sub
 
      ''' <summary>
      ''' Startet eine neue Seite (nur wichtig für den Windows Spooler).
      ''' </summary>
      ''' <exception cref="System.ComponentModel.Win32Exception">Beim 
      ''' Aufruf einer Win32-API trat ein Fehler auf.</exception>
      Public Sub StartPage()
        If Not pageStarted Then
          CheckApiCall(StartPagePrinter(printer.HandlePrinter))
          pageStarted = True
        End If
      End Sub
 
      ''' <summary>
      ''' Beendet die aktuelle Seite.
      ''' </summary>
      ''' <exception cref="System.ComponentModel.Win32Exception">Beim 
      ''' Aufruf einer Win32-API trat ein Fehler auf.</exception>
      Public Sub FinishPage()
        If pageStarted Then
          CheckApiCall(EndPagePrinter(printer.HandlePrinter))
          pageStarted = False
        End If
      End Sub
 
      Protected Overrides Sub Dispose(ByVal disposing As Boolean)
        If Not disposed Then
          If disposing Then
            If pageStarted Then
              FinishPage()
            End If
 
            CheckApiCall(EndDocPrinter(printer.HandlePrinter))
 
            printer.currentDoc = Nothing
          End If
 
          disposed = True
        End If
 
        MyBase.Dispose(disposing)
      End Sub
 
      Public Overrides ReadOnly Property CanRead() As Boolean
        Get
          Return False
        End Get
      End Property
 
      Public Overrides ReadOnly Property CanSeek() As Boolean
        Get
          Return False
        End Get
      End Property
 
      Public Overrides ReadOnly Property CanWrite() As Boolean
        Get
          Return True
        End Get
      End Property
 
      Public Overrides Sub Flush()
        ' Ignorieren
      End Sub
 
      Public Overrides ReadOnly Property Length() As Long
        Get
          Throw New NotSupportedException()
        End Get
      End Property
 
      Public Overrides Property Position() As Long
        Get
          Throw New NotSupportedException()
        End Get
        Set(ByVal value As Long)
          Throw New NotSupportedException()
        End Set
      End Property
 
      Public Overrides Function Read(ByVal buffer As Byte(), _
        ByVal offset As Integer, ByVal count As Integer) As Integer
        Throw New NotSupportedException()
      End Function
 
      Public Overrides Function Seek(ByVal offset As Long, _
        ByVal origin As SeekOrigin) As Long
        Throw New NotSupportedException()
      End Function
 
      Public Overrides Sub SetLength(ByVal value As Long)
        Throw New NotSupportedException()
      End Sub
 
      ''' <summary>
      ''' Sendet die angegebenen Bytes an den Drucker.
      ''' </summary>
      ''' <param name="buffer"></param>
      ''' <param name="offset"></param>
      ''' <param name="count"></param>
      ''' <exception cref="System.ComponentModel.Win32Exception">Beim Aufruf 
      ''' einer Win32-API trat ein Fehler auf.</exception>
      Public Overrides Sub Write(ByVal buffer As Byte(), _
        ByVal offset As Integer, ByVal count As Integer)
        If disposed Then
          Throw New ObjectDisposedException("RawDocumentStream")
        End If
 
        If offset < 0 OrElse count < 0 OrElse offset + count > buffer.Length Then
          Throw New ArgumentException()
        End If
 
        If Not pageStarted Then
          StartPage()
        End If
 
        ' Pointer auf Byte-Array holen.
        ' In C#: fixed (byte* pBytes = buffer) { ... }, 
        ' dann Pointerarithmetik verwenden
        Dim hgc As GCHandle = GCHandle.Alloc(buffer, GCHandleType.Pinned)
        Try
 
          Dim bytesCompleted As Integer = 0
          While count - bytesCompleted > 0
            Dim tempBytesWritten As Integer = 0
 
            CheckApiCall(WritePrinter(printer.HandlePrinter, _
              Marshal.UnsafeAddrOfPinnedArrayElement(buffer, offset + bytesCompleted), _
              count - bytesCompleted, tempBytesWritten))
 
            If Not (tempBytesWritten > 0) Then
              Exit While
            End If
 
            bytesCompleted += tempBytesWritten
 
          End While
 
        Finally
          hgc.Free()
        End Try
      End Sub
    End Class
 
  End Class
 
End Namespace