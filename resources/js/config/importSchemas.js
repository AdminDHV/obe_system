/**
 * Cột mong đợi cho bảng xem trước (FilePreview) của các ô nhập ĐỊNH DẠNG CŨ (khung nhiều sheet,
 * file điểm PA1–PA4). Quy trình chuẩn hiện nay là File 1 + phiếu lớp học phần, không dùng bảng này.
 */

/** Chuẩn hoá tiêu đề cột: bỏ dấu + lowercase + bỏ khoảng trắng/ký tự đặc biệt. */
export function normalizeHeader(s) {
  return String(s ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '') // bỏ dấu thanh
    .replace(/đ/gi, 'd')
    .toLowerCase()
    .replace(/[^a-z0-9]/g, ''); // bỏ space + ký tự đặc biệt
}

// File điểm (Bước 2)
export const GRADE_SCHEMAS = {
  // PA1 — khóa cũ ≤2024 (self-contained)
  legacy: {
    required: ['Mã ngành', 'Năm khóa', 'Mã môn', 'MSSV', 'Điểm'],
    optional: ['Tên SV'],
  },
  // PA2 / PA3 — điểm từng bài đánh giá
  standard: {
    required: ['Mã lớp', 'MSSV', 'Tên bài đánh giá', 'Điểm'],
    optional: [],
  },
  // PA4 — điểm từng CÂU HỎI (rubric): mỗi dòng 1 câu, có CLO nó đo + thang tối đa
  question: {
    required: ['Mã lớp', 'MSSV', 'Bài kiểm tra', 'Mã CLO', 'Điểm đạt', 'Điểm tối đa'],
    optional: [],
  },
};

// File danh sách sinh viên (Bước 1) — mỗi dòng 1 SV; MSSV trùng → cập nhật
export const STUDENT_ROSTER_SCHEMA = {
  required: ['MSSV', 'Họ tên', 'Mã ngành'],
  optional: ['Năm khóa', 'Mã khóa', 'Tên SV'],
};

// File khảo sát đo GIÁN TIẾP (Bước 2) — mỗi dòng = mức đạt 1 PLO theo 1 nhóm
export const SURVEY_SCHEMA = {
  required: ['Mã ngành', 'Đối tượng', 'Mã PLO', 'Điểm TB Likert'],
  optional: ['Mã khóa', 'Số phản hồi', 'Thang'],
};

// File khung CTĐT (Bước 1) — mỗi sheet 1 thực thể
export const STRUCTURE_SHEETS = [
  { sheet: 'Nganh', label: 'Ngành', required: ['Mã ngành', 'Tên ngành', 'Khoa'], optional: [] },
  { sheet: 'Khoa', label: 'Khóa', required: ['Mã ngành', 'Tên khóa', 'Năm bắt đầu'], optional: [] },
  { sheet: 'Mon', label: 'Môn', required: ['Mã môn', 'Tên môn', 'Số tín chỉ', 'Mã ngành'], optional: [] },
  { sheet: 'CLO', label: 'CLO', required: ['Mã môn', 'Mã CLO', 'Mô tả'], optional: [] },
  { sheet: 'BaiDanhGia', label: 'Bài đánh giá', required: ['Mã môn', 'Tên bài đánh giá', 'Loại', 'Trọng số', 'CLO'], optional: [] },
  { sheet: 'BaiDanhGia_CLO', label: 'Ánh xạ Bài đánh giá→CLO', required: ['Mã môn', 'Tên bài đánh giá', 'Mã CLO'], optional: ['Trọng số'] },
  { sheet: 'PLO', label: 'PLO', required: ['Mã ngành', 'Mã PLO', 'Mô tả'], optional: [] },
  { sheet: 'PI', label: 'PI', required: ['Mã ngành', 'Mã PLO', 'Mã PI', 'Mô tả'], optional: ['Trọng số'] },
  { sheet: 'CLO_PI', label: 'Ma trận CLO→PI', required: ['Mã ngành', 'Mã môn', 'Mã CLO', 'Mã PI', 'Mức độ'], optional: [] },
  { sheet: 'Lop', label: 'Lớp', required: ['Mã lớp', 'Mã môn', 'Tên khóa', 'Mã GV', 'Năm học', 'Học kỳ'], optional: [] },
];
