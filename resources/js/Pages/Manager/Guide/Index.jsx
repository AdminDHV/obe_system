import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import {
  LifeBuoy, Boxes, Upload, Gauge, FileText, ArrowRight, CheckCircle2,
  AlertTriangle, BookOpen, ListChecks, HelpCircle, Sparkles, Table2, Layers,
} from 'lucide-react';
import { CONCEPTS, STEPS, PHUONG_AN } from '@/config/guideContent';

// Mỗi bước trong STEPS PHẢI có icon ở đây. Thiếu một mã ⇒ Icon là undefined ⇒ React lỗi #130 và
// TOÀN BỘ trang hướng dẫn trắng trơn (đã xảy ra khi thêm bước 6). Thêm bước mới thì thêm cả icon.
const STEP_ICONS = { 1: Boxes, 2: Upload, 3: Gauge, 4: FileText, 5: Sparkles, 6: Table2, 7: Layers };

function Section({ id, icon: Icon, title, children }) {
  return (
    <section id={id} className="scroll-mt-20 space-y-4">
      <h2 className="flex items-center gap-2 text-xl font-bold tracking-tight">
        <Icon className="h-5 w-5 text-primary" /> {title}
      </h2>
      {children}
    </section>
  );
}

const TOC = [
  ['luong', 'Sơ đồ luồng tổng'],
  ['cac-buoc', 'Các bước làm chi tiết'],
  ['phuong-an', 'Chọn phương án nào?'],
  ['ket-qua', 'Đọc kết quả & báo cáo'],
  ['tu-dien', 'Từ điển khái niệm'],
  ['faq', 'Câu hỏi thường gặp'],
];

export default function Index() {
  return (
    <DashboardLayout>
      <Head title="Hướng dẫn sử dụng" />

      <div className="mx-auto max-w-4xl space-y-10 pb-16">
        {/* Header */}
        <div className="rounded-xl border-2 border-primary/20 bg-primary/5 p-6">
          <h1 className="flex items-center gap-2 text-2xl font-bold tracking-tight">
            <LifeBuoy className="h-6 w-6 text-primary" /> Hướng dẫn sử dụng
          </h1>
          <p className="mt-2 text-muted-foreground">
            Tài liệu hướng dẫn quy trình đo lường chuẩn đầu ra — từ khai báo khung chương trình, nhập điểm, đo lường đến xuất báo cáo kiểm định.
          </p>
          {/* Mục lục */}
          <div className="mt-4 flex flex-wrap gap-2">
            {TOC.map(([id, label]) => (
              <a key={id} href={`#${id}`}
                className="rounded-full border bg-card px-3 py-1 text-xs font-medium text-muted-foreground transition hover:border-primary hover:text-primary">
                {label}
              </a>
            ))}
          </div>
        </div>

        {/* Dùng thử ngay */}
        <div className="rounded-xl border-2 border-dashed border-amber-300 bg-amber-50/50 p-5">
          <p className="flex items-center gap-2 font-semibold">
            <Sparkles className="h-5 w-5 text-amber-600" /> Trải nghiệm nhanh với dữ liệu mẫu
          </p>
          <p className="mt-1 text-sm text-muted-foreground">
            Tại <b>Trung tâm đo lường → Bước 1</b>, chọn <b>"Nạp dữ liệu mẫu"</b>. Hệ thống tạo sẵn <b>3 trường</b> khác tính chất
            {' '}(kỹ thuật · kinh tế · sư phạm), mỗi trường có khoa, ngành, môn, CLO/PI/PLO, lớp, sinh viên và điểm — đã đo sẵn nên
            xem được ngay bảng mức đạt, biểu đồ và báo cáo. Điểm cố ý rải để có cả PLO đạt lẫn PLO <b>chưa</b> đạt, giống dữ liệu thật.
            Tên trường là tên hư cấu mang nhãn <b>[DEMO]</b> — chọn tại ô <i>Trường đang làm việc</i> để xem.
            Sau khi tham khảo, chọn <b>"Xóa"</b> để gỡ dữ liệu mẫu.
          </p>
          <Button asChild className="mt-3 h-9">
            <Link href={route('manager.reports.index', { step: 1 })}>
              Mở Bước 1 để nạp dữ liệu mẫu <ArrowRight className="h-4 w-4" />
            </Link>
          </Button>
        </div>

        {/* Sơ đồ luồng */}
        <Section id="luong" icon={ArrowRight} title="Sơ đồ luồng tổng">
          <Card className="shadow-none">
            <CardContent className="p-5">
              <pre className="overflow-x-auto rounded-lg bg-muted/50 p-4 text-xs leading-relaxed text-foreground">{`Quy trình gồm 4 bước chính, kèm 3 bước tùy chọn:
  1. Dựng khung  →  2. Nhập điểm  →  3. Đo lường  →  4. Xuất báo cáo
                                       ( →  5. Cải tiến )   ( →  6. Bảng đầu ra & tra cứu )
                                       ( →  7. Cấu trúc CTĐT & độ phủ )

Hệ thống tự tính (sau khi có điểm và khung):
  Điểm bài  →  kết quả môn (CLO)  →  chỉ số (PI)  →  chương trình (PLO)  →  báo cáo`}</pre>
              <p className="mt-3 text-sm text-muted-foreground">
                Bước 1 làm một lần cho mỗi ngành. Bước 2–4 lặp lại mỗi khi có điểm mới. Bước 5 (Cải tiến), bước 6 (Bảng đầu ra cho học viên · khoa · giảng viên) và bước 7 (Cấu trúc CTĐT, để hiện độ phủ &ldquo;1/3 học phần&rdquo;) tùy chọn, làm sau khi đã đo.
              </p>
            </CardContent>
          </Card>
        </Section>

        {/* Các bước làm chi tiết */}
        <Section id="cac-buoc" icon={ListChecks} title="Các bước làm chi tiết">
          <div className="space-y-5">
            {STEPS.map((s) => {
              // Có dự phòng: thiếu icon thì chỉ xấu một ô, chứ không làm trắng cả trang hướng dẫn.
              const Icon = STEP_ICONS[s.n] ?? ListChecks;
              return (
                <Card key={s.n} className="overflow-hidden shadow-none">
                  <div className="flex items-center gap-3 border-b bg-muted/40 px-5 py-3">
                    <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-primary-foreground">
                      {s.n}
                    </span>
                    <Icon className="h-5 w-5 text-primary" />
                    <h3 className="text-base font-bold">{s.title}</h3>
                  </div>
                  <CardContent className="space-y-4 p-5 text-sm">
                    <p><span className="font-semibold text-primary">Mục đích: </span>{s.purpose}</p>

                    {s.file && (
                      <div>
                        <p className="mb-1 font-semibold">File cần dùng: <span className="font-normal">{s.file.name}</span></p>
                        {s.file.note && <p className="mb-2 text-xs text-muted-foreground">{s.file.note}</p>}
                        <div className="overflow-hidden rounded-lg border">
                          <table className="w-full text-xs">
                            <tbody className="divide-y">
                              {s.file.columns.map((c, i) => (
                                <tr key={i}>
                                  <td className="w-2/5 bg-muted/30 px-3 py-2 font-medium align-top">{c.col}</td>
                                  <td className="px-3 py-2 text-muted-foreground">{c.meaning}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </div>
                      </div>
                    )}

                    <div>
                      <p className="mb-1 font-semibold">Thao tác:</p>
                      <ol className="ml-4 list-decimal space-y-1 text-muted-foreground">
                        {s.actions.map((a, i) => <li key={i}>{a}</li>)}
                      </ol>
                    </div>

                    <div className="flex items-start gap-2 rounded-lg bg-emerald-50 p-3 text-emerald-900">
                      <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
                      <span><b>Kết quả: </b>{s.result}</span>
                    </div>

                    <div>
                      <p className="mb-1 flex items-center gap-1.5 font-semibold text-amber-700">
                        <AlertTriangle className="h-4 w-4" /> Lỗi thường gặp:
                      </p>
                      <ul className="space-y-1.5 text-muted-foreground">
                        {s.errors.map((e, i) => (
                          <li key={i}><b>{e.when}</b> → {e.fix}</li>
                        ))}
                      </ul>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
          </div>
          <Button asChild className="gap-1.5">
            <Link href={route('manager.reports.index')}>
              Mở Trung tâm đo lường để làm theo <ArrowRight className="h-4 w-4" />
            </Link>
          </Button>
        </Section>

        {/* 4. Phương án */}
        <Section id="phuong-an" icon={ListChecks} title="Chọn phương án nào?">
          <Card className="shadow-none">
            <CardContent className="p-0">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/50 text-left text-xs uppercase tracking-wider text-muted-foreground">
                    <th className="p-3">Phương án</th><th className="p-3">Khóa</th>
                    <th className="p-3">File điểm</th><th className="p-3">Cách đo</th>
                  </tr>
                </thead>
                <tbody className="divide-y">
                  {PHUONG_AN.map((p, i) => (
                    <tr key={i} className="align-top">
                      <td className="p-3 font-semibold">{p.pa}</td>
                      <td className="p-3"><span className="rounded bg-blue-50 px-2 py-0.5 text-xs font-bold text-blue-700">{p.khoa}</span></td>
                      <td className="p-3 text-muted-foreground">{p.diem}</td>
                      <td className="p-3 text-muted-foreground">{p.cachDo}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
          <p className="text-sm text-muted-foreground">
            <b>Không cần chọn thủ công</b> — hệ thống tự nhận phương án theo năm của khóa. Các ô nhập ở Bước 2 chỉ nhằm dùng đúng định dạng file.
          </p>
        </Section>

        {/* 6. Đọc kết quả */}
        <Section id="ket-qua" icon={Gauge} title="Đọc kết quả & báo cáo">
          <Card className="shadow-none">
            <CardContent className="space-y-2 p-5 text-sm text-muted-foreground">
              <p className="text-foreground">
                Hệ thống tự động tính toán. Từ điểm sinh viên, kết quả được quy về mức đạt ở ba cấp:
                môn (CLO), chỉ số (PI) và chương trình (PLO). Môn nhiều tín chỉ và mức đóng góp cao có ảnh hưởng lớn hơn.
              </p>
              <p><b className="text-foreground">% đạt:</b> tỉ lệ sinh viên đạt 1 PLO (hoặc PI) — vượt ngưỡng đạt.</p>
              <p><b className="text-foreground">Mục tiêu (target):</b> tỉ lệ cần đạt, ví dụ 70%. <b className="text-foreground">"Đạt"</b> nghĩa là % đạt ≥ mục tiêu.</p>
              <p><b className="text-foreground">Mức:</b> phân loại cao/thấp theo % đạt (dùng cho kiểm định).</p>
              <p><b className="text-foreground">Trên màn hình (Bước 3):</b> bảng mức đạt PLO <i>và PI (chỉ báo)</i> kèm <b className="text-foreground">biểu đồ cột</b> so với đường mục tiêu.</p>
              <p><b className="text-foreground">File báo cáo:</b> CSV (bảng PLO + ma trận SV×PLO) hoặc <b className="text-foreground">Excel 4 sheet</b> (Tổng quan · Mức đạt PLO · Mức đạt PI · Ma trận SV×PLO). Mở bằng Excel.</p>
            </CardContent>
          </Card>

          {/* Ví dụ minh hoạ cách tính */}
          <Card className="shadow-none">
            <CardContent className="space-y-2 p-5 text-sm">
              <p className="font-semibold">Ví dụ minh hoạ cách tính</p>
              <p className="text-muted-foreground">
                1 chỉ số <b>PI1</b> được 2 môn bồi đắp: Môn A (3 tín chỉ, mức M = hệ số 3, điểm PI của môn = 7) và
                {' '}Môn B (2 tín chỉ, mức R = hệ số 2, điểm = 6).
              </p>
              <pre className="overflow-x-auto rounded-lg bg-muted/50 p-3 text-xs leading-relaxed text-foreground">{`PI1 = ( 3×3×7 + 2×2×6 )  /  ( 3×3×10 + 2×2×10 )
    = ( 63 + 24 )      /  ( 90 + 40 )
    = 87 / 130  ≈  0.669   (tức 66.9%)`}</pre>
              <p className="text-muted-foreground">
                PLO gộp các PI theo trọng số PI. Ví dụ PLO1 = PI1×0.5 + PI2×0.5. Môn nhiều tín chỉ và mức cao (M/A) kéo kết quả mạnh hơn.
              </p>
              <p className="text-xs text-muted-foreground">Hệ thống tự động thực hiện toàn bộ phép tính; ví dụ trên chỉ nhằm minh hoạ cách một con số được hình thành.</p>
            </CardContent>
          </Card>
        </Section>

        {/* 7. Từ điển */}
        <Section id="tu-dien" icon={BookOpen} title="Từ điển khái niệm">
          <div className="grid gap-3 sm:grid-cols-2">
            {CONCEPTS.map((c, i) => (
              <Card key={i} className="shadow-none">
                <CardContent className="p-4">
                  <p className="text-sm font-semibold">{c.term}</p>
                  <p className="mt-1 text-xs text-muted-foreground">{c.short}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        </Section>

        {/* 8. FAQ */}
        <Section id="faq" icon={HelpCircle} title="Câu hỏi thường gặp">
          <div className="space-y-2 text-sm">
            {[
              ['Không thấy trường trong ô "Trường đang làm việc"?', 'Vào Danh mục → Trường để thêm/bật "Đang hoạt động". Trường mới phải bật hoạt động mới hiện.'],
              ['Nhập điểm xong nhưng PLO vẫn trống?', 'Vào Bước 3 bấm "Tính lại toàn trường". Đo lường chỉ chạy khi bạn bấm hoặc khi nhập điểm mới.'],
              ['Báo "chưa sẵn sàng đo lường"?', 'Thiếu khung: thường là chưa có PLO/PI hoặc bài đánh giá chưa gắn CLO. Quay lại Bước 1 bổ sung.'],
              ['Khóa cũ (≤2024) có cần khai PI/PLO không?', 'Mặc định không — PA1 chỉ cần CLO của môn, thả file điểm là tính (tự tạo khóa/lớp/SV). Nếu muốn khóa cũ vẫn ra PI/PLO thì dùng ô "Σ — Điểm tổng → PI/PLO" và khai đủ PLO/PI + ma trận CLO→PI.'],
              ['Trường chỉ có điểm tổng học phần (không có điểm thành phần) thì đo được PI/PLO không?', 'Được. Dùng ô "Σ — Điểm tổng → PI/PLO" ở Bước 2: hệ thống gán điểm tổng cho mọi CLO của môn rồi cuốn lên PI/PLO cấp chương trình (cần ma trận CLO→PI + trọng số PI ở Khung). Đổi lại: không tách được chi tiết tới từng CLO trong môn.'],
              ['Ai đã sửa điểm, khi nào — có xem được không?', 'Có. Mọi thay đổi điểm (kể cả qua import) được ghi ở "Nhật ký hoạt động" (menu Hệ thống), lọc theo StudentGrade. Lịch sử import và nhật ký đo lường cũng có màn hình riêng.'],
              ['Có trùng mã giữa 2 trường được không?', 'Được. Mã ngành/môn/MSSV/lớp chỉ cần unique TRONG 1 trường.'],
            ].map(([q, a], i) => (
              <Card key={i} className="shadow-none">
                <CardContent className="p-4">
                  <p className="font-semibold">{q}</p>
                  <p className="mt-1 text-muted-foreground">{a}</p>
                </CardContent>
              </Card>
            ))}
          </div>
        </Section>
      </div>
    </DashboardLayout>
  );
}
