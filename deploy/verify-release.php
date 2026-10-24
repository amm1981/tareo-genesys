<?php

// Verifica el paquete recien extraido. No requiere vendor/, .env ni acceso a bases.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
$root = realpath(__DIR__.'/..');
$manifestFile = $root.'/manifest.json';
if (! is_file($manifestFile)) {
    fwrite(STDERR, "Falta manifest.json. Ejecute dentro del paquete extraido.\n");
    exit(1);
}
try {
    $manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1 || ! is_string($manifest['release'] ?? null) || ! preg_match('/^get-genesys-[A-Za-z0-9._-]+$/D', $manifest['release']) || ! is_array($manifest['files'] ?? null) || ! $manifest['files']) {
        throw new RuntimeException('Manifiesto no valido.');
    }
    $errors = 0;
    $count = 0;
    $seen = [];
    foreach ($manifest['files'] as $file) {
        $relative = $file['path'] ?? null;
        if (! is_string($relative) || $relative === '' || preg_match('~(^/|\\\\|(^|/)\.\.(/|$)|:|[\x00-\x1f])~', $relative) || isset($seen[$relative]) || ! is_string($file['sha256'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
            throw new RuntimeException('Entrada no valida en el manifiesto.');
        }
        $seen[$relative] = true;
        $path = $root.'/'.$relative;
        $real = realpath($path);
        if (! $real || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR) || is_link($path) || ! is_file($path) || ! hash_equals($file['sha256'], hash_file('sha256', $path))) {
            fwrite(STDERR, 'No coincide el archivo: '.$relative."\n");
            $errors++;
        }
        $count++;
    }
    if ($errors) {
        exit(1);
    }
    echo 'Integridad correcta: '.$count.' archivos. Release '.$manifest['release']."\n";
    echo "Esto verifica archivos; no valida instalacion, secretos, PHP-FPM ni HTTPS.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "No se pudo verificar el manifiesto del paquete.\n");
    exit(1);
}
