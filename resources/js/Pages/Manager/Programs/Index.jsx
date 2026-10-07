import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Eye, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2
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

export default function Index({ programs, departments, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ code: '', name: '', department_id: '', required_credits: '' });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.programs.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.programs.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedPrograms = useMemo(() => {
    if (!sortField || !sortDir) return programs.data;
    return [...programs.data].sort((a, b) => {
      let cmp;
      if (sortField === 'code') cmp = a.code.localeCompare(b.code);
      else if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else if (sortField === 'department') cmp = (a.department?.code ?? '').localeCompare(b.department?.code ?? '');
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [programs.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ code: '', name: '', department_id: '', required_credits: '' });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ code: item.code, name: item.name, department_id: item.department_id ?? item.department?.uuid ?? '', required_credits: item.required_credits ?? '' });
    setFormMode(item.uuid);
  };

  const closeForm = () => { setFormMode(null); setFormData({ code: '', name: '', department_id: '', required_credits: '' }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.programs.store'), formData, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.programs.update', formMode), formData, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa ngành này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.programs.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý ngành đào tạo" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý ngành đào tạo</h2>
            <p className="text-muted-foreground">Danh sách chương trình / ngành đào tạo</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.department_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('department_id', v)}
              className="w-full sm:w-[180px] h-9"
              placeholder="Tất cả khoa"
              options={[
                { value: 'all', label: 'Tất cả khoa' },
                ...departments.map((d) => ({ value: String(d.id), label: `${d.code} - ${d.name}` })),
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm mã ngành, tên ngành..."
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
              Thêm ngành
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm ngành mới' : 'Chỉnh sửa ngành'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã ngành</Label>
                  <Input value={formData.code} onChange={(e) => setFormData({ ...formData, code: e.target.value })} placeholder="VD: CNTT" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên ngành</Label>
                  <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Công nghệ thông tin" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Khoa</Label>
                  <SearchableSelect
                    value={formData.department_id}
                    onValueChange={(v) => setFormData({ ...formData, department_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn khoa --"
                    options={departments.map((d) => ({ value: String(d.id), label: `${d.code} - ${d.name}` }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Số tín chỉ yêu cầu để tốt nghiệp</Label>
                  <Input
                    type="number"
                    min="1"
                    value={formData.required_credits}
                    onChange={(e) => setFormData({ ...formData, required_credits: e.target.value })}
                    placeholder="VD: 60"
                  />
                  <p className="text-xs text-muted-foreground">
                    Dùng để tính % tích lũy của từng SV. Sinh viên chưa tích lũy đủ sẽ được tách
                    riêng, không gộp vào thống kê mức đạt PLO của khóa. Bỏ trống = không lọc ai.
                  </p>
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('code')}>
                    <div className="flex items-center">Mã ngành <SortIcon field="code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên ngành <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('department')}>
                    <div className="flex items-center">Khoa <SortIcon field="department" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số HP</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số PLO</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedPrograms.map((program, index) => (
                  <tr key={program.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(programs.current_page - 1) * programs.per_page + index + 1}</td>
                    <td className="p-4">
                      <Link href={route('manager.programs.show', program.uuid)} className="font-medium text-primary hover:underline">
                        {program.code}
                      </Link>
                    </td>
                    <td className="p-4 font-medium">{program.name}</td>
                    <td className="p-4">{program.department?.code ?? '—'}</td>
                    <td className="p-4 text-center">{program.courses_count ?? program.courses?.length ?? 0}</td>
                    <td className="p-4 text-center">{program.plos_count ?? program.plos?.length ?? 0}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link href={route('manager.programs.show', program.uuid)} className="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition" title="Xem">
                          <Eye className="w-4 h-4" />
                        </Link>
                        <button onClick={() => openEdit(program)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(program.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {programs.data.length === 0 && (
            <ListEmptyState
              what="ngành"
              filters={currentFilters}
              routeName="manager.programs.index"
              hint={<GoiYKhung>Ngành khai trong sheet <b>Nganh</b> của file khung, hoặc thêm tay bằng nút ở trên. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          {programs.last_page > 1 && (
            <PaginationControls
              currentPage={programs.current_page}
              totalPages={programs.last_page}
              onPageChange={handlePageChange}
              totalItems={programs.total}
            />
          )}
        </Card>
      </div>
    </DashboardLayout>
  );
}
