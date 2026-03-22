Option Explicit

Dim word, doc, rng, fso
Set fso = CreateObject("Scripting.FileSystemObject")

Set word = CreateObject("Word.Application")
word.Visible = False
word.DisplayAlerts = 0
word.AutomationSecurity = 3 ' msoAutomationSecurityForceDisable

WScript.StdErr.WriteLine "Opening document with macros disabled..."

Dim docPath
docPath = "C:\users\joe\work\dev\yada\docs\YY-s01v01-An Intro to God-Dabarym-Words.docx"

' Open: FileName, ConfirmConversions=False, ReadOnly=True, AddToRecentFiles=False,
'       OpenAndRepair=False, NoEncodingDialog=True
Set doc = word.Documents.Open(docPath, False, True, False, , , , , , , , , , , True)

WScript.StdErr.WriteLine "Document opened! Pages: " & doc.ComputeStatistics(2)

' Test: get page number for a paragraph near the end containing "wa hayah"
Set rng = doc.Content.Duplicate
rng.Find.ClearFormatting
rng.Find.Text = "wa hayah"
rng.Find.Forward = True
rng.Find.Wrap = 0
rng.Find.Execute

If rng.Find.Found Then
    WScript.StdErr.WriteLine "Found 'wa hayah' at page (Info 1): " & rng.Information(1)
    WScript.StdErr.WriteLine "Found 'wa hayah' at page (Info 3): " & rng.Information(3)
    WScript.StdOut.WriteLine "INFO1=" & rng.Information(1) & " INFO3=" & rng.Information(3)
Else
    WScript.StdErr.WriteLine "Text not found"
End If

doc.Close False
word.Quit
WScript.StdErr.WriteLine "Done"
