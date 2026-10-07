import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
  FileText, Search, X, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import ListEmptyState, { GoiYKhung } from '@/components/ListEmptyState';

export default function Index({ syllabi, courses, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ course_id: '', version: '', is_active: false });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.syllabi.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.syllabi.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedSyllabi = useMemo(() => {
    if (!sortField || !sortDir) return syllabi.data;
    return [...syllabi.data].sort((a, b) => {
      let cmp;
      if (sortField === 'course') cmp = (a.course?.name ?? '').localeCompare(b.course?.name ?? '');
      else if (sortField === 'version') cmp = a.version.localeCompare(b.version);
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [syllabi.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ course_id: '', version: '', is_active: false });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ course_id: item.course_id ?? item.course?.uuid ?? '', version: item.version, is_active: !!item.is_active });
    setFormMode(item.uuid);
  };

  const closeForm = () => { setFormMode(null); setFormData({ course_id: '', version: '', is_active: false }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.syllabi.store'), formData, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.syllabi.update', formMode), formData, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa đề cương này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.syllabi.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý đề cương" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý đề cương</h2>
            <p className="text-muted-foreground">Danh sách đề cương học phần</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.course_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('course_id', v)}
              className="w-full sm:w-[180px] h-9"
              placeholder="Tất cả HP"
              options={[
                { value: 'all', label: 'Tất cả HP' },
                ...courses.map((c) => ({ value: String(c.id), label: `${c.code} - ${c.name}` })),
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm phiên bản..."
                defaultValue={currentFilters.q || ''}
                onChange={(e) => handleSearchChange(e.target.value)}
                className="h-9 w-full rounded-md border border-input bg-background px-8 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
              {currentFilters.q && (
                <button onClick={() => handleFilterChange('q', '')} className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground">
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
            <Button onClick={openCreate} className="h-9 gap-1">
              <Plus className="w-4 h-4" />
              Thêm đề cương
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm đề cương mới' : 'Chỉnh sửa đề cương'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Học phần</Label>
                  <SearchableSelect
                    value={formData.course_id}
                    onValueChange={(v) => setFormData({ ...formData, course_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn HP --"
                    options={courses.map((c) => ({ value: String(c.id), label: `${c.code} - ${c.name}` }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Phiên bản</Label>
                  <Input value={formData.version} onChange={(e) => setFormData({ ...formData, version: e.target.value })} placeholder="VD: v1.0" required />
                </div>
                <div className="space-y-1.5 flex items-end pb-2">
                  <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <Checkbox checked={formData.is_active} onCheckedChange={(v) => setFormData({ ...formData, is_active: !!v })} />
                    <span className="text-xs font-medium text-muted-foreground">Kích hoạt</span>
                  </label>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9">{formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}</Button>
                </div>
              </form>
        </FormDialog>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('course')}>
                    <div className="flex items-center">Học phần <SortIcon field="course" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('version')}>
                    <div className="flex items-center">Phiên bản <SortIcon field="version" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Kích hoạt</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số CLO</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số Assessment</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedSyllabi.map((syllabus, index) => (
                  <tr key={syllabus.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(syllabi.current_page - 1) * syllabi.per_page + index + 1}</td>
                    <td className="p-4 font-medium">{syllabus.course?.name ?? '—'}</td>
                    <td className="p-4">{syllabus.version}</td>
                    <td className="p-4 text-center">
                      <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${syllabus.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-muted text-muted-foreground'}`}>
                        {syllabus.is_active ? 'Có' : 'Không'}
                      </span>
                    </td>
                    <td className="p-4 text-center">{syllabus.clos_count ?? syllabus.clos?.length ?? 0}</td>
                    <td className="p-4 text-center">{syllabus.assessments_count ?? syllabus.assessments?.length ?? 0}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openEdit(syllabus)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(syllabus.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {syllabi.data.length === 0 && (
            <ListEmptyState
              icon={FileText}
              what="đề cương"
              filters={currentFilters}
              routeName="manager.syllabi.index"
              hint={<GoiYKhung>Đề cương sinh ra cùng lúc với môn học khi dựng khung. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={syllabi.current_page}
            totalPages={syllabi.last_page}
            onPageChange={handlePageChange}
            totalItems={syllabi.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
