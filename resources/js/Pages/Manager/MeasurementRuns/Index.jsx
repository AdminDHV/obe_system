import React from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router } from '@inertiajs/react';
import { Activity, X } from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import PaginationControls from '@/components/PaginationControls';

const SOURCE_LABELS = { manual: 'Thủ công', command: 'Dòng lệnh', import: 'Sau import' };
const STATUS_STYLES = {
  completed: 'bg-emerald-50 text-emerald-700',
  failed: 'bg-rose-50 text-rose-700',
};
const STATUS_LABELS = { completed: 'Hoàn thành', failed: 'Thất bại' };

export default function Index({ runs, schools, programs, currentFilters }) {
  const go = (params) => router.get(route('manager.measurement-runs.index'), params, { preserveState: true, replace: true });
  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') delete params[key]; else params[key] = value;
    delete params.page;
    go(params);
  };
  const hasFilters = ['school_id', 'program_id', 'source', 'status', 'date_from', 'date_to'].some((k) => currentFilters[k]);
  const schoolPrograms = currentFilters.school_id
    ? programs.filter((p) => String(p.school_id) === String(currentFilters.school_id))
    : programs;

  return (
    <DashboardLayout>
      <Head title="Nhật ký đo lường" />

      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Nhật ký đo lường</h2>
            <p className="text-muted-foreground">Các lần tính lại CLO/PI/PLO — gồm cả lần thất bại</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.school_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('school_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Trường"
              options={[{ value: 'all', label: 'Mọi trường' }, ...schools.map((s) => ({ value: String(s.id), label: s.name }))]}
            />
            <SearchableSelect
              value={currentFilters.program_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('program_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Ngành"
              options={[{ value: 'all', label: 'Mọi ngành' }, ...schoolPrograms.map((p) => ({ value: String(p.id), label: p.code }))]}
            />
            <SearchableSelect
              value={currentFilters.source ?? 'all'}
              onValueChange={(v) => handleFilterChange('source', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Nguồn"
              options={[
                { value: 'all', label: 'Mọi nguồn' },
                { value: 'manual', label: 'Thủ công' },
                { value: 'command', label: 'Dòng lệnh' },
                { value: 'import', label: 'Sau import' },
              ]}
            />
            <SearchableSelect
              value={currentFilters.status ?? 'all'}
              onValueChange={(v) => handleFilterChange('status', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Trạng thái"
              options={[
                { value: 'all', label: 'Mọi trạng thái' },
                { value: 'completed', label: 'Hoàn thành' },
                { value: 'failed', label: 'Thất bại' },
              ]}
            />
            <Input type="date" value={currentFilters.date_from ?? ''} onChange={(e) => handleFilterChange('date_from', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            <Input type="date" value={currentFilters.date_to ?? ''} onChange={(e) => handleFilterChange('date_to', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            {hasFilters && (
              <button onClick={() => router.get(route('manager.measurement-runs.index'))} className="h-9 px-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thời gian</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trường</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Ngành</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Khóa</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Nguồn</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số cặp</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Người chạy</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {runs.data.map((m) => (
                  <tr key={m.id} className="hover:bg-muted/30">
                    <td className="p-4 whitespace-nowrap text-muted-foreground">{m.created_at}</td>
                    <td className="p-4">{m.school ?? '—'}</td>
                    <td className="p-4">{m.program ?? '—'}</td>
                    <td className="p-4">{m.cohort ?? '—'}</td>
                    <td className="p-4"><span className="px-2 py-0.5 rounded text-xs bg-muted text-muted-foreground">{SOURCE_LABELS[m.source] ?? m.source}</span></td>
                    <td className="p-4">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${STATUS_STYLES[m.status] ?? 'bg-muted text-muted-foreground'}`}>
                        {STATUS_LABELS[m.status] ?? m.status}
                      </span>
                    </td>
                    <td className="p-4 text-muted-foreground">{m.pairs_count}</td>
                    <td className="p-4">{m.user ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {runs.data.length === 0 && (
            <div className="p-12 text-center">
              <Activity className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa có lần đo lường nào</p>
            </div>
          )}

          <PaginationControls
            currentPage={runs.current_page}
            totalPages={runs.last_page}
            onPageChange={(page) => go({ ...currentFilters, page })}
            totalItems={runs.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
