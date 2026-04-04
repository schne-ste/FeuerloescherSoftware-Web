<Global.Microsoft.VisualBasic.CompilerServices.DesignerGenerated()> _
Partial Class Druckservice
    Inherits System.Windows.Forms.Form

    'Das Formular überschreibt den Löschvorgang, um die Komponentenliste zu bereinigen.
    <System.Diagnostics.DebuggerNonUserCode()> _
    Protected Overrides Sub Dispose(ByVal disposing As Boolean)
        Try
            If disposing AndAlso components IsNot Nothing Then
                components.Dispose()
            End If
        Finally
            MyBase.Dispose(disposing)
        End Try
    End Sub

    'Wird vom Windows Form-Designer benötigt.
    Private components As System.ComponentModel.IContainer

    'Hinweis: Die folgende Prozedur ist für den Windows Form-Designer erforderlich.
    'Das Bearbeiten ist mit dem Windows Form-Designer möglich.  
    'Das Bearbeiten mit dem Code-Editor ist nicht möglich.
    <System.Diagnostics.DebuggerStepThrough()> _
    Private Sub InitializeComponent()
        Dim resources As System.ComponentModel.ComponentResourceManager = New System.ComponentModel.ComponentResourceManager(GetType(Druckservice))
        Me.tb_debug = New System.Windows.Forms.RichTextBox()
        Me.Label2 = New System.Windows.Forms.Label()
        Me.tb_printerEti = New System.Windows.Forms.ComboBox()
        Me.Label1 = New System.Windows.Forms.Label()
        Me.tb_printerBon = New System.Windows.Forms.ComboBox()
        Me.tb_apiUrl = New System.Windows.Forms.TextBox()
        Me.Label3 = New System.Windows.Forms.Label()
        Me.tb_apiToken = New System.Windows.Forms.TextBox()
        Me.Label4 = New System.Windows.Forms.Label()
        Me.bt_rechnungen = New System.Windows.Forms.Button()
        Me.bt_restart = New System.Windows.Forms.Button()
        Me.Label5 = New System.Windows.Forms.Label()
        Me.tb_copyright = New System.Windows.Forms.Label()
        Me.SuspendLayout()
        '
        'tb_debug
        '
        resources.ApplyResources(Me.tb_debug, "tb_debug")
        Me.tb_debug.Name = "tb_debug"
        '
        'Label2
        '
        resources.ApplyResources(Me.Label2, "Label2")
        Me.Label2.Name = "Label2"
        '
        'tb_printerEti
        '
        resources.ApplyResources(Me.tb_printerEti, "tb_printerEti")
        Me.tb_printerEti.DropDownStyle = System.Windows.Forms.ComboBoxStyle.DropDownList
        Me.tb_printerEti.FormattingEnabled = True
        Me.tb_printerEti.Items.AddRange(New Object() {resources.GetString("tb_printerEti.Items"), resources.GetString("tb_printerEti.Items1"), resources.GetString("tb_printerEti.Items2")})
        Me.tb_printerEti.Name = "tb_printerEti"
        '
        'Label1
        '
        resources.ApplyResources(Me.Label1, "Label1")
        Me.Label1.Name = "Label1"
        '
        'tb_printerBon
        '
        resources.ApplyResources(Me.tb_printerBon, "tb_printerBon")
        Me.tb_printerBon.DropDownStyle = System.Windows.Forms.ComboBoxStyle.DropDownList
        Me.tb_printerBon.FormattingEnabled = True
        Me.tb_printerBon.Items.AddRange(New Object() {resources.GetString("tb_printerBon.Items"), resources.GetString("tb_printerBon.Items1"), resources.GetString("tb_printerBon.Items2")})
        Me.tb_printerBon.Name = "tb_printerBon"
        '
        'tb_apiUrl
        '
        resources.ApplyResources(Me.tb_apiUrl, "tb_apiUrl")
        Me.tb_apiUrl.Name = "tb_apiUrl"
        '
        'Label3
        '
        resources.ApplyResources(Me.Label3, "Label3")
        Me.Label3.Name = "Label3"
        '
        'tb_apiToken
        '
        resources.ApplyResources(Me.tb_apiToken, "tb_apiToken")
        Me.tb_apiToken.Name = "tb_apiToken"
        '
        'Label4
        '
        resources.ApplyResources(Me.Label4, "Label4")
        Me.Label4.Name = "Label4"
        '
        'bt_rechnungen
        '
        resources.ApplyResources(Me.bt_rechnungen, "bt_rechnungen")
        Me.bt_rechnungen.Name = "bt_rechnungen"
        Me.bt_rechnungen.UseVisualStyleBackColor = True
        '
        'bt_restart
        '
        resources.ApplyResources(Me.bt_restart, "bt_restart")
        Me.bt_restart.Name = "bt_restart"
        Me.bt_restart.UseVisualStyleBackColor = True
        '
        'Label5
        '
        resources.ApplyResources(Me.Label5, "Label5")
        Me.Label5.Name = "Label5"
        '
        'tb_copyright
        '
        resources.ApplyResources(Me.tb_copyright, "tb_copyright")
        Me.tb_copyright.Name = "tb_copyright"
        '
        'Druckservice
        '
        resources.ApplyResources(Me, "$this")
        Me.AutoScaleMode = System.Windows.Forms.AutoScaleMode.Font
        Me.Controls.Add(Me.tb_copyright)
        Me.Controls.Add(Me.Label5)
        Me.Controls.Add(Me.bt_restart)
        Me.Controls.Add(Me.bt_rechnungen)
        Me.Controls.Add(Me.tb_apiToken)
        Me.Controls.Add(Me.Label4)
        Me.Controls.Add(Me.tb_apiUrl)
        Me.Controls.Add(Me.Label3)
        Me.Controls.Add(Me.Label1)
        Me.Controls.Add(Me.tb_printerBon)
        Me.Controls.Add(Me.Label2)
        Me.Controls.Add(Me.tb_printerEti)
        Me.Controls.Add(Me.tb_debug)
        Me.MaximizeBox = False
        Me.Name = "Druckservice"
        Me.ResumeLayout(False)
        Me.PerformLayout()

    End Sub

    Friend WithEvents tb_debug As RichTextBox
    Friend WithEvents Label2 As Label
    Friend WithEvents tb_printerEti As ComboBox
    Friend WithEvents Label1 As Label
    Friend WithEvents tb_printerBon As ComboBox
    Friend WithEvents tb_apiUrl As TextBox
    Friend WithEvents Label3 As Label
    Friend WithEvents tb_apiToken As TextBox
    Friend WithEvents Label4 As Label
    Friend WithEvents bt_rechnungen As Button
    Friend WithEvents bt_restart As Button
    Friend WithEvents Label5 As Label
    Friend WithEvents tb_copyright As Label
End Class
