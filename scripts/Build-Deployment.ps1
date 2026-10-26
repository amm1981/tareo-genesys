[CmdletBinding()]
param([string]$ReleaseId = ('get-genesys-' + [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ')))

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ($ReleaseId -notmatch '^get-genesys-[A-Za-z0-9._-]+$') { throw 'ReleaseId invalido.' }
$artifacts = Join-Path $workspace 'artifacts'
$stage = [IO.Path]::GetFullPath((Join-Path $artifacts $ReleaseId))
$archive = Join-Path $artifacts ($ReleaseId + '.tar.gz')
if (!$stage.StartsWith($artifacts + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Destino fuera de artifacts/.' }
if (Test-Path -LiteralPath $stage) { throw 'El release ya existe. Use otro ReleaseId; se conserva el anterior.' }
if ((Test-Path -LiteralPath $archive) -or (Test-Path -LiteralPath ($archive + '.sha256'))) { throw 'El archivo de release ya existe. Use otro ReleaseId.' }
if (!(Get-Command tar -ErrorAction SilentlyContinue)) { throw 'Se requiere tar para generar el paquete.' }
$utf8 = [Text.UTF8Encoding]::new($false)

function Copy-ReleaseFile([string]$RelativeSource, [string]$RelativeTarget) {
    $source = [IO.Path]::GetFullPath((Join-Path $workspace $RelativeSource))
    if (!$source.StartsWith($workspace + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Origen fuera del proyecto.' }
    $item = Get-Item -LiteralPath $source -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'No se empaquetan enlaces del workspace.' }
    $target = [IO.Path]::GetFullPath((Join-Path $stage $RelativeTarget))
    if (!$target.StartsWith($stage + [IO.Path]::DirectorySeparatorChar,[StringComparison]::OrdinalIgnoreCase)) { throw 'Ruta de paquete invalida.' }
    [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
    if ([IO.Path]::GetExtension($source) -eq '.sh') {
        [IO.File]::WriteAllText($target,([IO.File]::ReadAllText($source) -replace "`r`n","`n"),$utf8)
    } else { Copy-Item -LiteralPath $source -Destination $target }
}
function Copy-ReleaseTree([string]$Relative) {
    $base = Join-Path $workspace $Relative
    $queue = [Collections.Generic.Queue[string]]::new()
    $queue.Enqueue($base)
    while ($queue.Count -gt 0) {
        $current = $queue.Dequeue()
        foreach ($item in Get-ChildItem -LiteralPath $current -Force) {
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Enlace no permitido en las fuentes.' }
            if ($item.PSIsContainer) { $queue.Enqueue($item.FullName) }
            else {
                $relativeFile = $item.FullName.Substring($workspace.Length + 1)
                Copy-ReleaseFile $relativeFile $relativeFile
            }
        }
    }
}

$schemaSource = Join-Path $workspace 'docs/source-schema.json'
$schema = [IO.File]::ReadAllText($schemaSource) | ConvertFrom-Json
if ($schema.database -ne 'CASABLANCA' -or $schema.procedure -ne 'dbo.SPWConsumoMesVF' -or $schema.columns.Count -ne 34) { throw 'Esquema no revisado.' }
$schemaHash = (Get-FileHash -LiteralPath $schemaSource -Algorithm SHA256).Hash.ToLowerInvariant()
$expectedSchemaHash = 'f3b5ba98326f724a41277cd9410b0658c71be750f23d9af17cd538ca57fefc0c'
if ($schemaHash -ne $expectedSchemaHash) { throw 'Cambio del esquema: revisar antes de empaquetar.' }
[IO.Directory]::CreateDirectory($stage) | Out-Null

# Lista positiva. Nunca se copian client/, vendor/, tests/, .env ni caches/logs locales.
foreach ($directory in @('server/app','server/config','server/routes','server/database/migrations','server/database/seeders','server/database/factories','server/public','deploy')) {
    Copy-ReleaseTree $directory
}
foreach ($file in @('server/artisan','server/composer.json','server/composer.lock','server/bootstrap/app.php','server/bootstrap/providers.php','docs/PHP_PRODUCCION.md','docs/MAPEO_ORIGEN.md','docs/API_CONSULTA.md','docs/AUTOMATIZACION.md')) {
    Copy-ReleaseFile $file $file
}
Copy-ReleaseFile 'deploy/production.env.example' 'server/.env.example'
Copy-ReleaseFile 'docs/source-schema.json' 'server/storage/app/private/source-schema.json'
foreach ($directory in @('server/bootstrap/cache','server/resources/views','server/storage/app/private','server/storage/framework/cache/data','server/storage/framework/sessions','server/storage/framework/views','server/storage/logs')) {
    $target = Join-Path $stage $directory
    [IO.Directory]::CreateDirectory($target) | Out-Null
    [IO.File]::WriteAllText((Join-Path $target '.gitignore'),"*`n!.gitignore`n",$utf8)
}
$releaseNotes = @"
# Release GET_Genesys

Release: $ReleaseId
Dominio: https://api.labour.agrocalera.app
Destino: Ubuntu 24.04, PHP 8.3.6-0ubuntu0.24.04.13 o revision oficial posterior de seguridad.
Esquema SHA256: $schemaHash

Comenzar por deploy/README.md. Las configuraciones .example requieren el inventario del VPS.
El paquete contiene la API receptora, login y consulta de costos publicados, con metadatos de columnas. No incluye credenciales ni datos locales. Crear cada cuenta consumidora en consola; consultar docs/API_CONSULTA.md.
No ejecutar migraciones o cambios de servicios antes de revisar el inventario y autorizar el despliegue.
"@
[IO.File]::WriteAllText((Join-Path $stage 'RELEASE.md'),$releaseNotes,$utf8)
$files = @(Get-ChildItem -LiteralPath $stage -File -Recurse -Force | Sort-Object FullName | ForEach-Object {
    $relative = $_.FullName.Substring($stage.Length + 1).Replace('\','/')
    if ($relative -match '(^|/)(vendor|node_modules|tests|\.runs)(/|$)' -or $_.Name -eq '.env' -or $_.Extension -in @('.log','.sqlite','.key','.pem')) { throw 'Archivo no permitido en el paquete.' }
    [ordered]@{ path = $relative; bytes = $_.Length; sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
})
$manifest = [ordered]@{
    format = 1; release = $ReleaseId; created_at_utc = [DateTime]::UtcNow.ToString('o')
    domain = 'api.labour.agrocalera.app'; php_package = '8.3.6-0ubuntu0.24.04.13'
    source_schema_sha256 = $schemaHash; files = $files
}
[IO.File]::WriteAllText((Join-Path $stage 'manifest.json'),($manifest | ConvertTo-Json -Depth 8),$utf8)
& tar -czf $archive -C $stage .
if ($LASTEXITCODE -ne 0) { throw 'No se pudo generar tar.gz.' }
$archiveHash = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
[IO.File]::WriteAllText(($archive + '.sha256'),($archiveHash + '  ' + [IO.Path]::GetFileName($archive) + "`n"),$utf8)
Write-Output ('Paquete: ' + $archive)
Write-Output ('SHA256: ' + $archiveHash)
Write-Output ('Archivos verificados en manifiesto: ' + $files.Count)
Write-Output 'Preparacion local terminada. No se realizaron cambios remotos.'
