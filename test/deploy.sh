#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/home/btcfoxman/docker/geoflow}"
APP_USER="${APP_USER:-btcfoxman}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="${APP_DIR}/docker-compose.yml"

log() {
  printf '[geoflow-deploy] %s\n' "$*"
}

retry() {
  local attempts="$1"
  local delay="$2"
  shift 2
  local i
  for i in $(seq 1 "${attempts}"); do
    if "$@"; then
      return 0
    fi
    if [ "${i}" -eq "${attempts}" ]; then
      return 1
    fi
    log "Command failed, retrying in ${delay}s (${i}/${attempts}): $*"
    sleep "${delay}"
  done
}

required_env_keys=(
  APP_KEY
  APP_URL
  GEOFLOW_ADMIN_USERNAME
  GEOFLOW_ADMIN_PASSWORD
  DB_HOST
  DB_DATABASE
  DB_USERNAME
  DB_PASSWORD
  REDIS_HOST
  REVERB_APP_ID
  REVERB_APP_KEY
  REVERB_APP_SECRET
)

read_env_value() {
  local key="$1"
  awk -v key="${key}" '
    index($0, key "=") == 1 {
      sub(/^[^=]*=/, "")
      print
      exit
    }
  ' "${APP_DIR}/.env"
}

validate_env() {
  local key value
  for key in "${required_env_keys[@]}"; do
    value="$(read_env_value "${key}")"
    if [ -z "${value}" ]; then
      log "Required setting ${key} is missing or empty in ${APP_DIR}/.env"
      return 1
    fi
    case "${value}" in
      change-me*|CHANGE_ME*|example*|EXAMPLE*)
        log "Required setting ${key} still contains a placeholder"
        return 1
        ;;
    esac
  done

  value="$(read_env_value APP_KEY)"
  if [[ "${value}" != base64:* ]]; then
    log "APP_KEY must use Laravel's base64: format"
    return 1
  fi
}

mkdir -p "${APP_DIR}/storage"

log "Syncing deployment compose to ${COMPOSE_FILE}"
cp -f "${SCRIPT_DIR}/docker-compose.yml" "${COMPOSE_FILE}"

if [ ! -f "${APP_DIR}/.env" ]; then
  cp -f "${SCRIPT_DIR}/.env.example" "${APP_DIR}/.env"
  chmod 600 "${APP_DIR}/.env"
  log "Created ${APP_DIR}/.env from example. Fill secrets before deploying."
  exit 1
fi

chmod 600 "${APP_DIR}/.env"
validate_env

if id "${APP_USER}" >/dev/null 2>&1; then
  chown -R "${APP_USER}:${APP_USER}" "${APP_DIR}" || true
fi

network_name="$(read_env_value SHARED_NETWORK_NAME)"
network_name="${network_name:-my-shared-net}"
if ! docker network inspect "${network_name}" >/dev/null 2>&1; then
  log "Required external Docker network ${network_name} does not exist"
  exit 1
fi

if [ -n "${GHCR_TOKEN:-}" ]; then
  log "Logging in to GHCR"
  docker_login_ghcr() {
    printf '%s' "${GHCR_TOKEN}" | docker login ghcr.io -u "${GHCR_USERNAME:-${GITHUB_ACTOR:-btcfoxman}}" --password-stdin >/dev/null
  }
  retry 5 10 docker_login_ghcr
fi

cd "${APP_DIR}"
export IMAGE_REGISTRY="${IMAGE_REGISTRY:-ghcr.io}"
export IMAGE_NAMESPACE="${IMAGE_NAMESPACE:-btcfoxman}"
export IMAGE_TAG="${IMAGE_TAG:-test-latest}"

log "Validating compose config"
docker compose config >/dev/null

log "Pulling application and database images"
retry 8 15 docker compose pull geoflow-db app web

log "Running database migrations and one-time install"
docker compose run --rm init

log "Starting services"
docker compose up -d --remove-orphans app web queue scheduler reverb

log "Waiting for application health"
web_port="$(read_env_value WEB_PORT)"
web_port="${web_port:-18080}"
for _ in $(seq 1 40); do
  if curl -fsS "http://127.0.0.1:${web_port}/up" >/dev/null 2>&1; then
    docker compose exec -T app php artisan migrate:status --no-ansi >/dev/null
    auth_probe_status="$(curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1:${web_port}/api/v1/integrations/content-jobs/00000000-0000-4000-8000-000000000001")"
    if [ "${auth_probe_status}" != "401" ]; then
      log "Integration API unauthenticated probe returned ${auth_probe_status}, expected 401"
      exit 1
    fi
    docker compose ps
    log "Deployment complete"
    exit 0
  fi
  sleep 5
done

log "Health check failed"
docker compose ps || true
docker compose logs --tail=200 app web queue scheduler reverb || true
exit 1
