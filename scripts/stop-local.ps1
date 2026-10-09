$ErrorActionPreference='Stop'
$projectRoot=Split-Path -Parent $PSScriptRoot
$runtimeDir=Join-Path $projectRoot '.runtime'
foreach($name in @('web','scheduler','mysql')){
    $pidPath=Join-Path $runtimeDir ($name+'.pid')
    if(-not(Test-Path -LiteralPath $pidPath)){continue}
    $ownedPid=[int](Get-Content -LiteralPath $pidPath)
    $process=Get-CimInstance Win32_Process -Filter ("ProcessId="+$ownedPid) -ErrorAction SilentlyContinue
    if($process -and $process.CommandLine -and ($process.CommandLine.Contains($projectRoot) -or $process.ExecutablePath.StartsWith($runtimeDir,[StringComparison]::OrdinalIgnoreCase))){
        if($name -eq 'mysql'){Write-Output 'Stop MySQL gracefully using mysqladmin and your private local root password. No database process killed.';continue}
        $children=Get-CimInstance Win32_Process -Filter ("ParentProcessId="+$ownedPid)
        foreach($child in $children){if($child.CommandLine.Contains($projectRoot)){Stop-Process -Id $child.ProcessId -ErrorAction SilentlyContinue}}
        Stop-Process -Id $ownedPid -ErrorAction SilentlyContinue
    }
    Remove-Item -LiteralPath $pidPath -ErrorAction SilentlyContinue
}
