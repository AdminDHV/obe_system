import {
  LayoutDashboard,
  Award,
  Users,
  BookOpen,
  Building2,
  GraduationCap,
  FolderOpen,
  FileText,
  ListChecks,
  ClipboardList,
  Shield,
  ShieldCheck,
  History,
  Landmark,
  Gauge,
  LifeBuoy,
  Layers,
  FileSpreadsheet,
} from 'lucide-react';

export function getNavigationItems(user) {
  const roles = user.roles || [];
  const isSuperAdmin = roles.includes('super_admin');

  // Mô hình vận hành hộ: mọi tài khoản vận hành (super_admin / lecturer) dùng
  // chung console quản lý. Portal tự-phục-vụ GV/SV đã gỡ (B1).
  // Giảng viên: chỉ theo dõi lớp mình dạy và nhập điểm. Danh sách này phải KHỚP với danh sách
  // trắng ở routes/web.php — hiện mục mà bấm vào bị 403 còn tệ hơn không hiện.
  if (!isSuperAdmin && roles.includes('lecturer')) {
    return [
      {
        label: 'Tổng quan',
        items: [
          { label: 'Bảng điều khiển', routeName: 'manager.dashboard.index', icon: LayoutDashboard, description: 'Lớp bạn phụ trách' },
          { label: 'Hướng dẫn sử dụng', routeName: 'manager.guide.index', icon: LifeBuoy, description: 'Cẩm nang từng bước' },
          { label: 'Bộ file mẫu', routeName: 'manager.templates.index', icon: FileSpreadsheet, description: 'Mọi file mẫu ở một chỗ' },
        ],
      },
      {
        label: 'Giảng dạy',
        items: [
          { label: 'Lớp học phần của tôi', routeName: 'manager.classes.index', icon: Users, description: 'Nhập điểm · theo dõi chuẩn đầu ra' },
        ],
      },
    ];
  }

  // Người xem (cố vấn học tập / ban giám hiệu / đảm bảo chất lượng): xem toàn trường, không ghi.
  // Chỉ hiện những mục THẬT SỰ hữu ích khi chỉ đọc — hiện mục quản trị mà mọi nút đều 403 thì tệ
  // hơn không hiện. Chặn thật nằm ở routes/web.php + EnsureReadOnlyRole.
  if (!isSuperAdmin && roles.includes('viewer')) {
    return [
      {
        label: 'Tổng quan',
        items: [
          { label: 'Bảng điều khiển', routeName: 'manager.dashboard.index', icon: LayoutDashboard, description: 'Tiến trình đo lường' },
          { label: 'Hướng dẫn sử dụng', routeName: 'manager.guide.index', icon: LifeBuoy, description: 'Cẩm nang từng bước' },
          { label: 'Bộ file mẫu', routeName: 'manager.templates.index', icon: FileSpreadsheet, description: 'Mọi file mẫu ở một chỗ' },
        ],
      },
      {
        label: 'Đo lường chuẩn đầu ra',
        items: [
          { label: 'Trung tâm đo lường', routeName: 'manager.reports.index', icon: Gauge, description: 'Kết quả đo & báo cáo' },
          { label: 'Báo cáo học phần', routeName: 'manager.reports.course.index', icon: FileText, description: 'Gộp các lớp, xuất file Word theo mẫu trường' },
          { label: 'Khung PLO · PI · CLO', routeName: 'manager.obe.index', icon: Award, description: 'Chuẩn đầu ra & ma trận' },
        ],
      },
      {
        label: 'Tra cứu',
        items: [
          { label: 'Sinh viên', routeName: 'manager.students.index', icon: GraduationCap, description: 'Kết quả từng học viên' },
          { label: 'Lớp học phần', routeName: 'manager.classes.index', icon: Users, description: 'Mức đạt CLO · PI của lớp' },
          { label: 'Chương trình đào tạo', routeName: 'manager.programs.index', icon: BookOpen, description: 'Ngành đào tạo' },
          { label: 'Cấu trúc CTĐT', routeName: 'manager.curriculum.index', icon: Layers, description: 'Khối bắt buộc + hướng chuyên ngành' },
        ],
      },
    ];
  }

  if (isSuperAdmin || roles.includes('lecturer')) {
    return [
      {
        label: 'Tổng quan',
        items: [
          { label: 'Bảng điều khiển', routeName: 'manager.dashboard.index', icon: LayoutDashboard, description: 'Tiến trình đo lường & việc cần làm' },
          { label: 'Hướng dẫn sử dụng', routeName: 'manager.guide.index', icon: LifeBuoy, description: 'Cẩm nang từng bước cho người mới' },
          { label: 'Bộ file mẫu', routeName: 'manager.templates.index', icon: FileSpreadsheet, description: 'Mọi file mẫu ở một chỗ' },
        ],
      },
      {
        label: 'Đo lường chuẩn đầu ra',
        items: [
          { label: 'Trung tâm đo lường', routeName: 'manager.reports.index', icon: Gauge, description: 'Dựng khung · nhập điểm · đo · báo cáo' },
          { label: 'Báo cáo học phần', routeName: 'manager.reports.course.index', icon: FileText, description: 'Gộp các lớp, xuất file Word theo mẫu trường' },
        ],
      },
      {
        label: 'Thiết lập chuẩn đầu ra',
        items: [
          { label: 'Khung PLO · PI · CLO', routeName: 'manager.obe.index', icon: Award, description: 'Khai báo chuẩn đầu ra & ma trận' },
        ],
      },
      {
        label: 'Danh mục',
        collapsible: true,
        defaultOpen: false,
        items: [
          ...(isSuperAdmin
            ? [{ label: 'Trường', routeName: 'manager.schools.index', icon: Landmark, description: 'Quản lý đa-trường' }]
            : []),
          { label: 'Khoa', routeName: 'manager.departments.index', icon: Building2, description: 'Khoa / bộ môn' },
          { label: 'Chương trình đào tạo', routeName: 'manager.programs.index', icon: BookOpen, description: 'Ngành đào tạo' },
          { label: 'Cấu trúc CTĐT', routeName: 'manager.curriculum.index', icon: Layers, description: 'Khối bắt buộc + hướng chuyên ngành' },
          { label: 'Niên khóa', routeName: 'manager.cohorts.index', icon: GraduationCap, description: 'Khóa tuyển sinh' },
          { label: 'Học phần', routeName: 'manager.courses.index', icon: FolderOpen, description: 'Môn học' },
          { label: 'Đề cương học phần', routeName: 'manager.syllabi.index', icon: FileText, description: 'Phiên bản đề cương + CLO' },
          { label: 'Thành phần đánh giá', routeName: 'manager.assessments.index', icon: ListChecks, description: 'Bài đánh giá của môn' },
          { label: 'Phiếu chấm (Rubric)', routeName: 'manager.rubrics.index', icon: ClipboardList, description: 'Tiêu chí chấm' },
          { label: 'Lớp học phần', routeName: 'manager.classes.index', icon: Users, description: 'Lớp + sĩ số' },
          { label: 'Sinh viên', routeName: 'manager.students.index', icon: GraduationCap, description: 'Danh sách SV' },
          { label: 'Giảng viên', routeName: 'manager.lecturers.index', icon: Users, description: 'Tài khoản GV' },
        ],
      },
      ...(isSuperAdmin
        ? [{
            label: 'Hệ thống',
            items: [
              { label: 'Người dùng & Vai trò', routeName: 'manager.users.index', icon: ShieldCheck, description: 'Tài khoản & phân quyền' },
              { label: 'Nhật ký hoạt động', routeName: 'manager.audit-logs.index', icon: History, description: 'Lịch sử thao tác (gồm sửa điểm)' },
              { label: 'Lịch sử import', routeName: 'manager.import-batches.index', icon: History, description: 'Mọi lần nhập file' },
              { label: 'Nhật ký đo lường', routeName: 'manager.measurement-runs.index', icon: History, description: 'Các lần tính lại CLO/PI/PLO' },
            ],
          }]
        : []),
    ];
  }

  // Portal tự-phục-vụ Giảng viên & Sinh viên đã gỡ (mô hình vận hành hộ — B1).
  // Khôi phục menu tương ứng qua git history nếu chuyển hướng SaaS.

  return [
    {
      label: 'Chung',
      items: [
        { label: 'Dashboard', routeName: 'dashboard', icon: LayoutDashboard },
      ],
    },
  ];
}
