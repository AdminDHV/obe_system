import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router } from '@inertiajs/react';
import { History, ChevronDown, ChevronRight, X, Download } from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import PaginationControls from '@/components/PaginationControls';

const TYPE_LABELS = {
  structure: 'Khung cấu trúc',
  grade_bulk: 'Điểm',
  grade_question: 'Điểm theo câu hỏi',
  student_roster: 'Danh sách SV',
  survey: 'Khảo sát',
  enrollment: 'Đăng ký lớp',
};

const STATUS_STYLES = {
  completed: 'bg-emerald-50 text-emerald-700',
  generated: 'bg-emerald-50 text-emerald-700',
  processing: 'bg-blue-50 text-blue-700',
  queued: 'bg-gray-50 text-gray-600',
  failed: 'bg-rose-50 text-rose-700',
};
const STATUS_LABELS = { completed: 'Hoàn thành', processing: 'Đang xử lý', queued: 'Đang chờ', failed: 'Thất bại' };

export default function Index({ batches, types, schools, actors, currentFilters }) {
  const [expanded, setExpanded] = useState({});

  const go = (params) => router.get(route('manager.import-batches.index'), params, { preserveState: true, replace: true });
  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') delete params[key]; else params[key] = value;
    delete params.page;
    go(params);
  };
  const toggle = (uuid) => setExpanded((e) => ({ ...e, [uuid]: !e[uuid] }));
  const hasFilters = ['type', 'status', 'school_id', 'user_id', 'date_from', 'date_to'].some((k) => currentFilters[k]);

  return (
    <DashboardLayout>
      <Head title="Lịch sử import" />

      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Lịch sử import</h2>
            <p className="text-muted-foreground">Mọi lần nhập file (khung · điểm · danh sách SV · khảo sát)</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.type ?? 'all'}
              onValueChange={(v) => handleFilterChange('type', v)}
              className="w-full sm:w-[150px] h-9"
              placeholder="Loại"
              options={[{ value: 'all', label: 'Mọi loại' }, ...types.map((t) => ({ value: t, label: TYPE_LABELS[t] ?? t }))]}
            />
            <SearchableSelect
              value={currentFilters.status ?? 'all'}
              onValueChange={(v) => handleFilterChange('status', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Trạng thái"
              options={[
                { value: 'all', label: 'Mọi trạng thái' },
                { value: 'completed', label: 'Hoàn thành' },
                { value: 'processing', label: 'Đang xử lý' },
                { value: 'queued', label: 'Đang chờ' },
                { value: 'failed', label: 'Thất bại' },
              ]}
            />
            <SearchableSelect
              value={currentFilters.school_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('school_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Trường"
              options={[{ value: 'all', label: 'Mọi trường' }, ...schools.map((s) => ({ value: String(s.id), label: s.name }))]}
            />
            <SearchableSelect
              value={currentFilters.user_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('user_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Người nhập"
              options={[{ value: 'all', label: 'Mọi người' }, ...actors.map((a) => ({ value: String(a.id), label: a.full_name }))]}
            />
            <Input type="date" value={currentFilters.date_from ?? ''} onChange={(e) => handleFilterChange('date_from', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            <Input type="date" value={currentFilters.date_to ?? ''} onChange={(e) => handleFilterChange('date_to', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            {hasFilters && (
              <button onClick={() => router.get(route('manager.import-batches.index'))} className="h-9 px-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                <X className="w-4 h-4" /> Xóa lọc
              </button>
            )}
          </div>
        </div>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 w-8"></th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thời gian</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Người nhập</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Loại</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số dòng</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">File</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {batches.data.map((b) => (
                  <React.Fragment key={b.uuid}>
                    <tr className="hover:bg-muted/30 cursor-pointer" onClick={() => toggle(b.uuid)}>
                      <td className="p-4 text-muted-foreground">{expanded[b.uuid] ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}</td>
                      <td className="p-4 whitespace-nowrap text-muted-foreground">{b.created_at}</td>
                      <td className="p-4"><div className="font-medium">{b.user?.full_name ?? '—'}</div></td>
                      <td className="p-4">{TYPE_LABELS[b.type] ?? b.type}</td>
                      <td className="p-4">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${STATUS_STYLES[b.status] ?? 'bg-muted text-muted-foreground'}`}>
                          {STATUS_LABELS[b.status] ?? b.status}
                        </span>
                      </td>
                      <td className="p-4 whitespace-nowrap text-muted-foreground">{b.processed_rows}/{b.total_rows}</td>
                      <td className="p-4">
                        {b.downloadable
                          ? <a href={route('manager.reports.batch-file', b.uuid)} onClick={(e) => e.stopPropagation()} className="text-primary hover:underline inline-flex items-center gap-0.5"><Download className="w-3 h-3" /> Tải</a>
                          : <span className="text-muted-foreground">—</span>}
                      </td>
                    </tr>
                    {expanded[b.uuid] && (
                      <tr className="bg-muted/20">
                        <td></td>
                        <td colSpan={6} className="p-4 space-y-2">
                          <div className="text-xs text-muted-foreground">
                            {b.file_name ?? '(không tên)'}
                            {b.summary && <span className="ml-2">· {Object.entries(b.summary).filter(([, v]) => v > 0).map(([k, v]) => `${k}: ${v}`).join(' · ')}</span>}
                          </div>
                          {b.error_log?.length > 0 && (
                            <ul className="list-inside list-disc space-y-0.5 max-h-40 overflow-auto text-rose-600 text-xs">
                              {b.error_log.slice(0, 30).map((err, i) => <li key={i}>{err}</li>)}
                              {b.error_log.length > 30 && <li>… và {b.error_log.length - 30} lỗi khác</li>}
                            </ul>
                          )}
                          {(!b.error_log || b.error_log.length === 0) && <p className="text-xs text-muted-foreground">Không có lỗi.</p>}
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>

          {batches.data.length === 0 && (
            <div className="p-12 text-center">
              <History className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa có lần import nào</p>
            </div>
          )}

          <PaginationControls
            currentPage={batches.current_page}
            totalPages={batches.last_page}
            onPageChange={(page) => go({ ...currentFilters, page })}
            totalItems={batches.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
