import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router } from '@inertiajs/react';
import { History, ChevronDown, ChevronRight, X } from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import PaginationControls from '@/components/PaginationControls';

const ACTION_STYLES = {
  created: 'bg-emerald-50 text-emerald-700',
  updated: 'bg-amber-50 text-amber-700',
  deleted: 'bg-rose-50 text-rose-700',
};
const ACTION_LABELS = { created: 'Tạo mới', updated: 'Cập nhật', deleted: 'Xóa' };

function ValueBlock({ title, values }) {
  if (!values || Object.keys(values).length === 0) return null;
  return (
    <div className="flex-1 min-w-0">
      <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-1">{title}</p>
      <div className="rounded-md bg-muted/50 p-2 text-xs font-mono space-y-0.5">
        {Object.entries(values).map(([k, v]) => (
          <div key={k} className="flex gap-2">
            <span className="text-muted-foreground shrink-0">{k}:</span>
            <span className="break-all">{v === null ? '—' : String(v)}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

export default function Index({ logs, modelTypes, actors, currentFilters }) {
  const [expanded, setExpanded] = useState({});

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') delete params[key]; else params[key] = value;
    delete params.page;
    router.get(route('manager.audit-logs.index'), params, { preserveState: true, replace: true });
  };

  const handlePageChange = (page) => {
    router.get(route('manager.audit-logs.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const toggle = (uuid) => setExpanded((e) => ({ ...e, [uuid]: !e[uuid] }));
  const hasFilters = ['action', 'model_type', 'user_id', 'date_from', 'date_to'].some((k) => currentFilters[k]);

  return (
    <DashboardLayout>
      <Head title="Nhật ký hoạt động" />

      <div className="space-y-6">
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Nhật ký hoạt động</h2>
            <p className="text-muted-foreground">Lịch sử thao tác tạo / cập nhật / xóa của người dùng</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.action ?? 'all'}
              onValueChange={(v) => handleFilterChange('action', v)}
              className="w-full sm:w-[130px] h-9"
              placeholder="Hành động"
              options={[
                { value: 'all', label: 'Mọi hành động' },
                { value: 'created', label: 'Tạo mới' },
                { value: 'updated', label: 'Cập nhật' },
                { value: 'deleted', label: 'Xóa' },
              ]}
            />
            <SearchableSelect
              value={currentFilters.model_type ?? 'all'}
              onValueChange={(v) => handleFilterChange('model_type', v)}
              className="w-full sm:w-[150px] h-9"
              placeholder="Đối tượng"
              options={[
                { value: 'all', label: 'Mọi đối tượng' },
                ...modelTypes.map((m) => ({ value: m, label: m })),
              ]}
            />
            <SearchableSelect
              value={currentFilters.user_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('user_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Người dùng"
              options={[
                { value: 'all', label: 'Mọi người dùng' },
                ...actors.map((a) => ({ value: String(a.id), label: a.full_name })),
              ]}
            />
            <Input type="date" value={currentFilters.date_from ?? ''} onChange={(e) => handleFilterChange('date_from', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            <Input type="date" value={currentFilters.date_to ?? ''} onChange={(e) => handleFilterChange('date_to', e.target.value)} className="h-9 w-full sm:w-[150px]" />
            {hasFilters && (
              <button onClick={() => router.get(route('manager.audit-logs.index'))} className="h-9 px-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground w-8"></th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thời gian</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Người dùng</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Đối tượng</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {logs.data.map((log) => (
                  <React.Fragment key={log.uuid}>
                    <tr className="hover:bg-muted/30 transition-colors cursor-pointer" onClick={() => toggle(log.uuid)}>
                      <td className="p-4 text-muted-foreground">
                        {expanded[log.uuid] ? <ChevronDown className="w-4 h-4" /> : <ChevronRight className="w-4 h-4" />}
                      </td>
                      <td className="p-4 whitespace-nowrap text-muted-foreground">{log.created_at}</td>
                      <td className="p-4">
                        <div className="font-medium">{log.user?.full_name ?? '—'}</div>
                        {log.user?.email && <div className="text-xs text-muted-foreground">{log.user.email}</div>}
                      </td>
                      <td className="p-4">
                        <span className={`px-2 py-0.5 rounded text-xs font-medium ${ACTION_STYLES[log.action] ?? 'bg-muted text-muted-foreground'}`}>
                          {ACTION_LABELS[log.action] ?? log.action}
                        </span>
                      </td>
                      <td className="p-4">
                        <span className="font-medium">{log.model_type}</span>
                        <span className="text-xs text-muted-foreground"> #{log.model_id}</span>
                      </td>
                    </tr>
                    {expanded[log.uuid] && (
                      <tr className="bg-muted/20">
                        <td></td>
                        <td colSpan={4} className="p-4">
                          <div className="flex flex-col md:flex-row gap-4">
                            <ValueBlock title="Giá trị cũ" values={log.old_values} />
                            <ValueBlock title="Giá trị mới" values={log.new_values} />
                            {!log.old_values && !log.new_values && (
                              <p className="text-xs text-muted-foreground">Không có chi tiết.</p>
                            )}
                          </div>
                        </td>
                      </tr>
                    )}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>

          {logs.data.length === 0 && (
            <div className="p-12 text-center">
              <History className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa có nhật ký nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">Các thao tác tạo/sửa/xóa sẽ được ghi lại tại đây</p>
            </div>
          )}

          <PaginationControls
            currentPage={logs.current_page}
            totalPages={logs.last_page}
            onPageChange={handlePageChange}
            totalItems={logs.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
