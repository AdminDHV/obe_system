#!/usr/bin/env bash
# scripts/dev.sh — Unified Development Manager
set -eo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/scripts/lib/common.sh"
source "$ROOT/scripts/lib/docker.sh"

CMD="${1:-status}"
shift || true

case "$CMD" in
  setup)
    print_header "Setting up ObeSystem Development Environment"
    if [ ! -f "$ROOT/.env" ]; then
      cp "$ROOT/.env.example" "$ROOT/.env"
      log_info "Created .env file"
    fi
    docker_up
    print_section "Installing PHP Dependencies"
    docker_exec_app composer install
    print_section "Installing Node Dependencies"
    docker_run_vite npm install
    print_section "Generating App Key & Migrating"
    docker_exec_app php artisan key:generate
    docker_exec_app php artisan migrate:fresh --seed
    print_section "Building Frontend Assets"
    docker_run_vite npm run build
    log_info "Setup complete. App running at http://localhost:8888"
    ;;
  
  start) docker_up ;;
  stop) docker_down ;;
  restart)
    docker_down
    docker_up
    ;;
  status) docker_status ;;
  
  artisan|art) docker_exec_app php artisan "$@" ;;
  composer) docker_exec_app composer "$@" ;;
  npm) docker_run_vite npm "$@" ;;
  test) docker_exec_app php artisan test "$@" ;;
  logs) docker compose logs -f "$@" ;;

  
  *)
    log_err "Unknown command: $CMD. Use: setup|start|stop|restart|artisan|composer|npm|test|logs"
    ;;
esac
