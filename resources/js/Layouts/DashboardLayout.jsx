import React from 'react';
import { SidebarProvider, SidebarInset, SidebarTrigger } from '@/components/ui/sidebar';
import { Separator } from '@/components/ui/separator';
import { AppSidebar } from '@/components/AppSidebar';
import { TooltipProvider } from '@/components/ui/tooltip';
import WorkingSchoolSwitcher from '@/components/WorkingSchoolSwitcher';
import { usePage } from '@inertiajs/react';
import { ChevronRight, Home } from 'lucide-react';
import { Link } from '@inertiajs/react';
import { Toaster } from 'sonner';

const segmentRoutes = {
  'manager': 'manager.dashboard.index',
  'lecturer': 'lecturer.dashboard.index',
  'student': 'student.dashboard.index',
};

const segmentLabels = {
  'manager': 'Quản lý',
  'lecturer': 'Giảng viên',
  'student': 'Sinh viên',
  'dashboard': 'Tổng quan',
  'classes': 'Lớp học phần',
  'grading': 'Chấm điểm',
  'students': 'Sinh viên',
  'lecturers': 'Giảng viên',
  'schools': 'Trường',
  'programs': 'Chương trình đào tạo',
  'curriculum': 'Cấu trúc CTĐT',
  'guide': 'Hướng dẫn sử dụng',
  'file-mau': 'Bộ file mẫu',
  'departments': 'Khoa',
  'cohorts': 'Niên khóa',
  'courses': 'Học phần',
  'syllabi': 'Đề cương học phần',
  'assessments': 'Thành phần đánh giá',
  'rubrics': 'Phiếu chấm (Rubric)',
  'obe': 'Chuẩn đầu ra (OBE)',
  'reports': 'Báo cáo AUN-QA',
  'hoc-phan': 'Báo cáo học phần',
  'bang-diem-psc': 'Bảng điểm cho phần mềm đào tạo',
  'audit-logs': 'Nhật ký hoạt động',
  'users': 'Người dùng & Vai trò',
  'enrollments': 'Ghi danh',
  'profile': 'Hồ sơ',
  // Tài nguyên con (ít khi là trang riêng)
  'clos': 'CLO',
  'pis': 'PI',
  'plos': 'PLO',
  'mappings': 'Ma trận CLO–PI',
  'rubric-criteria': 'Tiêu chí',
  'assessment-clo-mappings': 'Map CLO',
};

// UUID hoặc id số ở cuối URL (trang chi tiết) -> hiển thị "Chi tiết" thay vì chuỗi thô.
function prettifySegment(seg) {
  if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-/i.test(seg) || /^\d+$/.test(seg)) {
    return 'Chi tiết';
  }
  return segmentLabels[seg] || seg;
}

function Breadcrumbs() {
  const { ziggy } = usePage().props;
  const url = ziggy?.location || window.location.pathname;

  const segments = url.split('/').filter(Boolean);
  if (segments.length === 0) return null;

  return (
    <nav className="flex items-center gap-1 text-sm text-muted-foreground">
      <Link href={route('dashboard')} className="hover:text-foreground transition">
        <Home className="h-3.5 w-3.5" />
      </Link>
      {segments.map((segment, index) => {
        const label = prettifySegment(segment);
        const isLast = index === segments.length - 1;

        const href = segmentRoutes[segment] ? route(segmentRoutes[segment]) : null;

        return (
          <React.Fragment key={segment + index}>
            <ChevronRight className="h-3.5 w-3.5 shrink-0" />
            {isLast || !href ? (
              <span className="font-medium text-foreground">{label}</span>
            ) : (
              <Link href={href} className="hover:text-foreground transition">
                {label}
              </Link>
            )}
          </React.Fragment>
        );
      })}
    </nav>
  );
}

export default function DashboardLayout({ children }) {
  return (
    <TooltipProvider>
      <SidebarProvider>
        <AppSidebar />
        {/* min-w-0: không có thì khung flex này nở theo bảng rộng nhất trong trang và cả trang cuộn ngang
            trên điện thoại — khung cuộn riêng của bảng (overflow-x-auto) không còn tác dụng. */}
        <SidebarInset className="min-w-0">
          <header className="flex h-14 shrink-0 items-center gap-2 border-b px-3 sm:px-4">
            <SidebarTrigger className="md:hidden shrink-0" />
            <Separator orientation="vertical" className="md:hidden h-5" />
            <div className="min-w-0 flex-1 overflow-x-auto">
              <Breadcrumbs />
            </div>
            <div className="shrink-0">
              <WorkingSchoolSwitcher />
            </div>
          </header>
          <main className="flex-1 overflow-auto p-3 sm:p-4 lg:p-6">
            {children}
          </main>
        </SidebarInset>
      </SidebarProvider>
      <Toaster position="top-right" richColors />
    </TooltipProvider>
  );
}
