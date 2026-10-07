/**
 * Nhãn tiếng Việt của vai trò — MỘT chỗ cho cả console.
 *
 * Trước đây bảng nhãn nằm khoá trong `Pages/Manager/Users/Index.jsx`, nên chỗ nào khác muốn hiện
 * vai trò thì phải chép lại, rồi thêm vai trò mới là hai bên nói khác nhau.
 */
export const ROLE_LABELS = {
  super_admin: 'Quản trị hệ thống',
  lecturer: 'Giảng viên',
  student: 'Sinh viên',
  viewer: 'Người xem (Cố vấn học tập)',
};

export function roleLabel(role) {
  return ROLE_LABELS[role] ?? role;
}

/** Gộp nhiều vai trò thành một dòng đọc được. */
export function roleLabels(roles = []) {
  return (roles ?? []).map(roleLabel).join(' · ');
}
