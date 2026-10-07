/**
 * Nguồn nội dung HƯỚNG DẪN dùng chung cho:
 *  - Trang "Hướng dẫn sử dụng" (Pages/Manager/Guide/Index.jsx)
 *  - Khối "Hướng dẫn bước này" trong Trung tâm đo lường (Pages/Manager/Reports/Index.jsx)
 *  - Chú thích InfoHint tại field.
 * Giữ 1 nguồn để nội dung không bị lệch nhau.
 */

// ── Từ điển khái niệm (giải thích thuật ngữ) ────────────────────────────────
export const CONCEPTS = [
  { term: 'CLO — Chuẩn đầu ra học phần', short: 'Điều sinh viên phải đạt sau 1 MÔN. Đo bằng điểm các bài đánh giá của môn đó.' },
  { term: 'PLO — Chuẩn đầu ra chương trình', short: 'Điều sinh viên phải đạt sau cả NGÀNH. Tổng hợp từ nhiều môn (qua PI).' },
  { term: 'PI — Chỉ số đánh giá (của PLO)', short: '"Mảnh nhỏ" của 1 PLO để đo chi tiết hơn. PLO = gộp các PI của nó.' },
  { term: 'Khung CTĐT', short: 'Bản khai cấu trúc chương trình: ngành → môn → CLO; ngành → PLO → PI; và ma trận CLO ↔ PI. Khai 1 lần bằng 1 file Excel.' },
  { term: 'Phương án (đo lường)', short: 'Cách hệ thống đo tùy theo năm khóa: khóa ≤2024 mặc định chỉ đo tới CLO, các khóa sau đo đủ CLO → PI → PLO. Lớp nào nhập bằng phiếu lớp học phần thì luôn đo đủ tới PLO, bất kể năm khóa.' },
  { term: 'Tín chỉ', short: 'Trọng số của môn. Môn nhiều tín chỉ đóng góp nhiều hơn vào PLO khi tổng hợp. Viết tắt trên một số bảng: TC.' },
  { term: 'Bộ file mẫu', short: 'Một trang liệt kê đủ mọi file mẫu theo đúng thứ tự phải làm (menu Tổng quan → Bộ file mẫu). Thực tế chỉ cần 2 file là chạy được: Khung chương trình đào tạo và file điểm đúng phương án của khóa.' },
  { term: 'Học phần (HP)', short: 'Là MÔN học. "Lớp học phần" là một lớp cụ thể của môn đó trong một học kỳ — cùng một môn có thể mở nhiều lớp, và học viên học lại thì có nhiều lớp của cùng một môn.' },
  { term: 'Điểm trung bình tích lũy', short: 'Trung bình điểm các môn ĐÃ CÓ ĐIỂM, có nhân tín chỉ: Σ(điểm môn × tín chỉ) / Σ tín chỉ. Thang 10, KHÔNG phải thang 4 — nên đừng đọc như GPA 4.0 của nước ngoài. Tính cả môn chưa đạt; muốn biết phần đã tích lũy được thì xem "Tín chỉ tích lũy".' },
  { term: 'Điểm tổng kết môn', short: 'Điểm của một môn, ghép từ các bài đánh giá theo trọng số của trường (ví dụ 10% chuyên cần + 40% giữa kỳ + 50% cuối kỳ). Một số bảng ghi tắt là "Điểm TB". Khác với điểm CLO: điểm CLO đo năng lực theo chuẩn đầu ra, không dùng để tính tín chỉ.' },
  { term: 'Mức I / R / M / A', short: 'Mức môn đóng góp cho 1 PI: I = Giới thiệu, R = Củng cố, M = Thành thạo, A = Nâng cao. Quy ra số 1 / 2 / 3 / 3 khi tính. Trang tra cứu của học viên hiển thị bằng chữ dễ hiểu hơn: Làm quen · Rèn luyện · Thành thạo · Đánh giá.' },
  { term: 'Trọng số PI', short: 'Tỉ lệ 1 PI chiếm trong PLO của nó. Các PI của cùng 1 PLO nên cộng lại = 1. Để trống = chia đều.' },
  { term: 'Mức đạt (attainment)', short: 'Tỉ lệ % sinh viên đạt 1 PLO. So với "mục tiêu" (target, ví dụ 70%) để kết luận Đạt / Chưa đạt.' },
  { term: 'Ngưỡng đạt', short: 'Điểm (hoặc tỉ lệ) tối thiểu để coi là "đạt". Ví dụ CLO ≥ 5; PLO (thang tỉ lệ) ≥ 0.5.' },
  { term: 'Tín chỉ tốt nghiệp', short: 'Tổng tín chỉ phải tích lũy để tốt nghiệp ngành. Khai ở Danh mục → Chương trình đào tạo. CHƯA khai thì cột "% hoàn thành" để trống và bộ lọc "đúng tiến độ" không lọc được ai.' },
  { term: 'Đúng tiến độ', short: 'Học viên đã tích lũy từ 80% tín chỉ tốt nghiệp trở lên. Người chưa đủ vẫn được đo và hiển thị đầy đủ, nhưng TÁCH RIÊNG — không tính vào trung bình của khóa, tránh kéo lệch số liệu.' },
  { term: 'Mã tra cứu', short: 'Mã 8 ký tự riêng của từng học viên, in trên phiếu kết quả. Học viên dùng mã này để tự xem kết quả tại /ket-qua-hoc-tap mà không cần tài khoản.' },
  { term: 'Bản chốt (snapshot)', short: 'Ảnh chụp số liệu tại một thời điểm khi xuất bảng tổng lớp. Dùng để đối chiếu về sau, vì điểm còn nhập tiếp thì số sẽ đổi. Bản chốt lưu kèm cả độ phủ, nên đọc lại một năm sau vẫn biết số đó dựa trên bao nhiêu học phần.' },
  { term: 'Khối học phần (CTĐT)', short: 'Chương trình chia thành các khối: khối bắt buộc ai cũng học, và các hướng chuyên ngành để học viên chọn. Khai ở Danh mục → Cấu trúc CTĐT. Mỗi khối ghi SỐ TÍN CHỈ yêu cầu (không phải số môn), nên khối "mở 5 môn chọn 4" cũng khai được.' },
  { term: 'Hướng chuyên ngành', short: 'Khối tự chọn mà học viên theo. Cao học thường chọn ngay từ đầu khóa, đại học từ năm 3. Quyết định lộ trình riêng của học viên, do đó quyết định mẫu số của Độ phủ đo lường.' },
  { term: 'Độ phủ đo lường', short: 'Hiển thị dạng "PI1.1 (1/3)": chỉ số này được đo qua 3 học phần trong lộ trình của học viên, đã học xong 1. Mức đạt tính cộng dồn trên học phần ĐÃ có điểm nên xem được ngay sau mỗi học kỳ — nhưng 80% khi mới đo 1/3 khác hẳn 80% khi đã đo 3/3, nên luôn đọc kèm mẫu số.' },
  { term: 'Trường (đa-trường)', short: 'Hệ thống phục vụ nhiều trường. Mọi dữ liệu thuộc "Trường đang làm việc" bạn chọn ở đầu trang. 2 trường được trùng mã.' },
];

// ── Các bước vận hành (4 bước chính + bước 5 cải tiến tùy chọn; dùng cho trang + hub) ──
export const STEPS = [
  {
    n: 1,
    key: 'khung',
    title: 'Khai báo chương trình',
    short: 'Tải lên File 1: ngành và khóa, PLO/PI, danh sách môn, danh sách sinh viên. Mỗi khóa làm một lần.',
    purpose:
      'Khai báo những gì thuộc về CẢ chương trình, làm một lần khi mở khóa mới. CLO, bài đánh giá, ma trận CLO với PLO/PI và điểm KHÔNG khai ở đây — mỗi lớp học phần tự khai trong phiếu của mình (Bước 2).',
    file: {
      name: 'File 1 — Khai báo chương trình (Excel, 4 sheet)',
      note: 'Tải mẫu ở Bộ file mẫu hoặc ngay Bước 1. Dòng 1 mỗi sheet là tên cột, giữ nguyên. Mã ngành được bỏ trống ở các sheet sau nếu file chỉ khai một ngành.',
      columns: [
        { col: 'Sheet Nganh_Khoa', meaning: 'Mã ngành · Tên ngành · Khoa quản lý · Tên khóa · Năm bắt đầu. Mỗi khóa một dòng.' },
        { col: 'Sheet PLO_PI', meaning: 'Mã PLO · Mô tả · Mã PI · Mô tả PI · Trọng số PI. Mỗi PI một dòng. Trọng số các PI của một PLO phải cộng lại đúng 1. PLO nào không chia PI thì để trống Mã PI — CLO sẽ nối thẳng vào PLO đó.' },
        { col: 'Sheet MonHoc', meaning: 'Mã môn · Tên môn · Tín chỉ. Tín chỉ tham gia công thức PI/PLO nên phải đúng.' },
        { col: 'Sheet MaTran_CTDT', meaning: 'Chép bảng "Ma trận chuẩn đầu ra các học phần trong chương trình đào tạo" của CTĐT: Mã môn · mỗi PLO/PI một cột, ô ghi I/R/M/A. Cột PLO áp mức cho mọi PI của PLO đó. Mức ở đây là trọng số khi tính PI/PLO, và phiếu lớp chỉ được nối CLO vào PLO/PI mà ma trận ghi cho môn. Không khai CLO ở file này — CLO khai ở phiếu từng môn.' },
        { col: 'Sheet SinhVien', meaning: 'MSSV · Họ và tên · Năm khóa. Không bắt buộc — phiếu lớp học phần tự thêm sinh viên chưa có.' },
      ],
    },
    actions: [
      'Tải file mẫu → mở bằng Excel → xóa dòng ví dụ, điền của trường.',
      'Tải lên ở Bước 1. Thẻ tóm tắt cho biết đã có bao nhiêu ngành, môn, PLO, PI và còn thiếu gì.',
      'Hệ số và ngưỡng đo của trường khai ở Danh mục → Trường → "Cấu hình OBE" (File 1 kiểu cũ có sheet ThamSo vẫn nhận).',
      'Nên dựng luôn "Khung học phần theo đợt" (khối ngay dưới File 1): kỹ thuật của trường khai một lần cho cả khóa CLO và ma trận CLO, bài đánh giá kèm trọng số, lớp học phần kèm giảng viên. Sau đó giáo viên chỉ còn tải phiếu của lớp mình, điền câu hỏi và điểm.',
    ],
    result: 'Chương trình được dựng; tải lại file đã sửa là cập nhật, không nhân đôi. Sau bước này có thể tải phiếu lớp học phần điền sẵn ở Bước 2.',
    errors: [
      { when: 'Báo "tổng trọng số các PI của PLO… phải đúng 1"', fix: 'Sửa trọng số các PI của PLO đó cho cộng lại đúng 1. Hệ thống KHÔNG dựng PI của PLO sai trọng số — dựng rồi thì số đo vẫn ra, chỉ là sai.' },
      { when: 'Báo "PI … thiếu trọng số"', fix: 'Mọi PI của một PLO đều phải có trọng số.' },
      { when: 'Thiếu tín chỉ ở sheet MonHoc', fix: 'Điền tín chỉ cho mọi môn — công thức PI/PLO nhân theo tín chỉ.' },
      { when: 'File khai nhiều ngành', fix: 'Khi đó phải ghi Mã ngành ở sheet PLO_PI, MonHoc và SinhVien để biết dòng nào thuộc ngành nào.' },
    ],
  },
  {
    n: 2,
    key: 'diem',
    title: 'Phiếu lớp học phần',
    short: 'Mỗi lớp học phần một phiếu: khai CLO, bài đánh giá, điểm từng câu hỏi. Tải lên là hệ thống tự đo.',
    purpose:
      'Một phiếu chứa đủ mọi thứ của một lớp: thông tin lớp, CLO và ma trận CLO với PLO/PI (chép từ đề cương), các bài đánh giá kèm trọng số, và điểm từng câu hỏi của từng sinh viên. Từ một phiếu hệ thống tính cả điểm số môn lẫn mức đạt chuẩn đầu ra.',
    file: {
      name: 'File 2 — Phiếu lớp học phần (Excel)',
      note: 'Chọn ngành, khóa, môn ở Bước 2 để tải phiếu điền sẵn thông tin môn, cột PLO/PI của ngành và danh sách sinh viên. Không chọn thì tải bản trống có ví dụ.',
      columns: [
        { col: 'Sheet KhaiBao — thông tin', meaning: 'Mã môn học · Tên môn · Mã ngành · Khóa · Mã lớp học phần · Giảng viên giảng dạy · Năm học · Học kỳ. Giảng viên chỉ cần ghi tên.' },
        { col: 'Sheet KhaiBao — Chuẩn đầu ra', meaning: 'Mỗi CLO một dòng kèm nội dung; mỗi PLO/PI một cột, ghi I, R, M hoặc A vào ô CLO đóng góp — đúng bảng "Mối quan hệ giữa CLO và PLO" trong đề cương.' },
        { col: 'Sheet KhaiBao — Đánh giá', meaning: 'Tên bài · Loại (quá trình/cuối kỳ) · Trọng số %. Trường tự quyết trọng số từng bài (bài 20, bài 10…), miễn tổng đúng 100%.' },
        { col: 'Mỗi bài một sheet', meaning: 'Tên sheet trùng tên bài. Mỗi câu hỏi một cột: Hình thức · Điểm tối đa (tổng các câu đúng 10) · CLO cần đo. Một cột có thể là nhiều câu trắc nghiệm, ví dụ "Câu 1-15". Dòng PLO/PI cần đo để trống, hệ thống tự lấy từ ma trận. Điểm từng sinh viên ghi bên dưới.' },
      ],
    },
    actions: [
      'Chọn ngành, khóa, môn → "Tải phiếu điền sẵn".',
      'Điền mã lớp, giảng viên, CLO và ma trận (lần đầu của môn), các bài đánh giá, câu hỏi và điểm.',
      'Tải lên — chọn được nhiều phiếu một lần. Xem "Lịch sử nhập điểm" để biết phiếu nào xong, phiếu nào lỗi.',
      'Môn đã dựng khung theo đợt thì phiếu lấy CLO và bài đánh giá từ khung — chỉ cần câu hỏi và điểm. Giáo viên cũng tự tải và nộp phiếu được ở trang lớp học phần của mình.',
      'Học viên vắng thi ghi VT, không để trống. Vắng thi cuối kỳ thì chưa tính vào cỡ mẫu của môn. Thi lại xong thì tích "Phiếu bổ sung sau thi lại" và nộp phiếu chỉ có các bạn thi lại — điểm cả lớp giữ nguyên.',
    ],
    result: 'Lớp, giảng viên, sinh viên, câu hỏi và điểm được tạo từ chính phiếu, rồi hệ thống tự đo. Tải lại phiếu đã sửa thì điểm của lớp được THAY bằng phiếu mới (ô để trống = không có điểm), không cộng dồn. File gốc được giữ lại.',
    errors: [
      { when: 'Phiếu "Thất bại" kèm danh sách lỗi', fix: 'Phiếu có lỗi thì KHÔNG nhập gì. Mỗi lỗi ghi rõ sheet và ô — sửa hết rồi tải lên lại.' },
      { when: 'Tổng trọng số ≠ 100% hoặc tổng điểm tối đa ≠ 10', fix: 'Ô "Tổng" cạnh bảng trong phiếu tự cộng để trường thấy trước khi tải lên.' },
      { when: 'Câu hỏi chưa ghi CLO, hoặc CLO chưa nối PLO/PI', fix: 'Câu không có CLO, hay CLO không nối PLO/PI nào, thì không đo được — hệ thống chặn thay vì bỏ qua âm thầm.' },
      { when: 'Báo "PLO… đã chia thành PI"', fix: 'PLO đã chia PI thì ở ma trận phải ghi mã PI cụ thể thay cho PLO.' },
      { when: 'Chưa có môn / ngành', fix: 'Khai ở File 1 (Bước 1) trước.' },
      { when: 'Báo "để trống cả bài cuối kỳ"', fix: 'Học viên có điểm bài khác mà trống cả bài cuối kỳ: vắng thi thì ghi VT vào ô điểm.' },
      { when: 'Báo "lớp … không có trong khung học phần"', fix: 'Môn đã dựng khung theo đợt: lớp phải được khai ở sheet LopHocPhan của file khung trước.' },
    ],
  },
  {
    n: 3,
    key: 'do',
    title: 'Đo & kiểm tra',
    short: 'Chọn ngành, khóa để kiểm tra sẵn sàng và xem trước mức đạt PLO. Nhập phiếu là hệ thống đã tự đo.',
    purpose:
      'Kiểm tra đủ điều kiện chưa, rồi tính mức đạt CLO → PI → PLO cho toàn trường. Xem trước kết quả trước khi xuất báo cáo. Bước này KHÔNG cần tải file — chỉ bấm nút.',
    file: null,
    actions: [
      'Chọn Ngành + Khóa ở khối "Kiểm tra sẵn sàng đo lường" — hệ thống liệt kê đủ/thiếu gì.',
      'Nhập phiếu xong là hệ thống tự đo. Vừa sửa ngưỡng, hệ số hay ma trận thì bấm "Tính lại toàn trường" ở Bước 2 rồi quay lại đây.',
      'Xem bảng "Mức đạt chuẩn đầu ra (xem trước)": % SV đạt từng PLO + mức + có đạt mục tiêu không.',
      'Xem thêm bảng & BIỂU ĐỒ mức đạt PLO / PI (chỉ báo); bấm "Tải Excel (PLO + PI + ma trận)" nếu cần file nhiều sheet.',
    ],
    result: 'Mỗi PLO/PI có tỉ lệ % sinh viên đạt và kết luận Đạt/Chưa đạt so với mục tiêu, kèm biểu đồ. Mỗi lần đo ghi 1 dòng "Nhật ký đo lường" (thành công hoặc thất bại đều ghi).',
    errors: [
      { when: 'Báo "chưa sẵn sàng"', fix: 'Đọc danh sách ✗ — thường là thiếu PLO/PI hoặc bài đánh giá chưa gắn CLO. Quay lại Bước 1.' },
      { when: 'PLO trống / chưa có số', fix: 'Chưa nhập điểm (Bước 2) hoặc chưa bấm "Tính lại".' },
    ],
  },
  {
    n: 4,
    key: 'baocao',
    title: 'Báo cáo',
    short: 'Tạo báo cáo AUN-QA rồi tải về. Báo cáo chạy nền, trạng thái tự cập nhật.',
    purpose: 'Xuất báo cáo AUN-QA (mức đạt PLO + ma trận sinh viên × PLO) để nộp kiểm định. Có 2 dạng: file CSV (tạo nền) và file Excel nhiều sheet (tải ngay ở Bước 3).',
    file: null,
    actions: [
      'Chọn Ngành + Khóa → bấm "Khởi tạo báo cáo" để tạo file CSV (chạy nền).',
      'Trạng thái tự cập nhật (Đang xử lý → Hoàn thành) → bấm tải về CSV.',
      'Hoặc lấy file Excel 4 sheet (Tổng quan · Mức đạt PLO · Mức đạt PI · Ma trận SV×PLO) bằng nút "Tải Excel" ở Bước 3.',
    ],
    result: 'Báo cáo gồm: bảng mức đạt từng PLO (% đạt, mức, target), mức đạt từng PI, và ma trận điểm/tỉ lệ của từng sinh viên theo PLO. Mở bằng Excel.',
    errors: [
      { when: 'Báo cáo "Thất bại"', fix: 'Kiểm tra đã đo lường (Bước 3) chưa; thử "Tính lại" rồi tạo lại báo cáo.' },
    ],
  },
  {
    n: 5,
    key: 'caitien',
    title: 'Cải tiến (tùy chọn)',
    short: 'Từ các PLO chưa đạt mục tiêu, sinh danh sách việc cải tiến và theo dõi trước → sau.',
    purpose: 'Vòng cải tiến liên tục (CQI/PDCA): từ các PLO CHƯA đạt mục tiêu, tạo danh sách việc cần cải tiến và theo dõi trước → sau. Bước tùy chọn, dùng cho kiểm định nâng cao.',
    file: null,
    actions: [
      'Vào Bước 5 "Cải tiến" → bấm tạo việc từ kết quả đo (tự sinh cho PLO chưa đạt).',
      'Ghi hành động cải tiến cho từng PLO; lần đo sau sẽ so sánh trước → nay.',
      'Việc lỡ tạo nhầm / không làm nữa: bấm "Huỷ việc" (hoặc đổi trạng thái sang "Đã huỷ") — dòng vẫn giữ để có lịch sử, PLO đó vẫn tạo việc lại được.',
    ],
    result: 'Danh sách hành động cải tiến gắn với từng PLO chưa đạt, có mốc "trước" để đối chiếu tiến bộ.',
    errors: [
      { when: 'Chưa có việc nào', fix: 'Cần đã đo lường (Bước 3) và có PLO chưa đạt mục tiêu thì mới sinh việc.' },
    ],
  },
  {
    n: 6,
    key: 'daura',
    title: 'Bảng đầu ra & tra cứu',
    short: 'Ba bảng cho ba đối tượng: học viên tự xem · khoa xem cả lớp · giảng viên xem học phần mình dạy. Học viên tra tại /ket-qua-hoc-tap bằng mã riêng.',
    purpose:
      'Ba bảng kết quả cho ba đối tượng: từng học viên tự theo dõi, khoa/cố vấn học tập theo dõi cả lớp, và giảng viên theo dõi học phần mình dạy. Làm sau khi đã đo lường (Bước 3).',
    file: null,
    actions: [
      'BẢNG 1 — Từng học viên: Quản lý → Sinh viên → chọn học viên → xem PLO/PI + tín chỉ tích lũy. Xuất phiếu cá nhân bằng nút "Xuất CSV PLO + PI"; xuất cả khóa bằng "Xuất phiếu hàng loạt" (file .zip, tách riêng nhóm đủ và chưa đủ tín chỉ).',
      'BẢNG 2 — Tổng cả lớp: Quản lý → Sinh viên → "Xuất bảng tổng lớp". Chọn Ngành + Khóa; bật "Chỉ tính học viên đúng tiến độ" nếu muốn loại người chưa đủ tín chỉ khỏi trung bình; đặt tên bản chốt nếu muốn giữ số liệu của thời điểm này để đối chiếu sau.',
      'BẢNG 3 — Theo học phần (cho giảng viên): Quản lý → Lớp học phần → chọn lớp → mục "Mức đạt PI trong học phần". Có số SV được đo, số đạt, % đạt và điểm TB của từng PI; bên dưới tách từng bài kiểm tra quá trình và cuối kỳ kèm trọng số. Nút "Xuất CSV" để tải về.',
      'CỔNG TRA CỨU cho học viên: cấp mã bằng lệnh obe:lookup-codes (hoặc mã tự sinh khi xuất phiếu), phát mã cho từng người, học viên vào /ket-qua-hoc-tap nhập mã số + mã tra cứu là xem được kết quả của mình.',
      'ĐỘ PHỦ "1/3 học phần": muốn ba bảng trên hiện được mẫu số này thì phải khai Cấu trúc CTĐT trước (Danh mục → Cấu trúc CTĐT) — xem Bước 7.',
    ],
    result:
      'Ba bảng dùng chung một nguồn số liệu đã đo, nên con số ở phiếu cá nhân, bảng lớp và bảng học phần luôn khớp nhau.',
    errors: [
      { when: 'Cột "% hoàn thành" để trống', fix: 'Ngành chưa khai Số tín chỉ tốt nghiệp. Vào Danh mục → Chương trình đào tạo → sửa ngành → điền số tín chỉ.' },
      { when: 'Bật "chỉ tính đúng tiến độ" mà không loại ai', fix: 'Cũng do chưa khai tín chỉ tốt nghiệp — không có mẫu số thì hệ thống không có căn cứ loại ai (cố ý giữ an toàn, không tự đoán).' },
      { when: 'Bảng tổng chỉ ra một bảng cho cả khóa', fix: 'File danh sách học viên chưa có cột Lớp. Bổ sung cột lớp rồi nhập lại thì tách được theo từng lớp cho cố vấn học tập.' },
      { when: 'Học viên báo không tra cứu được', fix: 'Kiểm tra học viên đã được cấp mã chưa (trang chi tiết học viên có hiện mã). Mã cũ sẽ hết hiệu lực ngay khi bấm cấp lại.' },
      { when: 'Giảng viên không thấy lớp nào', fix: 'Tài khoản giảng viên chỉ thấy lớp có mình là giảng viên phụ trách, và phải được gán đúng Trường. Kiểm ở Danh mục → Giảng viên và ở sheet Lớp của khung CTĐT.' },
    ],
  },
  {
    n: 7,
    key: 'cautruc',
    title: 'Cấu trúc CTĐT & độ phủ',
    short: 'Khai khối bắt buộc + các hướng chuyên ngành, gán hướng cho học viên. Nhờ đó ba bảng đầu ra hiện được "PI1.1 (1/3)".',
    purpose:
      'Khai chương trình theo KHỐI học phần để hệ thống biết lộ trình riêng của từng học viên, từ đó hiện được độ phủ "PI1.1 (1/3)" — chỉ số này đo qua 3 học phần trong lộ trình của bạn, đã học xong 1. Không có bước này, mức đạt vẫn đúng nhưng người đọc không biết nó dựa trên bao nhiêu học phần.',
    file: null,
    actions: [
      'Vào Danh mục → Cấu trúc CTĐT, chọn ngành.',
      'Thêm khối BẮT BUỘC (ai cũng học) và các khối HƯỚNG CHUYÊN NGÀNH (học viên chọn). Mỗi khối ghi số TÍN CHỈ yêu cầu — khối mở 5 môn mà chỉ yêu cầu 4 thì ghi tín chỉ của 4 môn.',
      'Tick chọn học phần rồi bấm "Xếp vào khối". Học phần chưa xếp khối sẽ bị tính cho MỌI học viên như thể bắt buộc.',
      'Gán hướng cho học viên: tick chọn rồi chọn khối. Với khóa đã học xong, bấm "Suy từ điểm đã có" để hệ thống tự gán theo học phần họ đã học.',
    ],
    result:
      'Ba bảng đầu ra và trang tra cứu của học viên hiện thêm mẫu số "đã đo x / tổng y học phần", tính theo lộ trình riêng từng người — hai học viên khác hướng có mẫu số khác nhau.',
    errors: [
      { when: 'Không thấy "1/3 học phần" ở đâu cả', fix: 'Ngành chưa khai khối nào. Hệ thống cố ý không hiện gì thay vì hiện một mẫu số sai.' },
      { when: 'Khối báo thiếu tín chỉ (ví dụ yêu cầu 15 nhưng chỉ có 12)', fix: 'CTĐT còn thiếu học phần trong khối đó, hoặc số tín chỉ yêu cầu ghi sai. Bổ sung học phần vào khung CTĐT rồi xếp lại.' },
      { when: '"Suy từ điểm đã có" bỏ qua một số học viên', fix: 'Những người này có điểm ở nhiều hướng khác nhau (chuyển hướng giữa chừng, hoặc ma trận khai lẫn). Hệ thống cố ý không đoán — vào gán tay cho đúng.' },
      { when: 'Học viên báo mẫu số nhỏ hơn thực tế', fix: 'Học viên chưa được gán hướng chuyên ngành nên mới tính phần bắt buộc. Màn hình có ghi rõ điều này; gán hướng xong là đủ.' },
    ],
  },
];

// ── So sánh 3 phương án ──────────────────────────────────────────────────────
export const PHUONG_AN = [
  { pa: 'PA1 — Khóa cũ', khoa: '≤ 2024', diem: '1 cột điểm tổng kết môn', cachDo: 'Mặc định: gán điểm cho mọi CLO của môn (chỉ đo tới CLO). Muốn ra PI/PLO thì dùng chế độ "Σ — Điểm tổng" bên dưới.' },
  { pa: 'PA2 — Chuyển tiếp', khoa: '2025', diem: 'Điểm từng bài đánh giá', cachDo: 'CLO nối thẳng vào PLO (PLO không chia PI), hoặc CLO → PI → PLO nếu PLO đã chia PI.' },
  { pa: 'PA3 — Khóa mới', khoa: '≥ 2026', diem: 'Điểm từng bài đánh giá', cachDo: 'CLO → PI → PLO, PI do trường khai ở file khai báo chương trình. Đầy đủ nhất.' },
  { pa: 'Σ — Điểm tổng → PI/PLO', khoa: 'Mọi khóa (chỉ có điểm tổng)', diem: '1 cột điểm tổng kết môn', cachDo: 'Cho trường KHÔNG có điểm thành phần: gán điểm tổng cho mọi CLO của môn RỒI cuốn lên PI/PLO cấp chương trình (cần ma trận CLO→PI + trọng số PI). Không tách được từng CLO trong môn.' },
];
