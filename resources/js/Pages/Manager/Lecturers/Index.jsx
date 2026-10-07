import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Eye, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import FieldError from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import { lecturerStatusMeta, LECTURER_STATUS_OPTIONS } from '@/lib/status';
import ListEmptyState from '@/components/ListEmptyState';

export default function Index({ lecturers, departments, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({
    department_id: '', staff_code: '', full_name: '', position: '',
    email: '', password: '', phone: '', employment_status: 'full_time',
  });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.lecturers.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.lecturers.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedLecturers = useMemo(() => {
    if (!sortField || !sortDir) return lecturers.data;
    return [...lecturers.data].sort((a, b) => {
      let cmp;
      if (sortField === 'staff_code') cmp = a.staff_code.localeCompare(b.staff_code);
      else if (sortField === 'full_name') cmp = a.full_name.localeCompare(b.full_name);
      else if (sortField === 'email') cmp = a.email.localeCompare(b.email);
      else if (sortField === 'department') cmp = (a.department?.code ?? '').localeCompare(b.department?.code ?? '');
      else if (sortField === 'position') cmp = (a.position ?? '').localeCompare(b.position ?? '');
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [lecturers.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({
      department_id: '', staff_code: '', full_name: '', position: '',
      email: '', password: '', phone: '', employment_status: 'full_time',
    });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({
      department_id: item.department_id ?? item.department?.uuid ?? '',
      staff_code: item.staff_code,
      full_name: item.full_name,
      position: item.position ?? '',
      email: item.email,
      password: '',
      phone: item.phone ?? '',
      employment_status: item.employment_status ?? 'full_time',
    });
    setFormMode(item.uuid);
  };

  const closeForm = () => {
    setFormMode(null);
    setFormData({
      department_id: '', staff_code: '', full_name: '', position: '',
      email: '', password: '', phone: '', employment_status: 'full_time',
    });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    let payload = { ...formData };
    if (formMode !== 'create') {
      delete payload.password;
    }
    if (formMode === 'create') {
      router.post(route('manager.lecturers.store'), payload, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.lecturers.update', formMode), payload, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa giảng viên này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.lecturers.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý giảng viên" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý giảng viên</h2>
            <p className="text-muted-foreground">Danh sách giảng viên toàn trường</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.department_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('department_id', v)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Tất cả khoa"
              options={[
                { value: 'all', label: 'Tất cả khoa' },
                ...departments.map((d) => ({ value: String(d.id), label: `${d.code} - ${d.name}` })),
              ]}
            />
            <SearchableSelect
              value={currentFilters.employment_status ?? 'all'}
              onValueChange={(v) => handleFilterChange('employment_status', v)}
              className="w-full sm:w-[130px] h-9"
              placeholder="Trạng thái"
              options={[
                { value: 'all', label: 'Tất cả' },
                ...LECTURER_STATUS_OPTIONS,
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm MSSS, họ tên..."
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
              Thêm GV
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="lg"
          title={formMode === 'create' ? 'Thêm giảng viên mới' : 'Chỉnh sửa giảng viên'}
        >
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Khoa <span className="text-destructive">*</span></Label>
                  <SearchableSelect
                    value={formData.department_id}
                    onValueChange={(v) => setFormData({ ...formData, department_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn khoa --"
                    options={departments.map((d) => ({ value: String(d.id), label: `${d.code} - ${d.name}` }))}
                  />
                  <FieldError name="department_id" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã nhân sự <span className="text-destructive">*</span></Label>
                  <Input value={formData.staff_code} onChange={(e) => setFormData({ ...formData, staff_code: e.target.value })} placeholder="VD: GV001" required />
                  <FieldError name="staff_code" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Họ tên <span className="text-destructive">*</span></Label>
                  <Input value={formData.full_name} onChange={(e) => setFormData({ ...formData, full_name: e.target.value })} placeholder="VD: Nguyễn Văn A" required />
                  <FieldError name="full_name" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Chức vụ</Label>
                  <Input value={formData.position} onChange={(e) => setFormData({ ...formData, position: e.target.value })} placeholder="VD: Giảng viên" />
                  <FieldError name="position" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Email <span className="text-destructive">*</span></Label>
                  <Input type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} placeholder="VD: a.nguyen@example.com" required />
                  <FieldError name="email" />
                </div>
                {formMode === 'create' && (
                  <div className="space-y-1.5">
                    <Label className="text-xs font-medium text-muted-foreground">Mật khẩu</Label>
                    <Input type="password" value={formData.password} onChange={(e) => setFormData({ ...formData, password: e.target.value })} placeholder="Mật khẩu" required />
                  <FieldError name="password" />
                  </div>
                )}
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Số điện thoại</Label>
                  <Input value={formData.phone} onChange={(e) => setFormData({ ...formData, phone: e.target.value })} placeholder="VD: 0901234567" />
                  <FieldError name="phone" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Trạng thái</Label>
                  <SearchableSelect
                    value={formData.employment_status}
                    onValueChange={(v) => setFormData({ ...formData, employment_status: v })}
                    className="w-full h-9"
                    options={LECTURER_STATUS_OPTIONS}
                  />
                </div>
                <div className="md:col-span-2 flex justify-end gap-2 pt-2">
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('staff_code')}>
                    <div className="flex items-center">MSSS <SortIcon field="staff_code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('full_name')}>
                    <div className="flex items-center">Họ tên <SortIcon field="full_name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('position')}>
                    <div className="flex items-center">Chức vụ <SortIcon field="position" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('department')}>
                    <div className="flex items-center">Khoa <SortIcon field="department" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('email')}>
                    <div className="flex items-center">Email <SortIcon field="email" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedLecturers.map((lecturer, index) => (
                  <tr key={lecturer.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(lecturers.current_page - 1) * lecturers.per_page + index + 1}</td>
                    <td className="p-4">
                      <span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{lecturer.staff_code}</span>
                    </td>
                    <td className="p-4 font-medium">{lecturer.full_name}</td>
                    <td className="p-4 text-muted-foreground">{lecturer.position ?? '—'}</td>
                    <td className="p-4">{lecturer.department?.code ?? '—'}</td>
                    <td className="p-4 text-muted-foreground">{lecturer.email}</td>
                    <td className="p-4 text-center">
                      <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${lecturerStatusMeta(lecturer.employment_status).className}`}>
                        {lecturerStatusMeta(lecturer.employment_status).label}
                      </span>
                    </td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link href={route('manager.lecturers.show', lecturer.uuid)} className="p-1.5 text-indigo-600 hover:bg-indigo-50 rounded-lg transition" title="Xem">
                          <Eye className="w-4 h-4" />
                        </Link>
                        <button onClick={() => openEdit(lecturer)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(lecturer.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {lecturers.data.length === 0 && (
            <ListEmptyState
              icon={Search}
              what="giảng viên"
              filters={currentFilters}
              routeName="manager.lecturers.index"
              hint={<>Thêm giảng viên bằng nút <b>"Thêm giảng viên"</b> ở trên, hoặc khai trong sheet <b>GiangVien</b> của file khung.</>}
            />
          )}

          <PaginationControls
            currentPage={lecturers.current_page}
            totalPages={lecturers.last_page}
            onPageChange={handlePageChange}
            totalItems={lecturers.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
