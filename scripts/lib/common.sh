#!/usr/bin/env bash

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Logging functions
log() { echo -e "${CYAN}[APP]${NC} $1"; }
log_info() { echo -e "${GREEN}[INFO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_err() { echo -e "${RED}[ERROR]${NC} $1"; exit 1; }

# UI formatting
print_header() {
  echo ""
  echo -e "${BLUE}====================================================${NC}"
  echo -e "${BLUE}  $1${NC}"
  echo -e "${BLUE}====================================================${NC}"
  echo ""
}

print_section() {
  echo -e "${CYAN}--- $1 ---${NC}"
}
