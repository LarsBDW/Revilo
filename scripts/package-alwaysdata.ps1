param()
$ErrorActionPreference = 'Stop'
$reviloRoot = [IO.Path]::GetFullPath((Split-Path -Parent $PSScriptRoot))
Push-Location -LiteralPath $reviloRoot
try {
    if (-not (Test-Path -LiteralPath (Join-Path $reviloRoot 'node_modules/vite'))) {
        & npm.cmd ci
        if ($LASTEXITCODE -ne 0) { throw 'Frontend dependency installation failed.' }
    }
    & npm.cmd run build
    if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
} finally {
    Pop-Location
}
$reviloManifest = Join-Path $reviloRoot 'public/build/manifest.json'
if (-not (Test-Path -LiteralPath $reviloManifest)) { throw 'The frontend build manifest is missing.' }
$reviloOutput = Join-Path $reviloRoot 'dist'
New-Item -ItemType Directory -Path $reviloOutput -Force | Out-Null
$reviloArchivePath = Join-Path $reviloOutput ('revilo-alwaysdata-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
$reviloFiles = @()
foreach ($directory in @('app', 'bootstrap', 'config', 'database', 'lang', 'public', 'resources', 'routes', 'deploy/alwaysdata')) {
    $reviloFiles += Get-ChildItem -LiteralPath (Join-Path $reviloRoot $directory) -File -Recurse -Force |
        Where-Object { -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) }
}
foreach ($file in @('artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', 'vite.config.js', '.gitattributes', '.env.example', 'README.md', 'docs/alwaysdata-setup.md', 'docs/google-setup.md', 'docs/assets.md')) {
    $reviloFiles += Get-Item -LiteralPath (Join-Path $reviloRoot $file)
}
Add-Type -AssemblyName System.IO.Compression
$reviloArchiveStream = [IO.File]::Open($reviloArchivePath, [IO.FileMode]::CreateNew)
$reviloArchive = New-Object IO.Compression.ZipArchive($reviloArchiveStream, ([IO.Compression.ZipArchiveMode]::Create))
$reviloCount = 0
try {
    foreach ($file in $reviloFiles) {
        $relative = $file.FullName.Substring($reviloRoot.Length + 1).Replace('\', '/')
        if ($relative -match '^(bootstrap/cache/|public/storage/|public/hot$|deploy/alwaysdata/(backups/|\.env$|\.env\.(?!example$)))') { continue }
        if ($relative -match '^database/.*\.(sqlite(?:3)?(?:-wal|-shm|-journal)?|db|sql)$') { continue }
        if ($relative -match '(^|/)(\.env|local-admin\.txt|auth\.json)$') { throw 'Private data must not be packaged.' }
        $entry = $reviloArchive.CreateEntry($relative, [IO.Compression.CompressionLevel]::Optimal)
        $entryStream = $entry.Open()
        $inputStream = $null
        try {
            if ($file.Extension -eq '.sh') {
                $scriptBytes = [Text.UTF8Encoding]::new($false).GetBytes([IO.File]::ReadAllText($file.FullName).Replace("`r`n", "`n"))
                $entryStream.Write($scriptBytes, 0, $scriptBytes.Length)
            } else {
                $inputStream = $file.OpenRead()
                $inputStream.CopyTo($entryStream)
            }
        } finally {
            if ($inputStream) { $inputStream.Dispose() }
            $entryStream.Dispose()
        }
        $reviloCount++
    }
} finally {
    $reviloArchive.Dispose()
    $reviloArchiveStream.Dispose()
}
Get-FileHash -LiteralPath $reviloArchivePath -Algorithm SHA256 | ForEach-Object { $_.Hash + '  ' + [IO.Path]::GetFileName($reviloArchivePath) } | Set-Content -LiteralPath ($reviloArchivePath + '.sha256')
Write-Output "Prepared $reviloCount source/assets files: $reviloArchivePath"
Write-Output 'Built frontend assets are included. Server Composer dependencies are installed after upload.'
Write-Output 'Environment secrets, local databases, private files and existing customer data are excluded.'
