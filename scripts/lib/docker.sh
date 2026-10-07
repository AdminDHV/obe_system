#!/usr/bin/env bash

docker_up() {
  log_info "Starting Docker containers..."
  docker compose up -d
}

docker_down() {
  log_info "Stopping Docker containers..."
  docker compose down
}

# Map service name to display URL
_get_service_url() {
  case "$1" in
    web)    echo "http://localhost:8888" ;;
    app)    echo "(via nginx)" ;;
    vite)   echo "http://localhost:5173" ;;
    db)     echo "localhost:5432" ;;
    redis)  echo "localhost:6379" ;;
    worker) echo "(background)" ;;
    *)      echo "-" ;;
  esac
}

# Map service name to short label
_get_service_label() {
  case "$1" in
    web)    echo "web (nginx)" ;;
    app)    echo "app (PHP)" ;;
    db)     echo "db (pg)" ;;
    *)      echo "$1" ;;
  esac
}

# Return colored status string
_get_status_color() {
  local status="$1"
  case "$status" in
    running)  echo -e "${GREEN}● running${NC}" ;;
    exited)   echo -e "${RED}● exited${NC}" ;;
    stopped)  echo -e "${RED}● stopped${NC}" ;;
    restarting) echo -e "${YELLOW}● restarting${NC}" ;;
    created)  echo -e "${CYAN}● created${NC}" ;;
    paused)   echo -e "${YELLOW}● paused${NC}" ;;
    *)        echo -e "● $status" ;;
  esac
}

docker_status() {
  print_header "OBE System — Service Status"

  # Table header
  printf "  ${CYAN}%-16s  %-20s  %-14s  %s${NC}\n" \
    "Service" "Container" "Status" "URL / Access"
  printf "  %-16s  %-20s  %-14s  %s\n" \
    "----------------" "--------------------" "--------------" "--------------------"

  # Parse docker compose ps --format json (one object per line)
  local services_data
  services_data=$(docker compose ps --format json 2>/dev/null || docker compose ps --format "{{.Name}}\t{{.Service}}\t{{.State}}" 2>/dev/null)

  if [ -z "$services_data" ]; then
    # Fallback: plain docker compose ps
    printf "  ${YELLOW}%-16s${NC}\n" "(no containers running or docker not available)"
    echo ""
    return
  fi

  # Try JSON format first (Docker Compose v2+)
  if echo "$services_data" | head -1 | grep -q '"'; then
    while IFS= read -r line; do
      local name service state
      name=$(echo "$line" | grep -o '"Name":"[^"]*"' | cut -d'"' -f4)
      service=$(echo "$line" | grep -o '"Service":"[^"]*"' | cut -d'"' -f4)
      state=$(echo "$line" | grep -o '"State":"[^"]*"' | cut -d'"' -f4)

      local label=$(_get_service_label "$service")
      local url=$(_get_service_url "$service")
      local colored_status=$(_get_status_color "$state")

      # Truncate container name if too long
      if [ ${#name} -gt 20 ]; then
        name="${name:0:17}..."
      fi

      printf "  %-16s  %-20s  %-24b  %s\n" \
        "$label" "$name" "$colored_status" "$url"
    done <<< "$services_data"
  else
    # Fallback: tab-separated format
    while IFS=$'\t' read -r name service state rest; do
      local label=$(_get_service_label "$service")
      local url=$(_get_service_url "$service")
      local colored_status=$(_get_status_color "$state")

      if [ ${#name} -gt 20 ]; then
        name="${name:0:17}..."
      fi

      printf "  %-16s  %-20s  %-24b  %s\n" \
        "$label" "$name" "$colored_status" "$url"
    done <<< "$services_data"
  fi

  # Summary footer
  echo ""
  echo -e "  ${GREEN}App URL:${NC}   http://localhost:8888"
  echo -e "  ${CYAN}Vite HMR:${NC}  http://localhost:5173"
  echo ""
}

docker_exec_app() {
  docker compose exec app "$@"
}

docker_exec_vite() {
  docker compose exec vite "$@"
}

# One-off vite container (for npm install/build). The long-running `vite` service
# command is `npm run dev`, which exits immediately when node_modules is absent —
# so `exec` has nothing to attach to on a fresh checkout. `run --rm` spins up a
# throwaway container that writes node_modules/build into the mounted volume.
docker_run_vite() {
  docker compose run --rm --no-deps vite "$@"
}
