import React, { useState, useMemo } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Search, GraduationCap, AlertCircle, ChevronDown, Printer,
  CheckCircle2, CircleDashed, BookOpen, HelpCircle,
} from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';

/**
 * Cổng tra cứu kết quả học tập — trang CÔNG KHAI, người đọc là HỌC VIÊN chứ không phải cán bộ.
 *
 * Vì vậy toàn trang tránh từ chuyên môn: "PLO" gọi là "chuẩn đầu ra" và lấy chính câu mô tả làm
 * tiêu đề (mã chỉ là nhãn phụ); "PI" gọi là "tiêu chí"; mức I/R/M/A dịch ra tiếng Việt.
 * Chỉ hiện kết quả của CHÍNH người tra — không danh sách, không tìm kiếm.
 */

/** Mức độ học phần đóng góp vào một tiêu chí. Mã I/R/M/A vô nghĩa với học viên. */
const MUC_DO = {
  I: { nhan: 'Làm quen', y: 'Học phần giới thiệu nội dung này' },
  R: { nhan: 'Rèn luyện', y: 'Học phần luyện tập, củng cố nội dung này' },
  M: { nhan: 'Thành thạo', y: 'Học phần yêu cầu bạn làm chủ nội dung này' },
  A: { nhan: 'Đánh giá', y: 'Học phần dùng để đánh giá nội dung này' },
};

export default function Lookup({ result, schools = [], school = null }) {
  const { errors } = usePage().props;
  const [form, setForm] = useState({ school: school ?? '', student_code: '', lookup_code: '' });
  // Mã số học viên chỉ duy nhất trong một trường, nên nhiều trường thì phải chọn trường trước.
  const nhieuTruong = schools.length > 1;
  const truong = schools.find((s) => s.code === (form.school || school)) ?? null;
  const [moRong, setMoRong] = useState(null);
  const [moHuongDan, setMoHuongDan] = useState(false);

  const submit = (e) => {
    e.preventDefault();
    // Giữ những gì đã nhập (cả trường đã chọn) khi tra sai.
    router.post(route('lookup.submit'), form, { preserveScroll: true, preserveState: 'errors' });
  };

  /**
   * Hai thang điểm cùng tồn tại: phương pháp trọng số trả TỈ LỆ 0..1, phương pháp cũ trả điểm
   * thang 10. Nhân 100 vô điều kiện thì trường dùng thang 10 sẽ hiện "820%".
   */
  const laTiLe = result?.obe?.is_ratio !== false;
  const phanTram = (v) => (v === null || v === undefined ? null : laTiLe ? v * 100 : v * 10);
  const hienDiem = (v) => {
    const p = phanTram(v);
    return p === null ? '—' : laTiLe ? `${p.toFixed(1)}%` : `${Number(v).toFixed(1)}/10`;
  };
  /** Điểm môn về từ máy chủ với số lẻ khác nhau (8.67, 9, 8) — trông như lỗi. Thống nhất 2 số. */
  const diemMon = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(2));

  const nguongChu = result
    ? (laTiLe
      ? `${(result.obe.threshold * 100).toFixed(0)}%`
      : `${Number(result.obe.threshold).toFixed(1)}/10`)
    : '';

  // Gom tiêu chí theo từng chuẩn đầu ra — danh sách phẳng khiến học viên không thấy quan hệ.
  const tieuChiTheoChuan = useMemo(() => {
    const map = {};
    (result?.pis ?? []).forEach((pi) => {
      (map[pi.plo_code] ??= []).push(pi);
    });
    return map;
  }, [result]);

  const soDat = result?.plos.filter((p) => p.passed).length ?? 0;
  const tongChuan = result?.plos.length ?? 0;

  const doPhu = result?.obe?.coverage;

  return (
    <div className="min-h-screen bg-slate-50">
      <Head title="Cổng tra cứu kết quả học tập" />

      <header className="bg-white border-b print:border-b-2">
        <div className="mx-auto w-full max-w-4xl px-4 py-4 flex items-center gap-3">
          <div className="flex h-10 min-w-10 max-w-24 shrink-0 items-center justify-center rounded-lg bg-primary px-2 text-primary-foreground font-bold text-sm">
            {truong?.short_name ? (
              <span className="truncate">{truong.short_name}</span>
            ) : (
              <GraduationCap className="w-5 h-5" />
            )}
          </div>
          <div className="min-w-0">
            <p className="font-semibold leading-tight">Cổng tra cứu kết quả học tập</p>
            <p className="text-xs text-muted-foreground leading-snug mt-0.5">
              {truong ? truong.name : 'Chọn trường của bạn để tra cứu'}
            </p>
          </div>
        </div>
      </header>

      <main className="mx-auto w-full max-w-4xl px-4 py-8 space-y-6">
        {/* Ô nhập — khi đã có kết quả thì thu gọn lại để nhường chỗ cho nội dung. */}
        <Card className="shadow-sm print:hidden">
          <CardContent className="p-5 sm:p-6">
            {!result && (
              <div className="mb-5">
                <h1 className="text-lg font-semibold">Xem kết quả học tập của bạn</h1>
                <p className="text-sm text-muted-foreground mt-1">
                  {nhieuTruong
                    ? 'Chọn trường, rồi nhập mã số học viên và mã tra cứu được in trên phiếu kết quả do khoa cấp.'
                    : 'Nhập mã số học viên và mã tra cứu được in trên phiếu kết quả do khoa cấp.'}
                </p>
              </div>
            )}

            <form onSubmit={submit} className="grid grid-cols-[minmax(0,1fr)] gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end">
              {nhieuTruong && (
                <div className="space-y-1.5 sm:col-span-3">
                  <Label htmlFor="truong" className="text-xs font-medium">Trường</Label>
                  <Select
                    value={form.school || undefined}
                    onValueChange={(v) => setForm({ ...form, school: v })}
                  >
                    <SelectTrigger id="truong" className="h-11 sm:h-9 min-w-0 gap-2 text-base sm:text-sm bg-white [&>span]:truncate">
                      <SelectValue placeholder="Chọn trường của bạn" />
                    </SelectTrigger>
                    <SelectContent>
                      {schools.map((s) => (
                        <SelectItem key={s.code} value={s.code} className="py-2.5 sm:py-1.5">
                          {s.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  {errors?.school && (
                    <p className="flex items-start gap-2 text-sm text-red-600">
                      <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                      {errors.school}
                    </p>
                  )}
                </div>
              )}
              <div className="space-y-1.5">
                <Label htmlFor="mssv" className="text-xs font-medium">Mã số học viên</Label>
                <Input
                  id="mssv"
                  value={form.student_code}
                  onChange={(e) => setForm({ ...form, student_code: e.target.value })}
                  placeholder="Ví dụ: 2312HV9001"
                  autoComplete="off"
                  required
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="ma" className="text-xs font-medium">Mã tra cứu</Label>
                <Input
                  id="ma"
                  value={form.lookup_code}
                  onChange={(e) => setForm({ ...form, lookup_code: e.target.value })}
                  placeholder="Ví dụ: K7M2-9QXD"
                  autoComplete="off"
                  required
                />
              </div>
              <Button type="submit" className="gap-2 h-10">
                <Search className="w-4 h-4" />
                Xem kết quả
              </Button>
            </form>

            {errors?.lookup_code && (
              <p className="mt-3 flex items-start gap-2 text-sm text-red-600">
                <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                {errors.lookup_code}
              </p>
            )}

            <p className="mt-4 text-xs text-muted-foreground">
              Chưa có mã tra cứu hoặc quên mã? Liên hệ khoa để được cấp lại — mã được in trên
              phiếu kết quả cá nhân của bạn.
            </p>
          </CardContent>
        </Card>

        {/* Chưa tra thì trang chỉ có mỗi ô nhập trôi giữa khoảng trắng — nói trước sẽ xem được gì
            để người vào lần đầu biết đây là chỗ nào và có đáng bỏ công tìm phiếu không. */}
        {!result && (
          <div className="grid gap-4 sm:grid-cols-3">
            {[
              {
                Icon: GraduationCap,
                tieuDe: 'Tiến độ tích lũy',
                noiDung: 'Số tín chỉ đã tích lũy, số môn đã đạt và mức hoàn thành chương trình.',
              },
              {
                Icon: CheckCircle2,
                tieuDe: 'Chuẩn đầu ra',
                noiDung: 'Bạn đáp ứng từng chuẩn đầu ra của chương trình tới đâu.',
              },
              {
                Icon: BookOpen,
                tieuDe: 'Học phần đóng góp',
                noiDung: 'Những học phần nào đã góp vào từng tiêu chí, kèm điểm của bạn.',
              },
            ].map(({ Icon, tieuDe, noiDung }) => (
              <div key={tieuDe} className="rounded-lg border bg-white p-4">
                <Icon className="w-5 h-5 text-primary" />
                <p className="text-sm font-medium mt-2.5">{tieuDe}</p>
                <p className="text-xs text-muted-foreground mt-1 leading-relaxed">{noiDung}</p>
              </div>
            ))}
          </div>
        )}

        {result && (
          <>
            {/* ---- Thông tin học viên + kết luận chung ---- */}
            <Card className="shadow-sm">
              <CardContent className="p-5 sm:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                  <div className="min-w-0">
                    <h2 className="text-xl font-bold">{result.student.full_name}</h2>
                    <p className="text-sm text-muted-foreground mt-1">
                      Mã số {result.student.student_code}
                      {result.student.program && <> · {result.student.program}</>}
                      {result.student.cohort && <> · Khóa {result.student.cohort}</>}
                      {doPhu?.chosen_groups?.length > 0 && <> · {doPhu.chosen_groups.join(', ')}</>}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    className="gap-2 print:hidden"
                    onClick={() => window.print()}
                  >
                    <Printer className="w-4 h-4" />
                    In trang này
                  </Button>
                </div>

                <div className={cn(
                  'mt-5 flex items-start gap-3 rounded-lg border p-4',
                  soDat === tongChuan ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50',
                )}>
                  {soDat === tongChuan
                    ? <CheckCircle2 className="w-5 h-5 mt-0.5 shrink-0 text-emerald-600" />
                    : <CircleDashed className="w-5 h-5 mt-0.5 shrink-0 text-amber-600" />}
                  <div className="text-sm">
                    <p className="font-semibold">
                      Bạn đã đạt {soDat}/{tongChuan} chuẩn đầu ra của chương trình.
                    </p>
                    <p className="text-muted-foreground mt-0.5">
                      {soDat === tongChuan
                        ? 'Kết quả hiện tại đáp ứng toàn bộ chuẩn đầu ra đã công bố.'
                        : 'Những chuẩn chưa đạt được đánh dấu bên dưới — bạn có thể cải thiện ở các học phần liên quan.'}
                    </p>
                  </div>
                </div>

                {/* Chưa chọn hướng chuyên ngành thì phần "đã học mấy trên mấy học phần" mới chỉ
                    tính khối chung. Nói rõ, đừng để học viên tưởng mình sắp học xong. */}
                {doPhu?.pending_choice && (
                  <p className="mt-3 text-xs text-muted-foreground">
                    Bạn chưa chọn hướng chuyên ngành, nên số học phần bên dưới mới tính phần
                    bắt buộc chung. Sau khi chọn hướng, con số này sẽ đầy đủ hơn.
                  </p>
                )}
              </CardContent>
            </Card>

            {/* ---- Tiến độ tích lũy ---- */}
            <Card className="shadow-sm">
              <CardContent className="p-5 sm:p-6">
                <h3 className="text-sm font-semibold flex items-center gap-2">
                  <GraduationCap className="w-4 h-4 text-primary" />
                  Tiến độ tích lũy
                </h3>

                <div className="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-5">
                  <ChiSo
                    nhan="Tín chỉ đã tích lũy"
                    giaTri={result.progress.credits_earned}
                    phu={result.progress.required_credits
                      ? `trên tổng ${result.progress.required_credits} tín chỉ`
                      : 'của các môn đã đạt'}
                  />
                  <ChiSo
                    nhan="Tín chỉ đã học"
                    giaTri={result.progress.credits_attempted}
                    phu="gồm cả môn chưa đạt"
                  />
                  <ChiSo
                    nhan="Môn đã đạt"
                    giaTri={`${result.progress.courses_passed}/${result.progress.courses_graded}`}
                    phu="trên số môn đã có điểm"
                  />
                  {/* Ngành chưa khai số tín chỉ tốt nghiệp thì KHÔNG có mẫu số — hiện "—" trơ
                      trọi khiến học viên tưởng hệ thống lỗi, nên nói rõ ra. */}
                  {result.progress.required_credits ? (
                    <ChiSo
                      nhan="Mức hoàn thành"
                      giaTri={`${result.progress.completion_percent}%`}
                      phu="của chương trình đào tạo"
                    />
                  ) : (
                    <div>
                      <p className="text-xs text-muted-foreground">Mức hoàn thành</p>
                      <p className="text-sm text-muted-foreground mt-1.5 leading-snug">
                        Chưa tính được vì chương trình chưa khai báo tổng số tín chỉ tốt nghiệp.
                      </p>
                    </div>
                  )}
                </div>

                {result.progress.required_credits && (
                  <div className="mt-5 h-2 w-full rounded-full bg-slate-200 overflow-hidden">
                    <div
                      className="h-full rounded-full bg-primary transition-all"
                      style={{ width: `${Math.min(100, result.progress.completion_percent)}%` }}
                    />
                  </div>
                )}
              </CardContent>
            </Card>

            {/* ---- Hướng dẫn đọc: đặt TRƯỚC bảng điểm để học viên không hiểu sai con số ---- */}
            <Card className="shadow-sm border-slate-200">
              <CardContent className="p-0">
                <button
                  type="button"
                  onClick={() => setMoHuongDan(!moHuongDan)}
                  className="w-full flex items-center gap-2.5 p-4 sm:px-6 text-left hover:bg-slate-50 rounded-lg transition"
                >
                  <HelpCircle className="w-4 h-4 shrink-0 text-primary" />
                  <span className="text-sm font-medium flex-1">Cách đọc kết quả này</span>
                  <ChevronDown className={cn('w-4 h-4 shrink-0 text-muted-foreground transition',
                    moHuongDan && 'rotate-180')} />
                </button>

                {moHuongDan && (
                  <div className="px-4 sm:px-6 pb-5 space-y-3 text-sm text-muted-foreground">
                    <p>
                      <b className="text-foreground">Chuẩn đầu ra</b> là những điều nhà trường cam
                      kết bạn làm được khi tốt nghiệp. Mỗi chuẩn được đánh giá qua nhiều{' '}
                      <b className="text-foreground">tiêu chí</b> nhỏ, và mỗi tiêu chí lại được đo
                      bằng điểm của những học phần liên quan.
                    </p>
                    <p>
                      Con số bên cạnh mỗi chuẩn cho biết bạn đáp ứng chuẩn đó tới đâu. Từ{' '}
                      <b className="text-foreground">{nguongChu}</b> trở lên được xem là đạt.
                    </p>
                    <p>
                      Kết quả tính trên những học phần bạn <b className="text-foreground">đã có
                      điểm</b>. Học phần chưa học sẽ được cộng vào khi có điểm, nên con số còn
                      thay đổi trong quá trình học.
                    </p>
                    <p className="text-xs">
                      Thấy số liệu chưa đúng? Hãy liên hệ khoa hoặc cố vấn học tập để được đối chiếu.
                    </p>
                  </div>
                )}
              </CardContent>
            </Card>

            {/* ---- Chuẩn đầu ra + tiêu chí lồng bên trong ---- */}
            <Card className="shadow-sm">
              <CardContent className="p-5 sm:p-6">
                <h3 className="text-sm font-semibold">Kết quả theo từng chuẩn đầu ra</h3>
                <p className="text-xs text-muted-foreground mt-1">
                  Bấm vào một chuẩn để xem các tiêu chí và học phần đã đóng góp vào chuẩn đó.
                </p>

                <div className="mt-4 space-y-3">
                  {result.plos.map((chuan) => {
                    const dangMo = moRong === chuan.code;
                    const tieuChi = tieuChiTheoChuan[chuan.code] ?? [];

                    return (
                      <div key={chuan.code} className="rounded-lg border overflow-hidden">
                        <button
                          type="button"
                          onClick={() => setMoRong(dangMo ? null : chuan.code)}
                          className="w-full text-left p-4 hover:bg-slate-50 transition"
                        >
                          <div className="flex items-start gap-3">
                            <ChevronDown className={cn(
                              'w-4 h-4 mt-1 shrink-0 text-muted-foreground transition print:hidden',
                              dangMo && 'rotate-180')} />

                            <div className="min-w-0 flex-1">
                              {/* Câu mô tả làm tiêu đề, mã chỉ là nhãn phụ — học viên đọc chữ,
                                  cán bộ mới cần mã. */}
                              <p className="text-sm font-medium leading-snug">
                                {chuan.name || `Chuẩn đầu ra ${chuan.code}`}
                              </p>
                              <p className="text-xs text-muted-foreground mt-1">
                                {chuan.code}
                                {tieuChi.length > 0 && <> · {tieuChi.length} tiêu chí</>}
                              </p>

                              <div className="mt-2.5 flex items-center gap-3">
                                <div className="flex-1 h-2 rounded-full bg-slate-200 overflow-hidden">
                                  <div
                                    className={cn('h-full rounded-full',
                                      chuan.passed ? 'bg-emerald-500' : 'bg-amber-500')}
                                    style={{ width: `${Math.min(100, phanTram(chuan.score) ?? 0)}%` }}
                                  />
                                </div>
                                <span className="text-sm font-semibold tabular-nums w-16 text-right">
                                  {hienDiem(chuan.score)}
                                </span>
                                <TrangThai dat={chuan.passed} />
                              </div>
                            </div>
                          </div>
                        </button>

                        {dangMo && (
                          <div className="border-t bg-slate-50/60 p-4 space-y-4">
                            {tieuChi.length === 0 && (
                              <p className="text-xs text-muted-foreground">
                                Chuẩn này chưa có tiêu chí nào được đo.
                              </p>
                            )}

                            {tieuChi.map((tc) => (
                              <div key={tc.pi_code} className="rounded-lg border bg-white p-3.5">
                                {/* Điện thoại: điểm xuống dòng dưới, không ép câu mô tả thành
                                    cột chữ hẹp một hai từ mỗi dòng. */}
                                <div className="sm:flex sm:items-start sm:gap-3">
                                  <div className="min-w-0 sm:flex-1">
                                    <p className="text-[11px] uppercase tracking-wide text-muted-foreground">
                                      Tiêu chí {tc.pi_code}
                                    </p>
                                    <p className="text-sm leading-snug mt-0.5">
                                      {tc.pi_description || tc.pi_code}
                                    </p>
                                  </div>
                                  <div className="mt-2 flex items-center gap-3 sm:mt-0 sm:shrink-0">
                                    <span className="text-sm font-medium tabular-nums sm:w-16 sm:text-right">
                                      {hienDiem(tc.achieved_score)}
                                    </span>
                                    <TrangThai dat={tc.passed} />
                                  </div>
                                </div>

                                {/* Mẫu số là chỗ dễ hiểu nhầm nhất: 80% khi mới học 1 trong 3 học
                                    phần không giống 80% khi đã học đủ 3. Nói thẳng bằng câu chữ,
                                    không bắt học viên tự suy ra từ "1/3". */}
                                <TienDoTieuChi da={tc.courses_done} tong={tc.courses_planned} />

                                {tc.courses.length > 0 && (
                                  <div className="mt-3 pt-3 border-t">
                                    <p className="text-[11px] uppercase tracking-wide text-muted-foreground mb-1.5">
                                      Học phần đã đóng góp
                                    </p>
                                    <div className="divide-y">
                                      {tc.courses.map((mon) => {
                                        const muc = MUC_DO[mon.level];
                                        return (
                                          <div key={mon.course_code}
                                            className="flex items-start justify-between gap-3 py-2">
                                            <div className="min-w-0">
                                              <p className="text-xs flex items-start gap-1.5">
                                                <BookOpen className="w-3 h-3 mt-0.5 shrink-0 text-muted-foreground" />
                                                <span>{mon.course_name || mon.course_code}</span>
                                              </p>
                                              <p className="text-[11px] text-muted-foreground mt-0.5 ml-[18px]">
                                                {mon.credits} tín chỉ
                                                {muc && <span title={muc.y}> · {muc.nhan}</span>}
                                              </p>
                                            </div>
                                            <span className="text-xs font-semibold tabular-nums shrink-0">
                                              {diemMon(mon.course_score)}
                                            </span>
                                          </div>
                                        );
                                      })}
                                    </div>
                                  </div>
                                )}
                              </div>
                            ))}
                          </div>
                        )}
                      </div>
                    );
                  })}
                </div>
              </CardContent>
            </Card>

            <p className="text-xs text-muted-foreground text-center pb-4">
              Kết quả mang tính tham khảo trong quá trình học. Bản chính thức do phòng đào tạo cấp.
            </p>
          </>
        )}
      </main>
    </div>
  );
}

function ChiSo({ nhan, giaTri, phu }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{nhan}</p>
      <p className="text-2xl font-bold tracking-tight mt-0.5 tabular-nums">{giaTri}</p>
      {phu && <p className="text-xs text-muted-foreground mt-0.5 leading-snug">{phu}</p>}
    </div>
  );
}

/**
 * "Đã đo qua 1 trong 3 học phần" — mẫu số đếm theo LỘ TRÌNH RIÊNG của học viên (phần bắt buộc
 * cộng hướng chuyên ngành bạn ấy chọn), nên bạn học hướng Y tế không bị đếm môn của hướng khác.
 * Trường chưa khai nhóm học phần thì `tong` về null và khối này biến mất — thà không hiện còn hơn
 * hiện một mẫu số sai.
 */
function TienDoTieuChi({ da, tong }) {
  if (!tong) return null;

  const xong = da >= tong;
  const phanTram = Math.round((da / tong) * 100);

  return (
    <div className="mt-3 flex items-center gap-2.5">
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200">
        <div
          className={cn('h-full rounded-full', xong ? 'bg-emerald-500' : 'bg-sky-500')}
          style={{ width: `${phanTram}%` }}
        />
      </div>
      <span className="shrink-0 text-[11px] text-muted-foreground">
        {xong
          ? `Đã học đủ ${tong} học phần đo tiêu chí này`
          : `Đã học ${da}/${tong} học phần đo tiêu chí này`}
      </span>
    </div>
  );
}

function TrangThai({ dat }) {
  return (
    <span className={cn(
      'shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium',
      dat ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700',
    )}>
      {dat ? 'Đạt' : 'Chưa đạt'}
    </span>
  );
}
