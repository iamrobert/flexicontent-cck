[CmdletBinding()]
param(
    # Harness test names under harness\tests (without .cjs), run in order in one fixture boot.
    [Parameter(Mandatory)][string[]]$Tests,
    [ValidateRange(60, 3600)]
    [int]$TimeoutSeconds = 900,
    # Optional per-run options passed to the harness (small JSON object).
    [string]$OptionsJson = '{}'
)

# Offline Joomla fixture runner. Same containment model as ..\sandbox\Invoke-Sandbox.ps1
# (no network, read-only root and inputs, bounded tmpfs scratch, dropped capabilities,
# host wall clock), with a larger fixed "fixture" resource profile. Results come back only
# as bounded, structured FIXTURE_RESULT log lines that this script parses and records.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$inputs = Join-Path $root 'inputs'
$harness = Join-Path $root 'harness'
$records = Join-Path $root 'records'
$image = 'sha256:2925849391e99115a674537e942ee78a4d6d3087713769e764116babb7ccb698'
if ($image -notmatch '^sha256:[a-f0-9]{64}$') { throw 'Invalid pinned image ID' }
foreach ($t in $Tests) { if ($t -notmatch '^[a-z0-9][a-z0-9-]{0,40}$') { throw "Bad test name: $t" } }
foreach ($t in $Tests) { if (!(Test-Path -LiteralPath (Join-Path $harness "tests\$t.cjs") -PathType Leaf)) { throw "No such harness test: $t" } }
$null = ConvertFrom-Json $OptionsJson
if ($OptionsJson.Length -gt 4096) { throw 'Options too large' }

# Freeze the trusted harness so later independent work cannot alter an active run.
$harnessSnapshot = Join-Path $root ('runs\' + [guid]::NewGuid().ToString('N') + '\harness')
$null = New-Item -ItemType Directory -Path $harnessSnapshot -Force
Get-ChildItem -LiteralPath $harness -Force | Copy-Item -Destination $harnessSnapshot -Recurse
$harness = $harnessSnapshot

$MemoryBytes = 4294967296; $ScratchBytes = 2147483648; $Pids = 1024
$Limits = @{ fsize = 268435456; core = 0; nofile = 8192; cpu = 1200 }

function Invoke-Docker {
    param([string[]]$Arguments)
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = @(& docker @Arguments 2>&1); $code = $LASTEXITCODE } finally { $ErrorActionPreference = $previous }
    $text = ($output | ForEach-Object { $_.ToString() }) -join "`n"
    if ($code -ne 0) { throw "Docker failed ($code): $text" }
    return $text
}

function Assert-PlainTree {
    param([string]$Path)
    $item = Get-Item -LiteralPath $Path -Force
    while ($null -ne $item) {
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Reparse point: $($item.FullName)" }
        if ($item -is [IO.FileInfo]) { $item = $item.Directory } else { $item = $item.Parent }
    }
    Get-ChildItem -LiteralPath $Path -Recurse -Force | ForEach-Object {
        if (($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw "Reparse point: $($_.FullName)" }
    }
}

function Assert-FixturePolicy {
    param($Container)
    $h = $Container.HostConfig
    if ($h.NetworkMode -ne 'none' -or !$h.ReadonlyRootfs -or $h.Privileged -or
        $h.Memory -ne $MemoryBytes -or $h.MemorySwap -ne $MemoryBytes -or
        $h.NanoCpus -ne 2000000000 -or $h.CpusetCpus -ne '0,1' -or $h.PidsLimit -ne $Pids -or
        $h.IpcMode -ne 'none' -or $h.PidMode -ne '' -or $Container.Config.User -ne '10001:10001' -or !$h.Init) {
        throw 'Fixture containment policy mismatch'
    }
    if (@($h.CapDrop).Count -ne 1 -or $h.CapDrop[0] -ne 'ALL' -or
        @($h.SecurityOpt | Where-Object { $_ -match '^no-new-privileges(=true)?$' }).Count -ne 1) { throw 'Privilege policy mismatch' }
    if ($null -ne $h.PortBindings -and @($h.PortBindings.PSObject.Properties).Count -ne 0) { throw 'Unexpected published ports' }
    $mounts = @($Container.Mounts | Sort-Object Destination)
    if ($mounts.Count -ne 2 -or $mounts[0].Destination -ne '/fixture/harness' -or $mounts[1].Destination -ne '/fixture/inputs' -or
        $mounts[0].RW -or $mounts[1].RW -or $mounts[0].Type -ne 'bind' -or $mounts[1].Type -ne 'bind') { throw 'Unexpected mounts' }
    if (@($h.Tmpfs.PSObject.Properties).Count -ne 1 -or
        $h.Tmpfs.'/scratch' -ne "rw,nosuid,nodev,noexec,size=$ScratchBytes,uid=10001,gid=10001,mode=700") { throw 'Scratch policy mismatch' }
    if ($h.LogConfig.Type -ne 'local' -or $h.LogConfig.Config.'max-size' -ne '8m' -or
        $h.LogConfig.Config.'max-file' -ne '1' -or $h.LogConfig.Config.compress -ne 'false') { throw 'Log quota mismatch' }
    if (@($h.Ulimits).Count -ne $Limits.Count) { throw 'Unexpected ulimits' }
    foreach ($l in $h.Ulimits) {
        if (!$Limits.ContainsKey($l.Name) -or $l.Soft -ne $Limits[$l.Name] -or $l.Hard -ne $Limits[$l.Name]) { throw 'Ulimit mismatch' }
    }
}

function Invoke-FixtureContainer {
    param([string]$Action, [int]$Deadline, [string]$Payload = '')
    $id = $null
    $name = 'textarea2-fixture-' + [guid]::NewGuid().ToString('N')
    try {
        $arguments = @(
            'create', '--name', $name, '--label', 'textarea2.audit.fixture=1',
            '--pull=never', '--network=none', '--read-only', '--user=10001:10001',
            '--cap-drop=ALL', '--security-opt=no-new-privileges=true',
            '--memory=4096m', '--memory-swap=4096m', '--cpus=2', '--cpuset-cpus=0,1', "--pids-limit=$Pids",
            "--ulimit=fsize=$($Limits.fsize):$($Limits.fsize)", '--ulimit=core=0:0',
            "--ulimit=nofile=$($Limits.nofile):$($Limits.nofile)", "--ulimit=cpu=$($Limits.cpu):$($Limits.cpu)",
            '--ipc=none', '--init', '--restart=no',
            '--log-driver=local', '--log-opt=max-size=8m', '--log-opt=max-file=1', '--log-opt=compress=false',
            "--tmpfs=/scratch:rw,nosuid,nodev,noexec,size=$ScratchBytes,uid=10001,gid=10001,mode=700",
            '--mount', "type=bind,source=$inputs,target=/fixture/inputs,readonly",
            '--mount', "type=bind,source=$harness,target=/fixture/harness,readonly",
            $image, $Action
        )
        if ($Payload) { $arguments += $Payload }
        $id = (Invoke-Docker $arguments).Trim()
        if ($id -notmatch '^[a-f0-9]{64}$') { throw 'Invalid container ID' }
        Assert-FixturePolicy (@(ConvertFrom-Json (Invoke-Docker @('inspect', $id)))[0])
        $null = Invoke-Docker @('start', $id)
        $clock = [Diagnostics.Stopwatch]::StartNew()
        $timedOut = $false
        do {
            $state = ConvertFrom-Json (Invoke-Docker @('inspect', '--format', '{{json .State}}', $id))
            if (!$state.Running) { break }
            if ($clock.Elapsed.TotalSeconds -ge $Deadline) { $null = Invoke-Docker @('kill', $id); $timedOut = $true; break }
            Start-Sleep -Milliseconds 500
        } while ($true)
        $null = Invoke-Docker @('wait', $id)
        $state = ConvertFrom-Json (Invoke-Docker @('inspect', '--format', '{{json .State}}', $id))
        $logs = Invoke-Docker @('logs', '--tail=1500', $id)
        if ($logs.Length -gt 8388608) { throw 'Builder log limit exceeded' }
        return [pscustomobject]@{
            TimedOut = $timedOut; ExitCode = $state.ExitCode; OOMKilled = $state.OOMKilled
            ElapsedSeconds = [math]::Round($clock.Elapsed.TotalSeconds, 2); Logs = $logs
        }
    } finally {
        if ($id -match '^[a-f0-9]{64}$') { $null = Invoke-Docker @('rm', '--force', $id) }
    }
}

# Inputs must match the manifest the trusted builder wrote.
Assert-PlainTree $inputs
Assert-PlainTree $harness
$manifest = Get-Content -LiteralPath (Join-Path $inputs 'manifest.json') -Raw | ConvertFrom-Json
foreach ($key in 'site', 'db', 'fc') {
    $entry = $manifest.$key
    $file = Join-Path $inputs ($entry.file.Replace('/', '\'))
    if ((Get-FileHash -LiteralPath $file -Algorithm SHA256).Hash.ToLowerInvariant() -ne $entry.sha256) { throw "Input hash mismatch: $($entry.file)" }
}
$null = Invoke-Docker @('image', 'inspect', $image)

$probe = Invoke-FixtureContainer 'deadline-probe' 3
if (!$probe.TimedOut -or $probe.ExitCode -eq 0) { throw 'Host deadline enforcement probe failed' }

$payloadObject = @{ tests = $Tests; options = (ConvertFrom-Json $OptionsJson) }
$payload = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes((ConvertTo-Json -InputObject $payloadObject -Compress -Depth 6)))
$result = Invoke-FixtureContainer 'fixture' $TimeoutSeconds $payload
Write-Output $result.Logs

$lines = @($result.Logs -split "`n")
$preflightLines = @($lines | Where-Object { $_.StartsWith('SANDBOX_PREFLIGHT=') })
if ($preflightLines.Count -ne 1) { throw ('Fixture preflight receipt missing: ' + $result.Logs.Substring(0, [Math]::Min(2400, $result.Logs.Length))) }
$preflightLine = $preflightLines[0]
$parsed = @()
foreach ($line in $lines | Where-Object { $_.StartsWith('FIXTURE_RESULT=') }) {
    $raw = $line.Substring('FIXTURE_RESULT='.Length).Trim()
    if ($raw.Length -gt 65536 -or $raw -notmatch '^[A-Za-z0-9+/=]+$') { continue }
    try {
        $obj = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($raw)) | ConvertFrom-Json
        if ($obj.test -match '^[a-z0-9-]+$' -and $obj.status -in @('pass', 'fail', 'error', 'reproduced', 'not-reproduced', 'blocked')) { $parsed += $obj }
    } catch { }
}
$harnessHash = (Get-ChildItem -LiteralPath $harness -Recurse -File | Sort-Object FullName | ForEach-Object {
    (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash + ' ' + $_.FullName.Substring($harness.Length) }) -join "`n"
$sha = [Security.Cryptography.SHA256]::Create()
try { $harnessDigest = [BitConverter]::ToString($sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($harnessHash))).Replace('-', '').ToLowerInvariant() } finally { $sha.Dispose() }
$status = 'passed'
if ($result.TimedOut -or $result.ExitCode -ne 0 -or $result.OOMKilled -or !$preflightLine) { $status = 'failed' }
$receiptsComplete = $parsed.Count -eq $Tests.Count
foreach ($test in $Tests) {
    $receipts = @($parsed | Where-Object { $_.test -eq $test })
    if ($receipts.Count -ne 1 -or $receipts[0].status -ne 'pass') { $receiptsComplete = $false }
}
if (!$receiptsComplete) { $status = 'failed' }
$null = New-Item -ItemType Directory -Path $records -Force
$record = [ordered]@{
    runAt = [DateTime]::UtcNow.ToString('o'); status = $status; image = $image; tests = $Tests
    options = (ConvertFrom-Json $OptionsJson)
    inputs = $manifest; harnessSha256 = $harnessDigest
    timedOut = $result.TimedOut; exitCode = $result.ExitCode; oomKilled = $result.OOMKilled
    elapsedSeconds = $result.ElapsedSeconds; deadlineProbeKilled = $probe.TimedOut
    preflight = if ($preflightLine) { ConvertFrom-Json $preflightLine.Substring('SANDBOX_PREFLIGHT='.Length) } else { $null }
    results = $parsed
    receiptsComplete = $receiptsComplete
    harnessLog = @($lines | Where-Object { $_.StartsWith('[fixture') } | Select-Object -Last 80 | ForEach-Object { if ($_.Length -gt 400) { $_.Substring(0, 400) } else { $_ } })
    evidenceNote = 'Results are parsed from bounded, structured container log lines (no file export). Container output is target-influenced.'
}
$recordFile = Join-Path $records ('run-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss-fff') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8) + '.json')
$record | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $recordFile -Encoding UTF8
Write-Output "FIXTURE_RECORD=$recordFile status=$status elapsed=$($result.ElapsedSeconds)s"
if ($status -ne 'passed') { throw "Fixture run failed: timeout=$($result.TimedOut) exit=$($result.ExitCode) OOM=$($result.OOMKilled)" }
