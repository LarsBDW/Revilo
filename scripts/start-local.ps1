param([int]$Port = 8000)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $projectRoot
$runtimeDir = Join-Path $projectRoot '.runtime'
New-Item -ItemType Directory -Force -Path $runtimeDir | Out-Null
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$phpPath = if ($phpCommand) { $phpCommand.Source } else { 'C:\xampp\php\php.exe' }
if (-not (Test-Path -LiteralPath $phpPath)) { throw 'Install PHP 8.2+ and add php.exe to PATH.' }
if (-not (Test-Path -LiteralPath (Join-Path $projectRoot '.env'))) { throw 'Prepare .env and MySQL as described in README.md.' }
$mysqlListener = Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue
if (-not $mysqlListener) {
    $mysqlHome = Join-Path $runtimeDir 'mysql-8.4.11-winx64'
    $mysqlExe = Join-Path $mysqlHome 'bin\mysqld.exe'
    $mysqlData = Join-Path $runtimeDir 'mysql-data'
    if (-not (Test-Path -LiteralPath $mysqlExe)) { throw 'Start your configured MySQL server, or use Docker as described in README.md.' }
    if (-not (Test-Path -LiteralPath $mysqlData)) { throw 'Initialize the portable database before starting it. See README.md.' }
    $mysqlArguments = @('--no-defaults', ('--basedir="' + $mysqlHome.Replace('\','/') + '"'), ('--datadir="' + $mysqlData.Replace('\','/') + '"'), '--port=3307', '--bind-address=127.0.0.1', '--mysqlx=OFF', '--console')
    $mysqlProcess = Start-Process -FilePath $mysqlExe -ArgumentList $mysqlArguments -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'mysql.stdout.log') -RedirectStandardError (Join-Path $runtimeDir 'mysql.stderr.log')
    $mysqlProcess.Id | Set-Content -LiteralPath (Join-Path $runtimeDir 'mysql.pid')
    for ($attempt=0;$attempt -lt 40;$attempt++) { if(Get-NetTCPConnection -LocalPort 3307 -State Listen -ErrorAction SilentlyContinue){break};Start-Sleep -Milliseconds 250 }
}
& $phpPath (Join-Path $PSScriptRoot 'health.php')
if($LASTEXITCODE -ne 0){throw 'Configured MySQL connection failed.'}
if(-not (Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue)) {
    $serverProcess = Start-Process -FilePath $phpPath -ArgumentList @(('"'+(Join-Path $projectRoot 'artisan')+'"'),'serve','--host=127.0.0.1',("--port="+$Port),'--no-ansi') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'web.stdout.log') -RedirectStandardError (Join-Path $runtimeDir 'web.stderr.log')
    $serverProcess.Id | Set-Content -LiteralPath (Join-Path $runtimeDir 'web.pid')
}
$websiteReady = $false
$websiteDeadline = [DateTime]::UtcNow.AddSeconds(20)
while ([DateTime]::UtcNow -lt $websiteDeadline) {
    try {
        $websiteResponse = Invoke-WebRequest -Uri ("http://127.0.0.1:" + $Port + "/nl/login") -UseBasicParsing -TimeoutSec 2
        if ($websiteResponse.StatusCode -eq 200) {
            $websiteReady = $true
            break
        }
    } catch {
        Start-Sleep -Milliseconds 250
    }
}
if (-not $websiteReady) {
    throw 'The webshop did not become ready. See .runtime/web.stdout.log and .runtime/web.stderr.log.'
}
$schedulerPidPath = Join-Path $runtimeDir 'scheduler.pid'
$schedulerRunning = $false
if(Test-Path -LiteralPath $schedulerPidPath){$savedSchedulerPid=[int](Get-Content -LiteralPath $schedulerPidPath);$schedulerRunning=[bool](Get-Process -Id $savedSchedulerPid -ErrorAction SilentlyContinue)}
if(-not $schedulerRunning){
    $schedulerProcess=Start-Process -FilePath $phpPath -ArgumentList @(('"'+(Join-Path $projectRoot 'artisan')+'"'),'schedule:work','--no-ansi') -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $runtimeDir 'schedule.stdout.log') -RedirectStandardError (Join-Path $runtimeDir 'schedule.stderr.log')
    $schedulerProcess.Id | Set-Content -LiteralPath $schedulerPidPath
}
Write-Output ("Revilo: http://127.0.0.1:"+$Port+"/nl")
Write-Output 'Admin: /admin; private login: storage/app/private/local-admin.txt'
