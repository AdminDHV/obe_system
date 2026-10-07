import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
  BookOpen, Search, X, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2
} from 'lucide-react';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import ListEmptyState, { GoiYKhung } from '@/components/ListEmptyState';

export default function Index({ courses, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ code: '', name: '', credits: '' });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.courses.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.courses.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedCourses = useMemo(() => {
    if (!sortField || !sortDir) return courses.data;
    return [...courses.data].sort((a, b) => {
      let cmp;
      if (sortField === 'code') cmp = a.code.localeCompare(b.code);
      else if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else if (sortField === 'credits') cmp = a.credits - b.credits;
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [courses.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ code: '', name: '', credits: '' });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ code: item.code, name: item.name, credits: String(item.credits) });
    setFormMode(item.uuid);
  };

  const closeForm = () => { setFormMode(null); setFormData({ code: '', name: '', credits: '' }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = { ...formData, credits: parseInt(formData.credits, 10) };
    if (formMode === 'create') {
      router.post(route('manager.courses.store'), payload, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.courses.update', formMode), payload, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa học phần này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.courses.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý học phần" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý học phần</h2>
            <p className="text-muted-foreground">Danh sách học phần / môn học</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm mã HP, tên HP..."
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
              Thêm HP
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm học phần mới' : 'Chỉnh sửa học phần'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã học phần</Label>
                  <Input value={formData.code} onChange={(e) => setFormData({ ...formData, code: e.target.value })} placeholder="VD: CS101" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên học phần</Label>
                  <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Lập trình căn bản" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Số tín chỉ</Label>
                  <Input type="number" value={formData.credits} onChange={(e) => setFormData({ ...formData, credits: e.target.value })} placeholder="VD: 3" required />
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
                    <div className="flex items-center">Mã HP <SortIcon field="code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên HP <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('credits')}>
                    <div className="flex items-center justify-center">Số tín chỉ <SortIcon field="credits" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Số chương trình</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedCourses.map((course, index) => (
                  <tr key={course.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(courses.current_page - 1) * courses.per_page + index + 1}</td>
                    <td className="p-4"><span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{course.code}</span></td>
                    <td className="p-4">{course.name}</td>
                    <td className="p-4 text-center font-semibold">{course.credits}</td>
                    <td className="p-4 text-center">{course.programs_count ?? course.programs?.length ?? 0}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openEdit(course)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(course.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {courses.data.length === 0 && (
            <ListEmptyState
              icon={BookOpen}
              what="học phần"
              filters={currentFilters}
              routeName="manager.courses.index"
              hint={<GoiYKhung>Học phần khai trong sheet <b>MonHoc</b> của file khung, hoặc thêm tay bằng nút ở trên. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={courses.current_page}
            totalPages={courses.last_page}
            onPageChange={handlePageChange}
            totalItems={courses.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
