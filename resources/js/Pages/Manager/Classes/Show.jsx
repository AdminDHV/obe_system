import React, { useState, useCallback, useEffect, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { laVanHanh } from '@/lib/permissions';
import { 
  ArrowLeft, BookOpen, Users, BarChart3, Target, 
  TrendingUp, TrendingDown, ClipboardEdit, GraduationCap, Download,
  Upload, CheckCircle2, AlertTriangle, RefreshCw, FileSpreadsheet,
  UserCheck, UserX, Lock, PauseCircle, FileText, ChevronDown, Search
} from 'lucide-react';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell, LabelList } from 'recharts';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import SearchableSelect from '@/components/SearchableSelect';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import PaginationControls from '@/components/PaginationControls';

const GRADE_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#15803d'];

function getCloColor(score) {
  if (score == null) return 'bg-muted text-muted-foreground border-border';
  if (score >= 8) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  if (score >= 6.5) return 'bg-amber-50 text-amber-700 border-amber-200';
  return 'bg-rose-50 text-rose-700 border-rose-200';
}

function getScoreColor(score) {
  if (score == null) return 'text-muted-foreground';
  if (score >= 8) return 'text-emerald-600';
  if (score >= 6.5) return 'text-amber-600';
  return 'text-rose-600';
}

const STATUS_CONFIG = {
  active: { label: 'Đang học', color: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: CheckCircle2 },
  locked: { label: 'Khóa', color: 'bg-rose-50 text-rose-700 border-rose-200', icon: Lock },
  suspended: { label: 'Tạm dừng', color: 'bg-amber-50 text-amber-700 border-amber-200', icon: PauseCircle },
  completed: { label: 'Hoàn thành', color: 'bg-blue-50 text-blue-700 border-blue-200', icon: CheckCircle2 },
  withdrawn: { label: 'Rút học', color: 'bg-gray-50 text-gray-600 border-gray-200', icon: UserX },
};

export default function Show({ courseClass, enrollments, activeCount, fileBatches, gradeBatches, studentPerformance, gradeDistribution, cloAchievement, piAttainment = null, courseRollup = null, stats, enrollmentStatusCounts }) {
  const cc = courseClass;
  const { flash, auth } = usePage().props;
  // Ghi danh (thêm/bớt/đổi trạng thái SV) là việc của người vận hành; GV chỉ nhập điểm.
  const vanHanh = laVanHanh(auth?.user);

  const [uploadFile, setUploadFile] = useState(null);
  const [isImporting, setIsImporting] = useState(false);
  const [gradeFile, setGradeFile] = useState(null);
  const [isGradeImporting, setIsGradeImporting] = useState(false);
  const [sheetFile, setSheetFile] = useState(null);
  const [boSung, setBoSung] = useState(false);
  const [isSheetImporting, setIsSheetImporting] = useState(false);
  const [updatingId, setUpdatingId] = useState(null);
  const [expandedErrors, setExpandedErrors] = useState({});
  const [enrollmentSearch, setEnrollmentSearch] = useState('');
  const [debouncedEnrollmentSearch, setDebouncedEnrollmentSearch] = useState('');

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedEnrollmentSearch(enrollmentSearch), 300);
    return () => clearTimeout(timer);
  }, [enrollmentSearch]);

  const filteredEnrollments = useMemo(() => {
    const items = enrollments?.data ?? [];
    if (!debouncedEnrollmentSearch) return items;
    const q = debouncedEnrollmentSearch.toLowerCase();
    return items.filter(e =>
      (e.student_code && e.student_code.toLowerCase().includes(q)) ||
      (e.full_name && e.full_name.toLowerCase().includes(q))
    );
  }, [enrollments, debouncedEnrollmentSearch]);

  const handleEnrollmentPageChange = (page) => {
    router.get(route('manager.classes.show', cc.uuid), { page }, {
      preserveState: true,
      replace: true,
    });
  };

  const hasProcessingBatches =
    fileBatches?.some(b => ['processing', 'queued', 'pending'].includes(b.status)) ||
    gradeBatches?.some(b => ['processing', 'queued', 'pending'].includes(b.status)) ||
    isImporting || isGradeImporting || isSheetImporting;

  useEffect(() => {
    if (!hasProcessingBatches) return;
    const interval = setInterval(() => {
      router.reload({ only: ['fileBatches', 'gradeBatches', 'enrollments', 'activeCount', 'studentPerformance', 'stats'], preserveState: true });
    }, 5000);
    return () => clearInterval(interval);
  }, [hasProcessingBatches]);

  const getStatusBadge = (status) => {
    switch (status) {
      case 'completed':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3 h-3" /> Thành công</span>;
      case 'processing':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-blue-50 text-blue-700 border border-blue-200"><span className="w-1.5 h-1.5 rounded-full bg-blue-500 animate-ping"></span> Đang xử lý</span>;
      case 'failed':
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-rose-50 text-rose-700 border border-rose-200"><AlertTriangle className="w-3 h-3" /> Thất bại</span>;
      default:
        return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[10px] font-semibold bg-gray-50 text-gray-600 border border-gray-200"><RefreshCw className="w-3 h-3" /> Đang chờ</span>;
    }
  };

  const handleUpload = (e) => {
    e.preventDefault();
    if (!uploadFile) return;
    setIsImporting(true);
    const formData = new FormData();
    formData.append('file', uploadFile);
    router.post(route('manager.classes.enrollments.import', cc.uuid), formData, {
      onSuccess: () => { setIsImporting(false); setUploadFile(null); },
      onError: () => { setIsImporting(false); },
    });
  };

  const handleGradeUpload = (e) => {
    e.preventDefault();
    if (!gradeFile) return;
    setIsGradeImporting(true);
    const formData = new FormData();
    formData.append('file', gradeFile);
    router.post(route('manager.classes.grades.import', cc.uuid), formData, {
      onSuccess: () => { setIsGradeImporting(false); setGradeFile(null); },
      onError: () => { setIsGradeImporting(false); },
    });
  };

  // Phiếu điểm của lớp — đi qua đúng bộ nhập phiếu lớp; phiếu bổ sung chỉ cập nhật học viên có trong phiếu.
  const handleSheetUpload = (e) => {
    e.preventDefault();
    if (!sheetFile) return;
    const form = e.currentTarget;
    setIsSheetImporting(true);
    const formData = new FormData();
    formData.append('file', sheetFile);
    formData.append('bo_sung', boSung ? '1' : '0');
    router.post(route('manager.classes.sheet.import', cc.uuid), formData, {
      preserveScroll: true,
      onSuccess: () => { setSheetFile(null); setBoSung(false); form.reset(); },
      onFinish: () => setIsSheetImporting(false),
    });
  };

  const handleStatusChange = (enrollmentId, newStatus) => {
    setUpdatingId(enrollmentId);
    router.post(route('manager.enrollments.status', enrollmentId), { status: newStatus }, {
      preserveState: true,
      onFinish: () => setUpdatingId(null),
    });
  };

  const capacityPercent = cc.max_capacity > 0 ? Math.round((activeCount / cc.max_capacity) * 100) : 0;

  return (
    <DashboardLayout>
      <Head title={`${cc.class_code} - ${cc.course.name}`} />

      <div className="space-y-6">
        <div className="space-y-3">
          <Link
            href={route('manager.classes.index')}
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition"
          >
            <ArrowLeft className="w-4 h-4" />
            Quay lại danh sách lớp
          </Link>

          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
              <div className="flex items-center gap-3 mb-1">
                <h2 className="text-xl sm:text-2xl font-bold tracking-tight">{cc.class_code}</h2>
                <span className="text-sm text-muted-foreground">—</span>
                <h2 className="text-xl sm:text-2xl font-bold tracking-tight">{cc.course.name}</h2>
              </div>
              <p className="text-muted-foreground text-sm">
                {cc.course.code} • {cc.course.credits} tín chỉ • HK{cc.semester} {cc.academic_year} • {cc.cohort.name}
                {cc.cohort.program && ` • ${cc.cohort.program.name}`}
              </p>
              <p className="text-xs text-muted-foreground mt-1">
                Giảng viên: {cc.lecturer.full_name} • Đề cương: {cc.syllabus.version} • {cc.syllabus.clos.length} CLO • {cc.syllabus.assessments.length} thành phần đánh giá
              </p>
            </div>

            <Link
              href={route('manager.classes.psc.form', cc.uuid)}
              className="inline-flex h-9 items-center gap-1.5 rounded-md border border-border bg-card px-3 text-sm font-medium hover:bg-muted/50 shrink-0"
            >
              <FileSpreadsheet className="w-4 h-4" />
              Bảng điểm cho phần mềm đào tạo
            </Link>
          </div>
        </div>

        {flash && flash.success && (
          <div className="p-4 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <div className="text-sm font-medium">{flash.success}</div>
          </div>
        )}
        {flash && flash.error && (
          <div className="p-4 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
            <div className="text-sm font-medium">{flash.error}</div>
          </div>
        )}

        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <Users className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Sinh viên</p>
                  <div className="flex items-baseline gap-1.5 mt-0.5">
                    <span className="text-3xl font-bold tracking-tight">{stats.student_count}</span>
                  </div>
                  <span className="text-xs text-muted-foreground">{stats.passed_count ?? 0}/{stats.student_count} sinh viên đạt</span>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <BarChart3 className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Điểm TB</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className={`text-3xl font-bold tracking-tight ${getScoreColor(stats.avg_score)}`}>{stats.avg_score ?? '—'}</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <TrendingUp className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Điểm cao nhất</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight text-primary">{stats.max_score ?? '—'}</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <TrendingDown className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Điểm thấp nhất</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.min_score ?? '—'}</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Enrollment Management */}
        <Card className="shadow-none border-2 border-primary/20">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-semibold flex items-center gap-2">
              <UserCheck className="w-4 h-4 text-primary" />
              Quản lý Sinh viên trong lớp
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            {/* Capacity bar */}
            <div className="space-y-1.5">
              <div className="flex justify-between text-xs">
                <span className="font-medium text-muted-foreground">Sức chứa</span>
                <span className="font-semibold">{activeCount} / {cc.max_capacity} sinh viên đang học</span>
              </div>
              <div className="w-full bg-muted h-2 rounded-full overflow-hidden">
                <div
                  className={`h-full rounded-full transition-all ${capacityPercent >= 90 ? 'bg-rose-500' : capacityPercent >= 70 ? 'bg-amber-500' : 'bg-emerald-500'}`}
                  style={{ width: `${Math.min(100, capacityPercent)}%` }}
                />
              </div>
              <div className="text-[10px] text-muted-foreground">{capacityPercent}% sức chứa đã sử dụng</div>
            </div>

            {/* Import section — chỉ người vận hành */}
            {vanHanh && (
            <form onSubmit={handleUpload} className="flex flex-wrap items-end gap-3">
              <div className="space-y-1">
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                  Tải lên danh sách MSSV (.xlsx, .csv)
                </label>
                <input
                  type="file"
                  onChange={(e) => setUploadFile(e.target.files[0])}
                  required
                  className="text-xs border bg-background rounded-lg px-3 py-2 font-medium focus:outline-none focus:ring-2 focus:ring-ring w-full sm:w-72 file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1 file:text-xs file:font-medium file:text-primary hover:file:bg-primary/20 file:cursor-pointer cursor-pointer"
                />
              </div>
              <Button
                type="submit"
                disabled={isImporting || !uploadFile}
                size="sm"
                className="bg-primary hover:bg-primary/90"
              >
                {isImporting ? <span className="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
                <span className="ml-1">Tải lên</span>
              </Button>
              <p className="text-[11px] text-muted-foreground basis-full">
                File chỉ cần cột <b>MSSV</b> (dòng 1 là tên cột). Nhập phiếu lớp học phần thì sinh viên trong phiếu tự vào lớp, không cần file này.
              </p>
            </form>
            )}

            {/* Lịch sử và Tiến trình tải tệp tin gần đây */}
            {fileBatches && fileBatches.length > 0 && (
              <div className="space-y-2 pt-4 border-t">
                <div className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Lịch sử tải lên danh sách gần đây</div>
                <div className="divide-y max-w-2xl bg-muted/20 px-3 rounded-lg border">
                  {fileBatches.map(batch => (
                    <div key={batch.id} className="py-2.5 space-y-2">
                      <div className="flex justify-between items-center gap-4 text-xs font-medium text-muted-foreground">
                        <div className="flex items-center gap-2 truncate">
                          <FileSpreadsheet className="w-4 h-4 text-emerald-600 shrink-0" />
                          <div className="truncate">
                            <div className="font-bold text-foreground truncate">{batch.file_name ?? '(không tên)'}</div>
                            <div className="text-[9px] text-muted-foreground">
                              {batch.status === 'processing'
                                ? `Đã xử lý ${batch.processed_rows} dòng`
                                : `ID Batch: ${batch.id} • ${batch.created_at}`
                              }
                            </div>
                          </div>
                        </div>
                        <div className="flex items-center gap-3 shrink-0">
                          {getStatusBadge(batch.status)}
                        </div>
                      </div>
                      {batch.error_log && batch.error_log.length > 0 && (
                        <div className="ml-6">
                          <button
                            type="button"
                            onClick={() => setExpandedErrors(prev => ({ ...prev, [batch.id]: !prev[batch.id] }))}
                            className="text-[9px] font-bold text-rose-600 hover:text-rose-700 transition"
                          >
                            {expandedErrors[batch.id] ? 'Ẩn chi tiết lỗi' : `${batch.error_log.length} lỗi phát sinh`}
                          </button>
                          {expandedErrors[batch.id] && (
                            <div className="mt-1 p-2 bg-rose-50 rounded text-[10px] text-rose-700 space-y-0.5">
                              {batch.error_log.slice(0, 5).map((err, i) => (
                                <div key={i}>{err}</div>
                              ))}
                              {batch.error_log.length > 5 && (
                                <div className="text-rose-500 font-medium">... và {batch.error_log.length - 5} lỗi khác</div>
                              )}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}

            {/* Student list */}
            <div className="flex items-center justify-between">
              <div className="relative w-full sm:w-72">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted-foreground" />
                <Input
                  placeholder="Tìm kiếm theo MSSV, họ tên..."
                  value={enrollmentSearch}
                  onChange={(e) => setEnrollmentSearch(e.target.value)}
                  className="pl-9 h-10"
                />
              </div>
              <span className="text-xs text-muted-foreground font-medium">
                Hiển thị {filteredEnrollments.length}/{enrollments?.total ?? 0} sinh viên
              </span>
            </div>
            <div className="overflow-x-auto border rounded-lg">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">MSSV</th>
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Họ tên</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Ngày enroll</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thao tác</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {filteredEnrollments.map((enr) => {
                    const statusCfg = STATUS_CONFIG[enr.status] || STATUS_CONFIG.active;
                    const StatusIcon = statusCfg.icon;
                    return (
                      <tr key={enr.id} className="hover:bg-muted/30 transition-colors">
                        <td className="p-3 font-medium text-muted-foreground text-xs">{enr.student_code}</td>
                        <td className="p-3 font-semibold text-xs">
                          {vanHanh ? (
                            <Link
                              href={route('manager.students.show', enr.uuid)}
                              className="text-primary hover:underline font-bold"
                            >
                              {enr.full_name}
                            </Link>
                          ) : (
                            <span className="font-bold">{enr.full_name}</span>
                          )}
                        </td>
                        <td className="p-3 text-center text-xs text-muted-foreground">{enr.enrolled_at ?? '—'}</td>
                        <td className="p-3 text-center">
                          <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border ${statusCfg.color}`}>
                            <StatusIcon className="w-3 h-3" />
                            {statusCfg.label}
                          </span>
                        </td>
                        <td className="p-3 text-center">
                          <SearchableSelect
                            value={enr.status}
                            onValueChange={(val) => handleStatusChange(enr.id, val)}
                            disabled={! vanHanh || updatingId === enr.id}
                            className="h-8 text-[10px] w-[110px]"
                            options={[
                              { value: 'active', label: 'Đang học' },
                              { value: 'locked', label: 'Khóa' },
                              { value: 'suspended', label: 'Tạm dừng' },
                              { value: 'completed', label: 'Hoàn thành' },
                              { value: 'withdrawn', label: 'Rút học' },
                            ]}
                          />
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>

            {filteredEnrollments.length === 0 && (
              <div className="p-8 text-center text-muted-foreground text-sm">
                Chưa có sinh viên nào trong lớp. Tải lên file Excel để import danh sách.
              </div>
            )}

            <PaginationControls
              currentPage={enrollments?.current_page ?? 1}
              totalPages={enrollments?.last_page ?? 1}
              onPageChange={handleEnrollmentPageChange}
              totalItems={enrollments?.total ?? 0}
              perPage={20}
            />

            <div className="flex gap-4 text-xs text-muted-foreground pt-2 border-t">
              <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-emerald-500" /> Đang học: {enrollmentStatusCounts?.active ?? 0}</span>
              <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-rose-500" /> Khóa: {enrollmentStatusCounts?.locked ?? 0}</span>
              <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-amber-500" /> Tạm dừng: {enrollmentStatusCounts?.suspended ?? 0}</span>
              <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-blue-500" /> Hoàn thành: {enrollmentStatusCounts?.completed ?? 0}</span>
              <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-gray-500" /> Rút học: {enrollmentStatusCounts?.withdrawn ?? 0}</span>
            </div>
          </CardContent>
        </Card>

        {/* Grade Import */}
        <Card className="shadow-none border-2 border-primary/20">
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-semibold flex items-center gap-2">
              <FileSpreadsheet className="w-4 h-4 text-primary" />
              Nộp điểm của lớp
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <p className="text-sm text-muted-foreground max-w-2xl">
              Tải phiếu của lớp về, điền câu hỏi và điểm ở sheet từng bài rồi nộp lại. Học viên vắng thi thì ghi VT,
              đừng để trống. Nộp lại phiếu là thay điểm cũ của lớp, không cộng dồn.
            </p>
            <Button asChild size="sm" variant="outline" className="h-11 sm:h-9">
              <a href={route('manager.classes.sheet.template', cc.uuid)}>
                <Download className="w-4 h-4" />
                <span className="ml-1">Tải phiếu của lớp {cc.class_code}</span>
              </a>
            </Button>
            <form onSubmit={handleSheetUpload} className="space-y-3 max-w-2xl">
              <div className="flex flex-col sm:flex-row sm:items-end gap-3">
                <div className="space-y-1.5 w-full sm:w-80">
                  <label htmlFor="sheet-file" className="text-xs font-medium text-muted-foreground">Phiếu đã điền (.xlsx)</label>
                  <input
                    id="sheet-file"
                    type="file"
                    accept=".xlsx,.xls"
                    onChange={(e) => setSheetFile(e.target.files[0] ?? null)}
                    className="h-11 sm:h-9 w-full rounded-md border border-input text-base sm:text-sm file:mr-3 file:h-full file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                  />
                </div>
                <Button type="submit" size="sm" disabled={isSheetImporting || !sheetFile} className="h-11 sm:h-9">
                  {isSheetImporting
                    ? <span className="w-3 h-3 border-2 border-primary-foreground border-t-transparent rounded-full animate-spin" />
                    : <Upload className="w-4 h-4" />}
                  <span className="ml-1">{boSung ? 'Nộp phiếu bổ sung' : 'Nộp phiếu điểm'}</span>
                </Button>
              </div>
              <label htmlFor="bo-sung" className="flex items-start gap-2.5 min-h-11 sm:min-h-0 py-1 cursor-pointer select-none">
                <Checkbox id="bo-sung" checked={boSung} onCheckedChange={(v) => setBoSung(!!v)} className="mt-0.5" />
                <span className="text-sm leading-snug">
                  Phiếu bổ sung sau thi lại
                  <span className="block text-xs text-muted-foreground">
                    Chỉ cập nhật những học viên có điểm trong phiếu, điểm các bạn khác giữ nguyên. Chỉ cần sheet của bài thi lại.
                  </span>
                </span>
              </label>
            </form>
            <details className="text-xs">
              <summary className="cursor-pointer text-muted-foreground">Nhập file điểm định dạng cũ (chỉ dùng cho file trường đang có sẵn)</summary>
            <form onSubmit={handleGradeUpload} className="flex flex-wrap items-end gap-3 pt-3">
              <div className="space-y-1">
                <label className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">
                  Tải lên bảng điểm (.xlsx, .csv)
                </label>
                <input
                  type="file"
                  accept=".xlsx,.xls,.csv"
                  onChange={(e) => setGradeFile(e.target.files[0])}
                  required
                  className="text-base sm:text-xs border bg-background rounded-lg px-3 py-2 font-medium focus:outline-none focus:ring-2 focus:ring-ring w-full sm:w-72 file:mr-3 file:rounded-md file:border-0 file:bg-primary/10 file:px-3 file:py-1 file:text-xs file:font-medium file:text-primary hover:file:bg-primary/20 file:cursor-pointer cursor-pointer"
                />
              </div>
              <Button type="submit" disabled={isGradeImporting || !gradeFile} size="sm" variant="outline">
                {isGradeImporting ? <span className="w-3 h-3 border-2 border-primary border-t-transparent rounded-full animate-spin" /> : <Upload className="w-3.5 h-3.5" />}
                <span className="ml-1">Tải lên</span>
              </Button>
            </form>
            </details>

            {gradeBatches && gradeBatches.length > 0 && (
              <div className="space-y-2 pt-4 border-t">
                <div className="text-[10px] font-medium text-muted-foreground uppercase tracking-wider">Các lần nộp điểm gần đây</div>
                <div className="divide-y max-w-2xl bg-muted/20 px-3 rounded-lg border">
                  {gradeBatches.map(batch => (
                    <div key={batch.id} className="py-2.5 space-y-2">
                      <div className="flex justify-between items-center gap-4 text-xs font-medium text-muted-foreground">
                        <div className="flex items-center gap-2 truncate">
                          <FileSpreadsheet className="w-4 h-4 text-emerald-600 shrink-0" />
                          <div className="truncate">
                            <div className="font-bold text-foreground truncate">{batch.file_name ?? '(không tên)'}</div>
                            <div className="text-[9px] text-muted-foreground">
                              {batch.status === 'processing'
                                ? `Đã xử lý ${batch.processed_rows} dòng`
                                : `ID Batch: ${batch.id} • ${batch.created_at}`}
                            </div>
                          </div>
                        </div>
                        <div className="flex items-center gap-3 shrink-0">
                          {getStatusBadge(batch.status)}
                        </div>
                      </div>
                      {batch.error_log && batch.error_log.length > 0 && (
                        <div className="ml-6">
                          <button
                            type="button"
                            onClick={() => setExpandedErrors(prev => ({ ...prev, [`g${batch.id}`]: !prev[`g${batch.id}`] }))}
                            className="text-[9px] font-bold text-rose-600 hover:text-rose-700 transition"
                          >
                            {expandedErrors[`g${batch.id}`]
                              ? 'Ẩn chi tiết'
                              : batch.status === 'failed' ? `${batch.error_log.length} lỗi cần sửa` : `${batch.error_log.length} lưu ý`}
                          </button>
                          {expandedErrors[`g${batch.id}`] && (
                            <div className="mt-1 p-2 bg-rose-50 rounded text-[10px] text-rose-700 space-y-0.5">
                              {batch.error_log.slice(0, 30).map((err, i) => (
                                <div key={i}>{err}</div>
                              ))}
                              {batch.error_log.length > 30 && (
                                <div className="text-rose-500 font-medium">... và {batch.error_log.length - 30} dòng khác</div>
                              )}
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Charts Row */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <Card className="shadow-none">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold flex items-center gap-2">
                <BarChart3 className="w-4 h-4 text-primary" />
                Phân bố điểm lớp {cc.class_code}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {gradeDistribution.some(d => d.count > 0) ? (
                <div className="h-56">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={gradeDistribution} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                      <XAxis dataKey="range" tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                      <YAxis tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                      <Tooltip
                        contentStyle={{ backgroundColor: '#111827', borderRadius: '12px', border: 'none' }}
                        labelStyle={{ color: '#fff', fontWeight: 'bold' }}
                        itemStyle={{ color: '#818cf8' }}
                        formatter={(value) => [`${value} SV`, 'Số lượng']}
                      />
                      <Bar dataKey="count" radius={[6, 6, 0, 0]} maxBarSize={40}>
                        {gradeDistribution.map((entry, index) => (
                          <Cell key={`cell-${index}`} fill={GRADE_COLORS[index]} />
                        ))}
                        <LabelList dataKey="count" position="top" style={{ fontSize: 10, fill: '#6b7280' }} />
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <div className="h-56 flex items-center justify-center text-muted-foreground text-sm">Chưa có dữ liệu</div>
              )}
            </CardContent>
          </Card>

          {piAttainment && piAttainment.pis.length > 0 && (
            <Card className="shadow-none lg:col-span-2">
              <CardHeader className="pb-2">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0">
                    <CardTitle className="text-sm font-semibold flex items-center gap-2">
                      <Target className="w-4 h-4 text-primary" />
                      Mức đạt PI trong học phần
                    </CardTitle>
                    <p className="text-xs text-muted-foreground mt-1">
                      Ngưỡng đạt {piAttainment.pass_threshold} · mục tiêu {piAttainment.target_percent}% ·
                      nguồn đo: {piAttainment.is_question_scored ? 'điểm theo câu hỏi' : 'điểm theo bài đánh giá'}.
                      <b> Không cộng lại thành PI của chương trình.</b>
                    </p>
                  </div>
                  <a
                    href={route('manager.classes.export-pi', cc.uuid)}
                    className="inline-flex h-9 items-center gap-1 rounded-md border border-border bg-card px-3 text-sm font-medium hover:bg-muted/50 shrink-0"
                  >
                    <Download className="w-4 h-4" />
                    Xuất CSV
                  </a>
                </div>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-xs text-muted-foreground">
                      <th className="text-left py-2 pr-3 font-medium">PI</th>
                      <th className="text-left py-2 pr-3 font-medium">PLO</th>
                      <th className="text-left py-2 pr-3 font-medium">Mức</th>
                      <th
                        className="text-left py-2 pr-3 font-medium"
                        title="Tổng số học phần của chương trình có đo PI này. Học phần này là một trong số đó — % đạt ở đây là mức đạt TRONG học phần, không phải mức đạt PI của cả chương trình."
                      >
                        Học phần đo PI
                      </th>
                      <th className="text-left py-2 pr-3 font-medium">CLO đo</th>
                      <th className="text-right py-2 pr-3 font-medium">Sinh viên được đo</th>
                      <th className="text-right py-2 pr-3 font-medium">Đạt</th>
                      <th className="text-right py-2 pr-3 font-medium">% đạt</th>
                      <th className="text-right py-2 font-medium">Điểm TB</th>
                    </tr>
                  </thead>
                  <tbody>
                    {piAttainment.pis.map((pi) => (
                      <React.Fragment key={pi.pi_code}>
                        <tr className="border-b">
                          <td className="py-2 pr-3 font-medium">{pi.pi_code}</td>
                          <td className="py-2 pr-3 text-muted-foreground">{pi.plo_code}</td>
                          <td className="py-2 pr-3">{pi.mapping_level}</td>
                          {/* "1/3 học phần" nhắc GV rằng con số % bên phải là mức đạt TRONG học
                              phần này, chương trình còn 2 học phần khác cùng đo PI đó. */}
                          <td className="py-2 pr-3 text-muted-foreground tabular-nums">
                            {pi.program_courses ? `1/${pi.program_courses}` : '—'}
                          </td>
                          <td className="py-2 pr-3 text-muted-foreground">{pi.clo_codes}</td>
                          <td className="py-2 pr-3 text-right">{pi.students_measured}</td>
                          <td className="py-2 pr-3 text-right">{pi.passed}</td>
                          <td className={`py-2 pr-3 text-right font-semibold ${pi.met_target ? 'text-emerald-600' : 'text-amber-600'}`}>
                            {pi.attainment_percent === null ? '—' : `${pi.attainment_percent}%`}
                          </td>
                          <td className="py-2 text-right">{pi.avg_score ?? '—'}</td>
                        </tr>
                        {pi.assessments.map((a) => (
                          <tr key={`${pi.pi_code}-${a.assessment_name}`} className="border-b border-dashed text-xs text-muted-foreground">
                            <td className="py-1.5 pr-3"></td>
                            <td className="py-1.5 pr-3" colSpan={4}>
                              {a.assessment_name} · {a.category} · {a.weight_percent}%
                            </td>
                            <td className="py-1.5 pr-3 text-right">{a.students_sat}</td>
                            <td className="py-1.5 pr-3 text-right">{a.passed}</td>
                            <td className="py-1.5 pr-3 text-right">
                              {a.attainment_percent === null ? '—' : `${a.attainment_percent}%`}
                            </td>
                            <td className="py-1.5 text-right">{a.avg_score ?? '—'}</td>
                          </tr>
                        ))}
                      </React.Fragment>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          )}

          {/* Gộp mọi lớp cùng môn, cùng khóa — khách (buổi họp lần 1): "học phần có 1, 3, 5, 10, 20 lớp
              không biết — chỉ quan tâm học phần thôi". Chỉ hiện khi môn có từ 2 lớp trở lên. */}
          {courseRollup && courseRollup.pis.length > 0 && (
            <Card className="shadow-none lg:col-span-2">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                  <Target className="w-4 h-4 text-primary" />
                  Mức đạt PI của cả học phần ({courseRollup.classes.length} lớp)
                </CardTitle>
                <p className="text-xs text-muted-foreground mt-1">
                  Gộp các lớp cùng môn, cùng khóa: {courseRollup.classes.join(', ')}. % đạt = tổng sinh viên đạt / tổng sinh viên được đo của mọi lớp.
                </p>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-xs text-muted-foreground">
                      <th className="text-left py-2 pr-3 font-medium">PI</th>
                      <th className="text-left py-2 pr-3 font-medium">PLO</th>
                      <th className="text-right py-2 pr-3 font-medium">Số lớp có đo</th>
                      <th className="text-right py-2 pr-3 font-medium">Sinh viên được đo</th>
                      <th className="text-right py-2 pr-3 font-medium">Đạt</th>
                      <th className="text-right py-2 pr-3 font-medium">% đạt</th>
                      <th className="text-right py-2 font-medium">Điểm TB</th>
                    </tr>
                  </thead>
                  <tbody>
                    {courseRollup.pis.map((pi) => (
                      <tr key={pi.pi_code} className="border-b">
                        <td className="py-2 pr-3 font-medium">{pi.pi_code}</td>
                        <td className="py-2 pr-3 text-muted-foreground">{pi.plo_code}</td>
                        <td className="py-2 pr-3 text-right">{pi.classes}</td>
                        <td className="py-2 pr-3 text-right">{pi.students_measured}</td>
                        <td className="py-2 pr-3 text-right">{pi.passed}</td>
                        <td className={`py-2 pr-3 text-right font-semibold ${pi.met_target ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {pi.attainment_percent === null ? '—' : `${pi.attainment_percent}%`}
                        </td>
                        <td className="py-2 text-right">{pi.avg_score ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          )}

          {/* Tầng ngay dưới PI: PI hụt là vì CLO nào. Cùng ngưỡng, cùng cách đếm N với bảng trên.
              Phải chiếm TRỌN hàng như bảng PI — khối này nằm trong lưới 2 cột, để mặc định thì
              bảng bị ép vào nửa màn hình và 4 cột số (SV được đo · Đạt · % đạt · Điểm TB) bị cắt. */}
          {piAttainment && piAttainment.clos?.length > 0 && (
            <Card className="shadow-none lg:col-span-2">
              <CardHeader className="pb-2">
                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                  <Target className="w-4 h-4 text-primary" />
                  Mức đạt CLO trong học phần
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                  Ngưỡng đạt {piAttainment.pass_threshold} · mục tiêu {piAttainment.target_percent}% ·
                  {' '}“Sinh viên được đo” đếm sinh viên <strong>có điểm CLO đó</strong>, không phải sĩ số.
                </p>
              </CardHeader>
              <CardContent className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b text-xs text-muted-foreground">
                      <th className="text-left py-2 pr-3 font-medium">CLO</th>
                      <th className="text-left py-2 pr-3 font-medium">Nội dung</th>
                      <th className="text-left py-2 pr-3 font-medium">Đo PI</th>
                      <th className="text-left py-2 pr-3 font-medium">Mức</th>
                      <th className="text-right py-2 pr-3 font-medium">Sinh viên được đo</th>
                      <th className="text-right py-2 pr-3 font-medium">Đạt</th>
                      <th className="text-right py-2 pr-3 font-medium">% đạt</th>
                      <th className="text-right py-2 font-medium">Điểm TB</th>
                    </tr>
                  </thead>
                  <tbody>
                    {piAttainment.clos.map((clo) => (
                      <tr key={clo.clo_code} className="border-b">
                        <td className="py-2 pr-3 font-medium">{clo.clo_code}</td>
                        <td className="py-2 pr-3 text-muted-foreground max-w-md truncate" title={clo.clo_description}>
                          {clo.clo_description}
                        </td>
                        <td className="py-2 pr-3 text-muted-foreground">{clo.pi_codes || '—'}</td>
                        <td className="py-2 pr-3">{clo.mapping_level || '—'}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{clo.students_measured}</td>
                        <td className="py-2 pr-3 text-right tabular-nums">{clo.passed}</td>
                        <td className={`py-2 pr-3 text-right font-semibold tabular-nums ${clo.met_target ? 'text-emerald-600' : 'text-amber-600'}`}>
                          {clo.attainment_percent === null ? '—' : `${clo.attainment_percent}%`}
                        </td>
                        <td className="py-2 text-right tabular-nums">{clo.avg_score ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </CardContent>
            </Card>
          )}

          <Card className="shadow-none">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm font-semibold flex items-center gap-2">
                <Target className="w-4 h-4 text-primary" />
                Mức đạt CLO lớp {cc.class_code}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {cloAchievement.length > 0 ? (
                <div className="h-56">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={cloAchievement} layout="vertical" margin={{ top: 5, right: 40, left: 5, bottom: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="#f3f4f6" />
                      <XAxis type="number" domain={[0, 10]} tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                      <YAxis type="category" dataKey="code" tickLine={false} tick={{ fill: '#374151', fontSize: 11, fontWeight: 600 }} width={60} />
                      <Tooltip
                        contentStyle={{ backgroundColor: '#111827', borderRadius: '12px', border: 'none' }}
                        labelStyle={{ color: '#fff', fontWeight: 'bold' }}
                        itemStyle={{ color: '#818cf8' }}
                        formatter={(value, name, props) => [`${value} / 10`, props.payload.description || 'Điểm TB']}
                      />
                      <Bar dataKey="avg_score" radius={[0, 6, 6, 0]} maxBarSize={22} fill="#6366f1">
                        <LabelList dataKey="avg_score" position="right" style={{ fontSize: 10, fill: '#374151', fontWeight: 600 }} />
                      </Bar>
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              ) : (
                <div className="h-56 flex items-center justify-center text-muted-foreground text-sm">Chưa có dữ liệu CLO</div>
              )}
            </CardContent>
          </Card>
        </div>

        {/* CLO Matrix */}
        <Card className="shadow-none">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-semibold flex items-center gap-2">
              <Target className="w-4 h-4 text-primary" />
              Ma trận CLO từng sinh viên
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground mb-2 md:hidden">↔ Vuốt ngang để xem thêm cột</p>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground sticky left-0 bg-muted/50 z-10 border-r w-24">MSSV</th>
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground sticky left-24 bg-muted/50 z-10 border-r w-48">Họ tên</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground w-20">Kết quả</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground w-20" title="Điểm trung bình của môn, ghép từ các bài đánh giá theo trọng số của trường.">Điểm TB</th>
                    {cc.syllabus.clos.map((clo) => (
                      <th key={clo.id} className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground min-w-[80px]">
                        {clo.code}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {studentPerformance.map((sp) => (
                    <tr key={sp.id} className="hover:bg-muted/30 transition-colors">
                      <td className="p-3 font-medium text-muted-foreground sticky left-0 bg-card hover:bg-muted/30 z-10 border-r text-xs">{sp.student_code}</td>
                      <td className="p-3 font-medium sticky left-24 bg-card hover:bg-muted/30 z-10 border-r text-xs">{sp.full_name}</td>
                      <td className="p-3 text-center">
                        {sp.passed != null ? (
                          <span className={`inline-block whitespace-nowrap px-2 py-0.5 rounded text-xs font-bold ${
                            sp.passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                          }`}>
                            {sp.passed ? 'Đạt' : 'Không đạt'}
                          </span>
                        ) : (
                          <span className="text-muted-foreground text-xs">—</span>
                        )}
                      </td>
                      <td className="p-3 text-center">
                        <span className={`inline-block whitespace-nowrap px-2 py-0.5 rounded text-xs font-bold ${
                          sp.weighted_avg_score == null ? 'bg-muted text-muted-foreground' :
                          sp.weighted_avg_score >= 8 ? 'bg-emerald-50 text-emerald-700' :
                          sp.weighted_avg_score >= 6.5 ? 'bg-amber-50 text-amber-700' :
                          sp.weighted_avg_score >= 4 ? 'bg-blue-50 text-blue-700' :
                          'bg-rose-50 text-rose-700'
                        }`}>
                          {sp.weighted_avg_score ?? '—'}
                        </span>
                      </td>
                      {cc.syllabus.clos.map((clo) => {
                        const cloScore = sp.clo_scores.find(c => c.code === clo.code);
                        const score = cloScore?.score;
                        return (
                          <td key={clo.id} className="p-3 text-center">
                            {score != null ? (
                              <span className={`inline-block whitespace-nowrap px-2 py-0.5 rounded text-xs font-bold border ${getCloColor(score)}`}>{score}</span>
                            ) : (
                              <span className="text-muted-foreground text-xs">—</span>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {studentPerformance.length === 0 && (
              <div className="p-8 text-center text-muted-foreground text-sm">Chưa có dữ liệu sinh viên</div>
            )}
          </CardContent>
        </Card>

        {/* Assessment Grades Table */}
        <Card className="shadow-none">
          <CardHeader className="pb-2">
            <CardTitle className="text-sm font-semibold flex items-center gap-2">
              <ClipboardEdit className="w-4 h-4 text-primary" />
              Điểm theo từng thành phần đánh giá
            </CardTitle>
          </CardHeader>
          <CardContent>
            <p className="text-xs text-muted-foreground mb-2 md:hidden">↔ Vuốt ngang để xem thêm cột</p>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50">
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground sticky left-0 bg-muted/50 z-10 border-r w-24">MSSV</th>
                    <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground sticky left-24 bg-muted/50 z-10 border-r w-48">Họ tên</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground w-20" title="Điểm trung bình của môn, ghép từ các bài đánh giá theo trọng số của trường.">Điểm TB</th>
                    <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground w-20">Kết quả</th>
                    {cc.syllabus.assessments.map((assess) => (
                      <th key={assess.id} className="p-3 text-center font-semibold text-xs text-muted-foreground min-w-[100px]">
                        <div className="truncate max-w-[120px]" title={assess.name}>{assess.name}</div>
                        <div className="text-[9px] font-normal text-muted-foreground/70">{parseFloat(assess.weight_percent)}%</div>
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {studentPerformance.map((sp) => (
                    <tr key={sp.id} className="hover:bg-muted/30 transition-colors">
                      <td className="p-3 font-medium text-muted-foreground sticky left-0 bg-card hover:bg-muted/30 z-10 border-r text-xs">{sp.student_code}</td>
                      <td className="p-3 font-medium sticky left-24 bg-card hover:bg-muted/30 z-10 border-r text-xs">{sp.full_name}</td>
                      <td className="p-3 text-center">
                        <span className={`inline-block whitespace-nowrap px-2 py-0.5 rounded text-xs font-bold ${
                          sp.weighted_avg_score == null ? 'bg-muted text-muted-foreground' :
                          sp.weighted_avg_score >= 8 ? 'bg-emerald-50 text-emerald-700' :
                          sp.weighted_avg_score >= 6.5 ? 'bg-amber-50 text-amber-700' :
                          sp.weighted_avg_score >= 4 ? 'bg-blue-50 text-blue-700' :
                          'bg-rose-50 text-rose-700'
                        }`}>{sp.weighted_avg_score ?? '—'}</span>
                      </td>
                      <td className="p-3 text-center">
                        {sp.passed != null ? (
                          <span className={`inline-block whitespace-nowrap px-2 py-0.5 rounded text-xs font-bold ${
                            sp.passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                          }`}>
                            {sp.passed ? 'Đạt' : 'Không đạt'}
                          </span>
                        ) : (
                          <span className="text-muted-foreground text-xs">—</span>
                        )}
                      </td>
                      {cc.syllabus.assessments.map((assess) => {
                        const grade = sp.assessment_grades.find(g => g.assessment_id === assess.id);
                        const score = grade?.score;
                        return (
                          <td key={assess.id} className="p-3 text-center">
                            {score != null ? (
                              <span className={`text-xs font-bold ${score >= 8 ? 'text-emerald-600' : score >= 6.5 ? 'text-amber-600' : 'text-rose-600'}`}>{score}</span>
                            ) : (
                              <span className="text-muted-foreground text-xs">—</span>
                            )}
                          </td>
                        );
                      })}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
}
