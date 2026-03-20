param(
  [string]$WorkerContainer = "dataforge-php-worker",
  [string]$KafkaContainer = "dataforge-kafka",
  [string]$SolrSelectUrl = "http://localhost:18983/solr/reportcore/select"
)

$ErrorActionPreference = "Stop"
$PSNativeCommandUseErrorActionPreference = $false

function Assert-True {
  param(
    [bool]$Condition,
    [string]$Name,
    [string]$SuccessDetail,
    [string]$FailureDetail
  )

  if ($Condition) {
    Write-Host "[PASS] $Name - $SuccessDetail" -ForegroundColor Green
    return $true
  }

  Write-Host "[FAIL] $Name - $FailureDetail" -ForegroundColor Red
  return $false
}

function Run-Docker {
  param(
    [string[]]$DockerArgs,
    [switch]$AllowNonZeroExit
  )

  $previousPreference = $ErrorActionPreference
  $ErrorActionPreference = "Continue"
  $output = & docker @DockerArgs 2>&1
  $exitCode = $LASTEXITCODE
  $ErrorActionPreference = $previousPreference

  if ($exitCode -ne 0 -and -not $AllowNonZeroExit) {
    throw "Docker command failed: docker $($DockerArgs -join ' ')`n$output"
  }

  return ($output | Out-String)
}

function Produce-JsonLine {
  param(
    [string]$FilePath,
    [string]$Topic,
    [string]$KafkaContainer
  )

  $remote = "/tmp/$(Split-Path $FilePath -Leaf)"
  Run-Docker -DockerArgs @("cp", $FilePath, "${KafkaContainer}:$remote") | Out-Null
  Run-Docker -DockerArgs @("exec", "-i", $KafkaContainer, "sh", "-lc", "kafka-console-producer --bootstrap-server kafka:29092 --topic $Topic < $remote") | Out-Null
}

function Query-Solr {
  param(
    [string]$BaseUrl,
    [string]$Q,
    [string]$Fl,
    [int]$Rows = 1
  )

  $qEnc = [System.Uri]::EscapeDataString($Q)
  $flEnc = [System.Uri]::EscapeDataString($Fl)
  $uri = "${BaseUrl}?q=${qEnc}&fl=${flEnc}&rows=${Rows}&wt=json"
  return Invoke-RestMethod -Uri $uri
}

$runId = [DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$mainTopic = "reliability_run_${runId}"
$dlqTopic = "reliability_run_${runId}_dlq"
$retryTopic = "reliability_run_${runId}_retry"
$retryDlqTopic = "reliability_run_${runId}_retry_dlq"
$groupOk = "rel-group-${runId}"
$groupRetry = "rel-group-retry-${runId}"

Write-Host "=== Reliability Test Run: $runId ===" -ForegroundColor Cyan

Run-Docker -DockerArgs @("exec", $KafkaContainer, "kafka-topics", "--bootstrap-server", "kafka:29092", "--create", "--if-not-exists", "--topic", $mainTopic, "--partitions", "1", "--replication-factor", "1") | Out-Null
Run-Docker -DockerArgs @("exec", $KafkaContainer, "kafka-topics", "--bootstrap-server", "kafka:29092", "--create", "--if-not-exists", "--topic", $dlqTopic, "--partitions", "1", "--replication-factor", "1") | Out-Null
Run-Docker -DockerArgs @("exec", $KafkaContainer, "kafka-topics", "--bootstrap-server", "kafka:29092", "--create", "--if-not-exists", "--topic", $retryTopic, "--partitions", "1", "--replication-factor", "1") | Out-Null
Run-Docker -DockerArgs @("exec", $KafkaContainer, "kafka-topics", "--bootstrap-server", "kafka:29092", "--create", "--if-not-exists", "--topic", $retryDlqTopic, "--partitions", "1", "--replication-factor", "1") | Out-Null

$tmpDir = Join-Path $env:TEMP "dataforge-reliability-$runId"
New-Item -ItemType Directory -Path $tmpDir -Force | Out-Null

$upsertPath = Join-Path $tmpDir "upsert.jsonl"
$partialPath = Join-Path $tmpDir "partial.jsonl"
$invalidPath = Join-Path $tmpDir "invalid.jsonl"
$retryPath = Join-Path $tmpDir "retry.jsonl"

Set-Content -Path $upsertPath -Value '{"event_id":"rel-evt-1","name":"Alpha","amount":10,"event_date":"2026-03-18"}' -Encoding ascii
Set-Content -Path $partialPath -Value '{"event_id":"rel-evt-1","operation":"partial_update","amount":99,"status":"patched"}' -Encoding ascii
Set-Content -Path $invalidPath -Value '{"operation":"partial_update","status":"bad-no-id"}' -Encoding ascii
Set-Content -Path $retryPath -Value '{"event_id":"rel-evt-retry","name":"RetryCase","amount":7,"event_date":"2026-03-18"}' -Encoding ascii

$allPassed = $true

# Scenario 1: upsert success
Produce-JsonLine -FilePath $upsertPath -Topic $mainTopic -KafkaContainer $KafkaContainer
$consumerOut1 = Run-Docker -DockerArgs @("exec", "-e", "KAFKA_TOPIC=$mainTopic", "-e", "KAFKA_DLQ_TOPIC=$dlqTopic", "-e", "KAFKA_GROUP_ID=$groupOk", "-e", "CONSUMER_BATCH_SIZE=1", "-e", "CONSUMER_IDLE_POLLS=30", $WorkerContainer, "php", "/app/consumer.php")
$doc1 = Query-Solr -BaseUrl $SolrSelectUrl -Q "event_id_s:rel-evt-1" -Fl "event_id_s,name_s" -Rows 1
$allPassed = (Assert-True -Condition ($doc1.response.numFound -eq 1) -Name "Scenario 1 Upsert" -SuccessDetail "Document indexed in Solr" -FailureDetail "Document not found in Solr") -and $allPassed

# Scenario 2: partial update success
Produce-JsonLine -FilePath $partialPath -Topic $mainTopic -KafkaContainer $KafkaContainer
$consumerOut2 = Run-Docker -DockerArgs @("exec", "-e", "KAFKA_TOPIC=$mainTopic", "-e", "KAFKA_DLQ_TOPIC=$dlqTopic", "-e", "KAFKA_GROUP_ID=$groupOk", "-e", "CONSUMER_BATCH_SIZE=1", "-e", "CONSUMER_IDLE_POLLS=30", $WorkerContainer, "php", "/app/consumer.php")
$doc2 = Query-Solr -BaseUrl $SolrSelectUrl -Q "event_id_s:rel-evt-1" -Fl "event_id_s,amount_i,status_s" -Rows 1
$amountOk = $doc2.response.docs[0].amount_i -eq 99
$statusOk = $doc2.response.docs[0].status_s -eq "patched"
$allPassed = (Assert-True -Condition ($doc2.response.numFound -eq 1 -and $amountOk -and $statusOk) -Name "Scenario 2 Partial Update" -SuccessDetail "Atomic update applied" -FailureDetail "Updated values not found") -and $allPassed

# Scenario 3: invalid event to DLQ
Produce-JsonLine -FilePath $invalidPath -Topic $mainTopic -KafkaContainer $KafkaContainer
$consumerOut3 = Run-Docker -DockerArgs @("exec", "-e", "KAFKA_TOPIC=$mainTopic", "-e", "KAFKA_DLQ_TOPIC=$dlqTopic", "-e", "KAFKA_GROUP_ID=$groupOk", "-e", "CONSUMER_BATCH_SIZE=1", "-e", "CONSUMER_IDLE_POLLS=30", $WorkerContainer, "php", "/app/consumer.php")
$dlqRaw = Run-Docker -DockerArgs @("exec", $KafkaContainer, "sh", "-lc", "kafka-console-consumer --bootstrap-server kafka:29092 --topic $dlqTopic --from-beginning --max-messages 1 --timeout-ms 7000") -AllowNonZeroExit
$dlqOk = $dlqRaw -match '"reason":"invalid_event_missing_id"'
$allPassed = (Assert-True -Condition $dlqOk -Name "Scenario 3 Invalid->DLQ" -SuccessDetail "Invalid payload routed to DLQ" -FailureDetail "Expected DLQ reason not found") -and $allPassed

# Scenario 4: retryable failure then recovery
Produce-JsonLine -FilePath $retryPath -Topic $retryTopic -KafkaContainer $KafkaContainer
$consumerOut4a = Run-Docker -DockerArgs @("exec", "-e", "KAFKA_TOPIC=$retryTopic", "-e", "KAFKA_DLQ_TOPIC=$retryDlqTopic", "-e", "KAFKA_GROUP_ID=$groupRetry", "-e", "SOLR_UPDATE_BASE_URL=http://solr:9999/solr/reportcore/update", "-e", "CONSUMER_BATCH_SIZE=1", "-e", "CONSUMER_IDLE_POLLS=20", "-e", "CONSUMER_MAX_RETRIES=2", $WorkerContainer, "php", "/app/consumer.php")
$consumerOut4b = Run-Docker -DockerArgs @("exec", "-e", "KAFKA_TOPIC=$retryTopic", "-e", "KAFKA_DLQ_TOPIC=$retryDlqTopic", "-e", "KAFKA_GROUP_ID=$groupRetry", "-e", "CONSUMER_BATCH_SIZE=1", "-e", "CONSUMER_IDLE_POLLS=20", $WorkerContainer, "php", "/app/consumer.php")
$doc4 = Query-Solr -BaseUrl $SolrSelectUrl -Q "event_id_s:rel-evt-retry" -Fl "event_id_s,name_s,amount_i" -Rows 1
$retryFailSeen = $consumerOut4a -match 'solr_retryable_failures=1'
$firstCommitZero = $consumerOut4a -match 'commits=0'
$secondCommitOne = $consumerOut4b -match 'commits=1'
$allPassed = (Assert-True -Condition ($retryFailSeen -and $firstCommitZero -and $secondCommitOne -and $doc4.response.numFound -eq 1) -Name "Scenario 4 Retryable->Recover" -SuccessDetail "No commit on failure, commit after recovery" -FailureDetail "Retry/commit behavior mismatch") -and $allPassed

Write-Host ""
Write-Host "=== Summary ===" -ForegroundColor Cyan
if ($allPassed) {
  Write-Host "RESULT: PASS (4/4 scenarios)" -ForegroundColor Green
  exit 0
}

Write-Host "RESULT: FAIL (one or more scenarios failed)" -ForegroundColor Red
exit 1
