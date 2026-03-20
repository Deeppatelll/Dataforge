#!/usr/bin/env bash
set -euo pipefail

BROKER="kafka:29092"
TOPIC="report_data_topic"
DLQ_TOPIC="report_data_topic_dlq"
MAIN_PARTITIONS="${MAIN_PARTITIONS:-4}"
DLQ_PARTITIONS="${DLQ_PARTITIONS:-1}"
SOLR_ADMIN_URL="http://solr:8983/solr/admin/cores"
SOLR_CORE="reportcore"
SOLR_DLQ_CORE="reportcore_dlq"
SOLR_SCHEMA_URL="http://solr:8983/solr/${SOLR_CORE}/schema"
SOLR_DLQ_SCHEMA_URL="http://solr:8983/solr/${SOLR_DLQ_CORE}/schema"
ACTIVE_SCHEMA_URL="${SOLR_SCHEMA_URL}"

wait_for_kafka() {
  echo "[bootstrap] waiting for Kafka..."
  cub kafka-ready -b "${BROKER}" 1 120
}

wait_for_solr() {
  echo "[bootstrap] waiting for Solr..."
  for i in $(seq 1 90); do
    if curl -fsS "${SOLR_ADMIN_URL}?action=STATUS&core=${SOLR_CORE}" >/dev/null 2>&1; then
      echo "[bootstrap] Solr is reachable"
      return 0
    fi
    sleep 2
  done
  echo "[bootstrap] Solr did not become ready in time"
  exit 1
}

create_topic_if_missing() {
  local topic="$1"
  local partitions="$2"
  kafka-topics --bootstrap-server "${BROKER}" --list | grep -x "${topic}" >/dev/null 2>&1 && return 0
  kafka-topics --bootstrap-server "${BROKER}" --create --if-not-exists --topic "${topic}" --partitions "${partitions}" --replication-factor 1
}

enforce_partitions() {
  local existing
  existing=$(kafka-topics --bootstrap-server "${BROKER}" --describe --topic "${TOPIC}" | head -n 1 | sed -n 's/.*PartitionCount:[[:space:]]*\([0-9][0-9]*\).*/\1/p')
  if [ -z "${existing}" ]; then
    echo "[bootstrap] could not determine partition count for ${TOPIC}"
    exit 1
  fi

  if [ "${existing}" -lt "${MAIN_PARTITIONS}" ]; then
    echo "[bootstrap] increasing partitions for ${TOPIC} from ${existing} to ${MAIN_PARTITIONS}"
    kafka-topics --bootstrap-server "${BROKER}" --alter --topic "${TOPIC}" --partitions "${MAIN_PARTITIONS}"
  else
    echo "[bootstrap] partition count is already ${existing}"
  fi
}

verify_solr_core() {
  local core="$1"

  if curl -fsS "${SOLR_ADMIN_URL}?action=STATUS&core=${core}" | grep -q "\"name\":\"${core}\""; then
    echo "[bootstrap] Solr core ${core} is present"
    return 0
  fi

  echo "[bootstrap] Solr core ${core} missing; attempting create"
  curl -fsS "${SOLR_ADMIN_URL}?action=CREATE&name=${core}&configSet=_default&configSetBaseDir=/opt/solr/server/solr/configsets" >/dev/null
}

schema_contains_name() {
  local endpoint="$1"
  local name="$2"
  curl -fsS "${endpoint}" | tr -d '[:space:]' | grep -Fq "\"name\":\"${name}\""
}

ensure_field() {
  local name="$1"
  local type="$2"
  local indexed="$3"
  local stored="$4"
  local multi_valued="$5"
  local doc_values="$6"
  local required="${7:-false}"

  if schema_contains_name "${ACTIVE_SCHEMA_URL}/fields" "${name}"; then
    echo "[bootstrap] field ${name} already present"
    return 0
  fi

  echo "[bootstrap] adding field ${name}"
  curl -fsS -X POST -H "Content-Type: application/json" \
    "${ACTIVE_SCHEMA_URL}" \
    -d "{\"add-field\":{\"name\":\"${name}\",\"type\":\"${type}\",\"indexed\":${indexed},\"stored\":${stored},\"multiValued\":${multi_valued},\"docValues\":${doc_values},\"required\":${required}}}" >/dev/null
}

ensure_dynamic_field() {
  local name="$1"
  local type="$2"
  local indexed="$3"
  local stored="$4"
  local multi_valued="$5"
  local doc_values="$6"

  if schema_contains_name "${ACTIVE_SCHEMA_URL}/dynamicfields" "${name}"; then
    echo "[bootstrap] dynamic field ${name} already present"
    return 0
  fi

  echo "[bootstrap] adding dynamic field ${name}"
  curl -fsS -X POST -H "Content-Type: application/json" \
    "${ACTIVE_SCHEMA_URL}" \
    -d "{\"add-dynamic-field\":{\"name\":\"${name}\",\"type\":\"${type}\",\"indexed\":${indexed},\"stored\":${stored},\"multiValued\":${multi_valued},\"docValues\":${doc_values}}}" >/dev/null
}

replace_field() {
  local name="$1"
  local type="$2"
  local indexed="$3"
  local stored="$4"
  local multi_valued="$5"
  local doc_values="$6"
  local required="${7:-false}"

  if ! schema_contains_name "${ACTIVE_SCHEMA_URL}/fields" "${name}"; then
    return 0
  fi

  echo "[bootstrap] replacing field ${name} to enforce schema shape"
  curl -fsS -X POST -H "Content-Type: application/json" \
    "${ACTIVE_SCHEMA_URL}" \
    -d "{\"replace-field\":{\"name\":\"${name}\",\"type\":\"${type}\",\"indexed\":${indexed},\"stored\":${stored},\"multiValued\":${multi_valued},\"docValues\":${doc_values},\"required\":${required}}}" >/dev/null
}

ensure_copy_field() {
  local source="$1"
  local dest="$2"

  if ! schema_contains_name "${ACTIVE_SCHEMA_URL}/fields" "${dest}"; then
    echo "[bootstrap] skipping copyField ${source} -> ${dest} because destination field is missing"
    return 0
  fi

  if curl -fsS "${ACTIVE_SCHEMA_URL}/copyfields" | tr -d '[:space:]' | grep -Fq "\"source\":\"${source}\""; then
    echo "[bootstrap] copyField ${source} -> ${dest} already present"
    return 0
  fi

  echo "[bootstrap] adding copyField ${source} -> ${dest}"
  curl -fsS -X POST -H "Content-Type: application/json" \
    "${ACTIVE_SCHEMA_URL}" \
    -d "{\"add-copy-field\":{\"source\":\"${source}\",\"dest\":\"${dest}\"}}" >/dev/null
}

apply_schema() {
  local schema_url="$1"

  # Core reporting fields used by filtering, faceting, sorting and comparison.
  ACTIVE_SCHEMA_URL="${schema_url}"

  ensure_field "event_id_s" "string" true true false true true
  ensure_field "source_file_s" "string" true true false true
  ensure_field "row_num_i" "pint" true true false true
  ensure_field "category_s" "string" true true false true
  ensure_field "status_s" "string" true true false true
  ensure_field "name_s" "string" true true false true
  ensure_field "description_txt" "text_general" true true false false
  ensure_field "search_text_txt" "text_general" true true true false
  replace_field "search_text_txt" "text_general" true true true false false
  ensure_field "amount_f" "pfloat" true true false true
  ensure_field "quantity_i" "pint" true true false true
  ensure_field "is_active_b" "boolean" true true false true
  ensure_field "event_date_dt" "pdate" true true false true
  ensure_field "ingested_at_dt" "pdate" true true false true
  ensure_field "error_reason_s" "string" true true false true
  ensure_field "failed_at_dt" "pdate" true true false true

  ensure_dynamic_field "*_s" "string" true true false true
  ensure_dynamic_field "*_txt" "text_general" true true false false
  ensure_dynamic_field "*_i" "pint" true true false true
  ensure_dynamic_field "*_f" "pfloat" true true false true
  ensure_dynamic_field "*_b" "boolean" true true false true
  ensure_dynamic_field "*_dt" "pdate" true true false true

  ensure_copy_field "name_s" "search_text_txt"
  ensure_copy_field "category_s" "search_text_txt"
  ensure_copy_field "status_s" "search_text_txt"
  ensure_copy_field "description_txt" "search_text_txt"
  ensure_copy_field "description_txt" "text"
}

wait_for_kafka
wait_for_solr
create_topic_if_missing "${TOPIC}" "${MAIN_PARTITIONS}"
create_topic_if_missing "${DLQ_TOPIC}" "${DLQ_PARTITIONS}"
enforce_partitions
verify_solr_core "${SOLR_CORE}"
verify_solr_core "${SOLR_DLQ_CORE}"
apply_schema "${SOLR_SCHEMA_URL}"
apply_schema "${SOLR_DLQ_SCHEMA_URL}"

kafka-topics --bootstrap-server "${BROKER}" --describe --topic "${TOPIC}"
kafka-topics --bootstrap-server "${BROKER}" --describe --topic "${DLQ_TOPIC}"
curl -fsS "${SOLR_SCHEMA_URL}/fields" | grep -E 'event_id_s|amount_f|event_date_dt' >/dev/null
curl -fsS "${SOLR_DLQ_SCHEMA_URL}/fields" | grep -E 'event_id_s|error_reason_s|failed_at_dt' >/dev/null

echo "[bootstrap] completed"
