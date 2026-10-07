import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Eye, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2
} from 'lucide-react';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import ListEmptyState, { GoiYKhung } from '@/components/ListEmptyState';

export default function Index({ departments, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ code: '', name: '' });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.departments.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.departments.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedDepartments = useMemo(() => {
    if (!sortField || !sortDir) return departments.data;
    return [...departments.data].sort((a, b) => {
      let cmp;
      if (sortField === 'code') cmp = a.code.localeCompare(b.code);
      else if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [departments.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ code: '', name: '' });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ code: item.code, name: item.name });
    setFormMode(item.uuid);
  };

  const closeForm = () => { setFormMode(null); setFormData({ code: '', name: '' }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.departments.store'), formData, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.departments.update', formMode), formData, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa khoa này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.departments.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý khoa" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý khoa</h2>
            <p className="text-muted-foreground">Danh sách khoa / bộ môn</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm mã khoa, tên khoa..."
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
              Thêm khoa
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm khoa mới' : 'Chỉnh sửa khoa'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã khoa</Label>
                  <Input value={formData.code} onChange={(e) => setFormData({ ...formData, code: e.target.value })} placeholder="VD: CNTT" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên khoa</Label>
                  <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Công nghệ thông tin" required />
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
                    <div className="flex items-center">Mã khoa <SortIcon field="code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên khoa <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số GV</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số CTĐT</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedDepartments.map((dept, index) => (
                  <tr key={dept.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(departments.current_page - 1) * departments.per_page + index + 1}</td>
                    <td className="p-4">
                      <span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{dept.code}</span>
                    </td>
                    <td className="p-4 font-medium">{dept.name}</td>
                    <td className="p-4 text-center">{dept.lecturers_count ?? dept.lecturers?.length ?? 0}</td>
                    <td className="p-4 text-center">{dept.programs_count ?? dept.programs?.length ?? 0}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link href={route('manager.departments.show', dept.uuid)} className="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition" title="Xem">
                          <Eye className="w-4 h-4" />
                        </Link>
                        <button onClick={() => openEdit(dept)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(dept.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {departments.data.length === 0 && (
            <ListEmptyState
              icon={Search}
              what="khoa"
              filters={currentFilters}
              routeName="manager.departments.index"
              hint={<GoiYKhung>Khoa khai trong sheet <b>Khoa</b> của file khung, hoặc thêm tay bằng nút ở trên. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={departments.current_page}
            totalPages={departments.last_page}
            onPageChange={handlePageChange}
            totalItems={departments.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
