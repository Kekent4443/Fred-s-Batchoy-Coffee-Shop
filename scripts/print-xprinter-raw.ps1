# Local XP-58 RAW spooler. PHP receipt printing supplies validated text with
# -FromStdin; the other switches support manual printer diagnostics.
# Never expose this script as a web endpoint.
param(
    [switch]$Extended,
    [string]$ReceiptPdfPath,
    [switch]$FromStdin,
    [switch]$PreviewOnly
)

$printerName = 'Xprinter XP-58'
$printer = Get-Printer -Name $printerName -ErrorAction Stop
if ($printer.PortName -ne 'USB001' -or $printer.Datatype -ne 'RAW') {
    throw 'The expected local USB/RAW XP-58 printer configuration was not found.'
}
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;

public static class Xp58RawSpooler {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public sealed class DocInfo {
        public string pDocName;
        public string pOutputFile;
        public string pDatatype;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", CharSet = CharSet.Unicode, SetLastError = true)]
    public static extern int StartDocPrinter(IntPtr handle, int level, [In] DocInfo info);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool StartPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool WritePrinter(IntPtr handle, byte[] bytes, int count, out int written);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    public static extern bool ClosePrinter(IntPtr handle);
}
'@

$lines = @(
    'XP-58 INDEPENDENT RAW TEST',
    '01234567890123456789012345678901',
    'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdef',
    'IIIIIIIIIIIIIIIIIIIIIIIIIIIIIIII',
    'MMMMMMMMMMMMMMMMMMMMMMMMMMMMMMMM',
    'Text complete? No blank stripes?'
)
if ($Extended) {
    # Approximately receipt-length text to distinguish PDF/driver raster
    # issues from printer/power/transport trouble during sustained feeding.
    # Every line is numbered so a missing stripe is unambiguous.
    $lines = @('XP-58 EXTENDED RAW TEST - START')
    for ($lineNumber = 1; $lineNumber -le 50; $lineNumber++) {
        $lines += ('{0:D2} ABCDEFGHIJKLMNOPQRSTUVWXYZ' -f $lineNumber)
    }
    $lines += 'XP-58 EXTENDED RAW TEST - END'
}
if ($ReceiptPdfPath) {
    if ($Extended -or $FromStdin) { throw 'Choose only one test input.' }
    $resolvedPdf = (Resolve-Path -LiteralPath $ReceiptPdfPath -ErrorAction Stop).Path
    $pdfBytes = [System.IO.File]::ReadAllBytes($resolvedPdf)
    $pdfText = [System.Text.Encoding]::GetEncoding(1252).GetString($pdfBytes)
    if (-not $pdfText.StartsWith('%PDF-1.4') -or $pdfText -notmatch '/BaseFont /Courier') {
        throw 'This helper accepts only the application-generated plain-text receipt PDFs.'
    }
    $pattern = '/F[12] [0-9]+ Tf\s+[0-9.]+ [0-9.]+ Td\s+\(((?:\\.|[^\\)])*)\) Tj'
    $matches = [regex]::Matches($pdfText, $pattern)
    if ($matches.Count -lt 10) { throw 'The PDF did not contain a complete receipt text stream.' }
    $lines = foreach ($match in $matches) {
        $escaped = $match.Groups[1].Value
        $unescaped = [regex]::Replace($escaped, '\\([\\()])', '$1')
        # Avoid sending arbitrary control instructions from a PDF to the printer.
        [regex]::Replace($unescaped, '[^\x20-\x7E]', '?')
    }
}
if ($FromStdin) {
    if ($Extended) { throw 'Choose only one test input.' }
    $inputText = [Console]::In.ReadToEnd()
    if ($inputText.Length -gt 100000 -or $inputText -match '[^\x0A\x0D\x20-\x7E]') {
        throw 'The direct-print input is too large or contains unsupported control bytes.'
    }
    $lines = $inputText.TrimEnd("`r", "`n") -split "`r?`n"
    if ($lines.Count -lt 10) { throw 'The direct-print input is incomplete.' }
}
$payload = [System.Text.Encoding]::ASCII.GetBytes(($lines -join "`r`n") + "`r`n")
if ($PreviewOnly) {
    $lines
    return
}

$handle = [IntPtr]::Zero
$documentStarted = $false
$pageStarted = $false
if (-not [Xp58RawSpooler]::OpenPrinter($printerName, [ref]$handle, [IntPtr]::Zero)) {
    throw "Could not open the XP-58 printer (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))."
}
try {
    $info = New-Object Xp58RawSpooler+DocInfo
    $info.pDocName = if ($ReceiptPdfPath -or $FromStdin) { 'XP-58 receipt direct text' } elseif ($Extended) { 'XP-58 extended RAW diagnostic' } else { 'XP-58 independent ASCII diagnostic' }
    $info.pDatatype = 'RAW'
    $jobId = [Xp58RawSpooler]::StartDocPrinter($handle, 1, $info)
    if ($jobId -le 0) {
        throw "Could not start the XP-58 test job (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))."
    }
    $documentStarted = $true
    if (-not [Xp58RawSpooler]::StartPagePrinter($handle)) {
        throw "Could not start the XP-58 test page (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))."
    }
    $pageStarted = $true
    $written = 0
    if (-not [Xp58RawSpooler]::WritePrinter($handle, $payload, $payload.Length, [ref]$written) -or $written -ne $payload.Length) {
        throw "Could not write the complete XP-58 test job (Win32 error $([Runtime.InteropServices.Marshal]::GetLastWin32Error()))."
    }
    Write-Output "Submitted independent XP-58 test job $jobId ($written ASCII bytes). Inspect the paper for missing or faded rows."
} finally {
    if ($pageStarted) { [void][Xp58RawSpooler]::EndPagePrinter($handle) }
    if ($documentStarted) { [void][Xp58RawSpooler]::EndDocPrinter($handle) }
    [void][Xp58RawSpooler]::ClosePrinter($handle)
}
