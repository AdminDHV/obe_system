/**
 * Quyền phía giao diện — CHỈ để ẩn/hiện, KHÔNG phải hàng rào bảo mật.
 *
 * Hàng rào thật nằm ở routes/web.php + App\Support\LecturerScope. Chỗ này tồn tại vì lý do
 * khác: hiện một nút mà bấm vào bị 403 còn tệ hơn không hiện nó.
 */
export function laVanHanh(user) {
  return (user?.roles || []).includes('super_admin');
}

/** Giảng viên thuần: chỉ theo dõi & nhập điểm lớp mình dạy. */
export function laGiangVien(user) {
  const roles = user?.roles || [];
  return !roles.includes('super_admin') && roles.includes('lecturer');
}
