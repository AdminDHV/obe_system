#!/usr/bin/env bash
# scripts/migrate.sh — Production Safe Migrations
set -eo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/scripts/lib/common.sh"
source "$ROOT/scripts/lib/docker.sh"

print_header "Running Database Migrations (Safe Mode)"
log_info "Executing artisan migrate --force..."

docker_exec_app php artisan migrate --force

log_info "Migrations successfully applied."
