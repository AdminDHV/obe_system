import React, { useState, useCallback } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import HelpSheet from '@/components/HelpSheet';
import { Head, router, usePage, Link } from '@inertiajs/react';
import {
  Users, BookOpen, GraduationCap, Award, FileSpreadsheet,
  Download, RefreshCw, Upload, CheckCircle2, AlertTriangle,
  HelpCircle, FileText, Building2, TrendingUp, Calendar,
  ListOrdered, UserCheck, UserX, Loader2,
  Circle, MinusCircle, ChevronDown, ArrowRight, ClipboardList,
  Boxes, Sparkles
} from 'lucide-react';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell,
  LineChart, Line
} from 'recharts';
import SearchableSelect from '@/components/SearchableSelect';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Collapsible, CollapsibleTrigger, CollapsibleContent,
} from '@/components/ui/collapsible';

const COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4', '#3b82f6', '#8b5cf6', '#d946ef'];
const DIST_COLORS = ['#ef4444', '#f97316', '#eab308', '#22c55e', '#06b6d4'];

export default function Index({
  auth,
  stats,
  gradeDistribution,
  ploAverages,
  cloAverages,
  trendData,
  classTableStats,
  lecturerWorkload,
  programs,
  cohorts,
  semesterOptions,
  yearOptions,
  currentFilters,
  workflow,
}) {
  const { flash } = usePage().props;

  const [semesterFilter, setSemesterFilter] = useState(currentFilters.semester || '');
  const [yearFilter, setYearFilter] = useState(currentFilters.academic_year || '');
  const [programFilter, setProgramFilter] = useState(currentFilters.program_id || '');
  const [cohortFilter, setCohortFilter] = useState(currentFilters.cohort_id || '');

  const handleFilterChange = useCallback(() => {
    router.get(route('manager.dashboard.index'), {
      ...currentFilters,
      semester: semesterFilter || null,
      academic_year: yearFilter || null,
      program_id: programFilter || null,
      cohort_id: cohortFilter || null,
    }, { preserveState: true, replace: true });
  }, [semesterFilter, yearFilter, programFilter, cohortFilter, currentFilters]);

  // "Trường đang làm việc" do bộ chọn CHUNG ở header (WorkingSchoolSwitcher) quyết định —
  // Dashboard không còn selector riêng; server đã scope theo trường đang chọn.

  const hasPlos = ploAverages.length > 0;
  const hasClos = cloAverages.length > 0;
  const hasTrend = trendData.length > 0;
  const hasGrades = gradeDistribution.some(d => d.count > 0);

  // ── Operator command-center workflow ──────────────────────────────
  const wf = workflow ?? {
    structure: { programs: 0, courses: 0 },
    grades: { classes: 0, graded: 0, ungraded: 0 },
    measurement: { last_run_at: null, source: null },
    reports: { total: 0, completed: 0 },
  };
  const reportsBase = route('manager.reports.index');
  const sid = currentFilters.school_id;
  // Deep-link sang hub kèm Trường đang làm việc để hub mở đúng trường (không reset về trường đầu).
  const stepHref = (n) =>
    `${reportsBase}?step=${n}${sid != null && sid !== '' ? `&school_id=${sid}` : ''}`;
  const showWelcome = (wf.structure?.programs ?? 0) === 0;

  const STATUS = {
    done: { icon: CheckCircle2, cls: 'text-emerald-600', ring: 'ring-emerald-200', badge: 'bg-emerald-100 text-emerald-700' },
    warning: { icon: AlertTriangle, cls: 'text-amber-500', ring: 'ring-amber-200', badge: 'bg-amber-100 text-amber-700' },
    empty: { icon: Circle, cls: 'text-muted-foreground/50', ring: 'ring-border', badge: 'bg-muted text-muted-foreground' },
  };

  const steps = [
    (() => {
      const p = wf.structure?.programs ?? 0;
      const c = wf.structure?.courses ?? 0;
      return {
        n: 1, icon: Boxes, title: 'Khung CTĐT',
        status: p > 0 ? 'done' : 'empty',
        line: `${p} ngành · ${c} môn`,
        cta: 'Dựng / sửa khung', href: stepHref(1),
      };
    })(),
    (() => {
      const cls = wf.grades?.classes ?? 0;
      const graded = wf.grades?.graded ?? 0;
      const ungraded = wf.grades?.ungraded ?? 0;
      let status = 'empty';
      if (cls > 0 && ungraded === 0) status = 'done';
      else if (ungraded > 0) status = 'warning';
      return {
        n: 2, icon: ClipboardList, title: 'Nhập điểm',
        status,
        line: `${graded}/${cls} lớp có điểm${ungraded > 0 ? ` (còn ${ungraded} lớp)` : ''}`,
        cta: 'Nhập điểm', href: stepHref(2),
      };
    })(),
    (() => {
      const last = wf.measurement?.last_run_at ?? null;
      return {
        n: 3, icon: RefreshCw, title: 'Đo lường',
        status: last ? 'done' : 'empty',
        line: last ? `Lần cuối: ${last}` : 'Chưa đo lần nào',
        cta: 'Đo / kiểm tra', href: stepHref(3),
      };
    })(),
    (() => {
      const total = wf.reports?.total ?? 0;
      const completed = wf.reports?.completed ?? 0;
      return {
        n: 4, icon: FileText, title: 'Báo cáo',
        status: completed > 0 ? 'done' : 'empty',
        line: `${completed}/${total} báo cáo`,
        cta: 'Tạo báo cáo', href: stepHref(4),
      };
    })(),
  ];

  const StatCard = ({ icon: Icon, label, value, variant }) => {
    const isWarning = variant === 'warning';
    const isPositive = variant === 'positive';
    const iconBg = isWarning ? 'bg-amber-100 text-amber-600' : isPositive ? 'bg-emerald-100 text-emerald-600' : 'bg-primary/10 text-primary';
    return (
      <div className="bg-card p-4 rounded-xl border shadow-none">
        <div className="flex items-center gap-3">
          <div className={`flex items-center justify-center size-10 rounded-lg ${iconBg} shrink-0`}>
            <Icon className="size-5" />
          </div>
          <div className="min-w-0">
            <p className="text-xs font-medium text-muted-foreground truncate">{label}</p>
            <p className="text-xl font-bold tracking-tight">{value ?? '—'}</p>
          </div>
        </div>
      </div>
    );
  };

  return (
    <DashboardLayout>
      <Head title="Bảng điều khiển" />

      <div className="space-y-6">
        {/* Header — bộ chọn "Trường đang làm việc" nằm ở header chung (WorkingSchoolSwitcher). */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Bảng điều khiển</h2>
            <p className="text-muted-foreground text-sm">
              Trung tâm vận hành — đo lường chuẩn đầu ra theo từng bước
            </p>
            <div className="mt-2">
              <HelpSheet />
            </div>
          </div>
        </div>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        {/* Welcome card — only when no structure yet */}
        {showWelcome && (
          <Card className="border-primary/30 bg-primary/5 shadow-none">
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-lg">
                <Sparkles className="size-5 text-primary" />
                Chào mừng! Bắt đầu đo lường chuẩn đầu ra
              </CardTitle>
              <CardDescription>
                Hệ thống vận hành theo 4 bước. Bắt đầu bằng việc dựng khung chương trình đào tạo.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
                <div className="flex items-center gap-2"><Badge className="bg-primary/10 text-primary border-transparent">1</Badge> Dựng khung CTĐT (ngành, môn, CLO/PLO)</div>
                <div className="flex items-center gap-2"><Badge className="bg-primary/10 text-primary border-transparent">2</Badge> Nhập điểm các lớp học phần</div>
                <div className="flex items-center gap-2"><Badge className="bg-primary/10 text-primary border-transparent">3</Badge> Chạy đo lường PI / PLO</div>
                <div className="flex items-center gap-2"><Badge className="bg-primary/10 text-primary border-transparent">4</Badge> Xuất báo cáo AUN-QA</div>
              </div>
              <Button asChild>
                <Link href={stepHref(1)}>
                  Bắt đầu — Dựng khung <ArrowRight className="size-4" />
                </Link>
              </Button>
            </CardContent>
          </Card>
        )}

        {/* Tiến trình đo lường — 4 step cards */}
        <div className="space-y-3">
          <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
            <ListOrdered className="size-4" /> Tiến trình đo lường
          </h3>
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {steps.map((step) => {
              const sc = STATUS[step.status];
              const SIcon = sc.icon;
              const StepIcon = step.icon;
              return (
                <Card key={step.n} className={`shadow-none ring-1 ${sc.ring}`}>
                  <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <span className={`flex items-center justify-center size-7 rounded-md text-sm font-bold ${sc.badge}`}>
                          {step.n}
                        </span>
                        <StepIcon className="size-4 text-muted-foreground" />
                      </div>
                      <SIcon className={`size-5 ${sc.cls}`} />
                    </div>
                    <CardTitle className="text-base pt-2">{step.title}</CardTitle>
                  </CardHeader>
                  <CardContent className="space-y-3">
                    <p className="text-xs text-muted-foreground min-h-[2rem]">{step.line}</p>
                    <Button asChild size="sm" className="w-full">
                      <Link href={step.href}>
                        {step.cta} <ArrowRight className="size-3.5" />
                      </Link>
                    </Button>
                  </CardContent>
                </Card>
              );
            })}
          </div>
        </div>


        {/* ── Chi tiết số liệu & phân tích (mở sẵn) ── */}
        <Collapsible defaultOpen={true} className="space-y-6">
          <CollapsibleTrigger className="group flex items-center gap-2 w-full text-left rounded-lg border bg-card px-4 py-3 hover:bg-muted/50 transition-colors">
            <TrendingUp className="size-4 text-muted-foreground" />
            <span className="text-sm font-semibold">Số liệu &amp; phân tích chi tiết</span>
            <ChevronDown className="size-4 ml-auto text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-6">

        {/* Filter bar (drives charts/tables below) */}
        <div className="flex flex-col sm:flex-row sm:flex-wrap gap-2">
          <SearchableSelect
            value={semesterFilter}
            onValueChange={v => { setSemesterFilter(v); setTimeout(handleFilterChange, 0); }}
            className="w-full sm:w-[130px] h-8 text-xs"
            placeholder="Học kỳ"
            options={[
              { value: '', label: 'Tất cả HK' },
              ...semesterOptions.map(s => ({ value: String(s), label: `HK ${s}` })),
            ]}
          />
          <SearchableSelect
            value={yearFilter}
            onValueChange={v => { setYearFilter(v); setTimeout(handleFilterChange, 0); }}
            className="w-full sm:w-[140px] h-8 text-xs"
            placeholder="Năm học"
            options={[
              { value: '', label: 'Tất cả năm' },
              ...yearOptions.map(y => ({ value: y, label: y })),
            ]}
          />
          <SearchableSelect
            value={programFilter}
            onValueChange={v => { setProgramFilter(v); setTimeout(handleFilterChange, 0); }}
            className="w-full sm:w-[160px] h-8 text-xs"
            placeholder="Ngành"
            options={[
              { value: '', label: 'Tất cả ngành' },
              ...programs.map(p => ({ value: String(p.id), label: p.name })),
            ]}
          />
          <SearchableSelect
            value={cohortFilter}
            onValueChange={v => { setCohortFilter(v); setTimeout(handleFilterChange, 0); }}
            className="w-full sm:w-[130px] h-8 text-xs"
            placeholder="Khóa"
            options={[
              { value: '', label: 'Tất cả khóa' },
              ...cohorts.map(c => ({ value: String(c.id), label: c.name })),
            ]}
          />
        </div>

        {/* KPI Cards */}
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
          <StatCard icon={Users} label="Sinh viên" value={stats.total_students} />
          <StatCard icon={GraduationCap} label="Giảng viên" value={stats.total_lecturers} />
          <StatCard icon={Building2} label="Khoa" value={stats.total_departments} />
          <StatCard icon={BookOpen} label="Chương trình" value={stats.total_programs} />
          <StatCard icon={Award} label="Lớp HP" value={stats.total_classes} />
          <StatCard icon={Calendar} label="Khóa học" value={stats.total_cohorts} />
          <StatCard icon={TrendingUp} label="Điểm TB" value={stats.avg_score} variant="positive" />
          <StatCard icon={UserCheck} label="Đã chấm điểm" value={stats.total_graded_students} />
          <StatCard icon={UserX} label="SV yếu (&#60;5)" value={stats.at_risk_count} variant="warning" />
          <StatCard icon={AlertTriangle} label="Lớp chưa nhập điểm" value={stats.ungraded_class_count} variant="warning" />
        </div>

        {/* Charts */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Grade Distribution */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Phân bố điểm</h3>
            {hasGrades ? (
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={gradeDistribution} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                    <XAxis dataKey="range" tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                    <YAxis tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                    <Tooltip
                      contentStyle={{ backgroundColor: '#111827', borderRadius: '8px', border: 'none', fontSize: '12px' }}
                      labelStyle={{ color: '#fff', fontWeight: 600 }}
                      itemStyle={{ color: '#e2e8f0' }}
                      formatter={(value) => [value, 'Số lượng']}
                    />
                    <Bar dataKey="count" radius={[6, 6, 0, 0]} maxBarSize={50}>
                      {gradeDistribution.map((_, i) => (
                        <Cell key={i} fill={DIST_COLORS[i % DIST_COLORS.length]} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            ) : (
              <div className="h-64 flex flex-col items-center justify-center border border-dashed rounded-lg p-6 text-center bg-muted/30">
                <HelpCircle className="w-10 h-10 text-muted-foreground/50 mb-2" />
                <p className="text-sm text-muted-foreground">Chưa có dữ liệu điểm số</p>
              </div>
            )}
          </div>

          {/* PLO Averages */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">PLO Trung bình</h3>
            {hasPlos ? (
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={ploAverages} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                    <XAxis dataKey="code" tickLine={false} tick={{ fill: '#6b7280', fontSize: 11, fontWeight: 600 }} />
                    <YAxis domain={[0, 10]} tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                    <Tooltip
                      contentStyle={{ backgroundColor: '#111827', borderRadius: '8px', border: 'none', fontSize: '12px' }}
                      labelStyle={{ color: '#fff', fontWeight: 600 }}
                      itemStyle={{ color: '#818cf8' }}
                      formatter={(value) => [`${value} / 10`, 'Điểm TB']}
                    />
                    <Bar dataKey="score" radius={[6, 6, 0, 0]} maxBarSize={40}>
                      {ploAverages.map((_, i) => (
                        <Cell key={i} fill={COLORS[i % COLORS.length]} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            ) : (
              <div className="h-64 flex flex-col items-center justify-center border border-dashed rounded-lg p-6 text-center bg-muted/30">
                <HelpCircle className="w-10 h-10 text-muted-foreground/50 mb-2" />
                <p className="text-sm text-muted-foreground">Chọn Ngành + Khóa để xem PLO</p>
              </div>
            )}
          </div>

          {/* CLO Averages */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">CLO Trung bình</h3>
            {hasClos ? (
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={cloAverages} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                    <XAxis dataKey="code" tickLine={false} tick={{ fill: '#6b7280', fontSize: 11, fontWeight: 600 }} />
                    <YAxis domain={[0, 10]} tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                    <Tooltip
                      contentStyle={{ backgroundColor: '#111827', borderRadius: '8px', border: 'none', fontSize: '12px' }}
                      labelStyle={{ color: '#fff', fontWeight: 600 }}
                      itemStyle={{ color: '#22c55e' }}
                      formatter={(value) => [`${value} / 10`, 'Điểm TB']}
                    />
                    <Bar dataKey="avg_score" radius={[6, 6, 0, 0]} maxBarSize={40}>
                      {cloAverages.map((_, i) => (
                        <Cell key={i} fill={COLORS[i % COLORS.length]} />
                      ))}
                    </Bar>
                  </BarChart>
                </ResponsiveContainer>
              </div>
            ) : (
              <div className="h-64 flex flex-col items-center justify-center border border-dashed rounded-lg p-6 text-center bg-muted/30">
                <HelpCircle className="w-10 h-10 text-muted-foreground/50 mb-2" />
                <p className="text-sm text-muted-foreground">Chưa có dữ liệu CLO</p>
              </div>
            )}
          </div>

          {/* Trend */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Xu hướng điểm theo học kỳ</h3>
            {hasTrend ? (
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <LineChart data={trendData} margin={{ top: 5, right: 10, left: -15, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="#f3f4f6" />
                    <XAxis dataKey="semester" tickLine={false} tick={{ fill: '#6b7280', fontSize: 10 }} />
                    <YAxis domain={[0, 10]} tickLine={false} tick={{ fill: '#6b7280', fontSize: 11 }} />
                    <Tooltip
                      contentStyle={{ backgroundColor: '#111827', borderRadius: '8px', border: 'none', fontSize: '12px' }}
                      labelStyle={{ color: '#fff', fontWeight: 600 }}
                      itemStyle={{ color: '#3b82f6' }}
                      formatter={(value) => [`${value} / 10`, 'Điểm TB']}
                    />
                    <Line type="monotone" dataKey="avg_score" stroke="#3b82f6" strokeWidth={2} dot={{ fill: '#3b82f6', r: 4 }} />
                  </LineChart>
                </ResponsiveContainer>
              </div>
            ) : (
              <div className="h-64 flex flex-col items-center justify-center border border-dashed rounded-lg p-6 text-center bg-muted/30">
                <HelpCircle className="w-10 h-10 text-muted-foreground/50 mb-2" />
                <p className="text-sm text-muted-foreground">Chưa có dữ liệu theo học kỳ</p>
              </div>
            )}
          </div>
        </div>

        {/* Tables */}
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          {/* Class List */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Lớp học phần (Top 10)</h3>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b text-muted-foreground uppercase tracking-wider">
                    <th className="text-left py-2 pr-2 font-semibold">Lớp</th>
                    <th className="text-left py-2 pr-2 font-semibold">Môn</th>
                    <th className="text-left py-2 pr-2 font-semibold">GV</th>
                    <th className="text-center py-2 pr-2 font-semibold">SV</th>
                    <th className="text-center py-2 pr-2 font-semibold">Điểm TB</th>
                    <th className="text-right py-2 font-semibold">Tiến độ</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {classTableStats.map(c => (
                    <tr key={c.uuid} className="hover:bg-muted/50 transition-colors">
                      <td className="py-2 pr-2 font-medium whitespace-nowrap">
                        <a href={route('manager.classes.show', c.uuid)} className="text-primary hover:underline">
                          {c.class_code}
                        </a>
                      </td>
                      <td className="py-2 pr-2 text-muted-foreground max-w-[140px] truncate" title={c.course_name}>
                        {c.course_name}
                      </td>
                      <td className="py-2 pr-2 text-muted-foreground whitespace-nowrap">{c.lecturer_name}</td>
                      <td className="py-2 pr-2 text-center">{c.student_count}</td>
                      <td className="py-2 pr-2 text-center font-medium">
                        {c.avg_score !== null ? (
                          <span className={c.avg_score >= 5 ? 'text-emerald-600' : 'text-red-600'}>
                            {c.avg_score}
                          </span>
                        ) : '—'}
                      </td>
                      <td className="py-2 text-right">
                        <div className="flex items-center justify-end gap-2">
                          <div className="w-16 bg-gray-200 rounded-full h-1.5">
                            <div
                              className={`h-1.5 rounded-full ${c.completion_rate >= 80 ? 'bg-emerald-500' : c.completion_rate >= 50 ? 'bg-amber-500' : 'bg-red-500'}`}
                              style={{ width: `${c.completion_rate}%` }}
                            />
                          </div>
                          <span className="text-muted-foreground w-8 text-right">{c.completion_rate}%</span>
                        </div>
                      </td>
                    </tr>
                  ))}
                  {classTableStats.length === 0 && (
                    <tr><td colSpan={6} className="text-center py-8 text-muted-foreground">Không có lớp học phần</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>

          {/* Lecturer Workload */}
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Khối lượng giảng viên (Top 10)</h3>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b text-muted-foreground uppercase tracking-wider">
                    <th className="text-left py-2 pr-2 font-semibold">GV</th>
                    <th className="text-left py-2 pr-2 font-semibold">Chức vụ</th>
                    <th className="text-center py-2 pr-2 font-semibold">Lớp</th>
                    <th className="text-center py-2 pr-2 font-semibold">SV</th>
                    <th className="text-right py-2 font-semibold">Điểm TB</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {lecturerWorkload.map(l => (
                    <tr key={l.uuid} className="hover:bg-muted/50 transition-colors">
                      <td className="py-2 pr-2 font-medium whitespace-nowrap">{l.full_name}</td>
                      <td className="py-2 pr-2 text-muted-foreground">{l.position ?? '—'}</td>
                      <td className="py-2 pr-2 text-center font-medium">{l.class_count}</td>
                      <td className="py-2 pr-2 text-center">{l.total_students}</td>
                      <td className="py-2 text-right font-medium">
                        {l.avg_score !== null ? (
                          <span className={l.avg_score >= 5 ? 'text-emerald-600' : 'text-red-600'}>
                            {l.avg_score}
                          </span>
                        ) : '—'}
                      </td>
                    </tr>
                  ))}
                  {lecturerWorkload.length === 0 && (
                    <tr><td colSpan={5} className="text-center py-8 text-muted-foreground">Không có dữ liệu</td></tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>

        {/* Warnings */}
        {(stats.ungraded_class_count > 0 || stats.at_risk_count > 0) && (
          <div className="bg-card p-5 rounded-xl border shadow-none space-y-3">
            <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
              <AlertTriangle className="w-4 h-4 text-amber-500" /> Cảnh báo
            </h3>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              {stats.ungraded_class_count > 0 && (
                <div className="flex items-start gap-3 p-3 rounded-lg bg-amber-50 border border-amber-200">
                  <AlertTriangle className="w-5 h-5 text-amber-500 shrink-0 mt-0.5" />
                  <div>
                    <p className="text-sm font-semibold text-amber-800">Lớp chưa nhập điểm</p>
                    <p className="text-xs text-amber-700">
                      {stats.ungraded_class_count} lớp chưa có dữ liệu điểm số — cần nhập điểm gấp.
                    </p>
                  </div>
                </div>
              )}
              {stats.at_risk_count > 0 && (
                <div className="flex items-start gap-3 p-3 rounded-lg bg-red-50 border border-red-200">
                  <UserX className="w-5 h-5 text-red-500 shrink-0 mt-0.5" />
                  <div>
                    <p className="text-sm font-semibold text-red-800">Sinh viên yếu</p>
                    <p className="text-xs text-red-700">
                      {stats.at_risk_count} sinh viên có điểm TB &lt; 5 — cần theo dõi và hỗ trợ.
                    </p>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

          </CollapsibleContent>
        </Collapsible>

      </div>
    </DashboardLayout>
  );
}
