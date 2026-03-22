Dim word, fso, outputFile
Set fso = CreateObject("Scripting.FileSystemObject")
Set word = CreateObject("Word.Application")
word.Visible = False
word.DisplayAlerts = 0

Dim outPath
outPath = "C:\Users\Joe\Work\dev\yada\translations\test_pages_out.txt"
Set outputFile = fso.CreateTextFile(outPath, True, True)

WScript.StdErr.WriteLine "Opening document..."
Dim doc
Dim cleanPath
cleanPath = "C:\Users\Joe\Work\dev\yada\translations\test_clean.docx"

' Use a small document for testing
Set doc = word.Documents.Open("C:\users\joe\work\dev\yada\docs\YY-s05v03-Babel-Chemah-Venomous.docx", , True)

Dim totalPages
totalPages = doc.ComputeStatistics(2)
WScript.StdErr.WriteLine "Opened, pages: " & totalPages

Dim p, footerPage, pageText
Dim rng, rngEnd

For p = 1 To totalPages
    On Error Resume Next

    Set rng = doc.GoTo(1, 1, p)

    If p < totalPages Then
        Set rngEnd = doc.GoTo(1, 1, p + 1)
        rng.End = rngEnd.Start
    Else
        rng.End = doc.Content.End
    End If

    footerPage = rng.Information(1)
    pageText = rng.Text

    If Len(pageText) > 4000 Then
        pageText = Left(pageText, 4000)
    End If

    pageText = Replace(pageText, vbCr, " ")
    pageText = Replace(pageText, vbLf, " ")
    pageText = Replace(pageText, vbTab, " ")
    pageText = Replace(pageText, "|", " ")

    If Err.Number = 0 Then
        outputFile.WriteLine footerPage & "|" & pageText
    Else
        WScript.StdErr.WriteLine "Error on page " & p & ": " & Err.Description
        Err.Clear
    End If
    On Error GoTo 0
Next

doc.Close False
outputFile.Close
word.Quit
WScript.StdErr.WriteLine "Done - " & totalPages & " pages"
