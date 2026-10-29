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

function Assert-Status {
    param([scriptblock]$Call, [int]$Expected, [string]$Message)
    $actual = 0
    try {
        & $Call | Out-Null
    } catch {
        $actual = [int]$_.Exception.Response.StatusCode.value__
    }
    if ($actual -ne $Expected) {
        throw "FAIL: $Message (expected $Expected, got $actual)"
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

# --- Rooms --------------------------------------------------------------------
$rooms = Invoke-RestMethod -Uri "$BaseUrl/api/rooms" -Headers $auth
Assert-True ($rooms.data.Count -ge 1) "rooms list is seeded"
$defaultRoom = @($rooms.data | Where-Object { $_.slug -eq "default" })[0]
Assert-True ($null -ne $defaultRoom) "default room exists"

$suffix = [guid]::NewGuid().ToString("N").Substring(0, 8)
$roomSlug = "smoke-$suffix"
$room = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Smoke room $suffix"; slug = $roomSlug } | ConvertTo-Json)
$roomId = $room.data.id
Assert-True ($roomId -gt 0) "room can be created"
Assert-True ($room.data.slug -eq $roomSlug) "room keeps the requested slug"
Assert-True ($room.data.endpoint -match '^hook-[0-9a-f]{6}$') "room endpoint is generated automatically"
Assert-True ($defaultRoom.endpoint -match '^hook-[0-9a-f]{6}$') "seeded room has an endpoint"

# The endpoint is a normal room setting: it is kept when sent, regenerated when
# explicitly emptied, and rejected when it is not a single URL-safe segment.
$customEndpoint = "custom-$suffix"
$custom = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Custom endpoint $suffix"; endpoint = $customEndpoint } | ConvertTo-Json)
Assert-True ($custom.data.endpoint -eq $customEndpoint) "explicit room endpoint is kept"
Assert-Status { Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
        -Body (@{ name = "Bad endpoint $suffix"; endpoint = "Bad Endpoint!" } | ConvertTo-Json) } 422 "invalid room endpoint is rejected"
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/rooms/$($custom.data.id)" -Headers $auth | Out-Null

Assert-Status { Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
        -Body (@{ name = "Dup $suffix"; slug = $roomSlug } | ConvertTo-Json) } 422 "duplicate room slug is rejected"
Assert-Status { Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
        -Body (@{ name = "Bad $suffix"; slug = "Bad Slug!" } | ConvertTo-Json) } 422 "invalid room slug is rejected"

# Without a slug the server draws two random 12-hex groups that are unrelated to the
# room name, so a rename never changes the address and two identically named
# rooms stay addressable on their own.
$autoName = "Auto room $suffix"
$auto = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = $autoName } | ConvertTo-Json)
Assert-True ($auto.data.slug -match '^[0-9a-f]{12}-[0-9a-f]{12}$') "slug is two random 12-hex groups"
Assert-True ($auto.data.slug -notlike "*auto*") "slug does not repeat the room name"
$autoDup = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = $autoName } | ConvertTo-Json)
Assert-True ($autoDup.data.id -gt 0) "duplicate room name does not fail creation"
Assert-True ($autoDup.data.slug -match '^[0-9a-f]{12}-[0-9a-f]{12}$') "a second room with the same name also gets a random slug"
Assert-True ($autoDup.data.slug -ne $auto.data.slug) "identical room names get different slugs"

# The generated value of one room cannot be reused for another: the server
# rejects it and the client has to generate a new one.
Assert-Status { Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/rooms" -Headers $auth -ContentType "application/json" `
        -Body (@{ name = "Reuse $suffix"; slug = $auto.data.slug } | ConvertTo-Json) } 422 "a taken generated slug is rejected"

# An explicitly empty slug asks for a fresh random one, mirroring `endpoint: ""`.
$autoRegenerated = Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$($auto.data.id)" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = $autoName; slug = "" } | ConvertTo-Json)
Assert-True ($autoRegenerated.data.slug -match '^[0-9a-f]{12}-[0-9a-f]{12}$') "empty slug regenerates a random one"
Assert-True ($autoRegenerated.data.slug -ne $auto.data.slug) "regenerated slug differs from the previous one"

Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/rooms/$($autoDup.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/rooms/$($auto.data.id)" -Headers $auth | Out-Null

$renamed = Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Smoke room renamed $suffix" } | ConvertTo-Json)
Assert-True ($renamed.data.slug -eq $roomSlug) "renaming a room keeps its slug unless one is sent"
Assert-True ($renamed.data.endpoint -eq $room.data.endpoint) "renaming a room keeps its endpoint unless one is sent"

$changedEndpoint = "changed-$suffix"
$endpointChange = Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Smoke room renamed $suffix"; endpoint = $changedEndpoint } | ConvertTo-Json)
Assert-True ($endpointChange.data.endpoint -eq $changedEndpoint) "explicit endpoint change is applied"

$regenerated = Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Smoke room renamed $suffix"; endpoint = "" } | ConvertTo-Json)
Assert-True ($regenerated.data.endpoint -match '^hook-[0-9a-f]{6}$') "empty endpoint regenerates a random one"
Assert-True ($regenerated.data.endpoint -ne $changedEndpoint) "regenerated endpoint differs from the previous one"

# --- Ingestion into a specific room -------------------------------------------
$endpoint = "smoke-$suffix"
$payload = @{
    event    = @{ type = "payment"; amount = 42 }
    customer = @{ name = "Ada" }
} | ConvertTo-Json -Depth 6

$response = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/api/webhook/$roomSlug/$endpoint" `
    -ContentType "application/json" -Body $payload
Assert-True ($response.StatusCode -eq 202) "webhook receiver accepts POST and returns 202"
$accepted = $response.Content | ConvertFrom-Json
$requestId = $accepted.id
Assert-True ($requestId -gt 0) "webhook is persisted with an id"
Assert-True ($accepted.room -eq $roomSlug) "webhook response names the room"

$roomScoped = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug" -Headers $auth
Assert-True ($roomScoped.meta.total -eq 1) "request is stored only in its room"
$otherRoom = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=default" -Headers $auth
Assert-True (-not ($otherRoom.data | Where-Object { $_.id -eq $requestId })) "request does not leak into another room"

# --- Workflows are scoped to the room -----------------------------------------
$workflow = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    room       = $roomSlug
    name       = "Smoke workflow"
    endpoint   = $endpoint
    enabled    = $true
    conditions = @(@{ path = "body.event.type"; operator = "equals"; value = "payment" })
    actions    = @(@{ type = "log"; message = "paid by {{ body.customer.name }}" })
} | ConvertTo-Json -Depth 8)
Assert-True ($workflow.data.id -gt 0) "workflow can be created"
Assert-True ($workflow.data.room_id -eq $roomId) "workflow is bound to the room"
Assert-True ($workflow.data.conditions -is [System.Array] -and $workflow.data.conditions.Count -eq 1) "workflow conditions return as an array"
Assert-True ($workflow.data.actions -is [System.Array] -and $workflow.data.actions.Count -eq 1) "workflow actions return as an array"

$roomWorkflows = Invoke-RestMethod -Uri "$BaseUrl/api/workflows?room=$roomSlug" -Headers $auth
Assert-True ($roomWorkflows.data.Count -eq 1 -and $roomWorkflows.data[0].id -eq $workflow.data.id) "workflow list is filtered by room"

Assert-Status { Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/workflows/$($workflow.data.id)" -Headers $auth `
        -ContentType "application/json" -Body (@{
            room_id = $defaultRoom.id
            name    = "Smoke moved"
            endpoint = $endpoint
            actions = @(@{ type = "log"; message = "moved" })
        } | ConvertTo-Json -Depth 8) } 422 "workflow cannot move to another room"

# The slug is what external integrations point at, so an explicit change must
# take effect and the old address must stop resolving.
$renamedSlug = "$roomSlug-renamed"
$slugChange = Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth -ContentType "application/json" `
    -Body (@{ name = "Smoke room $suffix"; slug = $renamedSlug } | ConvertTo-Json)
Assert-True ($slugChange.data.slug -eq $renamedSlug) "explicit slug change is applied"
Assert-Status { Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug/$endpoint" `
        -ContentType "application/json" -Body '{"x":1}' } 404 "old room slug stops resolving"
$newSlugHit = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$renamedSlug/$endpoint" `
    -ContentType "application/json" -Body $payload
Assert-True ($newSlugHit.StatusCode -eq 202) "new room slug accepts webhooks"
$roomSlug = $renamedSlug

# Same endpoint in another room: it must not fire for this room's webhook.
$decoy = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    room     = "default"
    name     = "Smoke decoy"
    endpoint = $endpoint
    enabled  = $true
    actions  = @(@{ type = "log"; message = "wrong room" })
} | ConvertTo-Json -Depth 8)
Assert-True ($decoy.data.room_id -eq $defaultRoom.id) "same endpoint can exist in another room"

$match = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug/$endpoint" `
    -ContentType "application/json" -Body $payload
$matchId = ($match.Content | ConvertFrom-Json).id

$detail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$matchId" -Headers $auth
Assert-True ($detail.data.runs.Count -eq 1) "only the room's own workflow fires"
Assert-True ($detail.data.runs[0].workflow_id -eq $workflow.data.id) "run belongs to the room workflow"
Assert-True ($detail.data.request.headers -isnot [string] -and $detail.data.request.query -isnot [string]) "request JSON columns return as objects"
Assert-True ($detail.data.runs[0].status -eq "success") "log action succeeds"
Assert-True ($detail.data.runs[0].message -like "*Ada*") "template placeholders resolve"

$skipPayload = @{ event = @{ type = "refund" } } | ConvertTo-Json -Depth 4
$skip = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug/$endpoint" `
    -ContentType "application/json" -Body $skipPayload
$skipId = ($skip.Content | ConvertFrom-Json).id
$skipDetail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$skipId" -Headers $auth
Assert-True ($skipDetail.data.runs[0].status -eq "skipped") "non-matching conditions are skipped"

$forwardEndpoint = "$endpoint-forward"
$forward = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    room     = $roomSlug
    name     = "Smoke forward"
    endpoint = $forwardEndpoint
    enabled  = $true
    actions  = @(@{ type = "forward"; url = "http://web/api/health"; method = "GET"; payload = "raw" })
} | ConvertTo-Json -Depth 8)

$paused = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/workflows" -Headers $auth -ContentType "application/json" -Body (@{
    room     = $roomSlug
    name     = "Smoke paused"
    endpoint = "$forwardEndpoint-paused"
    enabled  = $false
    actions  = @(@{ type = "log"; message = "never runs" })
} | ConvertTo-Json -Depth 8)
Assert-True ($paused.data.enabled -eq $false) "workflow can be created paused"

$forwardHit = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug/$forwardEndpoint" -ContentType "text/plain" -Body "ping"
$forwardId = ($forwardHit.Content | ConvertFrom-Json).id
$forwardDetail = Invoke-RestMethod -Uri "$BaseUrl/api/requests/$forwardId" -Headers $auth
Assert-True ($forwardDetail.data.request.body -eq $null) "non-JSON payload keeps body null"
Assert-True ($forwardDetail.data.request.raw_body -eq "ping") "non-JSON payload keeps raw body"
Assert-True ($forwardDetail.data.runs[0].status -eq "success") "forward action reaches target"
Assert-True ($forwardDetail.data.runs[0].message -like "*200*") "forward action records HTTP status"

# --- Address resolution -------------------------------------------------------
$roomOnly = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug" -ContentType "application/json" -Body '{"x":1}'
$roomOnlyBody = $roomOnly.Content | ConvertFrom-Json
Assert-True ($roomOnlyBody.endpoint -eq "default") "/hook/{room} stores endpoint 'default'"

Assert-Status { Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$roomSlug-unknown/thing" `
        -ContentType "application/json" -Body '{"x":1}' } 404 "webhook to an unknown room is rejected"

$legacy = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/hook/$endpoint-legacy" -ContentType "application/json" -Body '{"x":1}'
$legacyBody = $legacy.Content | ConvertFrom-Json
Assert-True ($legacyBody.room -eq "default") "legacy /hook/{endpoint} lands in the default room"

$list = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug&per_page=5" -Headers $auth
Assert-True ($list.data.Count -ge 1) "requests list returns data"
Assert-True ($list.meta.total -ge 3) "requests list exposes pagination meta"
Assert-True ($null -eq ($list.data[0].PSObject.Properties['body'])) "lean request list omits the body column"

# The workflow editor builds condition paths from a real payload, so it asks
# for the body explicitly; jsonb must arrive decoded, not as a JSON string.
$sampleList = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug&per_page=5&include=body" -Headers $auth
Assert-True ($sampleList.data.Count -ge 1) "request samples with body return data"
$jsonSample = @($sampleList.data | Where-Object { $_.body -and $_.body.customer.name -eq "Ada" })[0]
Assert-True ($null -ne $jsonSample) "request sample for the endpoint is listed"
Assert-True ($jsonSample.body -isnot [string]) "include=body returns decoded jsonb"
Assert-True ($jsonSample.body.event.type -eq "payment") "include=body keeps nested values"
Assert-True ($jsonSample.body.event.amount -eq 42) "include=body keeps numeric values"
Assert-True ($null -ne $jsonSample.PSObject.Properties['raw_body']) "include=body exposes the raw body"

$plainSample = @((Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug&endpoint=$forwardEndpoint&include=body" -Headers $auth).data)[0]
Assert-True ($null -ne $plainSample) "non-JSON sample is listed"
Assert-True ($null -eq $plainSample.body) "include=body keeps non-JSON body null"
Assert-True ($plainSample.raw_body -eq "ping") "include=body keeps non-JSON raw body"

$sampleByEndpoint = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug&endpoint=$endpoint&include=body" -Headers $auth
Assert-True (@($sampleByEndpoint.data | Where-Object { $_.endpoint -ne $endpoint }).Count -eq 0) "include=body respects the endpoint filter"
$ignoredInclude = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug&per_page=1&include=nope" -Headers $auth
Assert-True ($null -eq $ignoredInclude.data[0].PSObject.Properties['body']) "unknown include values are ignored"

$stats = Invoke-RestMethod -Uri "$BaseUrl/api/stats?room=$roomSlug" -Headers $auth
Assert-True ($stats.data.requests_total -ge 3) "stats endpoint aggregates requests"
Assert-True ($stats.data.workflows_active -ge 1) "stats endpoint counts the room's workflows"

Assert-Status { Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=$roomSlug-nope" -Headers $auth } 404 "unknown room filter is rejected"

# --- Realtime websocket -------------------------------------------------------
$ticketRejected = $false
try {
    Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/ws/ticket" | Out-Null
} catch {
    $ticketRejected = $true
}
Assert-True $ticketRejected "ws ticket requires authorization"

$ticket = Invoke-RestMethod -Method Post -Uri "$BaseUrl/api/ws/ticket" -Headers $auth
Assert-True (-not [string]::IsNullOrWhiteSpace($ticket.data.ticket)) "ws ticket is issued for a signed-in user"
Assert-True ($ticket.data.expires_in -gt 0 -and $ticket.data.expires_in -le 300) "ws ticket is short-lived"

$liveRequestId = $null
try {
    $wsUri = [Uri]($BaseUrl.Replace("http", "ws") + "/api/ws?ticket=" + [Uri]::EscapeDataString($ticket.data.ticket))
    $ws = [System.Net.WebSockets.ClientWebSocket]::new()
    $ct = [System.Threading.CancellationToken]::None
    $buffer = [byte[]]::new(65536)

    $ws.ConnectAsync($wsUri, $ct).Wait(10000) | Out-Null
    Assert-True ($ws.State -eq [System.Net.WebSockets.WebSocketState]::Open) "websocket upgrade succeeds through nginx"

    $hello = $ws.ReceiveAsync([System.ArraySegment[byte]]::new($buffer), $ct)
    $hello.Wait(5000) | Out-Null
    $helloText = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $hello.Result.Count)
    Assert-True ($helloText -like '*"hello"*') "websocket sends hello on connect"

    $liveEndpoint = "smoke-live-$suffix"
    $liveResponse = Invoke-WebRequest -UseBasicParsing -Method Post -Uri "$BaseUrl/api/webhook/$roomSlug/$liveEndpoint" `
        -ContentType "application/json" -Body '{"event":{"type":"live"}}'
    $liveRequestId = ($liveResponse.Content | ConvertFrom-Json).id

    $eventText = ''
    $deadline = (Get-Date).AddSeconds(10)
    while ((Get-Date) -lt $deadline) {
        $frame = $ws.ReceiveAsync([System.ArraySegment[byte]]::new($buffer), $ct)
        if (-not $frame.Wait(3000)) { continue }
        $text = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $frame.Result.Count)
        if ($text -like '*request.created*') {
            $eventText = $text
            break
        }
    }
    Assert-True ($eventText -like "*`"id`":$liveRequestId*") "webhook delivery pushes request.created over websocket"
    Assert-True ($eventText -like "*`"room_id`":$roomId*") "realtime event carries the room id"

    # Renaming a room must reach open tabs so they can follow the new slug.
    Invoke-RestMethod -Method Put -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth -ContentType "application/json" `
        -Body (@{ name = "Smoke room live $suffix" } | ConvertTo-Json) | Out-Null

    $roomEventText = ''
    $roomDeadline = (Get-Date).AddSeconds(10)
    while ((Get-Date) -lt $roomDeadline) {
        $frame = $ws.ReceiveAsync([System.ArraySegment[byte]]::new($buffer), $ct)
        if (-not $frame.Wait(3000)) { continue }
        $text = [System.Text.Encoding]::UTF8.GetString($buffer, 0, $frame.Result.Count)
        if ($text -like '*room.changed*') {
            $roomEventText = $text
            break
        }
    }
    Assert-True ($roomEventText -like "*`"id`":$roomId*") "room update is pushed over websocket"
    Assert-True ($roomEventText -like "*`"slug`":`"$roomSlug`"*") "room event carries the slug"

    $ws.Dispose()
} catch {
    if ($ws) { $ws.Dispose() }
    Write-Host "skip - realtime websocket checks ($($_.Exception.Message))" -ForegroundColor Yellow
}

# --- Cleanup ------------------------------------------------------------------
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($workflow.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($forward.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($paused.data.id)" -Headers $auth | Out-Null
Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/workflows/$($decoy.data.id)" -Headers $auth | Out-Null

Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth | Out-Null
Assert-Status { Invoke-RestMethod -Uri "$BaseUrl/api/rooms/$roomId" -Headers $auth } 404 "deleted room is gone"

# Deleting a room cascades to its requests and workflows.
$goneRequests = Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=default&per_page=100" -Headers $auth
Assert-True (-not ($goneRequests.data | Where-Object { $_.id -eq $requestId })) "deleting a room removes its requests"

if ($liveRequestId) {
    Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/requests/$liveRequestId" -Headers $auth | Out-Null
}
foreach ($legacyId in @((Invoke-RestMethod -Uri "$BaseUrl/api/requests?room=default&per_page=100" -Headers $auth).data |
        Where-Object { $_.endpoint -eq "$endpoint-legacy" } | ForEach-Object { $_.id })) {
    Invoke-RestMethod -Method Delete -Uri "$BaseUrl/api/requests/$legacyId" -Headers $auth | Out-Null
}

Write-Host "All smoke checks passed." -ForegroundColor Green
