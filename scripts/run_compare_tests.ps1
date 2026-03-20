$ErrorActionPreference = 'Stop'

function Assert-True {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        throw "ASSERTION FAILED: $Message"
    }
}

function Assert-ApproxEqual {
    param(
        [double]$Left,
        [double]$Right,
        [double]$Tolerance,
        [string]$Message
    )

    if ([math]::Abs($Left - $Right) -gt $Tolerance) {
        throw "ASSERTION FAILED: $Message (left=$Left right=$Right tolerance=$Tolerance)"
    }
}

function Invoke-Compare {
    param(
        [hashtable]$Body
    )

    $json = $Body | ConvertTo-Json -Depth 12
    return Invoke-RestMethod -Method POST -Uri "http://localhost:18081/api/compare" -ContentType "application/json" -Body $json
}

Write-Host "=== Running Compare Logic API Tests ===" -ForegroundColor Cyan

$results = @()

# Test 1: previous_period mode should return complete compare payload.
$test1 = Invoke-Compare -Body @{
    date_field = 'event_date'
    mode = 'previous_period'
    current = @{ from = '2026-03-01T00:00:00Z'; to = '2026-03-31T23:59:59Z' }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
}
Assert-True ($test1.status -eq 'ok') 'Test1 status should be ok'
Assert-True ($test1.compare.mode -eq 'previous_period') 'Test1 mode should be previous_period'
Assert-True ($null -ne $test1.compare.current.value) 'Test1 current value must exist'
Assert-True ($null -ne $test1.compare.compare.value) 'Test1 compare value must exist'
Assert-True (($test1.compare.absolute_difference) -eq ($test1.compare.current.value - $test1.compare.compare.value)) 'Test1 absolute_difference formula check'
if ($test1.compare.compare.value -eq 0) {
    Assert-True ($null -eq $test1.compare.percentage_change) 'Test1 pct should be null when baseline is zero'
} else {
    $expectedPct = (($test1.compare.current.value - $test1.compare.compare.value) / [double]$test1.compare.compare.value) * 100.0
    Assert-ApproxEqual -Left ([double]$test1.compare.percentage_change) -Right $expectedPct -Tolerance 0.0001 -Message 'Test1 pct formula check'
}
$results += [pscustomobject]@{ test = 'previous_period formula'; expected = 'valid compare object + formula pass'; actual = 'pass' }

# Test 2: same_period_last_year mode should be accepted and return ranges.
$test2 = Invoke-Compare -Body @{
    date_field = 'event_date'
    mode = 'same_period_last_year'
    current = @{ from = '2026-03-01T00:00:00Z'; to = '2026-03-31T23:59:59Z' }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
}
Assert-True ($test2.status -eq 'ok') 'Test2 status should be ok'
Assert-True ($test2.compare.mode -eq 'same_period_last_year') 'Test2 mode should be same_period_last_year'
Assert-True (($test2.compare.compare.range.from) -like '2025-*') 'Test2 compare from should be previous year'
$results += [pscustomobject]@{ test = 'same_period_last_year mode'; expected = 'mode accepted + prior-year range'; actual = 'pass' }

# Test 3: divide-by-zero handling with explicit empty previous range.
$test3 = Invoke-Compare -Body @{
    date_field = 'event_date'
    mode = 'custom_previous'
    current = @{ from = '2026-03-01T00:00:00Z'; to = '2026-03-31T23:59:59Z' }
    previous = @{ from = '1900-01-01T00:00:00Z'; to = '1900-01-31T23:59:59Z' }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
}
Assert-True ($test3.status -eq 'ok') 'Test3 status should be ok'
Assert-True (($test3.compare.compare.value) -eq 0) 'Test3 compare value should be zero for empty historical range'
Assert-True ($null -eq $test3.compare.percentage_change) 'Test3 pct must be null to avoid divide-by-zero'
$results += [pscustomobject]@{ test = 'divide-by-zero guard'; expected = 'percentage_change null when compare value is 0'; actual = 'pass' }

# Test 4: empty result sets should remain stable.
$test4 = Invoke-Compare -Body @{
    date_field = 'event_date'
    mode = 'same_period_last_year'
    current = @{ from = '2099-01-01T00:00:00Z'; to = '2099-01-31T23:59:59Z' }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
}
Assert-True (($test4.compare.current.value) -eq 0) 'Test4 current value should be zero for empty future range'
Assert-True (($test4.compare.compare.value) -eq 0) 'Test4 compare value should be zero for empty prior-year range'
Assert-True (($test4.compare.absolute_difference) -eq 0) 'Test4 absolute difference should be zero'
Assert-True ($null -eq $test4.compare.percentage_change) 'Test4 pct should be null when compare value is zero'
$results += [pscustomobject]@{ test = 'empty result set stability'; expected = '0 current + 0 compare + 0 diff + null pct'; actual = 'pass' }

# Test 5: invalid range order should fail.
$invalidThrown = $false
try {
    Invoke-Compare -Body @{
        date_field = 'event_date'
        mode = 'previous_period'
        current = @{ from = '2026-03-31T00:00:00Z'; to = '2026-03-01T23:59:59Z' }
        filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
    } | Out-Null
} catch {
    $invalidThrown = $true
}
Assert-True $invalidThrown 'Test5 invalid range order should throw HTTP error'
$results += [pscustomobject]@{ test = 'invalid range order'; expected = 'http 400 error'; actual = 'pass' }

Write-Host "\n=== Compare Tests Passed ===" -ForegroundColor Green
$results | Format-Table -AutoSize | Out-String | Write-Host
