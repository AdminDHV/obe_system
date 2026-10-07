// "Trường đang làm việc" (tenant context) bền vững qua các trang.
// Lưu lựa chọn Trường để Dashboard ↔ hub Đo lường (Reports) dùng chung,
// giúp người vận hành không phải chọn lại trường mỗi lần điều hướng.

const KEY = 'obe.working_school';

/** Lấy id Trường đang làm việc (chuỗi rỗng nếu chưa có). */
export function getWorkingSchool() {
  try {
    return localStorage.getItem(KEY) || '';
  } catch {
    return '';
  }
}

/** Lưu id Trường đang làm việc (truyền rỗng/null để xoá). */
export function setWorkingSchool(id) {
  try {
    if (id === null || id === undefined || id === '') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, String(id));
  } catch {
    /* localStorage không khả dụng (private mode) — bỏ qua, vẫn chạy bằng URL/props. */
  }
}

// Cookie để SERVER đọc "Trường đang làm việc" (mọi trang tự scope, không cần luồn ?school_id).
const COOKIE = 'obe_working_school';

/** Ghi cookie trường đang làm việc (đồng bộ với localStorage cho luồng cũ). */
export function setWorkingSchoolCookie(id) {
  try {
    const v = id === null || id === undefined ? '' : String(id);
    const maxAge = 60 * 60 * 24 * 365; // 1 năm
    document.cookie = `${COOKIE}=${encodeURIComponent(v)}; path=/; max-age=${maxAge}; SameSite=Lax`;
  } catch {
    /* không ghi được cookie — bỏ qua */
  }
}

/**
 * Chọn id Trường hợp lệ đầu tiên trong danh sách ứng viên, đối chiếu với `schools`.
 * Dùng để khởi tạo: ưu tiên URL → localStorage → trường đầu tiên.
 */
export function resolveSchoolId(candidates, schools = []) {
  const valid = (candidates || [])
    .filter((c) => c !== null && c !== undefined && c !== '')
    .map(String)
    .find((c) => schools.some((s) => String(s.id) === c));
  if (valid) return valid;
  return schools[0] ? String(schools[0].id) : '';
}
