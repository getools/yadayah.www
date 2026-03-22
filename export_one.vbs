Option Explicit

Dim fso, objWord
Dim docPath, pdfPath

Set fso = CreateObject("Scripting.FileSystemObject")

docPath = "C:\Users\Joe\Work\dev\yada\docs\YY-s01v01-An Intro to God-Dabarym-Words.docx"
pdfPath = "C:\Users\Joe\Work\dev\yada\docs\YY-s01v01-An Intro to God-Dabarym-Words_new.pdf"

Set objWord = CreateObject("Word.Application")
objWord.Visible = False
objWord.DisplayAlerts = 0

Dim doc
On Error Resume Next
Set doc = objWord.Documents.Open(docPath, , True)
If Err.Number <> 0 Then
    WScript.Echo "ERROR opening: " & Err.Description
    objWord.Quit 0
    WScript.Quit 1
End If

doc.ExportAsFixedFormat pdfPath, 17, False, 0, 0, , , , True, , 1, True, , False
If Err.Number <> 0 Then
    WScript.Echo "ERROR exporting: " & Err.Description
    doc.Close 0
    objWord.Quit 0
    WScript.Quit 1
End If
On Error GoTo 0

doc.Close 0
objWord.Quit 0

WScript.Echo "OK -> " & pdfPath
