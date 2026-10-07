// Nhãn + màu badge cho các cột "Trạng thái" — dùng chung để không hiển thị key thô.
// Giá trị chuẩn lấy theo DB/migration + Filament (nguồn quản trị).

const MUTED = 'bg-muted text-muted-foreground';

// Sinh viên — students.status (DB default: 'studying')
const STUDENT_STATUS = {
  studying: { label: 'Đang học', className: 'bg-emerald-50 text-emerald-700' },
  suspended: { label: 'Bảo lưu', className: 'bg-amber-50 text-amber-700' },
  graduated: { label: 'Đã tốt nghiệp', className: 'bg-blue-50 text-blue-700' },
  dropped_out: { label: 'Đã thôi học', className: 'bg-rose-50 text-rose-700' },
  // alias dữ liệu cũ
  active: { label: 'Đang học', className: 'bg-emerald-50 text-emerald-700' },
  dropped: { label: 'Đã thôi học', className: 'bg-rose-50 text-rose-700' },
};

// Giảng viên — users.employment_status (DB default: 'full_time')
const LECTURER_STATUS = {
  full_time: { label: 'Cơ hữu', className: 'bg-emerald-50 text-emerald-700' },
  part_time: { label: 'Thỉnh giảng', className: 'bg-blue-50 text-blue-700' },
  inactive: { label: 'Ngừng công tác', className: 'bg-rose-50 text-rose-700' },
  // alias dữ liệu cũ
  active: { label: 'Đang công tác', className: 'bg-emerald-50 text-emerald-700' },
  probation: { label: 'Thử việc', className: 'bg-amber-50 text-amber-700' },
  resigned: { label: 'Đã nghỉ', className: 'bg-rose-50 text-rose-700' },
};

const fallback = (s) => ({ label: s ?? '—', className: MUTED });

export const studentStatusMeta = (s) => STUDENT_STATUS[s] ?? fallback(s);
export const lecturerStatusMeta = (s) => LECTURER_STATUS[s] ?? fallback(s);

// Option chuẩn cho <SearchableSelect> (form + filter)
export const STUDENT_STATUS_OPTIONS = [
  { value: 'studying', label: 'Đang học' },
  { value: 'suspended', label: 'Bảo lưu' },
  { value: 'graduated', label: 'Đã tốt nghiệp' },
  { value: 'dropped_out', label: 'Đã thôi học' },
];

export const LECTURER_STATUS_OPTIONS = [
  { value: 'full_time', label: 'Cơ hữu' },
  { value: 'part_time', label: 'Thỉnh giảng' },
  { value: 'inactive', label: 'Ngừng công tác' },
];
