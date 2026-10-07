# Hệ thống đo lường chuẩn đầu ra (OBE System)

Phần mềm đo mức đạt chuẩn đầu ra theo tiếp cận OBE và xuất báo cáo phục vụ kiểm định AUN-QA. Trường đưa
khung chương trình và điểm vào bằng file Excel; hệ thống tính mức đạt CLO → PI → PLO cho từng học viên
và cho cả khóa, rồi xuất báo cáo.

Tài liệu này dành cho bộ phận IT của trường: hệ thống gồm những gì, cài đặt và vận hành ra sao.

---

## 1. Hệ thống làm gì

**Ba loại file nhập:**

| File | Ai điền | Bao lâu một lần |
|---|---|---|
| Khai báo chương trình — ngành, khóa, PLO/PI kèm trọng số, môn học, ma trận chuẩn đầu ra các học phần, học viên | Phòng đảm bảo chất lượng | Mỗi khóa một lần |
| Khung học phần theo đợt — CLO và ma trận CLO → PLO/PI của từng môn, bài đánh giá kèm trọng số, lớp học phần kèm giảng viên | Kỹ thuật của trường | Mỗi khóa, mỗi năm học |
| Phiếu lớp học phần — câu hỏi từng bài và điểm từng câu của từng học viên | Giảng viên | Mỗi lớp mỗi lần có điểm |

File mẫu tải ở menu **Bộ file mẫu** hoặc trong **Trung tâm đo lường**. Nhập file nào hệ thống cũng kiểm
toàn bộ trước khi ghi: có một lỗi là không ghi gì và báo rõ sheet, ô sai.

**Kết quả ra:** mức đạt từng PLO/PI của khóa (bảng, biểu đồ, Excel), báo cáo AUN-QA (CSV), báo cáo học phần
theo mẫu của trường (Word), bảng điểm điền vào file của phần mềm quản lý đào tạo, cổng tra cứu kết quả cho học
viên (`/ket-qua-hoc-tap`, không cần tài khoản).

**Vai trò người dùng:**

| Vai trò | Làm được gì |
|---|---|
| `super_admin` — quản trị hệ thống | Mọi việc: khai báo, nhập điểm, tham số đo, báo cáo, tài khoản |
| `lecturer` — giảng viên | Xem lớp mình dạy, tải và nộp phiếu điểm của lớp đó |
| `viewer` — người xem | Xem số liệu và báo cáo toàn trường, không sửa gì |

Hệ thống phục vụ được nhiều trường trên một bản cài: dữ liệu tách theo trường (`school_id`), quản trị viên chọn
**Trường đang làm việc** ở đầu trang.

---

## 2. Công nghệ

| Thành phần | Phiên bản |
|---|---|
| Backend | Laravel 13, PHP 8.4 (php-fpm) |
| Frontend | React 18 + Inertia.js, Vite, TailwindCSS, shadcn/ui |
| Cơ sở dữ liệu | PostgreSQL 16 |
| Hàng đợi, cache | Redis |
| Đọc / ghi Excel, Word | PhpSpreadsheet, Maatwebsite Excel, PhpWord |
| Triển khai | Docker Compose |

Các service trong `docker-compose.yml`:

| Service | Việc |
|---|---|
| `app` | PHP-FPM chạy Laravel |
| `web` | Nginx, mở cổng `8888` |
| `db` | PostgreSQL (dữ liệu ở volume `db-data`) |
| `redis` | Hàng đợi, cache |
| `worker` | `queue:work` — nhập file, tạo báo cáo |
| `worker-measurements` | `queue:work --queue=measurements` — tính lại kết quả đo; production chạy 3 bản |
| `scheduler` | `schedule:work` — dọn nhật ký, file xuất cũ hằng ngày |
| `vite` | Máy chủ dev của frontend — **chỉ dùng khi phát triển, không chạy ở production** |

---

## 3. Cấu trúc mã nguồn

```
app/
  Http/Controllers/Manager/   Controller cho các màn hình (mỏng, gọi Service)
  Services/OBE/               Toàn bộ logic đo lường: nhập phiếu, khung, tính CLO/PI/PLO, báo cáo
  Services/Import/            Đọc file Excel nhập vào
  Services/Export/            Xuất file (bảng điểm cho phần mềm đào tạo…)
  Exports/                    File mẫu Excel và báo cáo xuất ra
  Jobs/                       Việc chạy nền (nhập file, tính lại, tạo báo cáo)
  Models/                     Eloquent model
  Console/Commands/           Lệnh artisan vận hành (mục 6)
  Support/                    Trường đang làm việc, phạm vi giảng viên…
database/migrations/          Cấu trúc CSDL
resources/js/Pages/           Các màn hình React (Inertia)
resources/js/config/          Menu, nội dung hướng dẫn trong app, danh sách file mẫu
routes/web.php                Toàn bộ route, chia nhóm theo vai trò
docker/                       Dockerfile PHP, cấu hình nginx
scripts/                      dev.sh (môi trường dev), deploy.sh (production)
tests/                        PHPUnit (Feature, Unit)
```

Công thức đo nằm ở `app/Services/OBE/` — điểm vào: `CloCalculationService`, `CoursePiScorer`,
`Methods/WeightedAttainmentMethod`, tham số ở `ObeConfig`. Mỗi file có chú thích nguyên tắc tính ở đầu.

---

## 4. Cài đặt môi trường phát triển

Yêu cầu: Docker Desktop (hoặc Docker Engine + Compose v2), Git. **Không cần** cài PHP, Node hay PostgreSQL
trên máy — mọi lệnh chạy trong container qua `scripts/dev.sh`.

```bash
git clone <địa chỉ repo này> obe-system
cd obe-system
bash scripts/dev.sh setup
```

`setup` sẽ: tạo `.env` từ `.env.example` → bật container → `composer install` → `npm install` →
tạo `APP_KEY` → `migrate:fresh --seed` → build frontend. Xong mở **http://localhost:8888**.

Tài khoản quản trị tạo sẵn khi seed (chỉ dùng ở máy dev): `admin@dhv.edu.vn`, mật khẩu là giá trị
`SEED_DEFAULT_PASSWORD` trong `.env` (mặc định `password`).

Muốn có dữ liệu để thử ngay: đăng nhập, vào **Trung tâm đo lường → Bước 1 → Nạp dữ liệu mẫu**, hoặc chạy
`bash scripts/dev.sh artisan obe:demo`. Dữ liệu mẫu nằm ở các trường mang nhãn `[DEMO]`, xóa được bằng
`obe:demo --clear`.

Lệnh hằng ngày:

```bash
bash scripts/dev.sh start | stop | restart | status
bash scripts/dev.sh logs app                 # xem log một service
bash scripts/dev.sh artisan <lệnh>           # vd: migrate, tinker, route:list
bash scripts/dev.sh composer <lệnh>
bash scripts/dev.sh npm <lệnh>               # vd: npm run build
bash scripts/dev.sh test                     # chạy toàn bộ test (PHPUnit, SQLite trong bộ nhớ)
bash scripts/dev.sh test --filter=TenTest
```

Khi dev, service `vite` phục vụ frontend có hot-reload (tạo file `public/hot`).

---

## 5. Triển khai production

### 5.1. Lần đầu

1. Máy chủ Linux có Docker Engine + Compose v2. Clone repo vào thư mục triển khai.
2. `cp .env.example .env`, đặt giá trị production:

   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://ten-mien-cua-truong
   APP_KEY=                     # sinh mới bằng: php artisan key:generate
   DB_PASSWORD=<mật khẩu mạnh>  # cũng là mật khẩu PostgreSQL của service db
   SESSION_SECURE_COOKIE=true   # khi chạy HTTPS
   LOG_LEVEL=warning
   TRUSTED_PROXIES=*            # khi đặt sau reverse proxy
   MAIL_MAILER=smtp             # và MAIL_HOST/PORT/USERNAME/PASSWORD thật — dùng cho quên mật khẩu
   ```

3. Chạy `bash scripts/deploy.sh` (mục 5.2).
4. Tạo tài khoản quản trị đầu tiên — **không chạy `db:seed` ở production** (seeder tạo tài khoản mật khẩu yếu).
   Dùng `php artisan tinker` trong container `app` để tạo một user rồi gán vai trò `super_admin`, hoặc nếu đã
   có sẵn tài khoản thì chạy `obe:reset-passwords` để đặt mật khẩu mạnh ngẫu nhiên (in ra một lần duy nhất,
   lưu ngay).
5. Đặt reverse proxy (nginx, Caddy…) có HTTPS trỏ vào cổng `8888`.

### 5.2. Mỗi lần cập nhật

```bash
bash scripts/deploy.sh
```

Script lần lượt: `git pull origin main` → build image → bật service (không có `vite`, `worker-measurements`
3 bản) → `composer install --no-dev` → `npm ci && npm run build` → `migrate --force` → `obe:backfill-school`
→ `storage:link` → cache config/route/view → sửa quyền thư mục → `queue:restart`. Mất khoảng 5–7 phút.

> ⚠️ **Đừng ngắt deploy giữa chừng.** Thư mục mã nguồn được gắn thẳng vào container, nên ngay sau
> `git pull` mã mới đã chạy. Ngắt trước bước `migrate` thì mã mới chạy trên CSDL cũ và nhiều trang sẽ lỗi 500.
> Nên chạy tách khỏi phiên SSH và ghi log:
>
> ```bash
> nohup bash scripts/deploy.sh > deploy_$(date +%Y%m%d_%H%M).log 2>&1 &
> tail -f deploy_*.log
> ```
>
> Lỡ bị ngắt: kiểm `docker compose exec -T app php artisan migrate:status`; còn migration `Pending` thì chạy
> `migrate --force` trước rồi deploy lại.

### 5.3. Lưu ý vận hành

- Phải có `worker`, `worker-measurements` và `scheduler` cùng chạy (`docker compose ps`). Thiếu worker thì nhập
  file xong mà kết quả không cập nhật.
- Worker giữ mã đã nạp trong bộ nhớ. Sửa mã Job/Service mà không qua `deploy.sh` thì phải
  `docker compose restart worker worker-measurements`.
- Không để service `vite` chạy và không để tồn tại file `public/hot` ở production — có nó thì trang nạp
  giao diện từ máy chủ dev và hiện trắng.
- Kiểm tra sống: `GET /up`.
- `/admin` (Filament) và `/horizon` là công cụ kiểm tra nội bộ, chỉ `super_admin` vào được; nên chặn thêm
  theo IP ở reverse proxy.
- Cổng PostgreSQL chỉ mở ở `127.0.0.1`; không mở ra ngoài.
- Sao lưu: volume `db-data` (PostgreSQL) và `storage/app` (file người dùng tải lên, file xuất).

Lệnh trên server gọi trực tiếp: `docker compose exec -T app php artisan <lệnh>`.

---

## 6. Lệnh artisan vận hành

| Lệnh | Việc |
|---|---|
| `obe:recalculate {--school=} {--program=} {--cohort=}` | Tính lại CLO/PI/PLO (sau khi đổi ngưỡng, hệ số; giao diện có nút "Tính lại toàn trường") |
| `obe:verify-measurements {mã trường…}` | Đối chiếu số đo đã lưu với phép tính lại độc lập (chỉ đọc) |
| `obe:data-health {--fix}` | Tìm dữ liệu cần dọn: dòng trùng, rò chéo trường, thiếu ghi danh |
| `obe:lookup-codes --school= {--program=} {--cohort=}` | Cấp mã tra cứu cho học viên, xuất CSV |
| `obe:reset-passwords {--length=18}` | Đặt mật khẩu mạnh ngẫu nhiên cho tài khoản người thật, in ra một lần |
| `obe:demo {--clear}` | Nạp / xóa dữ liệu mẫu `[DEMO]` |
| `obe:purge-school {mã…} {--dry-run}` | Xóa hẳn dữ liệu một trường thử nghiệm (nên chạy `--dry-run` trước) |
| `obe:backfill-school {--dry-run}` | Gán trường cho bản ghi còn thiếu (deploy tự chạy) |

---

## 7. Kiểm thử

`bash scripts/dev.sh test` chạy toàn bộ PHPUnit trên SQLite trong bộ nhớ, hàng đợi chạy đồng bộ — không đụng
CSDL dev. Các test trong `tests/Feature/Obe/` đối chiếu kết quả đo với số tính tay; sửa công thức thì các test
đó phải còn xanh.
