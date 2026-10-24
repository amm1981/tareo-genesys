[CmdletBinding()]
param([uri]$BaseUrl = 'https://api.labour.agrocalera.app', [switch]$WithToken)
$ErrorActionPreference = 'Stop'
if ($BaseUrl.Scheme -ne 'https' -or $BaseUrl.UserInfo -or $BaseUrl.Query -or $BaseUrl.Fragment -or $BaseUrl.AbsolutePath -ne '/') { throw 'Se requiere una URL base HTTPS sin ruta ni credenciales.' }
$base = $BaseUrl.AbsoluteUri.TrimEnd('/')
function Read-HttpStatus([string]$Url, [hashtable]$Headers) {
    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -Headers $Headers -TimeoutSec 30 -MaximumRedirection 0 -UseBasicParsing -ErrorAction Stop
        return [int]$response.StatusCode
    } catch {
        if ($_.Exception.Response) { return [int]$_.Exception.Response.StatusCode }
        throw 'No se pudo conectar por HTTPS. Revisar DNS, certificado y conectividad.'
    }
}
$health = Invoke-RestMethod -Uri ($base + '/api/health') -Headers @{Accept='application/json'} -TimeoutSec 30 -MaximumRedirection 0
if ($health.data.status -ne 'ok') { throw 'La salud no devolvio la respuesta prevista.' }
Write-Output 'OK: salud minima por HTTPS y certificado validado.'
$url = $base + '/api/v1/sync/runs/' + [guid]::NewGuid().ToString() + '/status'
if ((Read-HttpStatus $url @{Accept='application/json'}) -ne 401) { throw 'El endpoint protegido no rechazo el acceso anonimo como se esperaba.' }
Write-Output 'OK: endpoint protegido rechaza acceso anonimo (401).'
if ($WithToken) {
    $secure = Read-Host 'Token de sincronizacion de produccion (no se muestra)' -AsSecureString
    $credential = New-Object System.Management.Automation.PSCredential('api',$secure)
    $apiToken = $credential.GetNetworkCredential().Password
    try {
        $headers = @{Accept='application/json'; Authorization=('Bearer ' + $apiToken)}
        if ((Read-HttpStatus $url $headers) -ne 404) { throw 'El token no pudo consultar el estado del UUID inexistente. Revisar permiso, vigencia y autenticacion.' }
        Write-Output 'OK: token exclusivo aceptado; UUID inexistente devuelve 404.'
    } finally { $headers = $null; $apiToken = $null; $credential = $null; $secure = $null }
}
Write-Output 'Prueba de lectura terminada. No se enviaron lotes ni se publicaron datos.'
