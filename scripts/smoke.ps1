param(
    [string]$BaseUrl = "http://localhost:8080"
)

$ErrorActionPreference = "Stop"

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) {
        throw "FAIL: $Message"
    }
    Write-Host "ok - $Message" -ForegroundColor Green
}

Write-Host "Smoke testing $BaseUrl" -ForegroundColor Cyan

$health = Invoke-RestMethod -Uri "$BaseUrl/api/health"
Assert-True ($health.status -eq "ok") "health endpoint responds"

$login = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/auth/login" -ContentType "application/json" `
    -Body (@{ email = "admin@l.loc"; password = "123456" } | ConvertTo-Json)
$token = $login.token
Assert-True (-not [string]::IsNullOrWhiteSpace($token)) "admin@l.loc can sign in"

$auth = @{ Authorization = "Bearer $token" }

$unauthorized = $false
try {
    Invoke-RestMethod -Uri "$BaseUrl/api/requests" | Out-Null
} catch {
    $unauthorized = $true
}
Assert-True $unauthorized "requests API rejects anonymous access"

$endpoint = "smoke-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
$payload = @{
    event    = @{ type = "payment"; amount = 42 }
    customer = @{ name = "Ada" }
} | ConvertTo-Json -Depth 6

$response = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/api/webhook/$endpoint" `
    -ContentType "application/json" -Body $payload
Assert-True ($response.StatusCode -eq 202) "webhook receiver accepts POST and returns 202"
$requestId = ($response.Content | ConvertFrom-Json).id
Assert-True ($requestId -gt 0) "webhook is persisted with an id"

$workflow = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    name       = "Smoke workflow"
    endpoint   = $endpoint
    enabled    = $true
    conditions = @(@{ path = "body.event.type"; operator = "equals"; value = "payment" })
    actions    = @(@{ type = "log"; message = "paid by {{ body.customer.name }}" })
} | ConvertTo-Json -Depth 8)
Assert-True ($workflow.data.id -gt 0) "workflow can be created"
Assert-True ($workflow.data.conditions -is [System.Array] -and $workflow.data.conditions.Count -eq 1) "workflow conditions return as an array"
Assert-True ($workflow.data.actions -is [System.Array] -and $workflow.data.actions.Count -eq 1) "workflow actions return as an array"

$match = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/api/webhook/$endpoint" `
    -ContentType "application/json" -Body $payload
$matchId = ($match.Content | ConvertFrom-Json).id

$detail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$matchId" -Headers $auth
Assert-True ($detail.data.runs.Count -eq 1) "matching request records one workflow run"
Assert-True ($detail.data.request.headers -isnot [string] -and $detail.data.request.query -isnot [string]) "request JSON columns return as objects"
Assert-True ($detail.data.runs[0].status -eq "success") "log action succeeds"
Assert-True ($detail.data.runs[0].message -like "*Ada*") "template placeholders resolve"

$skipPayload = @{ event = @{ type = "refund" } } | ConvertTo-Json -Depth 4
$skip = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/api/webhook/$endpoint" `
    -ContentType "application/json" -Body $skipPayload
$skipId = ($skip.Content | ConvertFrom-Json).id
$skipDetail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$skipId" -Headers $auth
Assert-True ($skipDetail.data.runs[0].status -eq "skipped") "non-matching conditions are skipped"

$forwardEndpoint = "$endpoint-forward"
$forward = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    name     = "Smoke forward"
    endpoint = $forwardEndpoint
    enabled  = $true
    actions  = @(@{ type = "forward"; url = "http://web/api/health"; method = "GET"; payload = "raw" })
} | ConvertTo-Json -Depth 8)

$paused = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    name     = "Smoke paused"
    endpoint = "$forwardEndpoint-paused"
    enabled  = $false
    actions  = @(@{ type = "log"; message = "never runs" })
} | ConvertTo-Json -Depth 8)
Assert-True ($paused.data.enabled -eq $false) "workflow can be created paused"

$forwardHit = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$forwardEndpoint" -ContentType "text/plain" -Body "ping"
$forwardId = ($forwardHit.Content | ConvertFrom-Json).id
$forwardDetail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$forwardId" -Headers $auth
Assert-True ($forwardDetail.data.request.body -eq $null) "non-JSON payload keeps body null"
Assert-True ($forwardDetail.data.request.raw_body -eq "ping") "non-JSON payload keeps raw body"
Assert-True ($forwardDetail.data.runs[0].status -eq "success") "forward action reaches target"
Assert-True ($forwardDetail.data.runs[0].message -like "*200*") "forward action records HTTP status"

$list = Invoke-RestMethod -Uri "$BaseUrl/api/requests?per_page=5" -Headers $auth
Assert-True ($list.data.Count -ge 1) "requests list returns data"
Assert-True ($list.meta.total -ge 3) "requests list exposes pagination meta"

$stats = Invoke-RestMethod -Uri "$BaseUrl/api/stats" -Headers $auth
Assert-True ($stats.data.requests_total -ge 3) "stats endpoint aggregates requests"

Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($workflow.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($forward.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($paused.data.id)" -Headers $auth | Out-Null
foreach ($id in @($requestId, $matchId, $skipId, $forwardId)) {
    Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/requests/$id" -Headers $auth | Out-Null
}

Write-Host "All smoke checks passed." -ForegroundColor Green

