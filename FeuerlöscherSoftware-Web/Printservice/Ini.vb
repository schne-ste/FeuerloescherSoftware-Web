Imports System.Runtime.InteropServices
Imports System.Text

Public Class Ini

    Public Const INI_WRITE_ERROR = 0
    Public Const INI_WRITE_SUCCESS = 1

    Public Shared Function ReadValue(section As String, key As String, [default] As String, file As String) As String
        Dim bufferLength = 1024
        Dim stringBuilder = New StringBuilder(bufferLength)
        Dim length = GetPrivateProfileString(section, key, [default], stringBuilder, bufferLength, file)
        Dim bufferedValue = stringBuilder.ToString()
        Dim value = bufferedValue.Substring(0, length)
        Return value
    End Function

    Public Shared Function WriteValue(section As String, key As String, value As String, file As String) As Boolean
        Dim resultCode = WritePrivateProfileString(section, key, value, file)
        Dim successfulWrite = resultCode = INI_WRITE_SUCCESS
        Return successfulWrite
    End Function

    <DllImport("kernel32.dll", SetLastError:=True)>
    Public Shared Function GetPrivateProfileString(lpAppName As String,
                        lpKeyName As String,
                        lpDefault As String,
                        lpReturnedString As StringBuilder,
                        nSize As Integer,
                        lpFileName As String) As Integer
    End Function

    <DllImport("kernel32.dll", SetLastError:=True)>
    Public Shared Function WritePrivateProfileString(lpAppName As String,
                        lpKeyName As String,
                        lpString As String,
                        lpFileName As String) As Boolean
    End Function

End Class