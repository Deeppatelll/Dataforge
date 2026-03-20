<?php

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';

use Kafka\Producer;
use Kafka\ProducerConfig;

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

function envList(string $name): array
{
	$value = getenv($name);
	if ($value === false || trim($value) === '') {
		return [];
	}

	$parts = array_map('trim', explode(',', $value));
	$parts = array_filter($parts, static fn (string $item): bool => $item !== '');
	return array_values(array_unique($parts));
}

function clampBatchSize(int $batchSize): int
{
	if ($batchSize < 500) {
		return 500;
	}

	if ($batchSize > 1000) {
		return 1000;
	}

	return $batchSize;
}

function discoverCsvFiles(string $inputPath): array
{
	if (is_file($inputPath)) {
		return [$inputPath];
	}

	if (is_dir($inputPath)) {
		$files = glob(rtrim($inputPath, '/\\') . DIRECTORY_SEPARATOR . '*.csv');
		if ($files === false) {
			return [];
		}

		sort($files);
		return $files;
	}

	return [];
}

function normalizeHeaders(array $headers): array
{
	$seen = [];
	$normalized = [];

	foreach ($headers as $index => $header) {
		$raw = trim((string) $header);
		$key = $raw === '' ? 'column_' . ($index + 1) : preg_replace('/[^a-zA-Z0-9_]/', '_', $raw);
		if ($key === null || $key === '') {
			$key = 'column_' . ($index + 1);
		}

		$base = $key;
		$suffix = 1;
		while (isset($seen[$key])) {
			$suffix++;
			$key = $base . '_' . $suffix;
		}

		$seen[$key] = true;
		$normalized[] = $key;
	}

	return $normalized;
}

function autocast(string $value)
{
	$value = trim($value);
	if ($value === '') {
		return null;
	}

	$clean = str_replace(',', '', $value);
	if (preg_match('/^-?\d+$/', $clean) === 1) {
		return (int) $clean;
	}

	if (is_numeric($clean)) {
		return (float) $clean;
	}

	if (strcasecmp($value, 'true') === 0) {
		return true;
	}

	if (strcasecmp($value, 'false') === 0) {
		return false;
	}

	return $value;
}

function isNumericLike($value): bool
{
	if (is_int($value) || is_float($value)) {
		return true;
	}

	if (!is_string($value)) {
		return false;
	}

	$candidate = str_replace(',', '', trim($value));
	return $candidate !== '' && is_numeric($candidate);
}

function isDateLike($value): bool
{
	if (!is_string($value) || trim($value) === '') {
		return false;
	}

	return strtotime($value) !== false;
}

function buildEventId(string $sourceFile, int $rowNumber, array $record): string
{
	return sha1($sourceFile . '|' . (string) $rowNumber . '|' . json_encode($record, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
}

function sendWithRetry(Producer $producer, array $messages, int $maxRetries, int $baseBackoffMs): bool
{
	$attempt = 0;
	while ($attempt < $maxRetries) {
		try {
			$producer->send($messages);
			return true;
		} catch (Throwable $e) {
			$attempt++;
			if ($attempt >= $maxRetries) {
				return false;
			}

			$sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
			usleep($sleepMs * 1000);
		}
	}

	return false;
}

function buildProducerWithRetry(int $maxRetries, int $baseBackoffMs): ?Producer
{
	$attempt = 0;
	while ($attempt < $maxRetries) {
		try {
			return new Producer();
		} catch (Throwable $e) {
			$attempt++;
			if ($attempt >= $maxRetries) {
				fwrite(STDERR, '[producer] failed to initialize kafka producer after retries: ' . $e->getMessage() . PHP_EOL);
				return null;
			}

			$sleepMs = $baseBackoffMs * (2 ** ($attempt - 1));
			usleep($sleepMs * 1000);
		}
	}

	return null;
}

function logInvalidRow($handle, string $sourceFile, int $rowNumber, string $reason, array $row): void
{
	fwrite(
		$handle,
		json_encode([
			'source_file' => $sourceFile,
			'row_number' => $rowNumber,
			'reason' => $reason,
			'row' => $row,
			'logged_at' => gmdate('c'),
		], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL
	);
}

$broker = envString('KAFKA_BROKER', 'kafka:29092');
$topic = envString('KAFKA_TOPIC', 'report_data_topic');
$inputPath = envString('CSV_INPUT_PATH', '/app/sample-data');
$batchSize = clampBatchSize(envInt('PRODUCER_BATCH_SIZE', 500));
$maxRetries = max(1, envInt('PRODUCER_MAX_RETRIES', 5));
$baseBackoffMs = max(50, envInt('PRODUCER_BACKOFF_MS', 100));
$invalidLogPath = envString('INVALID_LOG_PATH', '/tmp/producer-invalid-rows.ndjson');

$requiredColumns = envList('REQUIRED_COLUMNS');
$numericColumns = envList('NUMERIC_COLUMNS');
$dateColumns = envList('DATE_COLUMNS');

$invalidDir = dirname($invalidLogPath);
if (!is_dir($invalidDir)) {
	mkdir($invalidDir, 0777, true);
}

$invalidHandle = fopen($invalidLogPath, 'ab');
if ($invalidHandle === false) {
	fwrite(STDERR, "Could not open invalid log file: {$invalidLogPath}\n");
	exit(1);
}

$files = discoverCsvFiles($inputPath);
if ($files === []) {
	fwrite(STDERR, "No CSV files found at: {$inputPath}\n");
	fclose($invalidHandle);
	exit(1);
}

$config = ProducerConfig::getInstance();
$config->setMetadataBrokerList($broker);
$producer = buildProducerWithRetry($maxRetries, $baseBackoffMs);
if ($producer === null) {
	fclose($invalidHandle);
	exit(1);
}

$stats = [
	'files' => 0,
	'rows_read' => 0,
	'rows_valid' => 0,
	'rows_invalid' => 0,
	'messages_sent' => 0,
	'messages_failed' => 0,
];

$buffer = [];

foreach ($files as $csvFile) {
	$stats['files']++;
	$sourceFile = basename($csvFile);
	echo "[producer] processing {$sourceFile}\n";

	$handle = fopen($csvFile, 'rb');
	if ($handle === false) {
		$stats['rows_invalid']++;
		logInvalidRow($invalidHandle, $sourceFile, 0, 'cannot_open_file', []);
		continue;
	}

	$headerRow = fgetcsv($handle);
	if ($headerRow === false || $headerRow === [null]) {
		fclose($handle);
		$stats['rows_invalid']++;
		logInvalidRow($invalidHandle, $sourceFile, 0, 'missing_header', []);
		continue;
	}

	$headers = normalizeHeaders($headerRow);
	$missingRequired = array_values(array_diff($requiredColumns, $headers));
	if ($missingRequired !== []) {
		fclose($handle);
		$stats['rows_invalid']++;
		logInvalidRow($invalidHandle, $sourceFile, 0, 'missing_required_columns:' . implode('|', $missingRequired), $headers);
		continue;
	}

	$rowNumber = 0;
	while (($row = fgetcsv($handle)) !== false) {
		$rowNumber++;
		$stats['rows_read']++;

		if ($row === [null]) {
			$stats['rows_invalid']++;
			logInvalidRow($invalidHandle, $sourceFile, $rowNumber, 'empty_row', []);
			continue;
		}

		if (count($row) !== count($headers)) {
			$stats['rows_invalid']++;
			logInvalidRow($invalidHandle, $sourceFile, $rowNumber, 'column_count_mismatch', $row);
			continue;
		}

		$combined = array_combine($headers, $row);
		if ($combined === false) {
			$stats['rows_invalid']++;
			logInvalidRow($invalidHandle, $sourceFile, $rowNumber, 'array_combine_failed', $row);
			continue;
		}

		$rowInvalidReason = '';
		foreach ($requiredColumns as $column) {
			$value = isset($combined[$column]) ? trim((string) $combined[$column]) : '';
			if ($value === '') {
				$rowInvalidReason = 'required_value_missing:' . $column;
				break;
			}
		}

		if ($rowInvalidReason === '') {
			foreach ($numericColumns as $column) {
				if (!array_key_exists($column, $combined) || trim((string) $combined[$column]) === '') {
					continue;
				}

				if (!isNumericLike($combined[$column])) {
					$rowInvalidReason = 'invalid_numeric:' . $column;
					break;
				}
			}
		}

		if ($rowInvalidReason === '') {
			foreach ($dateColumns as $column) {
				if (!array_key_exists($column, $combined) || trim((string) $combined[$column]) === '') {
					continue;
				}

				if (!isDateLike($combined[$column])) {
					$rowInvalidReason = 'invalid_date:' . $column;
					break;
				}
			}
		}

		if ($rowInvalidReason !== '') {
			$stats['rows_invalid']++;
			logInvalidRow($invalidHandle, $sourceFile, $rowNumber, $rowInvalidReason, $combined);
			continue;
		}

		$payload = [];
		foreach ($combined as $key => $value) {
			$payload[$key] = autocast((string) $value);
		}

		$eventId = buildEventId($sourceFile, $rowNumber, $payload);
		$payload['event_id'] = $eventId;
		$payload['source_file'] = $sourceFile;
		$payload['row_number'] = $rowNumber;
		$payload['ingested_at'] = gmdate('c');

		$buffer[] = [
			'topic' => $topic,
			'key' => $eventId,
			'value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
		];

		$stats['rows_valid']++;

		if (count($buffer) >= $batchSize) {
			if (sendWithRetry($producer, $buffer, $maxRetries, $baseBackoffMs)) {
				$stats['messages_sent'] += count($buffer);
			} else {
				foreach ($buffer as $message) {
					if (sendWithRetry($producer, [$message], $maxRetries, $baseBackoffMs)) {
						$stats['messages_sent']++;
					} else {
						$stats['messages_failed']++;
						logInvalidRow($invalidHandle, $sourceFile, $rowNumber, 'kafka_send_failed', ['key' => $message['key']]);
					}
				}
			}

			$buffer = [];
		}
	}

	fclose($handle);
}

if ($buffer !== []) {
	if (sendWithRetry($producer, $buffer, $maxRetries, $baseBackoffMs)) {
		$stats['messages_sent'] += count($buffer);
	} else {
		foreach ($buffer as $message) {
			if (sendWithRetry($producer, [$message], $maxRetries, $baseBackoffMs)) {
				$stats['messages_sent']++;
			} else {
				$stats['messages_failed']++;
				logInvalidRow($invalidHandle, 'final_flush', 0, 'kafka_send_failed', ['key' => $message['key']]);
			}
		}
	}
}

fclose($invalidHandle);

echo "[producer] completed\n";
echo "[producer] files={$stats['files']} rows_read={$stats['rows_read']} rows_valid={$stats['rows_valid']} rows_invalid={$stats['rows_invalid']} sent={$stats['messages_sent']} failed={$stats['messages_failed']}\n";
echo "[producer] invalid_log={$invalidLogPath}\n";
