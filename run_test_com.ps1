Stop-Process -Name WINWORD -Force -ErrorAction SilentlyContinue
Start-Sleep -Seconds 3

Set-Location 'C:\Users\Joe\Work\dev\yada\translations'

$proc = Start-Process -FilePath 'cscript' -ArgumentList '//NoLogo','test_s01v01_com.vbs' -NoNewWindow -PassThru -RedirectStandardOutput 'test_stdout.txt' -RedirectStandardError 'test_stderr.txt'

if (-not $proc.WaitForExit(300000)) {
    Stop-Process -Name WINWORD -Force -ErrorAction SilentlyContinue
    $proc.Kill()
    Write-Output 'TIMED OUT after 300s'
} else {
    Write-Output ('Exit code: ' + $proc.ExitCode)
}

Write-Output '--- STDOUT ---'
Get-Content test_stdout.txt -ErrorAction SilentlyContinue
Write-Output '--- STDERR ---'
Get-Content test_stderr.txt -ErrorAction SilentlyContinue
