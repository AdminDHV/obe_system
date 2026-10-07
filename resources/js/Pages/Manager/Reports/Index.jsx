import React, { useEffect, useRef, useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage, Link } from '@inertiajs/react';
import { FileText, RefreshCw, Download, CheckCircle2, AlertTriangle, Loader2, Upload, ClipboardCheck, ClipboardList, XCircle, ShieldCheck, ExternalLink, Database, Building2, BookOpen, ChevronDown, FileSpreadsheet
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';
import PaginationControls from '@/components/PaginationControls';
import Stepper from '@/components/Stepper';
import HelpSheet from '@/components/HelpSheet';
import InfoHint from '@/components/InfoHint';
import { STEPS } from '@/config/guideContent';
import FilePreview from '@/components/FilePreview';
import { GRADE_SCHEMAS, STRUCTURE_SHEETS, SURVEY_SCHEMA, STUDENT_ROSTER_SCHEMA } from '@/config/importSchemas';
import PloTargetsEditor from '@/Features/Reports/PloTargetsEditor';
import CqiPanel from '@/Features/Reports/CqiPanel';
import AttainmentChart from '@/Features/Reports/AttainmentChart';

// Khối "Hướng dẫn bước này" — gập/mở, lấy nội dung từ guideContent.STEPS[step-1].
function StepHelp({ data }) {
  if (!data) return null;
  return (
    <Collapsible defaultOpen={false} className="rounded-lg border border-primary/20 bg-primary/5">
      <CollapsibleTrigger className="group flex w-full items-center gap-2 px-3 py-2 text-left text-sm font-medium text-foreground">
        <BookOpen className="w-4 h-4 text-primary shrink-0" />
        <span>Hướng dẫn bước này</span>
        <ChevronDown className="w-4 h-4 ml-auto text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
      </CollapsibleTrigger>
      <CollapsibleContent className="px-3 pb-3 pt-0 space-y-2.5 text-sm text-muted-foreground">
        <p><span className="font-medium text-foreground">Mục đích:</span> {data.purpose}</p>
        {data.actions?.length > 0 && (
          <div>
            <p className="font-medium text-foreground">Thao tác:</p>
            <ol className="list-decimal list-inside space-y-0.5 mt-0.5">
              {data.actions.map((a, i) => <li key={i}>{a}</li>)}
            </ol>
          </div>
        )}
        {data.errors?.length > 0 && (
          <div>
            <p className="font-medium text-foreground">Lỗi thường gặp:</p>
            <ul className="list-disc list-inside space-y-0.5 mt-0.5">
              {data.errors.map((e, i) => <li key={i}><b>{e.when}</b> → {e.fix}</li>)}
            </ul>
          </div>
        )}
        <Link href={route('manager.guide.index')} className="inline-flex items-center gap-1 text-primary hover:underline font-medium">
          Xem hướng dẫn đầy đủ →
        </Link>
      </CollapsibleContent>
    </Collapsible>
  );
}

function StatusBadge({ status }) {
  if (status === 'generated' || status === 'completed') {
    return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><CheckCircle2 className="w-3 h-3" /> Hoàn thành</span>;
  }
  if (status === 'processing') {
    return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 border border-blue-200"><span className="w-1.5 h-1.5 rounded-full bg-blue-500 animate-ping" /> Đang xử lý</span>;
  }
  if (status === 'failed') {
    return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-rose-50 text-rose-700 border border-rose-200"><AlertTriangle className="w-3 h-3" /> Thất bại</span>;
  }
  return <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-[11px] font-semibold bg-gray-50 text-gray-600 border border-gray-200"><RefreshCw className="w-3 h-3" /> Đang chờ</span>;
}

// 1 box nhập điểm cho 1 phương án (PA1/PA2/PA3 hoặc "điểm tổng→PI/PLO"). Tự định tuyến theo trường.
// PHIẾU LỚP HỌC PHẦN — file thứ hai (và cuối cùng) trường cần điền, theo buổi họp lần 1 với khách:
// "một lớp học phần mình nhập một phiếu là đủ… khai báo một lần rồi cho cục điểm chấm vô là chạy".
// Chọn ngành/khóa/môn để tải phiếu ĐIỀN SẴN; tải lên được nhiều phiếu một lần (mỗi phiếu một lớp).
function ClassSheetBox({ schoolId = '', programs = [], cohorts = [], courses = [] }) {
  const [programId, setProgramId] = useState('');
  const [cohortId, setCohortId] = useState('');
  const [courseId, setCourseId] = useState('');
  const [files, setFiles] = useState([]);
  const [boSung, setBoSung] = useState(false);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const programCohorts = cohorts.filter(c => String(c.program_id) === programId);
  const programCourses = programId ? courses.filter(c => (c.program_ids ?? []).map(String).includes(programId)) : courses;
  // Ghép query sau route() thay vì truyền vào route(): route này không nhận tham số đường dẫn
  // (NoBrokenLinksTest chặn kiểu truyền đó), còn ngành/khóa/môn chỉ là query để điền sẵn.
  const query = new URLSearchParams(Object.entries({ program_id: programId, cohort_id: cohortId, course_id: courseId }).filter(([, v]) => v)).toString();
  const templateHref = route('manager.reports.class-sheets.template') + (query ? `?${query}` : '');

  const submit = (e) => {
    e.preventDefault();
    if (!files.length || !schoolId) return;
    setBusy(true);
    router.post(route('manager.reports.class-sheets'), { files, school_id: schoolId, bo_sung: boSung ? 1 : 0 }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setFiles([]); setBoSung(false); if (fileRef.current) fileRef.current.value = ''; },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Card className="shadow-none border-2 border-primary/20">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold flex items-center gap-2">
          <FileSpreadsheet className="w-4 h-4 text-primary" /> Phiếu lớp học phần
          <span className="font-normal text-xs text-muted-foreground">— mỗi lớp một phiếu, tải lên là hệ thống tự đo</span>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="space-y-2">
          <p className="text-xs font-medium text-muted-foreground">Tải phiếu điền sẵn thông tin môn, cột PLO/PI của ngành và danh sách sinh viên của khóa</p>
          <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="w-full sm:w-56">
              <SearchableSelect value={programId} onValueChange={(v) => { setProgramId(v); setCohortId(''); setCourseId(''); }} placeholder="Ngành"
                options={programs.map(p => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))} />
            </div>
            <div className="w-full sm:w-40">
              <SearchableSelect value={cohortId} onValueChange={setCohortId} placeholder="Khóa"
                options={programCohorts.map(c => ({ value: String(c.id), label: c.name }))} />
            </div>
            <div className="w-full sm:w-72">
              <SearchableSelect value={courseId} onValueChange={setCourseId} placeholder="Môn học"
                options={programCourses.map(c => ({ value: String(c.id), label: `${c.code} — ${c.name}` }))} />
            </div>
            <Button asChild variant="outline" className="h-9">
              <a href={templateHref}><Download className="w-4 h-4" /><span className="ml-1">{courseId ? 'Tải phiếu điền sẵn' : 'Tải phiếu trống'}</span></a>
            </Button>
          </div>
        </div>

        <form onSubmit={submit} className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4 border-t pt-4">
          <div className="space-y-1.5 w-full sm:w-96">
            <Label className="text-xs font-medium text-muted-foreground">Phiếu đã điền (.xlsx) — chọn được nhiều file</Label>
            <input
              ref={fileRef}
              type="file"
              multiple
              accept=".xlsx,.xls"
              onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
              className="h-9 w-full rounded-md border border-input text-sm file:mr-3 file:h-9 file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
            />
          </div>
          <Button type="submit" disabled={busy || !files.length || !schoolId} className="h-9">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            <span className="ml-1">{boSung ? 'Tải lên phiếu bổ sung' : (files.length > 1 ? `Tải lên ${files.length} phiếu` : 'Tải lên & đo')}</span>
          </Button>
          <label htmlFor="cs-bo-sung" className="flex items-start gap-2.5 w-full cursor-pointer select-none">
            <Checkbox id="cs-bo-sung" checked={boSung} onCheckedChange={(v) => setBoSung(!!v)} className="mt-0.5" />
            <span className="text-xs leading-snug">
              <b>Phiếu bổ sung sau thi lại</b> — chỉ cập nhật học viên có điểm trong phiếu, điểm các bạn khác của lớp giữ nguyên.
              Phiếu chỉ cần sheet của bài thi lại.
            </span>
          </label>
        </form>
        <p className="text-[11px] text-muted-foreground">
          Hệ thống kiểm từng phiếu trước khi nhập: tổng trọng số các bài đúng 100%, tổng điểm tối đa mỗi bài đúng 10,
          điểm không vượt điểm tối đa, câu nào cũng có CLO và CLO nào cũng nối PLO/PI. Phiếu có lỗi thì <b>không nhập gì</b> và báo lỗi theo từng ô.
          Học viên vắng thi ghi <b>VT</b>, không để trống: bạn vắng thi cuối kỳ chưa tính vào cỡ mẫu của môn cho tới khi có điểm thi lại.
          Môn đã dựng khung theo đợt thì phiếu lấy CLO và bài đánh giá từ khung, phần khai trong phiếu không đổi được khung.
        </p>
      </CardContent>
    </Card>
  );
}

// KHUNG HỌC PHẦN THEO ĐỢT — kỹ thuật của trường dựng một lần cho cả khóa (buổi họp 03/10/2026):
// CLO + ma trận CLO, bài đánh giá, lớp kèm giảng viên. Giáo viên sau đó chỉ tải phiếu lớp mình và nộp điểm.
function CourseFrameBox({ schoolId = '', programs = [], cohorts = [] }) {
  const [programId, setProgramId] = useState('');
  const [cohortId, setCohortId] = useState('');
  const [files, setFiles] = useState([]);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const programCohorts = cohorts.filter(c => String(c.program_id) === programId);
  const query = new URLSearchParams(Object.entries({ program_id: programId, cohort_id: cohortId }).filter(([, v]) => v)).toString();
  const templateHref = route('manager.reports.course-frame.template') + (query ? `?${query}` : '');

  const submit = (e) => {
    e.preventDefault();
    if (!files.length || !schoolId) return;
    setBusy(true);
    router.post(route('manager.reports.course-frame'), { files, school_id: schoolId }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setFiles([]); if (fileRef.current) fileRef.current.value = ''; },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Card className="shadow-none border-2 border-primary/20">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
          <Database className="w-4 h-4 text-primary" /> Khung học phần theo đợt
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-4">
        <p className="text-xs text-muted-foreground">
          Kỹ thuật của trường khai một lần cho cả khóa, năm học: <b>CLO và ma trận CLO → PLO/PI</b> của từng môn,
          <b> bài đánh giá kèm trọng số</b>, và <b>lớp học phần kèm giảng viên</b>. Sau đó giáo viên chỉ tải phiếu của lớp mình
          ở trang lớp học phần, điền câu hỏi và điểm rồi nộp. Tách nhiều file được, ví dụ mỗi khoa một file.
        </p>
        <div className="space-y-2">
          <p className="text-xs font-medium text-muted-foreground">Chọn ngành và khóa để tải file mẫu điền sẵn các môn của khóa</p>
          <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="w-full sm:w-56">
              <SearchableSelect value={programId} onValueChange={(v) => { setProgramId(v); setCohortId(''); }} placeholder="Ngành"
                options={programs.map(p => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))} />
            </div>
            <div className="w-full sm:w-40">
              <SearchableSelect value={cohortId} onValueChange={setCohortId} placeholder="Khóa"
                options={programCohorts.map(c => ({ value: String(c.id), label: c.name }))} />
            </div>
            <Button asChild variant="outline" className="h-11 sm:h-9">
              <a href={templateHref}><Download className="w-4 h-4" /><span className="ml-1">{cohortId ? 'Tải file khung điền sẵn' : 'Tải file khung trống'}</span></a>
            </Button>
          </div>
        </div>

        <form onSubmit={submit} className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4 border-t pt-4">
          <div className="space-y-1.5 w-full sm:w-96">
            <Label className="text-xs font-medium text-muted-foreground">File khung đã điền (.xlsx) — chọn được nhiều file</Label>
            <input
              ref={fileRef}
              type="file"
              multiple
              accept=".xlsx,.xls"
              onChange={(e) => setFiles(Array.from(e.target.files ?? []))}
              className="h-11 sm:h-9 w-full rounded-md border border-input text-base sm:text-sm file:mr-3 file:h-full file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
            />
          </div>
          <Button type="submit" disabled={busy || !files.length || !schoolId} className="h-11 sm:h-9">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            <span className="ml-1">{files.length > 1 ? `Tải lên ${files.length} file khung` : 'Tải lên & dựng khung'}</span>
          </Button>
        </form>
        <p className="text-[11px] text-muted-foreground">
          Kiểm như phiếu lớp: tổng trọng số các bài của một môn đúng 100%, CLO nào cũng nối PLO/PI, CLO chỉ nối vào PLO/PI mà ma trận CTĐT ghi cho môn.
          File có lỗi thì <b>không nhập gì</b>. Kết quả hiện cùng danh sách bên trên.
        </p>
      </CardContent>
    </Card>
  );
}

function ImportBox({ level, title, accent, badgeClass, desc, format, templateMode, selfContained = false, postRoute, note, schoolId = '' }) {
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const selfCarrier = selfContained || templateMode === 'aggregate';
  const previewSchema = templateMode === 'question'
    ? GRADE_SCHEMAS.question
    : (selfCarrier ? GRADE_SCHEMAS.legacy : GRADE_SCHEMAS.standard);

  const submit = (e) => {
    e.preventDefault();
    if (!file || !schoolId) return;
    setBusy(true);
    const url = postRoute
      ? route(postRoute)
      : (selfContained ? route('manager.reports.legacy-grades') : route('manager.reports.bulk-grades'));
    router.post(url, { file, school_id: schoolId }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setFile(null); if (fileRef.current) fileRef.current.value = ''; },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Card className={`shadow-none border-2 ${accent}`}>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold flex flex-wrap items-center gap-2">
          <span className={`inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold ${badgeClass}`}>{level}</span>
          {title}
          <span className="font-normal text-xs text-muted-foreground">— {desc}</span>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
          <div className="space-y-1.5 w-full sm:w-96">
            <Label className="text-xs font-medium text-muted-foreground">File điểm (CSV/Excel)</Label>
            <input
              ref={fileRef}
              type="file"
              accept=".csv,.xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="h-9 w-full rounded-md border border-input text-sm file:mr-3 file:h-9 file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
            />
          </div>
          <Button type="button" onClick={submit} disabled={busy || !file || !schoolId} className="h-9">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            <span className="ml-1">Tải lên &amp; tính</span>
          </Button>
        </div>
        <p className="text-[11px] text-muted-foreground">
          File: <b>{format}</b>
          <Link href={route('manager.templates.index')} className="inline-flex items-center gap-1 text-primary hover:underline ml-2">
            <FileSpreadsheet className="w-3 h-3" /> Lấy ở Bộ file mẫu
          </Link>
          {note
            ? <span className="ml-2">· {note}</span>
            : selfContained
              ? <span className="ml-2">· Tự tạo khóa/lớp/sinh viên từ file &amp; tính CLO (cần Môn + CLO đã khai).</span>
              : <span className="ml-2">· Tự định tuyến theo Mã lớp (đã dựng trong khung) &amp; tính sau khi nhập.</span>}
        </p>
        {file && (
          <FilePreview file={file} mode="grade" schema={previewSchema} />
        )}
      </CardContent>
    </Card>
  );
}

// Banner kết quả 1 batch import — trung thực (0 dòng khớp khung → đỏ, dẫn về Bước 1/3).
function BatchOutcome({ b, onJump }) {
  const total = b.total_rows ?? 0;
  const ok = b.processed_rows ?? 0;
  const noMatch = total > 0 && ok === 0;
  const partial = ok > 0 && (b.error_log?.length ?? 0) > 0;

  if (noMatch) {
    return (
      <div className="mt-2 p-2.5 rounded-md bg-rose-50 border border-rose-200 text-xs text-rose-800 space-y-1.5">
        <p className="font-semibold flex items-center gap-1.5">
          <AlertTriangle className="w-3.5 h-3.5" /> Không dòng nào khớp khung (0/{total}) — chưa đo được gì.
        </p>
        <p>Kiểm tra khung đã có ngành/môn/lớp/CLO tương ứng với file.</p>
        <div className="flex flex-wrap gap-2 pt-0.5">
          <button type="button" onClick={() => onJump(1)} className="inline-flex items-center gap-1 px-2 py-1 rounded border border-rose-300 bg-white hover:bg-rose-100 font-medium">Mở Bước 1 — Khung</button>
          <button type="button" onClick={() => onJump(3)} className="inline-flex items-center gap-1 px-2 py-1 rounded border border-rose-300 bg-white hover:bg-rose-100 font-medium">Kiểm tra sẵn sàng (Bước 3)</button>
        </div>
      </div>
    );
  }
  if (partial) {
    return (
      <div className="mt-2 p-2 rounded-md bg-amber-50 border border-amber-200 text-xs text-amber-800">
        <span className="font-semibold">{ok}/{total} dòng vào khung</span> · còn {total - ok} dòng lỗi (xem chi tiết bên dưới).
      </div>
    );
  }
  return null;
}

// Box nhập kết quả KHẢO SÁT (đo gián tiếp) — tổng hợp theo PLO, tự định tuyến theo trường.
function SurveyImportBox({ schoolId }) {
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const submit = (e) => {
    e.preventDefault();
    if (!file || !schoolId) return;
    setBusy(true);
    router.post(route('manager.reports.survey'), { file, school_id: schoolId }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setFile(null); if (fileRef.current) fileRef.current.value = ''; },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Card className="shadow-none border-2 border-violet-200">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-violet-100 text-violet-700">GT</span>
          Đo gián tiếp — Khảo sát bên liên quan
          <span className="font-normal text-xs text-muted-foreground">— tổng hợp theo PLO (cuối môn · tốt nghiệp · cựu sinh viên · nhà tuyển dụng)</span>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
          <div className="space-y-1.5 w-full sm:w-96">
            <Label className="text-xs font-medium text-muted-foreground">File khảo sát (CSV/Excel)</Label>
            <input
              ref={fileRef}
              type="file"
              accept=".csv,.xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="h-9 w-full rounded-md border border-input text-sm file:mr-3 file:h-9 file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
            />
          </div>
          <Button type="button" onClick={submit} disabled={busy || !file || !schoolId} className="h-9">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            <span className="ml-1">Tải lên khảo sát</span>
          </Button>
        </div>
        <p className="text-[11px] text-muted-foreground">
          File: <b>Mã ngành · Mã khóa (tùy chọn) · Đối tượng · Mã PLO · Số phản hồi · Điểm TB Likert · Thang</b>
          <Link href={route('manager.templates.index')} className="inline-flex items-center gap-1 text-primary hover:underline ml-2">
            <FileSpreadsheet className="w-3 h-3" /> Lấy ở Bộ file mẫu
          </Link>
          <span className="ml-2">· Trộn với điểm trực tiếp theo trọng số của trường (mặc định 80%/20%) khi đo lường.</span>
        </p>
        {file && (
          <FilePreview file={file} mode="grade" schema={SURVEY_SCHEMA} />
        )}
      </CardContent>
    </Card>
  );
}

// Box nhập DANH SÁCH SINH VIÊN hàng loạt (Bước 1) — chỉ ghi students, MSSV trùng → cập nhật.
function StudentRosterImportBox({ schoolId }) {
  const [file, setFile] = useState(null);
  const [busy, setBusy] = useState(false);
  const fileRef = useRef(null);

  const submit = (e) => {
    e.preventDefault();
    if (!file || !schoolId) return;
    setBusy(true);
    router.post(route('manager.reports.student-roster'), { file, school_id: schoolId }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setFile(null); if (fileRef.current) fileRef.current.value = ''; },
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Card className="shadow-none border-2 border-sky-200">
      <CardHeader className="pb-3">
        <CardTitle className="text-sm font-semibold flex flex-wrap items-center gap-2">
          <span className="inline-flex items-center justify-center w-6 h-6 rounded-full text-xs font-bold bg-sky-100 text-sky-700">SV</span>
          Danh sách sinh viên (nhập hàng loạt)
          <span className="font-normal text-xs text-muted-foreground">— thay cho việc thêm tay từng sinh viên; MSSV trùng sẽ được cập nhật</span>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
          <div className="space-y-1.5 w-full sm:w-96">
            <Label className="text-xs font-medium text-muted-foreground">File danh sách sinh viên (CSV/Excel)</Label>
            <input
              ref={fileRef}
              type="file"
              accept=".csv,.xlsx,.xls"
              onChange={(e) => setFile(e.target.files?.[0] ?? null)}
              className="h-9 w-full rounded-md border border-input text-sm file:mr-3 file:h-9 file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
            />
          </div>
          <Button type="button" onClick={submit} disabled={busy || !file || !schoolId} className="h-9">
            {busy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
            <span className="ml-1">Tải lên danh sách</span>
          </Button>
        </div>
        <p className="text-[11px] text-muted-foreground">
          File: <b>MSSV · Họ tên · Mã ngành · Năm khóa</b>
          <Link href={route('manager.templates.index')} className="inline-flex items-center gap-1 text-primary hover:underline ml-2">
            <FileSpreadsheet className="w-3 h-3" /> Lấy ở Bộ file mẫu
          </Link>
          <span className="ml-2">· Cần khai báo Ngành (khung) trước · xếp SV vào khóa theo Năm khóa (hoặc Mã khóa).</span>
        </p>
        {file && (
          <FilePreview file={file} mode="grade" schema={STUDENT_ROSTER_SCHEMA} />
        )}
      </CardContent>
    </Card>
  );
}

// Thẻ "khung đang có gì" (Bước 1) — trả lời tại chỗ câu "tôi khai xong chưa?".
// Trước đây Bước 1 render y hệt nhau dù đã dựng khung hay chưa: tải file lên xong chỉ thêm một
// dòng lịch sử, màn hình vẫn mời dựng khung. Người vận hành phải rời trang này đi nơi khác đếm.
function StructureOverviewCard({ data }) {
  if (!data) return null;
  const { built, counts: c, gaps } = data;

  if (!built) {
    return (
      <Card className="shadow-none border-2 border-dashed border-muted-foreground/30">
        <CardContent className="p-4 text-sm">
          <p className="font-semibold flex items-center gap-2"><Database className="w-4 h-4 text-muted-foreground" /> Trường này chưa có khung</p>
          <p className="text-muted-foreground text-xs mt-0.5">
            Chưa khai ngành hay môn nào. Bắt đầu bằng cách tải file khung cấu trúc ở ngay bên dưới —
            hoặc nạp dữ liệu mẫu để xem trước toàn bộ quy trình.
          </p>
        </CardContent>
      </Card>
    );
  }

  const chan = gaps.filter(g => g.severity === 'error');
  const luuY = gaps.filter(g => g.severity !== 'error');
  const so = [
    ['khoa', c.departments], ['ngành', c.programs], ['khóa', c.cohorts], ['môn', c.courses],
    ['CLO', c.clos], ['PLO', c.plos], ['PI', c.pis], ['ánh xạ CLO→PI', c.mappings],
    ['bài đánh giá', c.assessments], ['lớp', c.classes], ['sinh viên', c.students],
  ];

  return (
    <Card className={`shadow-none border-2 ${chan.length ? 'border-rose-300 bg-rose-50/40' : 'border-emerald-300 bg-emerald-50/40'}`}>
      <CardContent className="p-4 space-y-3">
        <p className="font-semibold text-sm flex items-center gap-2">
          {chan.length
            ? <><AlertTriangle className="w-4 h-4 text-rose-600" /> Khung đã dựng nhưng còn chỗ chặn đường đo</>
            : <><CheckCircle2 className="w-4 h-4 text-emerald-600" /> Khung đã dựng — đủ để đo</>}
        </p>

        <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs">
          {so.map(([nhan, n]) => (
            <span key={nhan} className={n > 0 ? '' : 'text-muted-foreground'}>
              <b className="tabular-nums">{n}</b> {nhan}
            </span>
          ))}
        </div>

        {gaps.length > 0 && (
          <ul className="space-y-1 text-xs">
            {[...chan, ...luuY].map(g => (
              <li key={g.key} className="flex flex-wrap items-baseline gap-x-1.5">
                <span className={g.severity === 'error' ? 'text-rose-700 font-medium' : 'text-amber-700 font-medium'}>
                  {g.count} {g.label.toLowerCase()}
                </span>
                <span className="text-muted-foreground">— {g.why}</span>
                <Link href={g.link} className="text-primary hover:underline">Đi sửa</Link>
              </li>
            ))}
          </ul>
        )}

        <p className="text-xs text-muted-foreground">
          Tải file khung mới lên bên dưới để <b>cập nhật</b> — cùng mã thì ghi đè, không nhân đôi.
        </p>
      </CardContent>
    </Card>
  );
}

// Nạp/xóa DỮ LIỆU MẪU (các trường mang nhãn [DEMO]) — bấm 1 nút là thấy trọn quy trình chạy thật, xóa được.
// Chỉ super_admin thấy (route cũng chặn role:super_admin) để operator không lỡ tạo/xoá trong dữ liệu thật.
function DemoDataCard({ compact = false }) {
  const roles = usePage().props.auth?.user?.roles ?? [];
  const [busy, setBusy] = useState('');
  if (!roles.includes('super_admin')) return null;

  const run = (name, action) => {
    const ok = name === 'clear'
      ? window.confirm('Xóa toàn bộ dữ liệu mẫu (các trường mang nhãn [DEMO])? Không đụng dữ liệu thật.')
      : window.confirm('Nạp dữ liệu mẫu vào các trường riêng mang nhãn [DEMO] để thử? (không đụng dữ liệu thật; xóa được sau)');
    if (!ok) return;
    setBusy(name);
    router.post(action, {}, { preserveScroll: true, onFinish: () => setBusy('') });
  };
  // Trường đang mở đã có khung thật rồi thì thôi mời nạp mẫu nữa — nhưng vẫn phải với tới được
  // nút Xóa, vì chính trường mẫu cũng là trường "đã dựng khung".
  if (compact) {
    return (
      <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-dashed border-amber-300 bg-amber-50/40 px-3 py-2 text-xs">
        <span className="flex items-center gap-2 text-amber-800">
          <Building2 className="w-3.5 h-3.5" /> Hệ thống đang có dữ liệu mẫu (các trường mang nhãn [DEMO]).
        </span>
        <Button type="button" size="sm" variant="outline" onClick={() => run('clear', route('manager.reports.demo.clear'))} disabled={!!busy} className="h-7 text-xs">
          {busy === 'clear' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <XCircle className="w-3.5 h-3.5" />}
          <span className="ml-1">Xóa dữ liệu mẫu</span>
        </Button>
      </div>
    );
  }

  return (
    <Card className="shadow-none border-2 border-dashed border-amber-300 bg-amber-50/40">
      <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="text-sm">
          <p className="font-semibold flex items-center gap-2"><Building2 className="w-4 h-4 text-amber-600" /> Dùng thử với dữ liệu mẫu</p>
          <p className="text-muted-foreground text-xs mt-0.5">
            Tạo sẵn <b>3 trường mẫu</b> khác tính chất (kỹ thuật · kinh tế · sư phạm), mỗi trường có khoa, ngành, môn,
            CLO/PI/PLO, lớp, sinh viên và điểm — đã đo sẵn nên xem được ngay mức đạt, biểu đồ, báo cáo.
            Điểm cố ý rải để có PLO đạt và PLO <b>chưa</b> đạt, đúng như dữ liệu thật.
            Tên trường là tên hư cấu mang nhãn <b>[DEMO]</b>. Chọn ở ô "Trường đang làm việc" để khám phá. Xong bấm "Xóa".
          </p>
        </div>
        <div className="flex shrink-0 gap-2">
          <Button type="button" onClick={() => run('load', route('manager.reports.demo.load'))} disabled={!!busy} className="h-9">
            {busy === 'load' ? <Loader2 className="w-4 h-4 animate-spin" /> : <Database className="w-4 h-4" />}
            <span className="ml-1">Nạp dữ liệu mẫu</span>
          </Button>
          <Button type="button" variant="outline" onClick={() => run('clear', route('manager.reports.demo.clear'))} disabled={!!busy} className="h-9">
            {busy === 'clear' ? <Loader2 className="w-4 h-4 animate-spin" /> : <XCircle className="w-4 h-4" />}
            <span className="ml-1">Xóa</span>
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

export default function Index({ structureOverview = null, demoLoaded = false, courses = [], snapshots, programs, cohorts, attainment, piAttainment = null, viewFilters, bulkBatches = [], structureBatches = [], surveyBatches = [], rosterBatches = [], ungradedClasses = [], measurementRuns = [] }) {
  const { flash, workingSchoolId } = usePage().props;
  const [programId, setProgramId] = useState('');
  const [cohortId, setCohortId] = useState('');
  const [isGenerating, setIsGenerating] = useState(false);

  // Bước hiện tại (1-based) — đọc từ ?step= để dashboard deep-link được.
  const initialStep = (() => {
    const s = parseInt(new URLSearchParams(window.location.search).get('step') || '1', 10);
    return s >= 1 && s <= 5 ? s : 1;
  })();
  const [step, setStep] = useState(initialStep);

  // Ngữ cảnh Trường (tenant) do bộ chọn CHUNG ở header (WorkingSchoolSwitcher) quyết định —
  // server đã scope mọi dữ liệu theo trường này; đây chỉ là bản sao chuỗi để lọc/gửi kèm.
  const schoolId = workingSchoolId ? String(workingSchoolId) : '';

  // Ghi step vào URL (shallow, không gọi server) để F5 / chia sẻ link giữ nguyên bước.
  const syncUrl = (patch) => {
    const params = new URLSearchParams(window.location.search);
    Object.entries(patch).forEach(([k, v]) => {
      if (v === null || v === undefined || v === '') params.delete(k);
      else params.set(k, String(v));
    });
    const qs = params.toString();
    window.history.replaceState(
      window.history.state,
      '',
      qs ? `${window.location.pathname}?${qs}` : window.location.pathname,
    );
  };

  // Đổi bước → cập nhật state + URL.
  const handleStepChange = (n) => {
    setStep(n);
    syncUrl({ step: n });
  };

  // Lần đầu mount: bảo đảm URL phản ánh đúng bước hiện tại.
  useEffect(() => {
    syncUrl({ step });
    // Vào thẳng Bước 2 (từ deep-link "X lớp chưa nhập điểm") → cuộn tới danh sách lớp.
    if (initialStep === 2) {
      setTimeout(() => document.getElementById('ungraded-panel')?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Dựng/cập nhật khung cấu trúc (Excel nhiều sheet)
  const [structureFile, setStructureFile] = useState(null);
  const [structureBusy, setStructureBusy] = useState(false);
  const structureFileRef = useRef(null);
  const handleStructureImport = (e) => {
    e.preventDefault();
    if (!structureFile) return;
    if (!schoolId) return;
    setStructureBusy(true);
    router.post(route('manager.reports.structure'), { file: structureFile, school_id: schoolId }, {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => { setStructureFile(null); if (structureFileRef.current) structureFileRef.current.value = ''; },
      onFinish: () => setStructureBusy(false),
    });
  };

  // Lọc ngành/khóa theo TRƯỜNG đang chọn.
  const schoolPrograms = schoolId ? programs.filter(p => String(p.school_id) === schoolId) : programs;
  const schoolCohorts = schoolId ? cohorts.filter(c => String(c.school_id) === schoolId) : cohorts;
  // Lọc lịch sử/báo cáo/log đo theo trường.
  const sameSchool = (x) => !schoolId || String(x.school_id) === schoolId;
  const visibleStructure = structureBatches.filter(sameSchool);
  const visibleBulk = bulkBatches.filter(sameSchool);
  const visibleSurvey = surveyBatches.filter(sameSchool);
  const visibleRoster = rosterBatches.filter(sameSchool);
  const visibleUngraded = ungradedClasses.filter(sameSchool);
  // Có tiến trình import nào đang chạy (để hiển thị trung thực, batch cũ không bị tưởng "treo").
  const activeBatches = [...visibleStructure, ...visibleBulk, ...visibleSurvey]
    .filter(b => ['queued', 'processing', 'pending'].includes(b.status));
  const visibleSnapshots = snapshots.data.filter(sameSchool);
  const visibleRuns = measurementRuns.filter(sameSchool);
  // Báo cáo: khóa theo trường + ngành đã chọn.
  const genCohorts = programId ? schoolCohorts.filter(c => String(c.program_id) === programId) : schoolCohorts;

  // Đo lại toàn trường (sau khi nhập điểm / đổi mapping).
  const [isMeasuring, setIsMeasuring] = useState(false);
  const handleMeasureSchool = () => {
    if (!schoolId) return;
    setIsMeasuring(true);
    router.post(route('manager.reports.recalculate'), { school_id: schoolId }, {
      preserveScroll: true,
      onFinish: () => setIsMeasuring(false),
    });
  };

  // Preflight "Kiểm tra sẵn sàng đo lường" (read-only)
  const [chkProgramId, setChkProgramId] = useState('');
  const [chkCohortId, setChkCohortId] = useState('');
  const [readiness, setReadiness] = useState(null);
  const [chkLoading, setChkLoading] = useState(false);
  // Lọc khóa theo trường + ngành đã chọn.
  const chkCohorts = chkProgramId ? schoolCohorts.filter(c => String(c.program_id) === chkProgramId) : schoolCohorts;

  useEffect(() => {
    if (!chkProgramId || !chkCohortId) { setReadiness(null); return; }
    let cancelled = false;
    setChkLoading(true);
    const url = `${route('manager.reports.readiness')}?program_id=${chkProgramId}&cohort_id=${chkCohortId}`;
    fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((data) => { if (!cancelled) setReadiness(data); })
      .catch(() => { if (!cancelled) setReadiness(null); })
      .finally(() => { if (!cancelled) setChkLoading(false); });
    return () => { cancelled = true; };
  }, [chkProgramId, chkCohortId]);

  // Đổi "Trường đang làm việc" ở header → reload giữ state; chủ động reset bộ lọc ngành/khóa
  // (thuộc trường cũ) để không thao tác nhầm sang trường mới.
  const prevSchoolRef = useRef(workingSchoolId);
  useEffect(() => {
    if (prevSchoolRef.current === workingSchoolId) return;
    prevSchoolRef.current = workingSchoolId;
    setProgramId('');
    setCohortId('');
    setChkProgramId('');
    setChkCohortId('');
  }, [workingSchoolId]);

  const reloadAttainment = (vp, vc) => {
    const params = { step, ...(schoolId ? { school_id: schoolId } : {}) };
    if (vp) params.view_program_id = vp;
    if (vc) params.view_cohort_id = vc;
    router.get(route('manager.reports.index'), params, { preserveState: true, replace: true, preserveScroll: true });
  };

  const isPending = (arr) => arr.some(b => ['queued', 'processing', 'pending'].includes(b.status));
  const hasPending = isPending(snapshots.data) || isGenerating || isPending(bulkBatches) || isPending(structureBatches) || isPending(surveyBatches) || isPending(rosterBatches);

  useEffect(() => {
    if (!hasPending) return;
    const t = setInterval(() => {
      router.reload({ only: ['snapshots', 'bulkBatches', 'structureBatches', 'surveyBatches', 'rosterBatches', 'measurementRuns'], preserveState: true });
    }, 5000);
    return () => clearInterval(t);
  }, [hasPending]);

  const handleGenerate = (e) => {
    e.preventDefault();
    if (!programId || !cohortId) return;
    setIsGenerating(true);
    router.post(route('manager.reports.generate'), { program_id: programId, cohort_id: cohortId }, {
      preserveScroll: true,
      onSuccess: () => { setIsGenerating(false); setProgramId(''); setCohortId(''); },
      onError: () => setIsGenerating(false),
    });
  };

  const handlePageChange = (page) => {
    router.get(
      route('manager.reports.index'),
      { page, step, ...(schoolId ? { school_id: schoolId } : {}) },
      { preserveState: true, replace: true },
    );
  };

  // PP "Tỉ lệ đạt" trả về số 0..1 → hiển thị dạng %. PP cũ giữ điểm thang 10.
  const isRatio = !!attainment?.is_ratio;
  const fmtScore = (v) => v == null ? '—' : (isRatio ? (v * 100).toFixed(1) + '%' : Number(v).toFixed(2));

  return (
    <DashboardLayout>
      <Head title="Trung tâm đo lường" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}
        {flash?.error && (
          <div className="p-3 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 flex items-start gap-3 text-sm">
            <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.error}</span>
          </div>
        )}

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Trung tâm đo lường</h2>
            <p className="text-muted-foreground">Dựng khung · nhập điểm · đo lường · xuất báo cáo — theo từng Trường.</p>
          </div>
          <HelpSheet />
        </div>

        {/* Thanh 4 bước vận hành — chuyển bước bằng cách bấm */}
        <Stepper
          current={step}
          onChange={handleStepChange}
          steps={[
            { title: 'Khung chương trình đào tạo', desc: 'Dựng / cập nhật cấu trúc', badge: 'Thiết lập · 1 lần', badgeClass: 'bg-amber-100 text-amber-700' },
            { title: 'Nhập điểm', desc: 'Tải phiếu lớp học phần', badge: 'Tải phiếu', badgeClass: 'bg-blue-100 text-blue-700' },
            { title: 'Đo & kiểm tra', desc: 'Chỉ bấm — không cần file', badge: 'Không cần file', badgeClass: 'bg-gray-100 text-gray-600' },
            { title: 'Báo cáo', desc: 'Chỉ bấm — không cần file', badge: 'Không cần file', badgeClass: 'bg-gray-100 text-gray-600' },
            { title: 'Cải tiến', desc: 'CQI — xử lý PLO chưa đạt', badge: 'PDCA', badgeClass: 'bg-violet-100 text-violet-700' },
          ]}
        />
        <p className="-mt-2 text-xs text-muted-foreground">
          Bước 1 chỉ làm <b>một lần</b> khi thiết lập. Hằng ngày bạn chỉ cần <b>Bước 2 (nhập điểm)</b> — hệ thống tự đo —
          rồi <b>Bước 4 (báo cáo)</b>. Bước 3 dùng khi muốn kiểm tra hoặc đo lại.
        </p>

        {/* ===== Bước 1 — Khung cấu trúc ===== */}
        {step === 1 && (
          <div className="space-y-6">
            <p className="text-sm text-muted-foreground">Bước 1 — Tải lên file khai báo chương trình (ngành và khóa, PLO/PI, danh sách môn, danh sách sinh viên). Mỗi khóa làm một lần; tải lại file đã sửa là cập nhật.</p>
            <StepHelp data={STEPS[0]} />
            <StructureOverviewCard data={structureOverview} />
            {/* Chưa dựng khung → mời nạp mẫu. Đã dựng mà hệ thống còn dữ liệu mẫu → chỉ còn nút Xóa. */}
            {!structureOverview?.built ? <DemoDataCard /> : (demoLoaded && <DemoDataCard compact />)}

            {/* Khung cấu trúc — dựng/cập nhật bằng Excel (phải có TRƯỚC khi nhập điểm) */}
            <Card className="shadow-none border-2 border-primary/20">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <Database className="w-4 h-4 text-primary" /> File 1 — Khai báo chương trình
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <p className="text-xs text-muted-foreground">
                  Gồm <b>ngành và khóa · PLO/PI kèm trọng số · danh sách môn · ma trận chuẩn đầu ra các học phần (sheet MaTran_CTDT, chép từ CTĐT) · danh sách sinh viên</b>.
                  Trọng số các PI của một PLO phải cộng lại đúng 1. Mức I/R/M/A trong ma trận CTĐT là trọng số khi tính PI/PLO; phiếu tải về ở Bước 2 chỉ có cột PLO/PI mà môn đóng góp.
                  CLO, bài đánh giá và điểm khai ở phiếu lớp học phần. File khung 11 sheet cũ vẫn nhập được.
                </p>
                <form onSubmit={handleStructureImport} className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
                  <div className="space-y-1.5 w-full sm:w-96">
                    <Label className="text-xs font-medium text-muted-foreground">File khai báo chương trình (.xlsx)</Label>
                    <input
                      ref={structureFileRef}
                      type="file"
                      accept=".xlsx,.xls"
                      onChange={(e) => setStructureFile(e.target.files?.[0] ?? null)}
                      className="h-9 w-full rounded-md border border-input text-sm file:mr-3 file:h-9 file:border-0 file:bg-primary file:px-3 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                    />
                  </div>
                  <Button type="submit" disabled={structureBusy || !structureFile || !schoolId} className="h-9">
                    {structureBusy ? <Loader2 className="w-4 h-4 animate-spin" /> : <Upload className="w-4 h-4" />}
                    <span className="ml-1">Tải lên &amp; dựng</span>
                  </Button>
                  <Link href={route('manager.templates.index')} className="inline-flex items-center gap-1 h-9 text-xs font-medium text-primary hover:underline">
                    <FileSpreadsheet className="w-3.5 h-3.5" /> Lấy ở Bộ file mẫu
                  </Link>
                </form>
                {structureFile && (
                  <FilePreview file={structureFile} mode="structure" schema={STRUCTURE_SHEETS} />
                )}
                {visibleStructure.length > 0 && (
                  <div className="space-y-2 pt-1">
                    {visibleStructure.map(b => (
                      <div key={b.id} className="border rounded-lg p-3 text-xs">
                        <div className="flex items-center justify-between gap-3">
                          <span className="font-medium truncate">
                            {b.file_name ?? '(không tên)'} · <span title={b.created_at}>{b.created_ago ?? b.created_at}</span>
                            {b.is_stale && <span className="ml-2 text-amber-600 font-normal">· đang xử lý lâu hơn thường lệ — kiểm tra worker</span>}
                          </span>
                          <div className="flex items-center gap-3 shrink-0">
                            <span className="text-muted-foreground">{b.processed_rows} mục</span>
                            <StatusBadge status={b.status} />
                            {b.downloadable && <a href={route('manager.reports.batch-file', b.uuid)} className="text-primary hover:underline inline-flex items-center gap-0.5"><Download className="w-3 h-3" /> File gốc</a>}
                          </div>
                        </div>
                        {b.summary && (
                          <div className="mt-1 text-muted-foreground">{Object.entries(b.summary).filter(([, v]) => v > 0).map(([k, v]) => `${k}: ${v}`).join(' · ')}</div>
                        )}
                        {b.error_log?.length > 0 && (
                          <ul className="mt-2 list-inside list-disc space-y-0.5 max-h-32 overflow-auto text-rose-600">
                            {b.error_log.slice(0, 20).map((err, i) => <li key={i}>{err}</li>)}
                            {b.error_log.length > 20 && <li>… và {b.error_log.length - 20} lỗi khác</li>}
                          </ul>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </CardContent>
            </Card>

            <CourseFrameBox schoolId={schoolId} programs={schoolPrograms} cohorts={schoolCohorts} />

            {/* Danh sách sinh viên nay nằm ở sheet SinhVien của File 1; ô riêng giữ cho file cũ, thu gọn. */}
            <details className="rounded-lg border px-4 py-3">
              <summary className="cursor-pointer text-xs font-medium text-muted-foreground">Nhập danh sách sinh viên bằng file riêng (cách cũ — nay đã có sheet SinhVien trong File 1)</summary>
              <div className="mt-3">
            <StudentRosterImportBox schoolId={schoolId} />
            {visibleRoster.length > 0 && (
              <div className="space-y-2">
                {visibleRoster.map(b => (
                  <div key={b.id} className="border rounded-lg p-3 text-xs">
                    <div className="flex items-center justify-between gap-3">
                      <span className="font-medium truncate">
                        {b.file_name ?? '(không tên)'} · <span title={b.created_at}>{b.created_ago ?? b.created_at}</span>
                        {b.is_stale && <span className="ml-2 text-amber-600 font-normal">· đang xử lý lâu hơn thường lệ — kiểm tra worker</span>}
                      </span>
                      <div className="flex items-center gap-3 shrink-0">
                        <span className="text-muted-foreground">{b.processed_rows}/{b.total_rows} SV</span>
                        <StatusBadge status={b.status} />
                        {b.downloadable && <a href={route('manager.reports.batch-file', b.uuid)} className="text-primary hover:underline inline-flex items-center gap-0.5"><Download className="w-3 h-3" /> File gốc</a>}
                      </div>
                    </div>
                    <BatchOutcome b={b} onJump={handleStepChange} />
                    {b.error_log?.length > 0 && (
                      <ul className="mt-2 list-inside list-disc space-y-0.5 max-h-32 overflow-auto text-rose-600">
                        {b.error_log.slice(0, 20).map((err, i) => <li key={i}>{err}</li>)}
                        {b.error_log.length > 20 && <li>… và {b.error_log.length - 20} lỗi khác</li>}
                      </ul>
                    )}
                  </div>
                ))}
              </div>
            )}
              </div>
            </details>
          </div>
        )}

        {/* ===== Bước 2 — Phiếu lớp học phần ===== */}
        {step === 2 && (
          <div className="space-y-6">
            <p className="text-sm text-muted-foreground">Bước 2 — Tải lên phiếu lớp học phần, mỗi lớp một phiếu. Phiếu gồm thông tin lớp, CLO và ma trận CLO với PLO/PI, các bài đánh giá, và điểm từng câu hỏi. Nhập xong hệ thống tự đo.</p>
            <StepHelp data={STEPS[1]} />

            {/* Việc cụ thể: các lớp CHƯA nhập điểm (mục "X lớp chưa nhập điểm" ở Dashboard trỏ tới đây) */}
            <Card id="ungraded-panel" className="shadow-none border-2 border-amber-200">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <ClipboardList className="w-4 h-4 text-amber-600" /> Lớp chưa nhập điểm ({visibleUngraded.length})
                  <InfoHint title="Lớp chưa nhập điểm">Lớp đã có trong khung nhưng chưa có dòng điểm nào. Chuẩn bị file điểm cho các lớp này rồi tải ở dưới (đúng phương án theo khóa).</InfoHint>
                </CardTitle>
              </CardHeader>
              <CardContent>
                {!schoolId ? (
                  <p className="text-xs text-muted-foreground">Chọn trường ở trên để xem.</p>
                ) : visibleUngraded.length === 0 ? (
                  <p className="flex items-center gap-2 text-sm text-emerald-600"><CheckCircle2 className="w-4 h-4" /> Tất cả lớp đã có điểm — không còn lớp nào cần nhập.</p>
                ) : (
                  <div className="overflow-x-auto max-h-72 overflow-y-auto border rounded-lg">
                    <table className="w-full text-xs">
                      <thead className="sticky top-0 bg-muted">
                        <tr className="border-b text-muted-foreground uppercase tracking-wider">
                          <th className="text-left py-2 px-2 font-semibold">Mã lớp</th>
                          <th className="text-left py-2 px-2 font-semibold">Môn</th>
                          <th className="text-left py-2 px-2 font-semibold">GV</th>
                          <th className="text-left py-2 px-2 font-semibold">Khóa</th>
                          <th className="text-left py-2 px-2 font-semibold">Phương án</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y">
                        {visibleUngraded.map((c, i) => (
                          <tr key={i} className="hover:bg-muted/30">
                            <td className="py-1.5 px-2 font-medium whitespace-nowrap">{c.class_code}</td>
                            <td className="py-1.5 px-2 text-muted-foreground">{[c.course_code, c.course_name].filter(Boolean).join(' ') || '—'}</td>
                            <td className="py-1.5 px-2 text-muted-foreground whitespace-nowrap">{c.lecturer_name ?? '—'}</td>
                            <td className="py-1.5 px-2 text-muted-foreground whitespace-nowrap">{c.cohort_name ?? '—'}</td>
                            <td className="py-1.5 px-2"><span className="inline-block px-1.5 py-0.5 rounded bg-muted text-muted-foreground whitespace-nowrap">{c.phuong_an ?? '—'}</span></td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* Trạng thái tiến trình — trung thực (batch cũ không bị tưởng "treo") */}
            <div className="flex items-center gap-2 text-xs px-1">
              {activeBatches.length > 0 ? (
                <span className="inline-flex items-center gap-1.5 text-blue-600 font-medium"><span className="w-1.5 h-1.5 rounded-full bg-blue-500 animate-ping" /> Đang xử lý {activeBatches.length} tiến trình…</span>
              ) : (
                <span className="inline-flex items-center gap-1.5 text-muted-foreground"><CheckCircle2 className="w-3.5 h-3.5 text-emerald-500" /> Không có tiến trình nào đang chạy.</span>
              )}
            </div>

            {/* Nhập điểm + đo lường — TÁCH 3 BOX theo phương án */}
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-2">
                <Upload className="w-4 h-4 text-primary" />
                <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Nhập phiếu lớp học phần</h3>
                <Button type="button" variant="outline" size="sm" onClick={handleMeasureSchool} disabled={isMeasuring || !schoolId} className="ml-auto h-8" title="Tính lại CLO/PI/PLO cho toàn bộ trường (sau khi nhập điểm hoặc đổi mapping/ngưỡng)">
                  {isMeasuring ? <Loader2 className="w-4 h-4 animate-spin" /> : <RefreshCw className="w-4 h-4" />}
                  <span className="ml-1">Tính lại toàn trường</span>
                </Button>
              </div>
              <ClassSheetBox schoolId={schoolId} programs={schoolPrograms} cohorts={schoolCohorts} courses={courses} />

              <details className="rounded-lg border px-4 py-3">
                <summary className="cursor-pointer text-xs font-medium text-muted-foreground">Các định dạng file điểm cũ (chỉ dùng cho file trường đang có sẵn)</summary>
              <div className="space-y-4 mt-3">
                <ImportBox
                  level={1}
                  title="Phương án 1 — Khóa cũ (≤2024)"
                  accent="border-amber-200"
                  badgeClass="bg-amber-100 text-amber-700"
                  desc="Dữ liệu cũ — chỉ cần thả file điểm, hệ thống tự dựng khóa/lớp/SV rồi tính CLO."
                  format="Mã ngành · Năm khóa · Mã môn · MSSV · Điểm"
                  templateMode="legacy"
                  selfContained
                  schoolId={schoolId}
                />
                <ImportBox
                  level={2}
                  title="Phương án 2 — Khóa 2025"
                  accent="border-sky-200"
                  badgeClass="bg-sky-100 text-sky-700"
                  desc="Điểm từng bài đánh giá, một dòng một bài."
                  format="Mã lớp · MSSV · Tên bài đánh giá · Điểm (nhiều dòng/SV)"
                  templateMode="standard"
                  schoolId={schoolId}
                />
                <ImportBox
                  level={3}
                  title="Phương án 3 — Khóa mới (≥2026)"
                  accent="border-emerald-200"
                  badgeClass="bg-emerald-100 text-emerald-700"
                  desc="Đo CLO → PI (thật) → PLO. Cần đủ PI + ánh xạ CLO→PI."
                  format="Mã lớp · MSSV · Tên bài đánh giá · Điểm (nhiều dòng/SV)"
                  templateMode="standard"
                  schoolId={schoolId}
                />
                <ImportBox
                  level="Σ"
                  title="Điểm tổng → PI/PLO (chỉ có điểm tổng kết)"
                  accent="border-indigo-200"
                  badgeClass="bg-indigo-100 text-indigo-700"
                  desc="Trường chỉ có điểm tổng học phần — vẫn đo lên PI/PLO cấp chương trình."
                  format="Mã ngành · Năm khóa · Mã môn · MSSV · Điểm"
                  templateMode="aggregate"
                  postRoute="manager.reports.aggregate-grades"
                  note="Điểm tổng gán cho mọi CLO của môn → cuốn lên PI/PLO (cần ma trận CLO→PI + trọng số PI). Không tách được từng CLO trong môn."
                  schoolId={schoolId}
                />
                <ImportBox
                  level={4}
                  title="Phương án 4 — Điểm theo CÂU HỎI (rubric)"
                  accent="border-violet-200"
                  badgeClass="bg-violet-100 text-violet-700"
                  desc="Đo CLO từ từng câu hỏi — chính xác nhất, không phụ thuộc trọng số điểm."
                  format="Mã lớp · MSSV · Bài kiểm tra · Mã CLO · Điểm đạt · Điểm tối đa"
                  templateMode="question"
                  postRoute="manager.reports.question-grades"
                  note="CLO = Σ điểm đạt / Σ điểm tối đa của các câu đo CLO đó. TÁCH RIÊNG với điểm số: điểm thành phần (chuyên cần/giữa kỳ/cuối kỳ) vẫn nhập bằng Phương án 2 hoặc 3."
                  schoolId={schoolId}
                />
              </div>
              </details>

              {visibleBulk.length > 0 && (
                <Card className="shadow-none">
                  <div className="px-4 py-2.5 border-b">
                    <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Lịch sử nhập điểm gần đây</h4>
                  </div>
                  <CardContent className="space-y-2 p-3">
                    {visibleBulk.map(b => (
                      <div key={b.id} className="border rounded-lg p-3 text-xs">
                        <div className="flex items-center justify-between gap-3">
                          <span className="font-medium truncate">
                            {b.file_name ?? '(không tên)'} · <span title={b.created_at}>{b.created_ago ?? b.created_at}</span>
                            {b.is_stale && <span className="ml-2 text-amber-600 font-normal">· đang xử lý lâu hơn thường lệ — kiểm tra worker</span>}
                          </span>
                          <div className="flex items-center gap-3 shrink-0">
                            <span className="text-muted-foreground">{b.processed_rows}/{b.total_rows} dòng</span>
                            <StatusBadge status={b.status} />
                            {b.downloadable && <a href={route('manager.reports.batch-file', b.uuid)} className="text-primary hover:underline inline-flex items-center gap-0.5"><Download className="w-3 h-3" /> File gốc</a>}
                          </div>
                        </div>
                        <BatchOutcome b={b} onJump={handleStepChange} />
                        {b.error_log?.length > 0 && (
                          <ul className="mt-2 list-inside list-disc space-y-0.5 max-h-32 overflow-auto text-rose-600">
                            {b.error_log.slice(0, 20).map((err, i) => <li key={i}>{err}</li>)}
                            {b.error_log.length > 20 && <li>… và {b.error_log.length - 20} lỗi khác</li>}
                          </ul>
                        )}
                      </div>
                    ))}
                  </CardContent>
                </Card>
              )}

              {/* Đo gián tiếp — khảo sát bên liên quan (tùy chọn, trộn với trực tiếp) */}
              <div className="pt-2 space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <ClipboardCheck className="w-4 h-4 text-violet-600" />
                  <h3 className="text-sm font-semibold uppercase tracking-wider text-muted-foreground">Đo gián tiếp (khảo sát) — tùy chọn</h3>
                  <InfoHint title="Đo gián tiếp">Kết quả khảo sát bên liên quan (Likert) quy về tỉ lệ 0..1 theo PLO, rồi trộn với mức đạt trực tiếp theo trọng số của trường. Không nhập khảo sát thì báo cáo giữ nguyên (chỉ trực tiếp).</InfoHint>
                </div>
                <SurveyImportBox schoolId={schoolId} />

                {visibleSurvey.length > 0 && (
                  <Card className="shadow-none">
                    <div className="px-4 py-2.5 border-b">
                      <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Lịch sử nhập khảo sát gần đây</h4>
                    </div>
                    <CardContent className="space-y-2 p-3">
                      {visibleSurvey.map(b => (
                        <div key={b.id} className="border rounded-lg p-3 text-xs">
                          <div className="flex items-center justify-between gap-3">
                            <span className="font-medium truncate">
                            {b.file_name ?? '(không tên)'} · <span title={b.created_at}>{b.created_ago ?? b.created_at}</span>
                            {b.is_stale && <span className="ml-2 text-amber-600 font-normal">· đang xử lý lâu hơn thường lệ — kiểm tra worker</span>}
                          </span>
                            <div className="flex items-center gap-3 shrink-0">
                              <span className="text-muted-foreground">{b.processed_rows}/{b.total_rows} dòng</span>
                              <StatusBadge status={b.status} />
                              {b.downloadable && <a href={route('manager.reports.batch-file', b.uuid)} className="text-primary hover:underline inline-flex items-center gap-0.5"><Download className="w-3 h-3" /> File gốc</a>}
                            </div>
                          </div>
                          {b.error_log?.length > 0 && (
                            <ul className="mt-2 list-inside list-disc space-y-0.5 max-h-32 overflow-auto text-rose-600">
                              {b.error_log.slice(0, 20).map((err, i) => <li key={i}>{err}</li>)}
                              {b.error_log.length > 20 && <li>… và {b.error_log.length - 20} lỗi khác</li>}
                            </ul>
                          )}
                        </div>
                      ))}
                    </CardContent>
                  </Card>
                )}
              </div>
            </div>
          </div>
        )}

        {/* ===== Bước 3 — Đo & kiểm tra ===== */}
        {step === 3 && (
          <div className="space-y-6">
            <p className="text-sm text-muted-foreground">Bước 3 — Kiểm tra đủ điều kiện đo lường rồi xem trước mức đạt chuẩn đầu ra (PLO) trước khi tạo báo cáo.</p>
            <StepHelp data={STEPS[2]} />

            {/* Kiểm tra sẵn sàng đo lường (read-only) */}
            <Card className="shadow-none">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <ClipboardCheck className="w-4 h-4 text-primary" /> Kiểm tra sẵn sàng đo lường
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
                  <div className="space-y-1.5 w-full sm:w-72">
                    <Label className="text-xs font-medium text-muted-foreground">Ngành</Label>
                    <SearchableSelect
                      value={chkProgramId}
                      onValueChange={(v) => { setChkProgramId(v); setChkCohortId(''); }}
                      className="w-full h-9"
                      placeholder="-- Chọn ngành --"
                      options={schoolPrograms.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                    />
                  </div>
                  <div className="space-y-1.5 w-full sm:w-56">
                    <Label className="text-xs font-medium text-muted-foreground">Khóa</Label>
                    <SearchableSelect
                      value={chkCohortId}
                      onValueChange={setChkCohortId}
                      className="w-full h-9"
                      placeholder={chkProgramId ? '-- Chọn khóa --' : 'Chọn ngành trước'}
                      options={chkCohorts.map(c => ({ value: String(c.id), label: c.name }))}
                    />
                  </div>
                </div>

                {chkLoading && (
                  <p className="text-xs text-muted-foreground flex items-center gap-1.5"><Loader2 className="w-3.5 h-3.5 animate-spin" /> Đang kiểm tra…</p>
                )}

                {!chkLoading && !readiness && (
                  <p className="text-center py-4 text-xs text-muted-foreground">Chọn Ngành &amp; Khóa để kiểm tra cấu trúc trước khi nhập điểm / chạy đo lường.</p>
                )}

                {!chkLoading && readiness && (
                  <div className="space-y-3">
                    <div className="flex flex-wrap items-center gap-3">
                      <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border bg-muted text-muted-foreground">{readiness.strategy_label}</span>
                      {readiness.ready ? (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200"><ShieldCheck className="w-3.5 h-3.5" /> Sẵn sàng đo lường</span>
                      ) : (
                        <span className="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200"><AlertTriangle className="w-3.5 h-3.5" /> Còn thiếu cấu trúc</span>
                      )}
                      <span className="text-xs text-muted-foreground">
                        {readiness.counts.classes} lớp · {readiness.counts.students_in_cohort} SV khóa · {readiness.counts.students_with_grades} SV đã có điểm
                      </span>
                    </div>

                    <ul className="space-y-1.5">
                      {readiness.checks.map((c) => (
                        <li key={c.key} className="flex items-start gap-2 text-sm">
                          {c.ok
                            ? <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />
                            : <XCircle className="w-4 h-4 text-rose-500 shrink-0 mt-0.5" />}
                          <div className="min-w-0">
                            <span className={c.ok ? 'text-foreground' : 'font-medium text-foreground'}>{c.label}</span>
                            <span className="text-muted-foreground"> — {c.detail}</span>
                            {!c.ok && c.hint && (
                              <div className="text-xs text-muted-foreground mt-0.5">
                                {c.hint}
                                {c.link && (
                                  <a href={c.link} className="inline-flex items-center gap-0.5 text-primary hover:underline ml-1">
                                    Mở <ExternalLink className="w-3 h-3" />
                                  </a>
                                )}
                              </div>
                            )}
                          </div>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* PLO Attainment (mức đạt chuẩn) */}
            <Card className="shadow-none">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <CheckCircle2 className="w-4 h-4 text-primary" /> Mức đạt chuẩn đầu ra (xem trước)
                  <InfoHint title="Mức đạt">% sinh viên đạt 1 PLO so với mục tiêu (target). "Đạt" = % đạt ≥ mục tiêu.</InfoHint>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex flex-wrap items-end gap-4">
                  <div className="space-y-1.5 w-full sm:w-72">
                    <Label className="text-xs font-medium text-muted-foreground">Ngành</Label>
                    <SearchableSelect
                      value={viewFilters.view_program_id ? String(viewFilters.view_program_id) : ''}
                      onValueChange={(v) => reloadAttainment(v, viewFilters.view_cohort_id)}
                      className="w-full h-9"
                      placeholder="-- Chọn ngành --"
                      options={schoolPrograms.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                    />
                  </div>
                  <div className="space-y-1.5 w-full sm:w-56">
                    <Label className="text-xs font-medium text-muted-foreground">Khóa (tùy chọn)</Label>
                    <SearchableSelect
                      value={viewFilters.view_cohort_id ? String(viewFilters.view_cohort_id) : 'all'}
                      onValueChange={(v) => reloadAttainment(viewFilters.view_program_id, v === 'all' ? null : v)}
                      className="w-full h-9"
                      placeholder="Tất cả khóa"
                      options={[
                        { value: 'all', label: 'Tất cả khóa' },
                        ...schoolCohorts.map(c => ({ value: String(c.id), label: c.name })),
                      ]}
                    />
                  </div>
                </div>

                {viewFilters.view_program_id && (
                  <PloTargetsEditor programId={viewFilters.view_program_id} />
                )}

                {attainment ? (
                  <>
                    <p className="text-xs text-muted-foreground">
                      Ngưỡng đạt mỗi SV: <b>{fmtScore(attainment.threshold)}</b>{isRatio ? '' : '/10'} · Mục tiêu mặc định: <b>≥ {attainment.target}%</b> SV đạt (có thể đặt riêng từng PLO ở trên).
                    </p>
                    {/* Sinh viên chưa tích lũy đủ tín chỉ bị TÁCH khỏi các con số dưới đây —
                        phải nói rõ, kẻo người đọc tưởng "% đạt" tính trên toàn bộ sĩ số. */}
                    {attainment.eligibility?.students_excluded > 0 && (
                      <div className="p-2.5 rounded-md bg-amber-50 border border-amber-200 text-xs text-amber-800 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                        <span>
                          <b>{attainment.eligibility.students_excluded} sinh viên chưa tích lũy đủ</b> (dưới{' '}
                          {attainment.eligibility.min_completion_percent}% của{' '}
                          {attainment.eligibility.required_credits} tín chỉ) — đã <b>tách khỏi</b> thống kê bên dưới.
                          Số liệu đang tính trên <b>{attainment.eligibility.students_eligible}/{attainment.eligibility.students_total}</b> SV.
                          {' '}Danh sách chi tiết nằm ở mục <b>“Tiến độ tích lũy”</b> trong file báo cáo xuất ra.
                        </span>
                      </div>
                    )}
                    {attainment.eligibility?.required_credits == null && (
                      <p className="text-xs text-muted-foreground">
                        Ngành chưa khai <b>số tín chỉ yêu cầu để tốt nghiệp</b> → không lọc sinh viên nào.
                        SV mới học nửa chương trình vẫn được gộp vào “% đạt”. Khai ở{' '}
                        <b>Quản lý ngành đào tạo</b> để tách nhóm cho đúng.
                      </p>
                    )}
                    {attainment.plos.length > 0 && attainment.plos.every(r => (r.students_measured ?? 0) === 0) && (
                      <div className="p-2.5 rounded-md bg-amber-50 border border-amber-200 text-xs text-amber-800 flex items-start gap-2">
                        <AlertTriangle className="w-4 h-4 shrink-0 mt-0.5" />
                        <span>
                          <b>Chưa đo được PLO nào</b> — có PLO nhưng 0 sinh viên có kết quả. Thường do thiếu điểm đã nhập đúng khung, hoặc thiếu ma trận CLO→PI / PI. Bấm <b>“Kiểm tra sẵn sàng đo lường”</b> ở trên hoặc rà lại <button type="button" onClick={() => handleStepChange(1)} className="underline font-medium">Bước 1 — Khung</button>.
                        </span>
                      </div>
                    )}
                    <div className="overflow-x-auto border rounded-lg">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b bg-muted/50">
                            <th className="p-3 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">PLO</th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Sinh viên đo</th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">{isRatio ? 'TB (tỉ lệ)' : 'Điểm TB'}</th>
                            {attainment.has_indirect && (
                              <>
                                <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">% Trực tiếp</th>
                                <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-violet-600">% Gián tiếp</th>
                              </>
                            )}
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">{attainment.has_indirect ? '% Tổng hợp' : '% Đạt'}</th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mục tiêu</th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">So với mục tiêu</th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">
                              Mức
                              <InfoHint title="Mức">Xếp bậc theo % đạt, dùng chung cho mọi PLO của trường. Ngưỡng từng bậc khai ở <b>Cấu hình OBE</b> của trường (mặc định 80 / 70 / 60%). Khác với cột <b>Kết quả</b>: cột đó so % đạt với <b>mục tiêu</b> riêng của từng PLO.</InfoHint>
                            </th>
                            <th className="p-3 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Kết quả</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y">
                          {attainment.plos.map((row) => (
                            <tr key={row.plo_code} className="hover:bg-muted/30">
                              <td className="p-3">
                                <span className="font-bold text-blue-600">{row.plo_code}</span>
                                <div className="text-xs text-muted-foreground line-clamp-1 max-w-md">{row.plo_description}</div>
                              </td>
                              <td className="p-3 text-center text-muted-foreground">{row.students_measured}</td>
                              <td className="p-3 text-center font-medium">{row.avg_score ?? '—'}</td>
                              {attainment.has_indirect && (
                                <>
                                  <td className="p-3 text-center text-muted-foreground">{row.direct_percent != null ? `${row.direct_percent}%` : '—'}</td>
                                  <td className="p-3 text-center text-violet-600">{row.indirect_percent != null ? `${row.indirect_percent}%` : '—'}</td>
                                </>
                              )}
                              <td className="p-3 text-center font-bold">{row.attainment_percent != null ? `${row.attainment_percent}%` : '—'}</td>
                              <td className="p-3 text-center text-muted-foreground">{row.target_percent != null ? `${row.target_percent}%` : '—'}</td>
                              <td className="p-3 text-center font-medium">
                                {row.gap == null ? '—' : (
                                  <span className={row.gap > 0 ? 'text-rose-600' : 'text-emerald-600'}>
                                    {row.gap > 0 ? `−${row.gap}` : (row.gap < 0 ? `+${Math.abs(row.gap)}` : '0')}
                                  </span>
                                )}
                              </td>
                              <td className="p-3 text-center">
                                <span className="inline-block px-2 py-0.5 rounded text-xs font-bold bg-muted text-muted-foreground" title={row.level_label ?? undefined}>{row.level_label ?? row.level}</span>
                              </td>
                              <td className="p-3 text-center">
                                {row.attainment_percent == null ? (
                                  <span className="text-xs text-muted-foreground">Chưa có dữ liệu</span>
                                ) : row.met_target ? (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700"><CheckCircle2 className="w-3 h-3" /> Đạt</span>
                                ) : (
                                  <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700"><AlertTriangle className="w-3 h-3" /> Chưa đạt</span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                    {attainment.plos.length === 0 && (
                      <p className="text-center py-4 text-xs text-muted-foreground">Ngành này chưa có PLO.</p>
                    )}

                    {/* Biểu đồ mức đạt PLO + nút xuất Excel */}
                    {attainment.plos.some(r => r.attainment_percent != null) && (
                      <div className="border rounded-lg p-3">
                        <div className="flex items-center justify-between gap-2 mb-1">
                          <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Biểu đồ mức đạt PLO</h4>
                          <a
                            href={route('manager.reports.export-excel', { program_id: viewFilters.view_program_id, ...(viewFilters.view_cohort_id ? { cohort_id: viewFilters.view_cohort_id } : {}) })}
                            className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                          >
                            <Download className="w-3.5 h-3.5" /> Tải Excel (PLO + PI + ma trận)
                          </a>
                        </div>
                        <AttainmentChart
                          rows={attainment.plos.map(r => ({ code: r.plo_code, attainment_percent: r.attainment_percent, target_percent: r.target_percent, met_target: r.met_target }))}
                          target={attainment.target}
                        />
                      </div>
                    )}

                    {/* Mức đạt PI (cấp chỉ báo) — trước đây hoàn toàn ẩn dù đã tính & lưu */}
                    {piAttainment && piAttainment.pis.some(r => r.attainment_percent != null) && (
                      <div className="border rounded-lg p-3 space-y-3">
                        <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Mức đạt chỉ báo (PI)</h4>
                        <div className="overflow-x-auto">
                          <table className="w-full text-sm">
                            <thead>
                              <tr className="border-b bg-muted/50">
                                <th className="p-2 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">PI</th>
                                <th className="p-2 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">PLO</th>
                                <th className="p-2 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Sinh viên đo</th>
                                <th className="p-2 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">% Đạt</th>
                                <th className="p-2 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mức</th>
                                <th className="p-2 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Kết quả</th>
                              </tr>
                            </thead>
                            <tbody className="divide-y">
                              {piAttainment.pis.map((row) => (
                                <tr key={row.pi_code} className="hover:bg-muted/30">
                                  <td className="p-2 font-medium">{row.pi_code}</td>
                                  <td className="p-2 text-muted-foreground">{row.plo_code ?? '—'}</td>
                                  <td className="p-2 text-center text-muted-foreground">{row.students_measured}</td>
                                  <td className="p-2 text-center font-bold">{row.attainment_percent != null ? `${row.attainment_percent}%` : '—'}</td>
                                  <td className="p-2 text-center text-muted-foreground">{row.level_label ?? '—'}</td>
                                  <td className="p-2 text-center">
                                    {row.attainment_percent == null
                                      ? <span className="text-muted-foreground">Chưa đo</span>
                                      : row.met_target
                                        ? <span className="text-emerald-600 font-medium">Đạt</span>
                                        : <span className="text-rose-600 font-medium">Chưa đạt</span>}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                        <AttainmentChart
                          rows={piAttainment.pis.map(r => ({ code: r.pi_code, attainment_percent: r.attainment_percent, target_percent: r.target_percent, met_target: r.met_target }))}
                          target={piAttainment.target}
                        />
                      </div>
                    )}
                  </>
                ) : (
                  <p className="text-center py-4 text-xs text-muted-foreground">Chọn Ngành để xem mức đạt PLO.</p>
                )}
              </CardContent>
            </Card>

            {/* Nhật ký đo lường (measurement runs) */}
            {visibleRuns.length > 0 && (
              <Card className="shadow-none">
                <div className="px-4 py-2.5 border-b flex items-center justify-between gap-2">
                  <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Nhật ký đo lường gần đây</h4>
                  <a href={route('manager.measurement-runs.index')} className="text-xs font-medium text-primary hover:underline inline-flex items-center gap-1">
                    Xem tất cả <ExternalLink className="w-3 h-3" />
                  </a>
                </div>
                <CardContent className="p-3 space-y-1.5">
                  {visibleRuns.map((m, i) => (
                    <div key={i} className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs border rounded-lg p-2.5">
                      <span className="text-muted-foreground whitespace-nowrap">{m.created_at}</span>
                      <span className="font-medium">{m.pairs_count} cặp (SV·lớp)</span>
                      {m.program && <span className="text-muted-foreground">ngành {m.program}</span>}
                      {m.cohort && <span className="text-muted-foreground">khóa {m.cohort}</span>}
                      <span className="inline-block px-1.5 py-0.5 rounded bg-muted text-muted-foreground">{m.source}</span>
                      {m.user && <span className="text-muted-foreground ml-auto">bởi {m.user}</span>}
                    </div>
                  ))}
                </CardContent>
              </Card>
            )}
          </div>
        )}

        {/* ===== Bước 4 — Báo cáo ===== */}
        {step === 4 && (
          <div className="space-y-6">
            <p className="text-sm text-muted-foreground">Bước 4 — Tạo báo cáo AUN-QA cho ngành/khóa và tải file CSV về. Báo cáo tạo nền, trạng thái tự cập nhật trong danh sách.</p>
            <StepHelp data={STEPS[3]} />

            {/* Tạo báo cáo & tải về */}
            <Card className="shadow-none border-2 border-primary/20">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <FileText className="w-4 h-4 text-primary" /> Tạo báo cáo &amp; tải về
                </CardTitle>
              </CardHeader>
              <CardContent>
                <form onSubmit={handleGenerate} className="flex flex-col sm:flex-row sm:flex-wrap sm:items-end gap-4">
                  <div className="space-y-1.5 w-full sm:w-72">
                    <Label className="text-xs font-medium text-muted-foreground">Ngành</Label>
                    <SearchableSelect
                      value={programId}
                      onValueChange={(v) => { setProgramId(v); setCohortId(''); }}
                      className="w-full h-9"
                      placeholder="-- Chọn ngành --"
                      options={schoolPrograms.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                    />
                  </div>
                  <div className="space-y-1.5 w-full sm:w-56">
                    <Label className="text-xs font-medium text-muted-foreground">Khóa</Label>
                    <SearchableSelect
                      value={cohortId}
                      onValueChange={setCohortId}
                      className="w-full h-9"
                      placeholder={programId ? '-- Chọn khóa --' : 'Chọn ngành trước'}
                      options={genCohorts.map(c => ({ value: String(c.id), label: c.name }))}
                    />
                  </div>
                  <Button type="submit" disabled={isGenerating || !programId || !cohortId} className="h-9">
                    {isGenerating ? <Loader2 className="w-4 h-4 animate-spin" /> : <FileText className="w-4 h-4" />}
                    <span className="ml-1">Khởi tạo báo cáo</span>
                  </Button>
                </form>
                <p className="text-xs text-muted-foreground mt-3">Báo cáo tạo nền; trạng thái tự cập nhật khi xong (xem danh sách bên dưới). Nếu vừa nhập điểm hoặc đổi mapping/ngưỡng, bấm <b>Đo lường</b> ở Bước 2 trước khi tạo báo cáo.</p>
              </CardContent>
            </Card>

            {/* Snapshots list */}
            <Card className="shadow-none">
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b bg-muted/50">
                      <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                      <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tên báo cáo</th>
                      <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thời gian</th>
                      <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                      <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tải về</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y">
                    {visibleSnapshots.map((snap, index) => (
                      <tr key={snap.uuid} className="hover:bg-muted/30 transition-colors">
                        <td className="p-4 text-muted-foreground">{(snapshots.current_page - 1) * snapshots.per_page + index + 1}</td>
                        <td className="p-4 font-medium">{snap.name}</td>
                        <td className="p-4 text-muted-foreground whitespace-nowrap">{snap.created_at}</td>
                        <td className="p-4 text-center"><StatusBadge status={snap.status} /></td>
                        <td className="p-4 text-center">
                          {snap.downloadable ? (
                            <a href={route('manager.reports.download', snap.uuid)} className="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline">
                              <Download className="w-4 h-4" /> Tải CSV
                            </a>
                          ) : (
                            <span className="text-xs text-muted-foreground">—</span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {visibleSnapshots.length === 0 && (
                <div className="p-12 text-center">
                  <FileText className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
                  <p className="text-sm font-medium text-muted-foreground">Chưa có báo cáo nào cho trường này</p>
                  <p className="text-xs text-muted-foreground/70 mt-1">Chọn Ngành &amp; Khóa ở trên để tạo báo cáo đầu tiên</p>
                </div>
              )}

              <PaginationControls
                currentPage={snapshots.current_page}
                totalPages={snapshots.last_page}
                onPageChange={handlePageChange}
                totalItems={snapshots.total}
              />
            </Card>
          </div>
        )}

        {/* ===== Bước 5 — Cải tiến (CQI/PDCA) ===== */}
        {step === 5 && (
          <div className="space-y-6">
            <p className="text-sm text-muted-foreground">Bước 5 — Khép kín vòng cải tiến: với mỗi PLO chưa đạt mục tiêu, ghi nhận phát hiện · nguyên nhân · kế hoạch hành động · phụ trách, rồi đo lại để so sánh trước/sau (PDCA).</p>
            <Card className="shadow-none">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold uppercase tracking-wider text-muted-foreground flex items-center gap-2">
                  <ClipboardCheck className="w-4 h-4 text-violet-600" /> Hồ sơ cải tiến chất lượng (CQI)
                  <InfoHint title="CQI / PDCA">Vòng cải tiến liên tục mà AUN-QA (PDCA) và ABET (Criterion 4) đều yêu cầu: đo → phân tích PLO chưa đạt → hành động → đo lại.</InfoHint>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex flex-wrap items-end gap-4">
                  <div className="space-y-1.5 w-full sm:w-72">
                    <Label className="text-xs font-medium text-muted-foreground">Ngành</Label>
                    <SearchableSelect
                      value={viewFilters.view_program_id ? String(viewFilters.view_program_id) : ''}
                      onValueChange={(v) => reloadAttainment(v, viewFilters.view_cohort_id)}
                      className="w-full h-9"
                      placeholder="-- Chọn ngành --"
                      options={schoolPrograms.map(p => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                    />
                  </div>
                  <div className="space-y-1.5 w-full sm:w-56">
                    <Label className="text-xs font-medium text-muted-foreground">Khóa (tùy chọn)</Label>
                    <SearchableSelect
                      value={viewFilters.view_cohort_id ? String(viewFilters.view_cohort_id) : 'all'}
                      onValueChange={(v) => reloadAttainment(viewFilters.view_program_id, v === 'all' ? null : v)}
                      className="w-full h-9"
                      placeholder="Tất cả khóa"
                      options={[
                        { value: 'all', label: 'Tất cả khóa' },
                        ...schoolCohorts.map(c => ({ value: String(c.id), label: c.name })),
                      ]}
                    />
                  </div>
                </div>
                <CqiPanel programId={viewFilters.view_program_id} cohortId={viewFilters.view_cohort_id} />
              </CardContent>
            </Card>
          </div>
        )}
      </div>
    </DashboardLayout>
  );
}
