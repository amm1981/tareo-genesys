[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\Apps\GET_Genesys',
    [ValidatePattern('^([01][0-9]|2[0-3]):[0-5][0-9]$')][string]$At = '02:00',
    [string]$RunAs = ($env:USERDOMAIN + '\' + $env:USERNAME),
    [string]$NodePath = 'C:\Program Files\nodejs\node.exe',
    [string]$OutputPath,
    [switch]$Install
)
$ErrorActionPreference = 'Stop'
$root = [IO.Path]::GetFullPath($ProjectRoot)
if (!(Test-Path -LiteralPath (Join-Path $root 'scripts\windows\Invoke-AutoSync.ps1'))) { throw 'Revise ProjectRoot: falta Invoke-AutoSync.ps1.' }
foreach ($value in @($root,$NodePath)) { if ($value.Contains('"') -or $value.Contains("`n") -or $value.Contains("`r")) { throw 'Ruta invalida.' } }
if (!$OutputPath) { $OutputPath = Join-Path $root 'artifacts\GET_Genesys-Daily.xml' }
$target = [IO.Path]::GetFullPath($OutputPath)
if (Test-Path -LiteralPath $target) { throw 'El XML ya existe. Especifique otro OutputPath; se conserva el anterior.' }
$lima = [TimeZoneInfo]::FindSystemTimeZoneById('SA Pacific Standard Time')
$localNow = [TimeZoneInfo]::ConvertTimeFromUtc([DateTime]::UtcNow,$lima)
$start = [DateTime]::ParseExact(($localNow.ToString('yyyy-MM-dd') + ' ' + $At),'yyyy-MM-dd HH:mm',[Globalization.CultureInfo]::InvariantCulture)
if ($start -le $localNow) { $start = $start.AddDays(1) }
$boundary = $start.ToString('yyyy-MM-ddTHH:mm:ss') + '-05:00'
$escape = { param($text) [Security.SecurityElement]::Escape($text) }
$arguments = '-NoLogo -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File "' + (Join-Path $root 'scripts\windows\Invoke-AutoSync.ps1') + '" -ProjectRoot "' + $root + '" -NodePath "' + $NodePath + '" -Mode daily'
$user = & $escape $RunAs
$actionArgs = & $escape $arguments
$working = & $escape (Join-Path $root 'client')
$powershellPath = & $escape (Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe')
$xml = @"
<?xml version="1.0"?>
<Task version="1.4" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>GET_Genesys DAILY, America/Lima, ventana de 30 dias; reanuda pendientes. BACKFILL se inicia aparte.</Description></RegistrationInfo>
  <Triggers><CalendarTrigger><StartBoundary>$boundary</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger></Triggers>
  <Principals><Principal id="Operator"><UserId>$user</UserId><LogonType>Password</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><RunOnlyIfNetworkAvailable>true</RunOnlyIfNetworkAvailable><ExecutionTimeLimit>PT0S</ExecutionTimeLimit><Enabled>true</Enabled><RestartOnFailure><Interval>PT15M</Interval><Count>3</Count></RestartOnFailure></Settings>
  <Actions Context="Operator"><Exec><Command>$powershellPath</Command><Arguments>$actionArgs</Arguments><WorkingDirectory>$working</WorkingDirectory></Exec></Actions>
</Task>
"@
[IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
[IO.File]::WriteAllText($target,$xml,[Text.UTF8Encoding]::new($false))
Write-Output ('XML preparado: ' + $target + '; hora America/Lima ' + $At + '. No contiene contrasenas.')
if (!$Install) { Write-Output 'No se registro ninguna tarea. Revisar XML, cuenta, permisos, red y runtime antes de autorizar la instalacion.'; return }
# Solo por solicitud explicita con -Install. La credencial se pide oculta, nunca como argumento.
if (Get-ScheduledTask -TaskName 'GET_Genesys-Daily' -ErrorAction SilentlyContinue) { throw 'La tarea ya existe; revisarla antes de modificarla.' }
$credential = Get-Credential -UserName $RunAs -Message 'Cuenta dedicada que ejecutara GET_Genesys sin sesion interactiva'
try {
    Register-ScheduledTask -TaskName 'GET_Genesys-Daily' -Xml $xml -User $credential.UserName -Password $credential.GetNetworkCredential().Password -ErrorAction Stop | Out-Null
    Write-Output 'Tarea registrada por solicitud explicita; no se inicio una ejecucion.'
} finally { $credential = $null }
