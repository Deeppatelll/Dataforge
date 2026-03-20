<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';

function envString(string $name, string $default): string
{
	$value = getenv($name);
	if ($value === false || trim($value) === '') {
		return $default;
	}

	return trim($value);
}

function envInt(string $name, int $default): int
{
	$value = getenv($name);
	if ($value === false || trim($value) === '') {
		return $default;
	}

	if (!is_numeric($value)) {
		return $default;
	}

	return (int) $value;
}

function envBool(string $name, bool $default): bool
{
	$value = getenv($name);
	if ($value === false || trim($value) === '') {
		return $default;
	}

	$normalized = strtolower(trim($value));
	return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function sanitizeField(string $key): string
{
	$clean = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
	$clean = preg_replace('/_+/', '_', (string) $clean);
	$clean = trim((string) $clean, '_');
	return $clean === '' ? 'field' : $clean;
}

function isIsoDateString($value): bool
{
	if (!is_string($value) || trim($value) === '') {
		return false;
	}

	return strtotime($value) !== false;
}

function truncateText(string $value, int $maxLength): string
{
	if (strlen($value) <= $maxLength) {
		return $value;
	}

	return substr($value, 0, $maxLength);
}

function normalizeScalarValue($value)
{
	if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
		return $value;
	}

	if (is_string($value)) {
		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		$clean = str_replace(',', '', $trimmed);
		if (preg_match('/^-?\d+$/', $clean) === 1) {
			return (int) $clean;
		}
		if (is_numeric($clean)) {
			return (float) $clean;
		}
		if (strcasecmp($trimmed, 'true') === 0) {
			return true;
		}
		if (strcasecmp($trimmed, 'false') === 0) {
			return false;
		}

		return $trimmed;
	}

	return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function toSolrDoc(array $event): array
{
	$doc = [];

	$eventId = isset($event['event_id']) ? (string) $event['event_id'] : sha1(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	$doc['id'] = $eventId;
	$doc['event_id_s'] = $eventId;

	if (isset($event['source_file'])) {
		$doc['source_file_s'] = (string) $event['source_file'];
	}
	if (isset($event['row_number']) && is_numeric((string) $event['row_number'])) {
		$doc['row_num_i'] = (int) $event['row_number'];
	}
	if (isset($event['ingested_at']) && isIsoDateString($event['ingested_at'])) {
		$doc['ingested_at_dt'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $event['ingested_at']));
	}
	if (isset($event['event_date']) && isIsoDateString($event['event_date'])) {
		$doc['event_date_dt'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $event['event_date']));
	}

	foreach ($event as $key => $value) {
		if (in_array($key, ['event_id', 'source_file', 'row_number', 'ingested_at', 'operation'], true)) {
			continue;
		}
		if ($key === 'event_date') {
			continue;
		}
		if ($value === null) {
			continue;
		}

		$field = sanitizeField((string) $key);
		if (is_int($value)) {
			$doc[$field . '_i'] = $value;
			continue;
		}
		if (is_float($value)) {
			$doc[$field . '_f'] = $value;
			continue;
		}
		if (is_bool($value)) {
			$doc[$field . '_b'] = $value;
			continue;
		}
		if (isIsoDateString($value) && (str_contains($field, 'date') || str_contains($field, 'time'))) {
			$doc[$field . '_dt'] = gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $value));
			continue;
		}

		$doc[$field . '_s'] = (string) $value;
	}

	return $doc;
}

function toSolrPartialDoc(array $event): ?array
{
	$eventId = isset($event['event_id']) ? trim((string) $event['event_id']) : '';
	if ($eventId === '') {
		return null;
	}

	$doc = ['id' => $eventId];
	$ignored = ['id', 'event_id', 'operation', 'source_file', 'row_number', 'ingested_at'];

	foreach ($event as $key => $value) {
		if (in_array((string) $key, $ignored, true)) {
			continue;
		}

		$field = sanitizeField((string) $key);
		$normalized = normalizeScalarValue($value);
		if ($normalized === null) {
			continue;
		}

		if (is_int($normalized)) {
			$doc[$field . '_i'] = ['set' => $normalized];
			continue;
		}
		if (is_float($normalized)) {
			$doc[$field . '_f'] = ['set' => $normalized];
			continue;
		}
		if (is_bool($normalized)) {
			$doc[$field . '_b'] = ['set' => $normalized];
			continue;
		}
		if (isIsoDateString($normalized) && (str_contains($field, 'date') || str_contains($field, 'time'))) {
			$doc[$field . '_dt'] = ['set' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $normalized))];
			continue;
		}

		$doc[$field . '_s'] = ['set' => (string) $normalized];
	}

	return $doc;
}

function classifySolrHttpCode(int $httpCode): string
{
	if ($httpCode >= 500 || $httpCode === 429 || $httpCode === 0) {
		return 'retryable';
	}

	return 'unrecoverable';
}

function postJson(string $url, array $body, int $timeoutSeconds): array
{
	$ch = curl_init($url);
	curl_setopt_array($ch, [
		CURLOPT_POST => true,
		CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
		CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT => $timeoutSeconds,
	]);

	$response = curl_exec($ch);
	$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlError = curl_error($ch);
	curl_close($ch);

	if ($curlError !== '') {
		return [
			'ok' => false,
			'retryable' => true,
			'http_code' => 0,
			'error' => $curlError,
			'response' => '',
		];
	}

	if ($httpCode !== 200) {
		return [
			'ok' => false,
			'retryable' => classifySolrHttpCode($httpCode) === 'retryable',
			'http_code' => $httpCode,
			'error' => 'solr_http_error',
			'response' => is_string($response) ? truncateText($response, 1000) : '',
		];
	}

	return [
		'ok' => true,
		'retryable' => false,
		'http_code' => $httpCode,
		'error' => '',
		'response' => is_string($response) ? $response : '',
	];
}

function sendBatchToSolrWithRetry(
	string $solrUpdateUrl,
	array $batch,
	int $maxRetries,
	int $baseBackoffMs,
	int $timeoutSeconds
): array {
	if ($batch === []) {
		return ['ok' => true, 'attempts' => 0, 'retryable' => false, 'http_code' => 200, 'error' => '', 'response' => ''];
	}

	$attempt = 0;
	$last = ['ok' => false, 'attempts' => 0, 'retryable' => false, 'http_code' => 0, 'error' => 'unknown', 'response' => ''];

	while ($attempt < $maxRetries) {
		$attempt++;
		$result = postJson($solrUpdateUrl, $batch, $timeoutSeconds);
		$result['attempts'] = $attempt;
		$last = $result;

		if ($result['ok'] === true) {
			return $result;
		}

		if ($result['retryable'] !== true || $attempt >= $maxRetries) {
			return $result;
		}

		$sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
		usleep($sleepMs * 1000);
	}

	return $last;
}

function buildDlqPayload(array $item, string $reason, array $solrResult): array
{
	return [
		'dlq_id' => sha1(($item['event_id'] ?? 'none') . '|' . ($item['partition'] ?? -1) . '|' . ($item['offset'] ?? -1) . '|' . $reason),
		'failed_at' => gmdate('c'),
		'reason' => $reason,
		'error' => [
			'http_code' => $solrResult['http_code'] ?? 0,
			'error' => $solrResult['error'] ?? '',
			'response' => $solrResult['response'] ?? '',
			'attempts' => $solrResult['attempts'] ?? 0,
			'retryable' => $solrResult['retryable'] ?? false,
		],
		'kafka' => [
			'source_topic' => $item['source_topic'] ?? '',
			'source_partition' => $item['partition'] ?? -1,
			'source_offset' => $item['offset'] ?? -1,
			'source_key' => $item['key'] ?? '',
		],
		'event_id' => $item['event_id'] ?? '',
		'operation' => $item['operation'] ?? 'upsert',
		'payload' => $item['payload'] ?? [],
	];
}

function sendDlqMessage(RdKafka\Producer $producer, string $dlqTopic, array $payload, int $maxRetries, int $baseBackoffMs): bool
{
	$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
	if (!is_string($encoded)) {
		return false;
	}

	$topic = $producer->newTopic($dlqTopic);
	$attempt = 0;
	while ($attempt < $maxRetries) {
		$attempt++;
		try {
			$key = isset($payload['event_id']) ? (string) $payload['event_id'] : null;
			$topic->produce(RD_KAFKA_PARTITION_UA, 0, $encoded, $key);
			$producer->poll(0);
			$flushStatus = $producer->flush(5000);
			if ($flushStatus === RD_KAFKA_RESP_ERR_NO_ERROR) {
				return true;
			}
		} catch (Throwable $e) {
			fwrite(STDERR, '[consumer] dlq send error: ' . $e->getMessage() . PHP_EOL);
		}

		if ($attempt < $maxRetries) {
			$sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
			usleep($sleepMs * 1000);
		}
	}

	return false;
}

function buildDedupKey(array $payload, object $message): string
{
	$eventId = isset($payload['event_id']) ? trim((string) $payload['event_id']) : '';
	if ($eventId !== '') {
		return 'event:' . $eventId;
	}

	if (isset($message->partition, $message->offset)) {
		return 'kafka:' . (string) $message->partition . ':' . (string) $message->offset;
	}

	return 'hash:' . sha1(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function buildSolrItemFromPayload(array $payload, object $message): ?array
{
	$operation = strtolower(trim((string) ($payload['operation'] ?? 'upsert')));
	if ($operation === '') {
		$operation = 'upsert';
	}

	if ($operation === 'partial_update') {
		$doc = toSolrPartialDoc($payload);
	} else {
		$doc = toSolrDoc($payload);
		$operation = 'upsert';
	}

	if (!is_array($doc) || !isset($doc['id']) || trim((string) $doc['id']) === '') {
		return null;
	}

	$eventId = isset($payload['event_id']) && trim((string) $payload['event_id']) !== ''
		? trim((string) $payload['event_id'])
		: trim((string) $doc['id']);

	return [
		'doc' => $doc,
		'event_id' => $eventId,
		'operation' => $operation,
		'payload' => $payload,
		'key' => is_string($message->key ?? null) ? (string) $message->key : '',
		'partition' => (int) ($message->partition ?? -1),
		'offset' => (int) ($message->offset ?? -1),
		'source_topic' => is_string($message->topic_name ?? null) ? (string) $message->topic_name : '',
		'message' => $message,
	];
}

function flushBuffer(
	RdKafka\KafkaConsumer $consumer,
	RdKafka\Producer $dlqProducer,
	string $solrUpdateUrl,
	string $dlqTopic,
	array &$buffer,
	array &$dedupIndex,
	?object &$lastMessage,
	array &$stats,
	int $maxRetries,
	int $baseBackoffMs,
	int $solrTimeoutSeconds
): bool {
	if ($buffer === []) {
		return true;
	}

	$docs = array_map(static fn (array $item): array => $item['doc'], $buffer);
	$solrResult = sendBatchToSolrWithRetry($solrUpdateUrl, $docs, $maxRetries, $baseBackoffMs, $solrTimeoutSeconds);

	if ($solrResult['ok'] === true) {
		$stats['indexed'] += count($buffer);
		$stats['batches']++;
		if ($lastMessage !== null) {
			$consumer->commit($lastMessage);
			$stats['offset_commits']++;
		}

		$buffer = [];
		$dedupIndex = [];
		return true;
	}

	if ($solrResult['retryable'] === true) {
		$stats['solr_retryable_failures']++;
		fwrite(STDERR, '[consumer] retryable Solr failure after retries, keeping offsets uncommitted' . PHP_EOL);
		return false;
	}

	$stats['solr_unrecoverable_failures']++;
	$dlqAllSent = true;

	foreach ($buffer as $item) {
		$dlqPayload = buildDlqPayload($item, 'solr_unrecoverable', $solrResult);
		$sent = sendDlqMessage($dlqProducer, $dlqTopic, $dlqPayload, $maxRetries, $baseBackoffMs);
		if ($sent) {
			$stats['dlq_sent']++;
		} else {
			$dlqAllSent = false;
			$stats['dlq_failed']++;
		}
	}

	if ($dlqAllSent) {
		if ($lastMessage !== null) {
			$consumer->commit($lastMessage);
			$stats['offset_commits']++;
		}
		$buffer = [];
		$dedupIndex = [];
		return true;
	}

	fwrite(STDERR, '[consumer] unrecoverable Solr failure but DLQ publish failed for some records; offsets not committed' . PHP_EOL);
	return false;
}

$broker = envString('KAFKA_BROKER', 'kafka:29092');
$topic = envString('KAFKA_TOPIC', 'report_data_topic');
$groupId = envString('KAFKA_GROUP_ID', 'dataforge-solr-indexer');
$dlqTopic = envString('KAFKA_DLQ_TOPIC', 'report_data_topic_dlq');

$solrBaseUpdateUrl = envString('SOLR_UPDATE_BASE_URL', 'http://solr:8983/solr/reportcore/update');
$commitWithinMs = max(1, envInt('SOLR_COMMIT_WITHIN_MS', 5000));
$softCommitEveryBatch = envBool('SOLR_SOFT_COMMIT_EVERY_BATCH', false);
$finalSoftCommit = envBool('SOLR_SOFT_COMMIT_ON_FINAL_FLUSH', true);
$solrUpdateUrl = $solrBaseUpdateUrl . '?commitWithin=' . $commitWithinMs . '&overwrite=true&softCommit=' . ($softCommitEveryBatch ? 'true' : 'false');
$solrFinalUpdateUrl = $solrBaseUpdateUrl . '?commitWithin=' . $commitWithinMs . '&overwrite=true&softCommit=' . ($finalSoftCommit ? 'true' : 'false');

$batchSize = max(50, envInt('CONSUMER_BATCH_SIZE', 500));
$pollTimeoutMs = max(100, envInt('CONSUMER_POLL_TIMEOUT_MS', 1000));
$maxIdlePolls = max(1, envInt('CONSUMER_IDLE_POLLS', 12));
$maxRetries = max(1, envInt('CONSUMER_MAX_RETRIES', 4));
$baseBackoffMs = max(50, envInt('CONSUMER_RETRY_BACKOFF_MS', 100));
$solrTimeoutSeconds = max(5, envInt('SOLR_TIMEOUT_SECONDS', 60));
$dedupMaxEntries = max($batchSize * 2, envInt('DEDUP_MAX_ENTRIES', 20000));

$conf = new RdKafka\Conf();
$conf->set('group.id', $groupId);
$conf->set('metadata.broker.list', $broker);
$conf->set('auto.offset.reset', 'earliest');
$conf->set('enable.auto.commit', 'false');
$conf->set('enable.partition.eof', 'true');

$consumer = new RdKafka\KafkaConsumer($conf);
$consumer->subscribe([$topic]);

$producerConf = new RdKafka\Conf();
$producerConf->set('metadata.broker.list', $broker);
$dlqProducer = new RdKafka\Producer($producerConf);

echo "[consumer] started topic={$topic} group={$groupId} dlq_topic={$dlqTopic}\n";

$buffer = [];
$dedupIndex = [];
$lastMessage = null;
$idlePolls = 0;
$stats = [
	'consumed' => 0,
	'indexed' => 0,
	'skipped' => 0,
	'dedup_skipped' => 0,
	'batches' => 0,
	'offset_commits' => 0,
	'dlq_sent' => 0,
	'dlq_failed' => 0,
	'solr_retryable_failures' => 0,
	'solr_unrecoverable_failures' => 0,
];

while (true) {
	$message = $consumer->consume($pollTimeoutMs);

	if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
		$idlePolls = 0;
		$stats['consumed']++;

		$payload = json_decode((string) $message->payload, true);
		if (!is_array($payload)) {
			$dlqPayload = buildDlqPayload([
				'event_id' => '',
				'operation' => 'upsert',
				'payload' => ['raw_payload' => (string) $message->payload],
				'partition' => (int) $message->partition,
				'offset' => (int) $message->offset,
				'key' => is_string($message->key ?? null) ? (string) $message->key : '',
				'source_topic' => is_string($message->topic_name ?? null) ? (string) $message->topic_name : $topic,
			], 'invalid_json_payload', ['http_code' => 0, 'error' => 'json_decode_failed', 'response' => '', 'attempts' => 0, 'retryable' => false]);

			if (sendDlqMessage($dlqProducer, $dlqTopic, $dlqPayload, $maxRetries, $baseBackoffMs)) {
				$stats['dlq_sent']++;
				$consumer->commit($message);
				$stats['offset_commits']++;
			} else {
				$stats['dlq_failed']++;
			}

			$stats['skipped']++;
			continue;
		}

		$item = buildSolrItemFromPayload($payload, $message);
		if ($item === null) {
			$dlqPayload = buildDlqPayload([
				'event_id' => isset($payload['event_id']) ? (string) $payload['event_id'] : '',
				'operation' => isset($payload['operation']) ? (string) $payload['operation'] : 'upsert',
				'payload' => $payload,
				'partition' => (int) $message->partition,
				'offset' => (int) $message->offset,
				'key' => is_string($message->key ?? null) ? (string) $message->key : '',
				'source_topic' => is_string($message->topic_name ?? null) ? (string) $message->topic_name : $topic,
			], 'invalid_event_missing_id', ['http_code' => 0, 'error' => 'invalid_document', 'response' => '', 'attempts' => 0, 'retryable' => false]);

			if (sendDlqMessage($dlqProducer, $dlqTopic, $dlqPayload, $maxRetries, $baseBackoffMs)) {
				$stats['dlq_sent']++;
				$consumer->commit($message);
				$stats['offset_commits']++;
			} else {
				$stats['dlq_failed']++;
			}

			$stats['skipped']++;
			continue;
		}

		$dedupKey = buildDedupKey($payload, $message);
		if (isset($dedupIndex[$dedupKey])) {
			$stats['dedup_skipped']++;
			$lastMessage = $message;
			continue;
		}

		$dedupIndex[$dedupKey] = true;
		if (count($dedupIndex) > $dedupMaxEntries) {
			$dedupIndex = [];
		}

		$buffer[] = $item;
		$lastMessage = $message;

		if (count($buffer) >= $batchSize) {
			if (!flushBuffer(
				$consumer,
				$dlqProducer,
				$solrUpdateUrl,
				$dlqTopic,
				$buffer,
				$dedupIndex,
				$lastMessage,
				$stats,
				$maxRetries,
				$baseBackoffMs,
				$solrTimeoutSeconds
			)) {
				break;
			}
		}

		continue;
	}

	if ($message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF || $message->err === RD_KAFKA_RESP_ERR__TIMED_OUT) {
		$idlePolls++;
		if ($idlePolls >= $maxIdlePolls) {
			break;
		}
		continue;
	}

	fwrite(STDERR, "[consumer] kafka error: {$message->errstr()}\n");
	$idlePolls++;
	if ($idlePolls >= $maxIdlePolls) {
		break;
	}
}

if ($buffer !== []) {
	flushBuffer(
		$consumer,
		$dlqProducer,
		$solrFinalUpdateUrl,
		$dlqTopic,
		$buffer,
		$dedupIndex,
		$lastMessage,
		$stats,
		$maxRetries,
		$baseBackoffMs,
		$solrTimeoutSeconds
	);
}

$dlqProducer->poll(0);

echo "[consumer] completed consumed={$stats['consumed']} indexed={$stats['indexed']} skipped={$stats['skipped']} dedup_skipped={$stats['dedup_skipped']} batches={$stats['batches']} commits={$stats['offset_commits']} dlq_sent={$stats['dlq_sent']} dlq_failed={$stats['dlq_failed']} solr_retryable_failures={$stats['solr_retryable_failures']} solr_unrecoverable_failures={$stats['solr_unrecoverable_failures']}\n";
