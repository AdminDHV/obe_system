import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Download, FileSpreadsheet, Info, ArrowRight } from 'lucide-react';
import { TEMPLATE_GROUPS } from '@/config/templateFiles';

/**
 * BỘ FILE MẪU — một trang liệt kê đủ mọi file mẫu, theo đúng thứ tự phải làm.
 *
 * Trước đây các nút "Tải template" nằm rải rác ở Trung tâm đo lường (lại chia theo bước) và trang
 * Lớp học phần. Bàn giao cho trường mới thì họ không biết cần file gì, lấy ở đâu, làm trước sau
 * ra sao. Trang này trả lời đúng ba câu đó ở một chỗ.
 */
function FileRow({ file }) {
  const href = file.query
    ? `${route(file.routeName)}?${new URLSearchParams(file.query).toString()}`
    : route(file.routeName);

  return (
    <div className="flex items-start justify-between gap-4 py-3 border-b last:border-0">
      <div className="min-w-0">
        <p className="text-sm font-medium flex items-center gap-2 flex-wrap">
          {file.name}
          {file.must && (
            <span className="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-primary/10 text-primary">
              BẮT BUỘC
            </span>
          )}
        </p>
        <p className="text-xs text-muted-foreground mt-0.5">{file.desc}</p>
      </div>
      <a
        href={href}
        className="shrink-0 inline-flex items-center gap-1.5 border border-border bg-card hover:bg-muted/50 px-3 py-1.5 rounded-lg text-xs font-medium transition"
      >
        <Download className="w-3.5 h-3.5" />
        Tải về
      </a>
    </div>
  );
}

export default function Index() {
  return (
    <DashboardLayout>
      <Head title="Bộ file mẫu" />

      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Bộ file mẫu</h1>
          <p className="text-muted-foreground mt-1">
            Toàn bộ file mẫu của hệ thống, xếp theo thứ tự phải làm. Tải về, điền theo dòng ví dụ
            có sẵn (nhớ xóa dòng ví dụ), rồi tải lên ở Trung tâm đo lường.
          </p>
        </div>

        <Card className="shadow-none border-primary/30 bg-primary/5">
          <CardContent className="p-4 flex gap-3">
            <Info className="w-4 h-4 text-primary shrink-0 mt-0.5" />
            <div className="text-sm">
              <p className="font-medium">Chỉ cần 2 file là chạy được</p>
              <p className="text-muted-foreground mt-0.5">
                <strong>File khai báo chương trình</strong> (mỗi khóa làm một lần) và{' '}
                <strong>phiếu lớp học phần</strong> (mỗi lớp một phiếu). File khảo sát chỉ dùng khi
                trường có đo gián tiếp.
              </p>
            </div>
          </CardContent>
        </Card>

        {TEMPLATE_GROUPS.map((nhom) => (
          <Card key={nhom.key} className="shadow-none">
            <CardHeader className="pb-1">
              <CardTitle className="text-sm font-semibold flex items-center gap-2">
                <FileSpreadsheet className="w-4 h-4 text-primary" />
                {nhom.title}
              </CardTitle>
              {nhom.note && <p className="text-xs text-muted-foreground">{nhom.note}</p>}
            </CardHeader>
            <CardContent className="pt-0">
              {nhom.files.map((f) => (
                <FileRow key={f.name} file={f} />
              ))}
            </CardContent>
          </Card>
        ))}


        <div className="flex flex-wrap gap-3">
          <Link
            href={route('manager.reports.index')}
            className="inline-flex items-center gap-2 bg-primary text-primary-foreground px-4 py-2 rounded-lg text-sm font-medium"
          >
            Tới Trung tâm đo lường để tải lên <ArrowRight className="w-4 h-4" />
          </Link>
          <Link
            href={route('manager.guide.index')}
            className="inline-flex items-center gap-2 border border-border px-4 py-2 rounded-lg text-sm font-medium hover:bg-muted/50"
          >
            Xem hướng dẫn từng bước
          </Link>
        </div>
      </div>
    </DashboardLayout>
  );
}
