import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router } from '@inertiajs/react';
import {
  ArrowLeft, User, BookOpen, Target, Award, TrendingUp,
  Phone, Mail, BarChart3, Star,
  ChevronDown, ChevronRight, Search, X, Download, CheckCircle, KeyRound
} from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import SearchableSelect from '@/components/SearchableSelect';
import { Button } from '@/components/ui/button';
import {
  Dialog, DialogContent, DialogDescription, DialogFooter,
  DialogHeader, DialogTitle, DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer } from 'recharts';
import { studentStatusMeta } from '@/lib/status';

function getScoreColor(score) {
  if (score == null) return 'text-muted-foreground';
  if (score >= 8) return 'text-emerald-600';
  if (score >= 6.5) return 'text-amber-600';
  return 'text-rose-600';
}

function getCloColor(score) {
  if (score == null) return 'bg-muted text-muted-foreground border-border';
  if (score >= 8) return 'bg-emerald-50 text-emerald-700 border-emerald-200';
  if (score >= 6.5) return 'bg-amber-50 text-amber-700 border-amber-200';
  return 'bg-rose-50 text-rose-700 border-rose-200';
}

/**
 * Một CỤM thành phần đánh giá — gộp đúng như CourseGradeFormula gộp để tính điểm.
 *
 * Ba bài giữa kỳ mỗi bài 13.33% hiện thành một dòng "Giữa kỳ 40%" kèm điểm trung bình; bấm vào
 * mới xổ ra từng bài. Ba dòng 13.33% rời rạc khiến người dùng tưởng hệ thống tính sai, dù
 * 13.33 × 3 = 40 và kết quả y hệt phép trung bình.
 */
function CumDiem({ nhom }) {
  const [mo, setMo] = useState(false);
  const nhieuBai = (nhom.items?.length ?? 0) > 1;

  return (
    <>
      <tr
        className={cn('hover:bg-muted/30', nhieuBai && 'cursor-pointer')}
        onClick={nhieuBai ? () => setMo(v => !v) : undefined}
      >
        <td className="p-2.5 text-sm">
          <span className="inline-flex items-center gap-1.5">
            {nhieuBai && (
              <ChevronRight className={cn('w-3.5 h-3.5 text-muted-foreground transition-transform', mo && 'rotate-90')} />
            )}
            {nhom.label}
          </span>
        </td>
        <td className="p-2.5 text-center text-xs text-muted-foreground">{parseFloat(nhom.weight_percent)}%</td>
        <td className="p-2.5 text-center">
          {nhom.score != null ? (
            <span className={cn('text-xs font-bold', getScoreColor(nhom.score))}>{nhom.score}</span>
          ) : (
            <span className="text-muted-foreground text-xs">—</span>
          )}
        </td>
      </tr>
      {mo && nhom.items.map(g => (
        <tr key={g.assessment_id} className="bg-muted/20 text-xs">
          <td className="py-1.5 pl-9 pr-2.5 text-muted-foreground">{g.assessment_name}</td>
          <td className="py-1.5 text-center text-muted-foreground">{parseFloat(g.weight_percent)}%</td>
          <td className="py-1.5 text-center">
            {g.score != null ? <span className={getScoreColor(g.score)}>{g.score}</span> : '—'}
          </td>
        </tr>
      ))}
    </>
  );
}

export default function Show({ student, classes, ploResults, piResults = [], obe = {}, progress = null, stats, canManage = false }) {
  // Ngưỡng đạt CLO / đạt môn khai được theo trường — KHÔNG hardcode 5 ở đây nữa, nếu không
  // màn hình sẽ tô xanh những CLO mà engine coi là chưa đạt.
  const nguongClo = Number(obe.clo_pass_mark ?? 5);
  const [activeTab, setActiveTab] = useState('grades');
  const [semesterFilter, setSemesterFilter] = useState('all');
  const [yearFilter, setYearFilter] = useState('all');
  const [searchQuery, setSearchQuery] = useState('');
  const [hoiCapMa, setHoiCapMa] = useState(false);
  const [sortKey, setSortKey] = useState('');
  const [expandedGroups, setExpandedGroups] = useState({});
  const [expandedPis, setExpandedPis] = useState({});

  const tabs = [
    { key: 'grades', label: 'Điểm theo lớp', icon: BookOpen },
    { key: 'clo', label: 'Kết quả CLO', icon: Target },
    { key: 'pi', label: 'Kết quả PI', icon: Star },
    { key: 'plo', label: 'Kết quả PLO', icon: Award },
  ];

  // Engine mặc định (WeightedAttainment) cho PI/PLO ở thang TỈ LỆ 0..1 → hiển thị bằng %.
  // Engine cũ (StandardAverage) cho thang điểm 10 → giữ nguyên số.
  const isRatio = obe.is_ratio ?? false;
  const threshold = Number(obe.threshold ?? 0.5);
  const fmtScore = (v) => (isRatio ? `${(Number(v) * 100).toFixed(1)}%` : Number(v).toFixed(2));
  const barWidth = (v) => Math.min(100, isRatio ? Number(v) * 100 : (Number(v) / 10) * 100);

  const pisByPlo = useMemo(() => {
    const grouped = new Map();
    for (const pi of piResults) {
      if (!grouped.has(pi.plo_code)) grouped.set(pi.plo_code, { plo_name: pi.plo_name, pis: [] });
      grouped.get(pi.plo_code).pis.push(pi);
    }
    return [...grouped.entries()];
  }, [piResults]);

  const yearOptions = useMemo(() =>
    [...new Set(classes.map(c => c.academic_year))].sort().reverse(),
    [classes]
  );

  const semesterOptions = useMemo(() =>
    [...new Set(classes.map(c => c.semester))].sort((a, b) => a - b),
    [classes]
  );

  const filteredClasses = useMemo(() => {
    let result = classes;

    if (semesterFilter !== 'all') {
      result = result.filter(c => String(c.semester) === semesterFilter);
    }
    if (yearFilter !== 'all') {
      result = result.filter(c => c.academic_year === yearFilter);
    }
    if (searchQuery.trim()) {
      const q = searchQuery.trim().toLowerCase();
      result = result.filter(c =>
        c.course_name.toLowerCase().includes(q) ||
        c.course_code.toLowerCase().includes(q) ||
        c.class_code.toLowerCase().includes(q) ||
        c.lecturer_name.toLowerCase().includes(q)
      );
    }

    if (sortKey === 'score_desc') {
      result = [...result].sort((a, b) => (b.avg_score ?? -1) - (a.avg_score ?? -1));
    } else if (sortKey === 'score_asc') {
      result = [...result].sort((a, b) => (a.avg_score ?? 999) - (b.avg_score ?? 999));
    } else if (sortKey === 'name_asc') {
      result = [...result].sort((a, b) => a.course_name.localeCompare(b.course_name));
    } else if (sortKey === 'semester_desc') {
      result = [...result].sort((a, b) => {
        if (a.academic_year !== b.academic_year) {
          return b.academic_year.localeCompare(a.academic_year);
        }
        return b.semester - a.semester;
      });
    }

    return result;
  }, [classes, semesterFilter, yearFilter, searchQuery, sortKey]);

  const hasActiveFilters = semesterFilter !== 'all' || yearFilter !== 'all' || searchQuery.trim() || sortKey !== '';

  const clearFilters = () => {
    setSemesterFilter('all');
    setYearFilter('all');
    setSearchQuery('');
    setSortKey('');
  };

  const groupedClasses = useMemo(() => {
    const groups = {};
    filteredClasses.forEach(cls => {
      const key = `HK${cls.semester} - ${cls.academic_year}`;
      if (!groups[key]) groups[key] = [];
      groups[key].push(cls);
    });
    return Object.entries(groups).sort(([a], [b]) => {
      const ha = a.replace('HK', '').split(' - ');
      const hb = b.replace('HK', '').split(' - ');
      if (ha[1] !== hb[1]) return hb[1].localeCompare(ha[1]);
      return parseInt(hb[0]) - parseInt(ha[0]);
    });
  }, [filteredClasses]);

  const trendData = useMemo(() => {
    const grouped = {};
    classes.forEach(cls => {
      const key = `${cls.semester}_${cls.academic_year}`;
      const label = `HK${cls.semester}\n${cls.academic_year}`;
      if (!grouped[key]) grouped[key] = { label, scores: [] };
      if (cls.weighted_avg_score !== null) {
        grouped[key].scores.push(cls.weighted_avg_score);
      }
    });
    return Object.values(grouped)
      .map(g => ({ label: g.label, avg: g.scores.length > 0 ? g.scores.reduce((a, b) => a + b) / g.scores.length : 0 }))
      .sort((a, b) => {
        const [aSem, aYear] = a.label.split('\n');
        const [bSem, bYear] = b.label.split('\n');
        return aYear.localeCompare(bYear) || aSem.localeCompare(bSem);
      });
  }, [classes]);

  const toggleGroup = (key) => {
    setExpandedGroups(prev => ({ ...prev, [key]: !prev[key] }));
  };

  const renderFilterBar = () => (
    <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3 bg-card p-4 rounded-xl border shadow-none">
      <SearchableSelect
        value={semesterFilter}
        onValueChange={setSemesterFilter}
        className="w-full sm:w-[130px] h-9 text-xs"
        placeholder="HK"
        options={[
          { value: 'all', label: 'HK: Tất cả' },
          ...semesterOptions.map(s => ({ value: String(s), label: `HK${s}` })),
        ]}
      />

      <SearchableSelect
        value={yearFilter}
        onValueChange={setYearFilter}
        className="w-full sm:w-[160px] h-9 text-xs"
        placeholder="Năm học"
        options={[
          { value: 'all', label: 'Năm: Tất cả' },
          ...yearOptions.map(y => ({ value: y, label: y })),
        ]}
      />

      <div className="relative w-full sm:grow sm:max-w-[240px]">
        <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-muted-foreground" />
        <Input
          placeholder="Tìm môn học, giảng viên..."
          value={searchQuery}
          onChange={(e) => setSearchQuery(e.target.value)}
          className="pl-8 h-9 text-xs"
        />
      </div>

      <SearchableSelect
        value={sortKey}
        onValueChange={setSortKey}
        className="w-full sm:w-[150px] h-9 text-xs"
        placeholder="Mặc định"
        options={[
          { value: '', label: 'Mặc định' },
          { value: 'semester_desc', label: 'Học kỳ (mới → cũ)' },
          { value: 'score_desc', label: 'Điểm TB (cao → thấp)' },
          { value: 'score_asc', label: 'Điểm TB (thấp → cao)' },
          { value: 'name_asc', label: 'Tên môn (A → Z)' },
        ]}
      />

      <div className="text-xs text-muted-foreground font-medium bg-muted px-2.5 py-1 rounded-lg">
        {filteredClasses.length} / {classes.length} lớp
      </div>

      {/* Hệ số trường đang áp — không nói ra thì người xem phải tự đoán vì sao ĐTB ra con số đó. */}
      {obe.grading_note && (
        <div className="text-xs text-muted-foreground w-full sm:w-auto sm:ml-auto">
          {obe.grading_note}
        </div>
      )}

      {hasActiveFilters && (
        <Button variant="ghost" size="sm" onClick={clearFilters} className="h-9 text-xs gap-1">
          <X className="w-3.5 h-3.5" />
          Xoá bộ lọc
        </Button>
      )}
    </div>
  );

  const renderClassCard = (cls) => (
    <Card key={cls.uuid} className="shadow-none border-border/80">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold flex items-center justify-between">
          <div className="flex flex-col gap-1 min-w-0">
            <div className="flex items-center gap-2">
              <span className="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded shrink-0">
                {cls.class_code}
              </span>
              <span className="truncate">{cls.course_name}</span>
              {!hasActiveFilters && (
                <span className="text-xs text-muted-foreground font-normal shrink-0">
                  ({cls.course_code}) • {cls.credits} tín chỉ
                </span>
              )}
            </div>
            <span className="text-xs text-muted-foreground font-normal">
              Giảng viên: <span className="font-semibold text-foreground">{cls.lecturer_name}</span>
            </span>
          </div>
          <div className="flex items-center gap-2 shrink-0 ml-2">
            <span className={cn('text-sm font-bold', getScoreColor(cls.weighted_avg_score))}>
              Điểm TB: {cls.weighted_avg_score ?? '—'}
            </span>
            {cls.passed ? (
              <span className="inline-flex items-center whitespace-nowrap gap-0.5 text-xs font-medium text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded">
                <CheckCircle className="w-3 h-3" />
                Đạt
              </span>
            ) : (
              <span className="inline-flex items-center whitespace-nowrap gap-0.5 text-xs font-medium text-rose-700 bg-rose-50 px-1.5 py-0.5 rounded">
                Không đạt
              </span>
            )}
          </div>
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b bg-muted/50">
                <th className="p-2.5 text-left font-semibold text-xs text-muted-foreground">Thành phần đánh giá</th>
                <th className="p-2.5 text-center font-semibold text-xs text-muted-foreground">Trọng số</th>
                <th className="p-2.5 text-center font-semibold text-xs text-muted-foreground">Điểm</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {(cls.grade_groups ?? []).map((nhom, i) => (
                <CumDiem key={i} nhom={nhom} />
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex justify-end mt-3">
          <Link
            href={route('manager.classes.show', cls.uuid)}
            className="text-xs font-medium text-primary hover:underline"
          >
            Xem chi tiết lớp học →
          </Link>
        </div>
      </CardContent>
    </Card>
  );

  return (
    <DashboardLayout>
      <Head title={`${student.full_name} - Chi tiết sinh viên (Manager)`} />

      <div className="space-y-6">
        <div className="space-y-3">
          <Link
            href={route('manager.classes.index')}
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground transition"
          >
            <ArrowLeft className="w-4 h-4" />
            Quay lại quản lý lớp
          </Link>

          <div className="flex flex-col sm:flex-row sm:flex-wrap justify-between items-start sm:items-center gap-4">
            <div className="flex items-center gap-4 min-w-0">
              <div className="flex items-center justify-center size-14 rounded-xl bg-primary/10 text-primary shrink-0">
                <User className="size-7" />
              </div>
              <div>
                <div className="flex items-center gap-3 mb-0.5">
                  <h2 className="text-xl sm:text-2xl font-bold tracking-tight">{student.full_name}</h2>
                  {student.status && (
                    <span className={cn(
                      'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border',
                      studentStatusMeta(student.status).className
                    )}>
                      {studentStatusMeta(student.status).label}
                    </span>
                  )}
                </div>
                <p className="text-muted-foreground text-sm">
                  MSSV: <span className="font-semibold text-foreground">{student.student_code}</span>
                  {' • '}{student.program?.name ?? '—'}
                  {' • Niên khóa '}{student.cohort?.name ?? '—'}
                  {student.cohort?.start_year ? ` (${student.cohort.start_year})` : ''}
                </p>
                <p className="text-xs text-muted-foreground mt-1 flex flex-wrap items-center gap-3">
                  {student.email && (
                    <span className="inline-flex items-center gap-1">
                      <Mail className="w-3.5 h-3.5" />
                      {student.email}
                    </span>
                  )}
                  {student.phone && (
                    <span className="inline-flex items-center gap-1">
                      <Phone className="w-3.5 h-3.5" />
                      {student.phone}
                    </span>
                  )}
                  {student.gender && <span>{student.gender === 'male' ? 'Nam' : 'Nữ'}</span>}
                  {student.date_of_birth && <span>• {student.date_of_birth}</span>}
                </p>
              </div>
            </div>

            {/* Cả hai nút đều chạm tới MÃ TRA CỨU (nút xuất phiếu in mã lên giấy), nên chỉ người
                vận hành thấy. Ẩn ở đây chỉ cho gọn mắt — chặn thật nằm ở route + middleware. */}
            {canManage && (
            <div className="flex items-center gap-2 shrink-0">
            {/* Mã tra cứu: học viên dùng để tự xem kết quả tại /ket-qua-hoc-tap, in sẵn trên phiếu.
                Hỏi lại bằng Dialog của shadcn, KHÔNG dùng confirm() gốc — confirm() chặn toàn bộ
                sự kiện trang và không theo được style của hệ thống. */}
            <Dialog open={hoiCapMa} onOpenChange={setHoiCapMa}>
              <DialogTrigger asChild>
                <Button
                  variant="outline"
                  className="gap-2"
                  title={student.lookup_code ? `Mã hiện tại: ${student.lookup_code}` : 'Chưa cấp mã'}
                >
                  <KeyRound className="w-4 h-4" />
                  {student.lookup_code ? `Mã: ${student.lookup_code}` : 'Cấp mã tra cứu'}
                </Button>
              </DialogTrigger>
              <DialogContent>
                <DialogHeader>
                  <DialogTitle>
                    {student.lookup_code ? 'Cấp lại mã tra cứu?' : 'Cấp mã tra cứu?'}
                  </DialogTitle>
                  {/* Lời cảnh báo phải khác nhau: lần đầu thì chẳng có mã cũ nào để mất hiệu lực. */}
                  <DialogDescription>
                    {student.lookup_code
                      ? `Mã hiện tại (${student.lookup_code}) sẽ NGỪNG dùng được ngay. Học viên chỉ tra cứu được sau khi nhận mã mới, nên hãy xuất lại phiếu để phát.`
                      : 'Hệ thống sẽ sinh mã riêng cho học viên này. Mã được in trên phiếu kết quả để học viên tự tra tại trang tra cứu.'}
                  </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                  <Button variant="outline" onClick={() => setHoiCapMa(false)}>Hủy</Button>
                  <Button
                    onClick={() => {
                      router.put(route('manager.students.lookup-code', student.uuid), {}, {
                        preserveScroll: true,
                        onFinish: () => setHoiCapMa(false),
                      });
                    }}
                  >
                    {student.lookup_code ? 'Cấp lại mã' : 'Cấp mã'}
                  </Button>
                </DialogFooter>
              </DialogContent>
            </Dialog>

            <a
              href={route('manager.students.export-plo', student.uuid)}
              className="flex items-center gap-2 border border-border bg-card hover:bg-muted/50 text-foreground px-4 py-2 rounded-lg text-sm font-medium transition shrink-0"
            >
              <Download className="w-4 h-4" />
              Xuất CSV PLO + PI
            </a>
            </div>
            )}
          </div>
        </div>

        {/* 4 cột CHỈ từ 1280px: khung nội dung = bề ngang màn hình trừ sidebar ~256px, nên ở laptop
            1024px bốn thẻ không đủ chỗ và thẻ cuối bị đẩy ra khỏi màn hình. Nhãn viết đầy đủ
            ("Điểm trung bình tích lũy") cần rộng hơn nhãn viết tắt cũ ("GPA"). */}
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
          <Card className="shadow-none min-w-0">
            <CardContent className="p-4 sm:p-6 flex items-center gap-3 sm:gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <BookOpen className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Tổng lớp đã học</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.total_classes}</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none min-w-0">
            <CardContent className="p-4 sm:p-6 flex items-center gap-3 sm:gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <TrendingUp className="size-6" />
              </div>
              <div className="min-w-0">
                {/* KHÔNG ghi "GPA": khách hỏi thẳng "GPA là viết tắt của gì". Ở Việt Nam GPA còn
                    hay bị hiểu là thang 4, trong khi số này là thang 10 và đã nhân tín chỉ. */}
                <p className="text-sm font-medium text-muted-foreground">Điểm trung bình tích lũy</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className={`text-3xl font-bold tracking-tight ${getScoreColor(stats.weighted_gpa)}`}>
                    {stats.weighted_gpa ?? '—'}
                  </span>
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">
                  thang 10 · đã nhân tín chỉ · {stats.total_credits} tín chỉ đã học
                </p>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none min-w-0">
            <CardContent className="p-4 sm:p-6 flex items-center gap-3 sm:gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <CheckCircle className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Đạt</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.passed_count}</span>
                  <span className="text-base font-medium text-muted-foreground">/{stats.total_classes}</span>
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">lớp</p>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none min-w-0">
            <CardContent className="p-4 sm:p-6 flex items-center gap-3 sm:gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <Award className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Chuẩn đầu ra đạt</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.plo_achieved}</span>
                  <span className="text-base font-medium text-muted-foreground">/{stats.plo_total}</span>
                </div>
                <p className="text-xs text-muted-foreground mt-0.5">PLO của chương trình đào tạo</p>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Tiến độ tích lũy — cùng nguồn số với báo cáo khóa (StudentProgressService) */}
        {progress && (
          <Card className="shadow-none">
            <CardContent className="p-6">
              <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="min-w-0">
                  <h3 className="text-sm font-semibold flex items-center gap-2">
                    <TrendingUp className="w-4 h-4 text-primary" />
                    Tiến độ tích lũy
                  </h3>
                  <p className="text-xs text-muted-foreground mt-1">
                    Chỉ môn <b>đạt</b> (≥ {Number(progress.pass_mark).toFixed(1)}) mới được cộng tín chỉ.
                    Môn rớt vẫn được đo chuẩn đầu ra.
                  </p>
                </div>

                {progress.required_credits ? (
                  <span
                    className={cn(
                      'shrink-0 rounded-full px-3 py-1 text-xs font-medium',
                      progress.is_eligible
                        ? 'bg-emerald-50 text-emerald-700'
                        : 'bg-amber-50 text-amber-700'
                    )}
                  >
                    {progress.is_eligible
                      ? 'Đủ điều kiện gộp thống kê khóa'
                      : `Chưa đủ tích lũy (< ${progress.min_completion_percent}%) — tách khỏi TB khóa`}
                  </span>
                ) : (
                  <span className="shrink-0 rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground">
                    Ngành chưa khai số tín chỉ tốt nghiệp
                  </span>
                )}
              </div>

              <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                  <p className="text-xs text-muted-foreground">Tín chỉ tích lũy</p>
                  <p className="text-2xl font-bold tracking-tight">
                    {progress.credits_earned}
                    {progress.required_credits && (
                      <span className="text-base font-medium text-muted-foreground">
                        /{progress.required_credits}
                      </span>
                    )}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Tín chỉ đã học</p>
                  <p className="text-2xl font-bold tracking-tight">{progress.credits_attempted}</p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">Môn đạt</p>
                  <p className="text-2xl font-bold tracking-tight">
                    {progress.courses_passed}
                    <span className="text-base font-medium text-muted-foreground">
                      /{progress.courses_graded}
                    </span>
                  </p>
                </div>
                <div>
                  <p className="text-xs text-muted-foreground">% hoàn thành</p>
                  <p className="text-2xl font-bold tracking-tight">
                    {progress.completion_percent === null ? '—' : `${progress.completion_percent}%`}
                  </p>
                </div>
              </div>

              {progress.completion_percent !== null && (
                <div className="mt-4 h-2 w-full rounded-full bg-muted overflow-hidden">
                  <div
                    className={cn(
                      'h-full rounded-full',
                      progress.is_eligible ? 'bg-emerald-500' : 'bg-amber-500'
                    )}
                    style={{ width: `${Math.min(100, progress.completion_percent)}%` }}
                  />
                </div>
              )}
            </CardContent>
          </Card>
        )}

        {/* Trend Chart */}
        <Card className="shadow-none">
          <CardContent className="p-6">
            <h3 className="text-sm font-semibold mb-4 flex items-center gap-2">
              <TrendingUp className="w-4 h-4 text-primary" />
              Xu hướng điểm theo học kỳ
            </h3>
            {trendData.length > 0 ? (
              <ResponsiveContainer width="100%" height={200}>
                <LineChart data={trendData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                  <YAxis domain={[0, 10]} tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Line type="monotone" dataKey="avg" stroke="#6366f1" strokeWidth={2} dot={{ r: 4 }} />
                </LineChart>
              </ResponsiveContainer>
            ) : (
              <p className="text-sm text-muted-foreground">Chưa có dữ liệu</p>
            )}
          </CardContent>
        </Card>

        {/* Tabs */}
        <div className="flex border-b overflow-x-auto">
          {tabs.map(tab => (
            <button
              key={tab.key}
              onClick={() => setActiveTab(tab.key)}
              className={cn(
                'flex shrink-0 whitespace-nowrap items-center gap-2 px-3 sm:px-5 py-3 text-sm font-medium border-b-2 transition',
                activeTab === tab.key
                  ? 'border-primary text-primary'
                  : 'border-transparent text-muted-foreground hover:text-foreground'
              )}
            >
              <tab.icon className="w-4 h-4" />
              {tab.label}
            </button>
          ))}
        </div>

        {/* Filter Bar (Grades + CLO tabs only) */}
        {!['plo', 'pi'].includes(activeTab) && classes.length > 0 && renderFilterBar()}

        {/* Tab: Grades */}
        {activeTab === 'grades' && (
          <div className="space-y-3">
            {classes.length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Sinh viên chưa tham gia lớp học phần nào.
                </CardContent>
              </Card>
            ) : filteredClasses.length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Không tìm thấy lớp học phần nào khớp với điều kiện lọc.
                </CardContent>
              </Card>
            ) : hasActiveFilters ? (
              <div className="space-y-3">
                {filteredClasses.map(cls => renderClassCard(cls))}
              </div>
            ) : (
              groupedClasses.map(([groupKey, groupClasses]) => {
                const isExpanded = expandedGroups[groupKey] !== false;
                return (
                  <div key={groupKey} className="space-y-1">
                    <button
                      onClick={() => toggleGroup(groupKey)}
                      className="flex items-center gap-2 w-full text-left px-4 py-2.5 rounded-lg bg-muted/50 hover:bg-muted transition text-sm font-semibold text-foreground"
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-4 h-4 text-muted-foreground" />
                      ) : (
                        <ChevronRight className="w-4 h-4 text-muted-foreground" />
                      )}
                      <span>{groupKey}</span>
                      <span className="text-xs text-muted-foreground font-normal">
                        ({groupClasses.length} lớp)
                      </span>
                    </button>
                    {isExpanded && (
                      <div className="space-y-3 pl-6">
                        {groupClasses.map(cls => renderClassCard(cls))}
                      </div>
                    )}
                  </div>
                );
              })
            )}
          </div>
        )}

        {/* Tab: CLO */}
        {activeTab === 'clo' && (
          <div className="space-y-3">
            {classes.filter(c => c.clo_results.length > 0).length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Chưa có kết quả CLO nào được tính toán.
                </CardContent>
              </Card>
            ) : filteredClasses.filter(c => c.clo_results.length > 0).length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Không tìm thấy lớp học phần nào khớp với điều kiện lọc.
                </CardContent>
              </Card>
            ) : hasActiveFilters ? (
              <div className="space-y-3">
                {filteredClasses.filter(c => c.clo_results.length > 0).map(cls => (
                  <Card key={cls.uuid} className="shadow-none border-border/80">
                    <CardHeader className="pb-3">
                      <CardTitle className="text-sm font-semibold flex items-center justify-between">
                        <div className="flex flex-col gap-1 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
                              {cls.class_code}
                            </span>
                            <span>{cls.course_name}</span>
                          </div>
                          <span className="text-xs text-muted-foreground font-normal">
                            Giảng viên: <span className="font-semibold text-foreground">{cls.lecturer_name}</span> • HK{cls.semester} {cls.academic_year}
                          </span>
                        </div>
                        <span className="text-xs text-muted-foreground shrink-0 ml-2">
                          {cls.clo_achieved_count}/{cls.clo_total} CLO đạt
                        </span>
                      </CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        {cls.clo_results.map((clo, i) => (
                          <div key={i} className="p-3 bg-muted rounded-lg border space-y-2">
                            <div className="flex items-center justify-between">
                              <span className="text-xs font-bold">{clo.clo_code}</span>
                              <span className={cn(
                                'text-xs font-bold px-1.5 py-0.5 rounded',
                                clo.achieved_score >= nguongClo ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                              )}>
                                {clo.achieved_score}
                              </span>
                            </div>
                            <p className="text-[10px] text-muted-foreground line-clamp-2">{clo.clo_description}</p>
                            <div className="w-full bg-border h-1.5 rounded-full overflow-hidden">
                              <div
                                className={cn('h-full rounded-full', clo.achieved_score >= nguongClo ? 'bg-emerald-500' : 'bg-rose-500')}
                                style={{ width: `${Math.min(100, (clo.achieved_score / 10) * 100)}%` }}
                              />
                            </div>
                          </div>
                        ))}
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>
            ) : (
              groupedClasses.filter(([, gc]) => gc.some(c => c.clo_results.length > 0)).map(([groupKey, groupClasses]) => {
                const isExpanded = expandedGroups[groupKey] !== false;
                const classesWithClo = groupClasses.filter(c => c.clo_results.length > 0);
                if (classesWithClo.length === 0) return null;
                return (
                  <div key={groupKey} className="space-y-1">
                    <button
                      onClick={() => toggleGroup(groupKey)}
                      className="flex items-center gap-2 w-full text-left px-4 py-2.5 rounded-lg bg-muted/50 hover:bg-muted transition text-sm font-semibold text-foreground"
                    >
                      {isExpanded ? (
                        <ChevronDown className="w-4 h-4 text-muted-foreground" />
                      ) : (
                        <ChevronRight className="w-4 h-4 text-muted-foreground" />
                      )}
                      <span>{groupKey}</span>
                      <span className="text-xs text-muted-foreground font-normal">
                        ({classesWithClo.length} lớp)
                      </span>
                    </button>
                    {isExpanded && (
                      <div className="space-y-3 pl-6">
                        {classesWithClo.map(cls => (
                          <Card key={cls.uuid} className="shadow-none border-border/80">
                            <CardHeader className="pb-3">
                              <CardTitle className="text-sm font-semibold flex items-center justify-between">
                                <div className="flex flex-col gap-1 min-w-0">
                                  <div className="flex items-center gap-2">
                                    <span className="text-xs font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded">
                                      {cls.class_code}
                                    </span>
                                    <span>{cls.course_name}</span>
                                  </div>
                                  <span className="text-xs text-muted-foreground font-normal">
                                    Giảng viên: <span className="font-semibold text-foreground">{cls.lecturer_name}</span>
                                  </span>
                                </div>
                                <span className="text-xs text-muted-foreground shrink-0 ml-2">
                                  {cls.clo_achieved_count}/{cls.clo_total} CLO đạt
                                </span>
                              </CardTitle>
                            </CardHeader>
                            <CardContent>
                              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                {cls.clo_results.map((clo, i) => (
                                  <div key={i} className="p-3 bg-muted rounded-lg border space-y-2">
                                    <div className="flex items-center justify-between">
                                      <span className="text-xs font-bold">{clo.clo_code}</span>
                                      <span className={cn(
                                        'text-xs font-bold px-1.5 py-0.5 rounded',
                                        clo.achieved_score >= nguongClo ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                                      )}>
                                        {clo.achieved_score}
                                      </span>
                                    </div>
                                    <p className="text-[10px] text-muted-foreground line-clamp-2">{clo.clo_description}</p>
                                    <div className="w-full bg-border h-1.5 rounded-full overflow-hidden">
                                      <div
                                        className={cn('h-full rounded-full', clo.achieved_score >= nguongClo ? 'bg-emerald-500' : 'bg-rose-500')}
                                        style={{ width: `${Math.min(100, (clo.achieved_score / 10) * 100)}%` }}
                                      />
                                    </div>
                                  </div>
                                ))}
                              </div>
                            </CardContent>
                          </Card>
                        ))}
                      </div>
                    )}
                  </div>
                );
              })
            )}
          </div>
        )}

        {/* Tab: PI — chỉ số thực hiện, kèm giải trình "đo ở môn nào" */}
        {activeTab === 'pi' && (
          <div className="space-y-4">
            {piResults.length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Chưa có kết quả PI nào. PI chỉ ra số khi sinh viên đã có điểm ở các môn có ánh xạ CLO → PI.
                </CardContent>
              </Card>
            ) : (
              <>
                <p className="text-xs text-muted-foreground">
                  Đạt <b>{stats.pi_achieved}/{stats.pi_total}</b> PI (ngưỡng <b>{fmtScore(threshold)}</b>).
                  PI gộp qua <b>nhiều môn</b>: mỗi môn đóng góp theo <b>tín chỉ × mức I/R/M/A</b> — nên PI
                  không thuộc về một lớp nào cả. Bấm vào một PI để xem chính xác nó được đo ở những môn nào.
                </p>

                {pisByPlo.map(([ploCode, group]) => (
                  <Card key={ploCode} className="shadow-none">
                    <CardHeader className="pb-3">
                      <CardTitle className="text-sm font-semibold">
                        {ploCode} <span className="font-normal text-muted-foreground">— {group.plo_name}</span>
                      </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y p-0">
                      {group.pis.map(pi => {
                        const open = expandedPis[pi.pi_code] ?? false;
                        return (
                          <div key={pi.pi_code}>
                            <button
                              type="button"
                              onClick={() => setExpandedPis(s => ({ ...s, [pi.pi_code]: !open }))}
                              className="w-full flex items-center gap-3 px-4 py-3 text-left hover:bg-muted/50 transition"
                            >
                              {open ? <ChevronDown className="w-4 h-4 shrink-0 text-muted-foreground" />
                                    : <ChevronRight className="w-4 h-4 shrink-0 text-muted-foreground" />}
                              <div className="min-w-0 flex-1">
                                <div className="text-sm font-semibold flex items-center gap-2">
                                  {pi.pi_code}
                                  {/* Độ phủ: PI này đo qua mấy học phần trong lộ trình riêng của
                                      học viên, đã đo được mấy. Không có mẫu số thì 80% ở học kỳ 1
                                      và 80% lúc tốt nghiệp trông y hệt nhau. */}
                                  {pi.courses_planned > 0 && (
                                    <span
                                      title={`Tiêu chí này được đo qua ${pi.courses_planned} học phần trong lộ trình của học viên; đã đo được ${pi.courses_done}.`}
                                      className={cn(
                                        'rounded px-1.5 py-0.5 text-[10px] font-medium tabular-nums',
                                        pi.courses_done >= pi.courses_planned
                                          ? 'bg-emerald-50 text-emerald-700'
                                          : 'bg-sky-50 text-sky-700',
                                      )}
                                    >
                                      {pi.courses_done}/{pi.courses_planned} học phần
                                    </span>
                                  )}
                                </div>
                                {pi.pi_description && (
                                  <p className="text-[11px] text-muted-foreground line-clamp-1">{pi.pi_description}</p>
                                )}
                              </div>
                              <div className="flex items-center gap-2 shrink-0">
                                <div className="w-28 bg-muted h-2 rounded-full overflow-hidden">
                                  <div
                                    className={cn('h-full rounded-full', pi.passed ? 'bg-blue-600' : 'bg-rose-500')}
                                    style={{ width: `${barWidth(pi.achieved_score)}%` }}
                                  />
                                </div>
                                <span className="text-xs font-bold text-muted-foreground w-14 text-right">
                                  {fmtScore(pi.achieved_score)}
                                </span>
                                <span className={cn(
                                  'text-xs font-bold px-2 py-0.5 rounded-full',
                                  pi.passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                                )}>
                                  {pi.passed ? 'Đạt' : 'Chưa đạt'}
                                </span>
                              </div>
                            </button>

                            {open && (
                              <div className="px-4 pb-4 pl-11">
                                <div className="rounded-lg border overflow-x-auto">
                                  <table className="w-full text-xs">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                      <tr>
                                        <th className="text-left font-medium px-3 py-2">Môn đóng góp</th>
                                        <th className="text-center font-medium px-3 py-2">Tín chỉ</th>
                                        <th className="text-center font-medium px-3 py-2">Mức</th>
                                        <th className="text-center font-medium px-3 py-2">Điểm CLO</th>
                                        <th className="text-left font-medium px-3 py-2">CLO đo PI này</th>
                                      </tr>
                                    </thead>
                                    <tbody className="divide-y">
                                      {pi.courses.map(c => (
                                        <tr key={c.course_code}>
                                          <td className="px-3 py-2">
                                            <span className="font-semibold">{c.course_code}</span>
                                            <span className="text-muted-foreground"> — {c.course_name}</span>
                                          </td>
                                          <td className="text-center px-3 py-2">{c.credits}</td>
                                          <td className="text-center px-3 py-2">
                                            <span className="font-bold px-1.5 py-0.5 rounded bg-muted">{c.level}</span>
                                          </td>
                                          <td className={cn('text-center px-3 py-2 font-bold',
                                            c.course_score >= nguongClo ? 'text-emerald-700' : 'text-rose-700')}>
                                            {Number(c.course_score).toFixed(2)}
                                          </td>
                                          <td className="px-3 py-2 text-muted-foreground">
                                            {c.clos.map(x => `${x.clo_code} (${Number(x.score).toFixed(2)})`).join(' · ')}
                                          </td>
                                        </tr>
                                      ))}
                                    </tbody>
                                  </table>
                                </div>
                                <p className="text-[11px] text-muted-foreground mt-2">
                                  Điểm CLO thang 10. PI = Σ(tín chỉ × mức × điểm CLO) / Σ(tín chỉ × mức × 10) — môn tín chỉ
                                  cao và mức cao (M/A) kéo PI mạnh hơn.
                                </p>
                              </div>
                            )}
                          </div>
                        );
                      })}
                    </CardContent>
                  </Card>
                ))}
              </>
            )}
          </div>
        )}

        {/* Tab: PLO */}
        {activeTab === 'plo' && (
          <div className="space-y-4">
            {ploResults.length === 0 ? (
              <Card className="shadow-none">
                <CardContent className="p-12 text-center text-muted-foreground text-sm">
                  Chưa có kết quả PLO nào.
                </CardContent>
              </Card>
            ) : (
              <Card className="shadow-none">
                <CardHeader>
                  <CardTitle className="text-sm font-semibold">Kết quả chuẩn đầu ra chương trình (PLO) - Quản lý</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="divide-y">
                    {ploResults.map((r, i) => (
                      <div key={i} className="py-4 flex items-center justify-between gap-4">
                        <div className="min-w-0">
                          <div className="text-sm font-semibold">{r.plo.code} - {r.plo.name}</div>
                        </div>
                        <div className="flex items-center gap-4 shrink-0">
                          <div className="flex items-center gap-2">
                            <div className="w-32 bg-muted h-2 rounded-full overflow-hidden">
                              <div
                                className={cn('h-full rounded-full', r.passed ? 'bg-blue-600' : 'bg-rose-500')}
                                style={{ width: `${barWidth(r.achieved_score)}%` }}
                              />
                            </div>
                            <span className="text-xs font-bold text-muted-foreground w-14 text-right">
                              {fmtScore(r.achieved_score)}
                            </span>
                          </div>
                          <span className={cn(
                            'text-xs font-bold px-2 py-0.5 rounded-full',
                            r.passed ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
                          )}>
                            {r.passed ? 'Đạt' : 'Chưa đạt'}
                          </span>
                        </div>
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>
            )}
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
