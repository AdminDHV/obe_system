import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Search, X, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2, GraduationCap
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import ListEmptyState, { GoiYKhung } from '@/components/ListEmptyState';

export default function Index({ cohorts, programs, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ name: '', start_year: '', program_id: '' });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.cohorts.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.cohorts.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedCohorts = useMemo(() => {
    if (!sortField || !sortDir) return cohorts.data;
    return [...cohorts.data].sort((a, b) => {
      let cmp;
      if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else if (sortField === 'start_year') cmp = a.start_year - b.start_year;
      else if (sortField === 'program') cmp = (a.program?.code ?? '').localeCompare(b.program?.code ?? '');
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [cohorts.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ name: '', start_year: '', program_id: '' });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ name: item.name, start_year: String(item.start_year), program_id: item.program_id ?? item.program?.uuid ?? '' });
    setFormMode(item.uuid);
  };

  const closeForm = () => { setFormMode(null); setFormData({ name: '', start_year: '', program_id: '' }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.cohorts.store'), formData, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.cohorts.update', formMode), formData, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa niên khóa này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.cohorts.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý niên khóa" />

      <div className="space-y-6">
        {/* Flash */}
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        {/* Header */}
        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý niên khóa</h2>
            <p className="text-muted-foreground">Danh sách niên khóa</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm khóa..."
                defaultValue={currentFilters.q || ''}
                onChange={(e) => handleSearchChange(e.target.value)}
                className="h-9 w-full rounded-md border border-input bg-background px-8 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
              />
              {currentFilters.q && (
                <button
                  onClick={() => handleFilterChange('q', '')}
                  className="absolute right-2 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
            <Button onClick={openCreate} className="h-9 gap-1">
              <Plus className="w-4 h-4" />
              Thêm khóa
            </Button>
          </div>
        </div>

        {/* Form */}
        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm niên khóa mới' : 'Chỉnh sửa niên khóa'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên khóa</Label>
                  <Input
                    value={formData.name}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="VD: Khóa 2024-2028"
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Năm bắt đầu</Label>
                  <Input
                    type="number"
                    value={formData.start_year}
                    onChange={(e) => setFormData({ ...formData, start_year: e.target.value })}
                    placeholder="VD: 2024"
                    required
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Chương trình</Label>
                  <SearchableSelect
                    value={formData.program_id}
                    onValueChange={(v) => setFormData({ ...formData, program_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn chương trình --"
                    options={programs.map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                  />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">
                    Hủy
                  </Button>
                  <Button type="submit" className="h-9">
                    {formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}
                  </Button>
                </div>
              </form>
        </FormDialog>

        {/* Table */}
        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên khóa <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('start_year')}>
                    <div className="flex items-center">Năm bắt đầu <SortIcon field="start_year" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('program')}>
                    <div className="flex items-center">Chương trình <SortIcon field="program" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số SV</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedCohorts.map((cohort, index) => (
                  <tr key={cohort.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(cohorts.current_page - 1) * cohorts.per_page + index + 1}</td>
                    <td className="p-4 font-medium">{cohort.name}</td>
                    <td className="p-4">{cohort.start_year}</td>
                    <td className="p-4"><span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{cohort.program?.code ?? '—'}</span></td>
                    <td className="p-4 text-center">{cohort.students_count ?? cohort.students?.length ?? 0}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openEdit(cohort)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(cohort.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {cohorts.data.length === 0 && (
            <ListEmptyState
              icon={GraduationCap}
              what="khóa"
              filters={currentFilters}
              routeName="manager.cohorts.index"
              hint={<GoiYKhung>Khóa (niên khóa) khai trong sheet <b>Khoa_Nganh</b> của file khung. Năm bắt đầu quyết định phương án đo, nên phải khai đúng. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={cohorts.current_page}
            totalPages={cohorts.last_page}
            onPageChange={handlePageChange}
            totalItems={cohorts.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
