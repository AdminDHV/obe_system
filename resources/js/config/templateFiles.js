/**
 * BỘ FILE MẪU — nguồn DUY NHẤT liệt kê file mẫu của hệ thống (trang "Bộ file mẫu" và Hướng dẫn
 * dùng chung).
 *
 * Buổi họp lần 1, khách chốt: chỉ còn HAI file — "một file khai báo cho nguyên chương
 * trình, và một file khai báo cho từng lớp học phần". Trước đó trường phải chọn giữa 11 sheet khung,
 * file danh sách sinh viên, 4 kiểu file điểm theo "phương án", file bảng điểm lớp và file ghi danh.
 * Các định dạng cũ vẫn nhập được (dữ liệu đang chạy không hỏng) nhưng không còn mời dùng ở đây.
 */
export const TEMPLATE_GROUPS = [
  {
    key: 'chuong-trinh',
    title: 'File 1 — Khai báo chương trình',
    note: 'Mỗi khóa làm một lần cho cả chương trình. Tải lại file đã sửa là cập nhật, không nhân đôi.',
    files: [
      {
        name: 'Khai báo chương trình',
        routeName: 'manager.reports.structure.template',
        desc: 'Ngành và khóa · PLO/PI kèm trọng số (các PI của một PLO cộng lại đúng 1) · danh sách môn · ma trận chuẩn đầu ra các học phần (môn × PLO/PI, chép từ CTĐT) · danh sách sinh viên. CLO không khai ở đây mà khai ở phiếu từng môn.',
        must: true,
      },
    ],
  },
  {
    key: 'khung-dot',
    title: 'Khung học phần theo đợt',
    note: 'Kỹ thuật của trường làm một lần cho cả khóa, năm học. Muốn file điền sẵn các môn của khóa thì tải ở Trung tâm đo lường, Bước 1.',
    files: [
      {
        name: 'Khung học phần theo đợt (bản trống có ví dụ)',
        routeName: 'manager.reports.course-frame.template',
        desc: 'Sheet CLO: CLO và ma trận CLO với PLO/PI của từng môn. Sheet DanhGia: bài đánh giá kèm trọng số (tổng một môn 100%). Sheet LopHocPhan: lớp học phần kèm giảng viên, năm học, học kỳ. Dựng xong thì giáo viên chỉ còn điền câu hỏi và điểm.',
      },
    ],
  },
  {
    key: 'phieu-lop',
    title: 'File 2 — Phiếu lớp học phần',
    note: 'Mỗi lớp học phần một phiếu. Muốn phiếu điền sẵn thông tin môn, cột PLO/PI và danh sách sinh viên thì tải ở Trung tâm đo lường, Bước 2.',
    files: [
      {
        name: 'Phiếu lớp học phần (bản trống có ví dụ)',
        routeName: 'manager.reports.class-sheets.template',
        desc: 'Sheet KhaiBao: thông tin lớp, CLO và ma trận CLO với PLO/PI, các bài đánh giá kèm trọng số (tổng 100%). Mỗi bài một sheet: mỗi câu hỏi một cột ghi hình thức, điểm tối đa (tổng 10) và CLO cần đo, rồi điểm từng sinh viên. Vắng thi ghi VT. Môn đã dựng khung theo đợt thì phần khai báo lấy từ khung.',
        must: true,
      },
    ],
  },
  {
    key: 'them',
    title: 'Tùy chọn — dùng khi cần',
    files: [
      {
        name: 'Kết quả khảo sát (đo gián tiếp)',
        routeName: 'manager.reports.survey.template',
        desc: 'Khảo sát cuối môn · tốt nghiệp · cựu sinh viên · nhà tuyển dụng. Trộn với đo trực tiếp theo tỉ lệ khai ở màn hình Trường.',
      },
    ],
  },
];
