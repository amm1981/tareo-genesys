[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\Apps\GET_Genesys',
    [ValidateSet('daily','backfill')][string]$Mode = 'daily',
    [string]$NodePath = 'C:\Program Files\nodejs\node.exe'
)
$ErrorActionPreference = 'Stop'
$exitCode = 1
try {
    $root = [IO.Path]::GetFullPath($ProjectRoot)
    $client = Join-Path $root 'client'
    $entry = Join-Path $client 'dist\auto.js'
    if (!(Test-Path -LiteralPath $client -PathType Container)) { throw 'PROJECT_NOT_FOUND' }
    $logs = Join-Path $client '.automation\spwconsumomes-v1'
    [IO.Directory]::CreateDirectory($logs) | Out-Null
    $log = Join-Path $logs ('task-' + [DateTime]::UtcNow.ToString('yyyyMM') + '.jsonl')
    $utf8 = [Text.UTF8Encoding]::new($false)
    function Write-TaskEvent([string]$EventName, [int]$Code, [string]$Reason = '') {
        $item = @{ at=[DateTime]::UtcNow.ToString('o'); event=$EventName; mode=$Mode; exit_code=$Code }
        if ($Reason -match '^[A-Z][A-Z0-9_]{1,79}$') { $item.code = $Reason }
        $line = $item | ConvertTo-Json -Compress
        [IO.File]::AppendAllText($log,$line + "`n",$utf8)
    }
    if (!(Test-Path -LiteralPath $entry -PathType Leaf) -or !(Test-Path -LiteralPath $NodePath -PathType Leaf)) { throw 'RUNTIME_NOT_PREPARED' }
    Set-Location -LiteralPath $client
    Write-TaskEvent 'task_started' 0
    # El runner registra eventos estructurados propios. No copiar errores arbitrarios de Node/driver al log.
    $startInfo = New-Object Diagnostics.ProcessStartInfo
    $startInfo.FileName = $NodePath
    $startInfo.Arguments = '"' + $entry + '" ' + $Mode
    $startInfo.WorkingDirectory = $client
    $startInfo.UseShellExecute = $false
    $startInfo.CreateNoWindow = $true
    $startInfo.RedirectStandardOutput = $true
    $startInfo.RedirectStandardError = $true
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $startInfo
    try {
        $process.Start() | Out-Null
        $outputDrain = $process.StandardOutput.ReadToEndAsync()
        $errorDrain = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $outputDrain.GetAwaiter().GetResult() | Out-Null
        $errorText = $errorDrain.GetAwaiter().GetResult()
        $stopCode = ''
        foreach ($errorLine in ($errorText -split "`n")) {
            try {
                $item = $errorLine | ConvertFrom-Json -ErrorAction Stop
                if ($item.event -eq 'automation_stopped' -and $item.code -match '^[A-Z][A-Z0-9_]{1,79}$') { $stopCode = $item.code }
            } catch { }
        }
        $exitCode = $process.ExitCode
    } finally { $process.Dispose() }
    Write-TaskEvent 'task_finished' $exitCode $stopCode
} catch {
    if (Get-Variable log -ErrorAction SilentlyContinue) {
        try { Write-TaskEvent 'task_wrapper_failed' 1 } catch { }
    }
    $exitCode = 1
}
exit $exitCode
