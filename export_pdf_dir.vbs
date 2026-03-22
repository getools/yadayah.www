Option Explicit

' Export all YY-s*.docx files to PDF in a specified output directory.
' Usage: cscript export_pdf_dir.vbs <output_directory>

Dim fso, objWord
Dim docsFolder, outFolder
Dim count, errCount, fileCount

Set fso = CreateObject("Scripting.FileSystemObject")

docsFolder = "C:\Users\Joe\Work\dev\yada\docs"

' Get output directory from command line argument
If WScript.Arguments.Count < 1 Then
    WScript.Echo "Usage: cscript export_pdf_dir.vbs <output_directory>"
    WScript.Quit 1
End If
outFolder = WScript.Arguments(0)

' Create output directory if it doesn't exist
If Not fso.FolderExists(outFolder) Then
    fso.CreateFolder(outFolder)
    WScript.Echo "Created output directory: " & outFolder
End If

' Start Word
Set objWord = CreateObject("Word.Application")
objWord.Visible = False
objWord.DisplayAlerts = 0 ' wdAlertsNone

count = 0
errCount = 0

' Collect matching filenames
Dim folder, files, f
Set folder = fso.GetFolder(docsFolder)
Set files = folder.Files

Dim fileNames()
fileCount = 0

For Each f In files
    If Left(f.Name, 3) = "YY-" And Right(LCase(f.Name), 5) = ".docx" Then
        If InStr(f.Name, "_backup") = 0 And InStr(f.Name, "_updated") = 0 Then
            fileCount = fileCount + 1
            ReDim Preserve fileNames(fileCount - 1)
            fileNames(fileCount - 1) = f.Name
        End If
    End If
Next

WScript.Echo "Exporting " & fileCount & " documents to: " & outFolder

Dim i, doc, docPath, baseName, pdfPath
For i = 0 To fileCount - 1
    docPath = docsFolder & "\" & fileNames(i)
    baseName = Left(fileNames(i), Len(fileNames(i)) - 5)
    pdfPath = outFolder & "\" & baseName & ".pdf"

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
