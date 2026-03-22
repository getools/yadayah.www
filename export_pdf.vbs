Option Explicit

Dim fso, objWord, objShell
Dim docsFolder, file, docPath, pdfPath
Dim count, errCount

Set fso = CreateObject("Scripting.FileSystemObject")
Set objShell = CreateObject("WScript.Shell")

docsFolder = "C:\Users\Joe\Work\dev\yada\docs"

' Start Word
Set objWord = CreateObject("Word.Application")
objWord.Visible = False
objWord.DisplayAlerts = 0 ' wdAlertsNone

count = 0
errCount = 0

' Process each YY-*.docx file
Dim folder
Set folder = fso.GetFolder(docsFolder)
Dim files
Set files = folder.Files

Dim fileNames()
Dim fileCount
fileCount = 0

' Collect matching filenames first
Dim f
For Each f In files
    If Left(f.Name, 3) = "YY-" And Right(LCase(f.Name), 5) = ".docx" Then
        If InStr(f.Name, "_backup") = 0 And InStr(f.Name, "_updated") = 0 Then
            fileCount = fileCount + 1
            ReDim Preserve fileNames(fileCount - 1)
            fileNames(fileCount - 1) = f.Name
        End If
    End If
Next

WScript.Echo "Found " & fileCount & " documents to export"

Dim i, doc, baseName
For i = 0 To fileCount - 1
    docPath = docsFolder & "\" & fileNames(i)
    baseName = Left(fileNames(i), Len(fileNames(i)) - 5)
    pdfPath = docsFolder & "\" & baseName & ".pdf"

    WScript.Echo "  [" & (i + 1) & "/" & fileCount & "] " & fileNames(i)

    On Error Resume Next
    Set doc = objWord.Documents.Open(docPath, , True)
    If Err.Number <> 0 Then
        WScript.Echo "    ERROR opening: " & Err.Description
        Err.Clear
        errCount = errCount + 1
    Else
        ' ExportAsFixedFormat: OutputFileName, ExportFormat (17=PDF),
        ' OpenAfterExport, OptimizeFor (0=print), Range, From, To,
        ' Item, IncludeDocProps, KeepIRM, CreateBookmarks (1=headings),
        ' DocStructureTags, BitmapMissingFonts, UseISO19005_1
        doc.ExportAsFixedFormat pdfPath, 17, False, 0, 0, , , , True, , 1, True, , False
        If Err.Number <> 0 Then
            WScript.Echo "    ERROR exporting: " & Err.Description
            Err.Clear
            errCount = errCount + 1
        Else
            count = count + 1
            WScript.Echo "    OK -> " & baseName & ".pdf"
        End If
        doc.Close 0 ' wdDoNotSaveChanges
    End If
    On Error GoTo 0
Next

objWord.Quit 0
Set objWord = Nothing

WScript.Echo ""
WScript.Echo "Done. " & count & " PDFs created, " & errCount & " errors."
