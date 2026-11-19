# Send a raw ESC/POS file to a Windows printer (USB / local printer) through the print spooler.
# No printer sharing is required. Used by server/printer (PRINTER_TYPE=usb on Windows).
#   powershell -NoProfile -ExecutionPolicy Bypass -File raw-print.ps1 -Path data.bin [-Printer "POS-80"]
# When -Printer is empty, the default printer of the current account is used.
param(
    [Parameter(Mandatory = $true)][string]$Path,
    [string]$Printer = ''
)

$ErrorActionPreference = 'Stop'
# Output UTF-8 so Node.js can read messages correctly
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

if (-not ('RawPrinter' -as [type])) {
    Add-Type -TypeDefinition @'
using System;
using System.ComponentModel;
using System.Runtime.InteropServices;

public static class RawPrinter {
    [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
    public class DOCINFO {
        [MarshalAs(UnmanagedType.LPWStr)] public string pDocName;
        [MarshalAs(UnmanagedType.LPWStr)] public string pOutputFile;
        [MarshalAs(UnmanagedType.LPWStr)] public string pDataType;
    }

    [DllImport("winspool.drv", EntryPoint = "OpenPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool ClosePrinter(IntPtr handle);
    [DllImport("winspool.drv", EntryPoint = "StartDocPrinterW", SetLastError = true, CharSet = CharSet.Unicode)]
    static extern int StartDocPrinter(IntPtr handle, int level, [In] DOCINFO info);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndDocPrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool StartPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool EndPagePrinter(IntPtr handle);
    [DllImport("winspool.drv", SetLastError = true)]
    static extern bool WritePrinter(IntPtr handle, byte[] data, int count, out int written);

    public static int Send(string printer, byte[] data) {
        IntPtr h;
        if (!OpenPrinter(printer, out h, IntPtr.Zero))
            throw new Win32Exception(Marshal.GetLastWin32Error(), "OpenPrinter failed: " + printer);
        try {
            DOCINFO info = new DOCINFO();
            info.pDocName = "Visitor Badge";
            info.pDataType = "RAW";
            if (StartDocPrinter(h, 1, info) == 0)
                throw new Win32Exception(Marshal.GetLastWin32Error(), "StartDocPrinter failed");
            try {
                StartPagePrinter(h);
                int written;
                if (!WritePrinter(h, data, data.Length, out written))
                    throw new Win32Exception(Marshal.GetLastWin32Error(), "WritePrinter failed");
                EndPagePrinter(h);
                return written;
            } finally {
                EndDocPrinter(h);
            }
        } finally {
            ClosePrinter(h);
        }
    }
}
'@
}

try {
    if (-not $Printer) {
        $default = Get-CimInstance Win32_Printer -Filter 'Default = TRUE' -ErrorAction SilentlyContinue | Select-Object -First 1
        if (-not $default) { throw 'No printer specified and no default printer found. Set PRINTER_NAME in .env' }
        $Printer = $default.Name
    }
    $bytes = [System.IO.File]::ReadAllBytes($Path)
    $written = [RawPrinter]::Send($Printer, $bytes)
    Write-Output "OK printer=$Printer bytes=$written"
    exit 0
} catch {
    # Report the original error (without the localized PowerShell wrapper text)
    $ex = $_.Exception.GetBaseException()
    $msg = $ex.Message
    if ($ex -is [System.ComponentModel.Win32Exception]) { $msg = "$msg (Win32 error $($ex.NativeErrorCode))" }
    [Console]::Error.WriteLine($msg)
    exit 1
}
