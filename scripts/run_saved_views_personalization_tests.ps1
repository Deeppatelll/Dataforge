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

function Api-Get {
    param([string]$Url)
    return Invoke-RestMethod -Method GET -Uri $Url
}

function Api-Post {
    param([string]$Url, [hashtable]$Body)
    $json = $Body | ConvertTo-Json -Depth 16
    return Invoke-RestMethod -Method POST -Uri $Url -ContentType 'application/json' -Body $json
}

function Api-Put {
    param([string]$Url, [hashtable]$Body)
    $json = $Body | ConvertTo-Json -Depth 16
    try {
        return Invoke-RestMethod -Method PUT -Uri $Url -ContentType 'application/json' -Body $json
    } catch {
        throw "PUT failed at URL: $Url`n$($_.Exception.Message)"
    }
}

function Api-Delete {
    param([string]$Url)
    return Invoke-RestMethod -Method DELETE -Uri $Url
}

$base = 'http://localhost:18081'
$userA = "user_a_$(Get-Random)"
$userB = "user_b_$(Get-Random)"

$results = @()

Write-Host '=== Running Saved Views Personalization E2E Tests ===' -ForegroundColor Cyan

# 1) Create saved view for user A with all personalization fields.
$definitionA = @{
    selectedColumns = @('event_id', 'status', 'amount')
    columnOrder = @('event_id', 'status', 'amount')
    columnWidths = @{ event_id = 210; status = 180; amount = 160 }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @(@{ type = 'rule'; field = 'category'; operator = 'eq'; value = 'finance' }) }
    sort = @(@{ field = 'event_date'; direction = 'desc' })
    visibleColumns = @{ event_id = $true; status = $true; amount = $true; category = $false }
    pageSize = 100
}

$createA = Api-Post "$base/api/saved-views?user_id=$userA" @{
    name = 'A Primary View'
    description = 'User A initial view'
    definition = $definitionA
    is_default = $true
}

$viewAId = $createA.view.id
Assert-True ($createA.status -eq 'ok') 'Create A should return ok'
Assert-True ($createA.view.is_default -eq $true) 'Created A view should be default'
Assert-True ($createA.view.definition.columnWidths.event_id -eq 210) 'A column width should persist'
$results += [pscustomobject]@{ test = 'create user A view'; expected = 'view persisted with personalization fields'; actual = 'pass' }

# 2) Create second view for user A and set it as default.
$createA2 = Api-Post "$base/api/saved-views?user_id=$userA" @{
    name = 'A Secondary View'
    description = 'User A secondary'
    definition = $definitionA
}
$viewA2Id = $createA2.view.id
Assert-True (-not [string]::IsNullOrWhiteSpace($viewA2Id)) 'Second view id should exist'

$setDefaultA2 = Api-Put "$base/api/saved-views/${viewA2Id}?user_id=$userA" @{ is_default = $true }
Assert-True ($setDefaultA2.status -eq 'ok') 'Set default should return ok'
Assert-True ($setDefaultA2.view.id -eq $viewA2Id) 'Set default should target second view'
$results += [pscustomobject]@{ test = 'set default view'; expected = 'default switched to second view'; actual = 'pass' }

# 3) Load default for user A.
$defaultA = Api-Get "$base/api/saved-views/default?user_id=$userA"
Assert-True ($defaultA.status -eq 'ok') 'Default A should return ok'
Assert-True ($defaultA.view.id -eq $viewA2Id) 'Default A should match second view'
$results += [pscustomobject]@{ test = 'load default user A'; expected = 'returns current default view'; actual = 'pass' }

# 4) Update user A second view and verify fields changed.
$updatedDef = @{
    selectedColumns = @('event_id', 'status')
    columnOrder = @('status', 'event_id')
    columnWidths = @{ event_id = 222; status = 177 }
    filters = @{ type = 'group'; logic = 'AND'; conditions = @(@{ type = 'rule'; field = 'status'; operator = 'eq'; value = 'approved' }) }
    sort = @(@{ field = 'amount'; direction = 'asc' })
    visibleColumns = @{ event_id = $true; status = $true }
    pageSize = 25
}
$updateA = Api-Put "$base/api/saved-views/${viewA2Id}?user_id=$userA" @{
    name = 'A Secondary View Updated'
    description = 'Updated preferences'
    definition = $updatedDef
}
Assert-True ($updateA.status -eq 'ok') 'Update A should return ok'
Assert-True ($updateA.view.name -eq 'A Secondary View Updated') 'Updated name should persist'
Assert-True ($updateA.view.definition.sort[0].field -eq 'amount') 'Updated sorting should persist'
$results += [pscustomobject]@{ test = 'update saved view'; expected = 'name/definition updates persisted'; actual = 'pass' }

# 5) User isolation: user B should not see user A views.
$createB = Api-Post "$base/api/saved-views?user_id=$userB" @{
    name = 'B View'
    description = 'User B only'
    definition = @{ selectedColumns = @('event_id'); columnOrder = @('event_id'); columnWidths = @{ event_id = 150 }; filters = @{ type='group'; logic='AND'; conditions=@() }; sort=@(@{ field='event_date'; direction='desc' }) }
    is_default = $true
}
Assert-True ($createB.status -eq 'ok') 'Create B should return ok'

$listA = Api-Get "$base/api/saved-views?user_id=$userA"
$listB = Api-Get "$base/api/saved-views?user_id=$userB"
Assert-True (($listA.views | Where-Object { $_.id -eq $createB.view.id } | Measure-Object).Count -eq 0) 'User A list must not include user B view'
Assert-True (($listB.views | Where-Object { $_.id -eq $viewAId } | Measure-Object).Count -eq 0) 'User B list must not include user A view'
$results += [pscustomobject]@{ test = 'user isolation'; expected = 'views isolated by user_id'; actual = 'pass' }

# 6) Delete user A default and verify fallback default selection.
Api-Delete "$base/api/saved-views/${viewA2Id}?user_id=$userA" | Out-Null
$defaultAfterDelete = Api-Get "$base/api/saved-views/default?user_id=$userA"
Assert-True ($defaultAfterDelete.status -eq 'ok') 'Default after delete should return ok'
Assert-True ($defaultAfterDelete.view.id -eq $viewAId) 'Default should fallback to remaining user A view'
$results += [pscustomobject]@{ test = 'delete + default fallback'; expected = 'default reassigned to remaining view'; actual = 'pass' }

# 7) Validate report reads remain Solr-driven and independent from saved views store.
$query = Api-Post "$base/api/query" @{
    page = 1
    page_size = 5
    columns = @('event_id', 'status', 'amount')
    sort = @(@{ field = 'event_date'; direction = 'desc' })
    filters = @{ type = 'group'; logic = 'AND'; conditions = @() }
}
Assert-True ($query.status -eq 'ok') 'Query endpoint should remain operational'
Assert-True ($null -ne $query.pagination.total) 'Query should return Solr pagination data'
$results += [pscustomobject]@{ test = 'solr-driven reads intact'; expected = 'query endpoint unaffected by saved views storage'; actual = 'pass' }

Write-Host "`n=== Saved Views Personalization Tests Passed ===" -ForegroundColor Green
$results | Format-Table -AutoSize | Out-String | Write-Host
