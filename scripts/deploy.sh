#!/usr/bin/env bash
# scripts/deploy.sh — Deploy production (repo-ready). Có downtime ngắn khi restart.
# Xem DEPLOY.md để biết .env prod cần đặt + các bước thủ công (đổi mật khẩu).
set -eo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
source "$ROOT/scripts/lib/common.sh"
source "$ROOT/scripts/lib/docker.sh"

print_header "Deploying OBE System to Production"

log_info "1. Kéo code mới nhất (main)..."
git -C "$ROOT" pull origin main

log_info "2. Build image app/worker/scheduler..."
docker compose build app worker worker-measurements scheduler

log_info "3. Khởi động services (KHÔNG chạy 'vite' — vite chỉ dùng cho dev). worker-measurements chạy song song 3 process để 'Tính lại toàn khóa' nhanh hơn."
# worker-measurements PHẢI có trong danh sách service: khi liệt kê service tường minh,
# docker compose coi service không được liệt kê là "disabled" và --scale của nó báo lỗi
# "no such service: worker-measurements: disabled" (gặp thật với compose v5.3.0).
docker compose up -d --scale worker-measurements=3 app web db redis worker worker-measurements scheduler

log_info "4. Cài dependency PHP (production, bỏ dev)..."
docker_exec_app composer install --no-dev --optimize-autoloader --no-interaction

log_info "5. Build frontend assets (public/build)..."
rm -f "$ROOT/public/hot"   # tránh Blade trỏ vào vite dev server
docker_exec_app npm ci
docker_exec_app npm run build

log_info "6. Migrations..."
docker_exec_app php artisan migrate --force

log_info "7. Gán trường (tenant) cho bản ghi còn NULL..."
docker_exec_app php artisan obe:backfill-school

log_info "8. storage:link..."
docker_exec_app php artisan storage:link || true

log_info "9. Tối ưu cache Laravel..."
docker_exec_app php artisan config:clear
docker_exec_app php artisan config:cache
docker_exec_app php artisan route:cache
docker_exec_app php artisan view:cache

log_info "9b. Sửa quyền runtime cho php-fpm (www-data)..."
# Deploy chạy artisan với user root -> các dir storage/bootstrap/cache bị root sở hữu.
# php-fpm chạy www-data không ghi được -> BladeCompiler::replace() gọi tempnam() fallback
# vào /tmp -> PHP 8.4 cảnh báo -> 500. Chown về www-data để tempnam ghi vào
# storage/framework/views (không fallback /tmp).
docker_exec_app chown -R www-data:www-data storage bootstrap/cache
docker_exec_app chmod -R 755 storage/framework storage/logs bootstrap/cache

log_info "10. Restart queue worker (nạp code Job/Service mới)..."
docker_exec_app php artisan queue:restart

log_warn "THỦ CÔNG lần đầu (in mật khẩu 1 lần, LƯU NGAY):"
log_warn "   docker compose exec app php artisan obe:reset-passwords"
log_info "Deploy hoàn tất."
