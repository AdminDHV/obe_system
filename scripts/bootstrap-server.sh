#!/usr/bin/env bash
# scripts/bootstrap-server.sh — Chuẩn bị một máy Ubuntu 22.04/24.04 trắng để chạy OBE System bằng Docker.
# Chạy MỘT lần trên server, bằng user có quyền sudo (cần nhập mật khẩu sudo):
#   sudo bash bootstrap-server.sh [deploy_user] [app_dir]
# Mặc định deploy_user = user gọi sudo, app_dir = /var/www/appdhv.
# Chạy lại nhiều lần vẫn an toàn (idempotent).
set -euo pipefail

DEPLOY_USER="${1:-${SUDO_USER:-}}"
APP_DIR="${2:-/var/www/appdhv}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Cần chạy bằng sudo: sudo bash $0" >&2
  exit 1
fi
if [ -z "$DEPLOY_USER" ] || ! id "$DEPLOY_USER" >/dev/null 2>&1; then
  echo "Không xác định được user deploy. Truyền vào: sudo bash $0 <user>" >&2
  exit 1
fi

echo "==> 1. Gói nền (git, curl, ca-certificates)"
apt-get update -y
apt-get install -y ca-certificates curl gnupg git

echo "==> 2. Docker Engine + Compose plugin (repo chính thức của Docker)"
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  . /etc/os-release
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -y
  apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi

echo "==> 3. Giới hạn log container (tránh đầy ổ)"
if [ ! -f /etc/docker/daemon.json ]; then
  cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "20m", "max-file": "5" }
}
JSON
fi
systemctl enable --now docker
systemctl restart docker

echo "==> 4. Cho $DEPLOY_USER dùng docker không cần sudo"
usermod -aG docker "$DEPLOY_USER"

echo "==> 5. Thư mục mã nguồn $APP_DIR (chủ sở hữu: $DEPLOY_USER)"
mkdir -p "$APP_DIR"
chown "$DEPLOY_USER":"$DEPLOY_USER" "$APP_DIR"

echo
docker --version
docker compose version
echo "Xong. $DEPLOY_USER cần đăng xuất rồi SSH lại để nhóm docker có hiệu lực."
