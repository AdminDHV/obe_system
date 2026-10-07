import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Upload } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

/**
 * Điền điểm vào file bảng điểm của phần mềm quản lý đào tạo.
 * Bước 1 tải file lên, bước 2 xác nhận cột nào lấy điểm bài nào rồi tải file đã điền về.
 */
export default function PscSheet({ courseClass, assessments, inspection = null, mapping = {}, token = null, fileName = null }) {
  const { flash } = usePage().props;
  const [chon, setChon] = React.useState(mapping);
  const [dangGui, setDangGui] = React.useState(false);

  React.useEffect(() => setChon(mapping), [token]);

  const taiLen = (e) => {
    const file = e.target.files?.[0];
    if (!file) return;
    router.post(route('manager.classes.psc.inspect', courseClass.uuid), { file }, { forceFormData: true });
  };

  const dienDiem = () => {
    setDangGui(true);
    const params = new URLSearchParams({ token });
    if (fileName) params.set('file_name', fileName);
    Object.entries(chon).forEach(([col, value]) => params.set(`mapping[${col}]`, value || ''));
    window.location.href = `${route('manager.classes.psc.fill', courseClass.uuid)}?${params.toString()}`;
    setTimeout(() => setDangGui(false), 1500);
  };

  return (
    <DashboardLayout>
      <Head title={`Bảng điểm phần mềm đào tạo — ${courseClass.class_code}`} />

      <div className="space-y-5 max-w-4xl">
        <div>
          <Link
            href={route('manager.classes.show', courseClass.uuid)}
            className="text-sm text-muted-foreground hover:text-foreground inline-flex items-center gap-1"
          >
            <ArrowLeft className="h-4 w-4" /> Về lớp {courseClass.class_code}
          </Link>
          <h1 className="text-2xl font-semibold tracking-tight mt-1">Bảng điểm cho phần mềm quản lý đào tạo</h1>
          <p className="text-sm text-muted-foreground mt-1">
            {courseClass.course} · lớp {courseClass.class_code} · học kỳ {courseClass.semester}, năm học {courseClass.academic_year}
          </p>
        </div>

        {flash?.error && (
          <Card className="p-4 border-destructive/40 text-destructive text-sm">{flash.error}</Card>
        )}

        <Card className="p-4 space-y-3">
          <div className="font-medium">Bước 1 — Tải file bảng điểm của phần mềm đào tạo lên</div>
          <p className="text-sm text-muted-foreground">
            Tải đúng file mà phần mềm đào tạo xuất ra cho lớp này. Hệ thống chỉ điền vào các cột điểm,
            mọi cột khác giữ nguyên.
          </p>
          <label className="inline-flex items-center gap-2 text-sm border rounded-md px-3 py-2 cursor-pointer hover:bg-muted/50 w-fit">
            <Upload className="h-4 w-4" />
            <span>Chọn file .xlsx</span>
            <input type="file" accept=".xlsx,.xls" className="hidden" onChange={taiLen} />
          </label>
          {fileName && <div className="text-sm text-muted-foreground">Đang dùng: {fileName}</div>}
        </Card>

        {inspection && (
          <Card className="p-4 space-y-4">
            <div>
              <div className="font-medium">Bước 2 — Xác nhận cột nào lấy điểm bài nào</div>
              <p className="text-sm text-muted-foreground mt-1">
                File có {inspection.students} sinh viên. Hệ thống đã đoán sẵn theo tên cột, đổi lại nếu chưa đúng.
              </p>
            </div>

            <div className="space-y-3">
              {inspection.columns.map((col) => (
                <div key={col.col} className="flex flex-wrap items-center gap-3">
                  <div className="w-64">
                    <div className="text-sm font-medium">{col.label}</div>
                    <div className="text-xs text-muted-foreground">
                      Cột {col.col}{col.group ? ` · ${col.group}` : ''}
                    </div>
                  </div>
                  <Select
                    value={chon[col.col] ?? ''}
                    onValueChange={(v) => setChon({ ...chon, [col.col]: v === 'bo_trong' ? '' : v })}
                  >
                    <SelectTrigger className="h-9 w-80">
                      <SelectValue placeholder="Không điền cột này" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="bo_trong">Không điền cột này</SelectItem>
                      <SelectItem value="qua_trinh">Điểm quá trình (gộp các bài quá trình)</SelectItem>
                      <SelectItem value="cuoi_ky">Điểm cuối kỳ (gộp các bài cuối kỳ)</SelectItem>
                      {assessments.map((a) => (
                        <SelectItem key={a.id} value={a.id}>
                          {a.name} — {a.category} {a.weight}%
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              ))}
            </div>

            <Button onClick={dienDiem} disabled={dangGui}>
              <Download className="h-4 w-4 mr-2" /> Điền điểm và tải file về
            </Button>

            <p className="text-sm text-muted-foreground">
              Sinh viên có trong file mà chưa có điểm trong hệ thống thì ô điểm để nguyên như file gốc.
            </p>
          </Card>
        )}
      </div>
    </DashboardLayout>
  );
}
