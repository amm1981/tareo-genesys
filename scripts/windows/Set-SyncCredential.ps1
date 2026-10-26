[CmdletBinding()]
param(
    [string]$ProjectRoot = 'C:\Apps\GET_Genesys',
    [string]$TaskAccount = ($env:USERDOMAIN + '\' + $env:USERNAME)
)
$ErrorActionPreference = 'Stop'
$client = Join-Path ([IO.Path]::GetFullPath($ProjectRoot)) 'client'
if (!(Test-Path -LiteralPath (Join-Path $client 'package.json'))) { throw 'Proyecto no encontrado.' }
function Read-PrivateDacl([IO.FileSystemInfo]$Item) {
    if ($PSVersionTable.PSEdition -eq 'Desktop') { return $Item.GetAccessControl() }
    return [IO.FileSystemAclExtensions]::GetAccessControl($Item)
}
function Write-PrivateDacl([IO.FileSystemInfo]$Item,$Dacl) {
    if ($PSVersionTable.PSEdition -eq 'Desktop') { $Item.SetAccessControl($Dacl) }
    else { [IO.FileSystemAclExtensions]::SetAccessControl($Item,$Dacl) }
}
$directory = Join-Path $client '.secrets'
$path = Join-Path $directory 'vps-sync-token'
[IO.Directory]::CreateDirectory($directory) | Out-Null
if ((Get-Item -LiteralPath $directory -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Directorio de credencial no permitido.' }
# ACL del directorio aplicada antes de escribir el secreto. No se modifican contrasenas de ninguna cuenta.
$acl = Read-PrivateDacl ([IO.DirectoryInfo]::new($directory))
$acl.SetAccessRuleProtection($true,$false)
foreach ($existing in $acl.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier])) { $acl.RemoveAccessRuleSpecific($existing) }
$taskSid = (New-Object Security.Principal.NTAccount($TaskAccount)).Translate([Security.Principal.SecurityIdentifier])
foreach ($identity in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18', 'S-1-5-32-544') | Select-Object -Unique) {
    $sid = New-Object Security.Principal.SecurityIdentifier($identity)
    $rule = New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','ContainerInherit,ObjectInherit','None','Allow')
    $acl.AddAccessRule($rule)
}
$readRule = New-Object Security.AccessControl.FileSystemAccessRule($taskSid,'ReadAndExecute','ContainerInherit,ObjectInherit','None','Allow')
$acl.AddAccessRule($readRule)
Write-PrivateDacl ([IO.DirectoryInfo]::new($directory)) $acl
function Set-PrivateCredentialFile([string]$FilePath) {
    $fileAcl = Read-PrivateDacl ([IO.FileInfo]::new($FilePath))
    $fileAcl.SetAccessRuleProtection($true,$false)
    foreach ($existing in $fileAcl.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier])) { $fileAcl.RemoveAccessRuleSpecific($existing) }
    foreach ($identity in @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544') | Select-Object -Unique) {
        $sid = New-Object Security.Principal.SecurityIdentifier($identity)
        $fileAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($sid,'FullControl','Allow')))
    }
    $fileAcl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($taskSid,'ReadAndExecute','Allow')))
    Write-PrivateDacl ([IO.FileInfo]::new($FilePath)) $fileAcl
}
if (Test-Path -LiteralPath $path) {
    if ((Get-Item -LiteralPath $path -Force).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Archivo de credencial no permitido.' }
    Set-PrivateCredentialFile $path
}
$secret = Read-Host 'Token sync:write del VPS (entrada oculta)' -AsSecureString
$credential = New-Object Management.Automation.PSCredential('sync',$secret)
$plain = $credential.GetNetworkCredential().Password
$temp = Join-Path $directory ([guid]::NewGuid().ToString('N') + '.tmp')
try {
    if ($plain -notmatch '^\d+\|[A-Za-z0-9_-]{40,200}$') { throw 'Formato de token Sanctum invalido.' }
    [IO.File]::WriteAllText($temp,$plain,[Text.UTF8Encoding]::new($false))
    if (Test-Path -LiteralPath $path) { [IO.File]::Replace($temp,$path,[NullString]::Value) }
    else { [IO.File]::Move($temp,$path) }
    Set-PrivateCredentialFile $path
    Write-Output 'Token guardado con ACL privada. El siguiente request leera el archivo; no se muestra el secreto.'
} finally {
    $plain = $null; $credential = $null; $secret = $null
    if (Test-Path -LiteralPath $temp) { Remove-Item -LiteralPath $temp }
}
