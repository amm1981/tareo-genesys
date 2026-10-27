[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$project = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$fixture = Join-Path $project ('artifacts\windows-test-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory((Join-Path $fixture 'client\dist')) | Out-Null
[IO.File]::WriteAllText((Join-Path $fixture 'client\package.json'),'{}')
$utf8 = [Text.UTF8Encoding]::new($false)
foreach ($file in @('Invoke-AutoSync.ps1','New-SyncTask.ps1','Set-SyncCredential.ps1')) {
    $parseErrors = $null; $tokens = $null
    [Management.Automation.Language.Parser]::ParseFile((Join-Path $PSScriptRoot $file),[ref]$tokens,[ref]$parseErrors) | Out-Null
    if ($parseErrors.Count) { throw ('Sintaxis invalida: ' + $file) }
}
$xmlFile = Join-Path $fixture 'daily.xml'
& (Join-Path $PSScriptRoot 'New-SyncTask.ps1') -ProjectRoot $project -OutputPath $xmlFile -At '02:00'
$service = New-Object -ComObject 'Schedule.Service'
$service.Connect()
$definition = $service.NewTask(0)
$definition.XmlText = [IO.File]::ReadAllText($xmlFile)
if ($definition.Triggers.Count -ne 1 -or $definition.Principal.LogonType -ne 1) { throw 'Definicion inesperada.' }
if (!$definition.Triggers.Item(1).StartBoundary.EndsWith('-05:00')) { throw 'Falta zona America/Lima.' }
function Read-Host { param([string]$Prompt,[switch]$AsSecureString) ConvertTo-SecureString ('1|' + ('x' * 48)) -AsPlainText -Force }
& (Join-Path $PSScriptRoot 'Set-SyncCredential.ps1') -ProjectRoot $fixture
& (Join-Path $PSScriptRoot 'Set-SyncCredential.ps1') -ProjectRoot $fixture
$acl = Get-Acl -LiteralPath (Join-Path $fixture 'client\.secrets\vps-sync-token')
if (!$acl.AreAccessRulesProtected) { throw 'DACL del token no protegida.' }
foreach ($rule in $acl.Access) {
    if ($rule.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -in @('S-1-1-0','S-1-5-11','S-1-5-32-545')) { throw 'Permisos demasiado amplios.' }
}
# El unico programa iniciado es un fixture que termina con codigo 7. No carga el runner real.
[IO.File]::WriteAllText((Join-Path $fixture 'client\dist\auto.js'),"console.error('SENSITIVE_FIXTURE_MARKER'); process.exit(7);",$utf8)
$node = (Get-Command node -ErrorAction Stop).Source
$powershell = Join-Path $env:WINDIR 'System32\WindowsPowerShell\v1.0\powershell.exe'
& $powershell -NoLogo -NoProfile -NonInteractive -ExecutionPolicy RemoteSigned -File (Join-Path $PSScriptRoot 'Invoke-AutoSync.ps1') -ProjectRoot $fixture -NodePath $node -Mode daily
if ($LASTEXITCODE -ne 7) { throw 'El wrapper no conservo el codigo de salida.' }
$log = Get-ChildItem -LiteralPath (Join-Path $fixture 'client\.automation\spwconsumomes-v1') -Filter '*.jsonl' | Select-Object -First 1
$contents = [IO.File]::ReadAllText($log.FullName)
if ($contents.Contains('SENSITIVE_FIXTURE_MARKER')) { throw 'Se copio un error arbitrario al log.' }
if (($contents -split "`n" | Where-Object { $_ } | Select-Object -Last 1 | ConvertFrom-Json).exit_code -ne 7) { throw 'Resultado no registrado.' }
Write-Output 'OK: XML validado en memoria, token sintetico con ACL, rotacion atomica y wrapper Windows PowerShell sin interaccion. Ninguna tarea instalada, sin SQL ni VPS.'
Write-Output ('Fixtures locales conservados en: ' + $fixture)
