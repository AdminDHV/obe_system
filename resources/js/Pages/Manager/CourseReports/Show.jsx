import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Download } from 'lucide-react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

/** "98.44" → "98,44%" */
const pct = (v) => (v === null || v === undefined ? '—' : `${Number(v).toFixed(2).replace('.', ',')}%`);

function BangClo({ clo }) {
  const soDong = clo.dong.reduce((n, d) => n + d.lop.length, 0);

  return (
    <Card className="p-4">
      <div className="font-medium">{clo.code}: {clo.description}</div>
      <div className="text-sm text-muted-foreground mt-1">
        Mục tiêu: yêu cầu trên {clo.muc_tieu}% sinh viên đạt.
      </div>

      <div className="mt-3 overflow-x-auto">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr className="bg-muted/50">
              <th rowSpan={2} className="border p-2 text-left font-medium">Phương pháp đánh giá</th>
              <th rowSpan={2} className="border p-2 font-medium">Lớp</th>
              <th colSpan={2} className="border p-2 font-medium">Số lượng sinh viên</th>
            </tr>
            <tr className="bg-muted/50">
              <th className="border p-2 font-medium">Đạt</th>
              <th className="border p-2 font-medium">Không đạt</th>
            </tr>
          </thead>
          <tbody>
            {soDong === 0 && (
              <tr>
                <td colSpan={4} className="border p-3 text-center text-muted-foreground">
                  Chưa có câu hỏi nào trong phiếu đo chuẩn đầu ra này.
                </td>
              </tr>
            )}
            {clo.dong.map((dong, i) =>
              dong.lop.map((lop, j) => (
                <tr key={`${i}-${j}`}>
                  {j === 0 && (
                    <td className="border p-2 align-top" rowSpan={dong.lop.length}>{dong.phuong_phap}</td>
                  )}
                  <td className="border p-2 text-center">{lop.lop}</td>
                  <td className="border p-2 text-center">{lop.dat}</td>
                  <td className="border p-2 text-center">{lop.khong_dat}</td>
                </tr>
              )),
            )}
            <tr className="font-medium bg-muted/30">
              <td className="border p-2" rowSpan={2}>TỔNG CỘNG — {clo.tong.ket_luan}</td>
              <td className="border p-2 text-center">Số lượng</td>
              <td className="border p-2 text-center">{clo.tong.dat}</td>
              <td className="border p-2 text-center">{clo.tong.khong_dat}</td>
            </tr>
            <tr className="bg-muted/30">
              <td className="border p-2 text-center">Tỷ lệ</td>
              <td className="border p-2 text-center">{pct(clo.tong.ty_le_dat)}</td>
              <td className="border p-2 text-center">{pct(clo.tong.ty_le_khong_dat)}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </Card>
  );
}

export default function Show({ course, filters, terms, report }) {
  const doiHocKy = (term) => {
    router.get(route('manager.reports.course.show', course.uuid), {
      nam_hoc: term.nam_hoc || '',
      hoc_ky: term.hoc_ky || '',
    });
  };

  const linkWord = route('manager.reports.course.word', course.uuid)
    + `?nam_hoc=${encodeURIComponent(filters.nam_hoc || '')}&hoc_ky=${filters.hoc_ky || ''}`;

  return (
    <DashboardLayout>
      <Head title={`Báo cáo học phần ${course.code}`} />

      <div className="space-y-5">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <Link
              href={route('manager.reports.course.index')}
              className="text-sm text-muted-foreground hover:text-foreground inline-flex items-center gap-1"
            >
              <ArrowLeft className="h-4 w-4" /> Chọn học phần khác
            </Link>
            <h1 className="text-2xl font-semibold tracking-tight mt-1">{course.name}</h1>
            <p className="text-sm text-muted-foreground">Mã học phần: {course.code}</p>
          </div>

          {report && (
            <Button asChild>
              <a href={linkWord}>
                <Download className="h-4 w-4 mr-2" /> Tải báo cáo Word
              </a>
            </Button>
          )}
        </div>

        {terms.length > 1 && (
          <div className="flex flex-wrap gap-2">
            {terms.map((term) => {
              const dangChon = String(term.nam_hoc || '') === String(filters.nam_hoc || '')
                && String(term.hoc_ky || '') === String(filters.hoc_ky || '');
              return (
                <Button
                  key={`${term.nam_hoc}-${term.hoc_ky}`}
                  variant={dangChon ? 'default' : 'outline'}
                  size="sm"
                  onClick={() => doiHocKy(term)}
                >
                  Học kỳ {term.hoc_ky || '—'} · {term.nam_hoc || 'chưa ghi năm học'}
                </Button>
              );
            })}
          </div>
        )}

        {!report ? (
          <Card className="p-6 text-muted-foreground">
            Học phần này chưa có lớp nào trong học kỳ đã chọn.
          </Card>
        ) : (
          <>
            <Card className="p-4 space-y-1 text-sm">
              <div className="font-medium text-base mb-2">Thông tin chung</div>
              <div>Học kỳ {report.hoc_ky || '—'}, năm học {report.nam_hoc || '—'}</div>
              <div>Thời lượng: {report.hoc_phan.so_tiet ? `${report.hoc_phan.so_tiet} tiết` : 'chưa khai số tiết'} · {report.hoc_phan.tin_chi} tín chỉ</div>
              <div>Lớp: {report.lop.join(', ')}</div>
              <div>Giảng viên: {report.giang_vien.length ? report.giang_vien.join(', ') : '—'}</div>
              <div>Khoa: {report.khoa || '—'} · Bộ môn phụ trách: {report.hoc_phan.bo_mon || 'chưa khai bộ môn'}</div>
            </Card>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              {[
                ['Sinh viên đăng ký', report.thong_ke.dang_ky],
                ['Dự thi cuối kỳ', report.thong_ke.du_thi_cuoi_ky],
                [`Qua môn (từ ${report.nguong.mon} trở lên)`, report.thong_ke.qua_mon],
                ['Tỷ lệ qua môn', pct(report.thong_ke.ty_le_qua_mon)],
              ].map(([nhan, so]) => (
                <Card key={nhan} className="p-4">
                  <div className="text-sm text-muted-foreground">{nhan}</div>
                  <div className="text-2xl font-semibold mt-1">{so}</div>
                </Card>
              ))}
            </div>

            <Card className="p-4">
              <div className="font-medium mb-3">Phương pháp giảng dạy và đánh giá</div>
              <div className="overflow-x-auto">
                <table className="w-full text-sm border-collapse">
                  <thead>
                    <tr className="bg-muted/50">
                      <th className="border p-2 text-left font-medium">Phương pháp</th>
                      <th className="border p-2 font-medium">Có sử dụng</th>
                      <th className="border p-2 text-left font-medium">CLO</th>
                      <th className="border p-2 text-left font-medium">Bài đánh giá</th>
                    </tr>
                  </thead>
                  <tbody>
                    {report.phuong_phap.map((row) => (
                      <tr key={row.ten} className={row.co_su_dung ? '' : 'text-muted-foreground'}>
                        <td className="border p-2">{row.ten}</td>
                        <td className="border p-2 text-center">{row.co_su_dung ? 'x' : ''}</td>
                        <td className="border p-2">{row.clos.join(', ')}</td>
                        <td className="border p-2">{row.bai.join(', ')}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {report.phuong_phap_chua_khai?.length > 0 && (
                <p className="text-sm text-muted-foreground mt-3">
                  Các bài {report.phuong_phap_chua_khai.join(', ')} chưa khai hình thức câu hỏi trong phiếu
                  lớp học phần, nên hệ thống chưa đánh dấu giúp được dòng nào.
                </p>
              )}
            </Card>

            <div className="space-y-3">
              <div className="font-medium">Đánh giá theo chuẩn đầu ra</div>
              {report.clos.map((clo) => <BangClo key={clo.code} clo={clo} />)}
            </div>

            <p className="text-sm text-muted-foreground">
              Phần nhận xét và đề xuất cải tiến để trống trong file Word, giảng viên tự viết trước khi ký.
            </p>
          </>
        )}
      </div>
    </DashboardLayout>
  );
}
