import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Search, X, Pencil, Trash2, Plus, CheckCircle2, AlertCircle, Lock, Unlock, ShieldCheck,
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import PasswordInput from '@/components/PasswordInput';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import ListEmptyState from '@/components/ListEmptyState';
import { roleLabel } from '@/lib/roles';


export default function Index({ users, departments, availableRoles, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [formMode, setFormMode] = useState(null); // 'create' | uuid | null
  const emptyForm = {
    full_name: '', email: '', staff_code: '', department_id: '', position: '', phone: '',
    password: '', roles: [], is_active: true,
  };
  const [formData, setFormData] = useState(emptyForm);

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') delete params[key]; else params[key] = value;
    delete params.page;
    router.get(route('manager.users.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.users.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const openCreate = () => { setFormData(emptyForm); setFormMode('create'); };
  const openEdit = (u) => {
    setFormData({
      full_name: u.full_name ?? '', email: u.email ?? '', staff_code: u.staff_code ?? '',
      department_id: u.department_id != null ? String(u.department_id) : '',
      position: u.position ?? '', phone: u.phone ?? '', password: '',
      roles: [...(u.roles ?? [])], is_active: !!u.is_active,
    });
    setFormMode(u.uuid);
  };
  const closeForm = () => { setFormMode(null); setFormData(emptyForm); };

  const toggleRole = (role) => {
    setFormData((d) => ({
      ...d,
      roles: d.roles.includes(role) ? d.roles.filter((r) => r !== role) : [...d.roles, role],
    }));
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = { ...formData, department_id: formData.department_id || null };
    if (formMode === 'create') {
      router.post(route('manager.users.store'), payload, { onSuccess: () => closeForm(), preserveScroll: true });
    } else {
      router.put(route('manager.users.update', formMode), payload, { onSuccess: () => closeForm(), preserveScroll: true });
    }
  };

  const handleToggleActive = (u) => {
    router.patch(route('manager.users.toggle-active', u.uuid), {}, { preserveScroll: true });
  };

  const handleDelete = async (u) => {
    if (await confirm({ title: 'Xác nhận xóa', description: `Xóa tài khoản ${u.full_name}?`, confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.users.destroy', u.uuid), { preserveScroll: true });
    }
  };

  return (
    <DashboardLayout>
      <Head title="Người dùng & Vai trò" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}
        {flash?.error && (
          <div className="p-3 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 flex items-start gap-3 text-sm">
            <AlertCircle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.error}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Người dùng & Vai trò</h2>
            <p className="text-muted-foreground">Quản lý tài khoản nhân sự và gán vai trò</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.role ?? 'all'}
              onValueChange={(v) => handleFilterChange('role', v)}
              className="w-full sm:w-[150px] h-9"
              placeholder="Tất cả vai trò"
              options={[
                { value: 'all', label: 'Tất cả vai trò' },
                ...availableRoles.map((r) => ({ value: r, label: roleLabel(r) })),
              ]}
            />
            <SearchableSelect
              value={currentFilters.is_active ?? 'all'}
              onValueChange={(v) => handleFilterChange('is_active', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Trạng thái"
              options={[
                { value: 'all', label: 'Mọi trạng thái' },
                { value: '1', label: 'Đang hoạt động' },
                { value: '0', label: 'Đã khóa' },
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm tên, email, mã..."
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
              <Plus className="w-4 h-4" /> Thêm người dùng
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="xl"
          title={formMode === 'create' ? 'Thêm người dùng mới' : 'Chỉnh sửa người dùng'}
        >
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Họ tên</Label>
                  <Input value={formData.full_name} onChange={(e) => setFormData({ ...formData, full_name: e.target.value })} required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Email</Label>
                  <Input type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã nhân sự</Label>
                  <Input value={formData.staff_code} onChange={(e) => setFormData({ ...formData, staff_code: e.target.value })} placeholder="VD: GV001" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Khoa</Label>
                  <SearchableSelect
                    value={formData.department_id || 'none'}
                    onValueChange={(v) => setFormData({ ...formData, department_id: v === 'none' ? '' : v })}
                    className="w-full h-9"
                    placeholder="-- Không có --"
                    options={[
                      { value: 'none', label: '-- Không có --' },
                      ...departments.map((d) => ({ value: String(d.id), label: d.name })),
                    ]}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Chức danh</Label>
                  <Input value={formData.position} onChange={(e) => setFormData({ ...formData, position: e.target.value })} placeholder="VD: Trưởng khoa" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Điện thoại</Label>
                  <Input value={formData.phone} onChange={(e) => setFormData({ ...formData, phone: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">
                    Mật khẩu {formMode !== 'create' && <span className="normal-case">(để trống nếu không đổi)</span>}
                  </Label>
                  <PasswordInput
                    value={formData.password}
                    onChange={(e) => setFormData({ ...formData, password: e.target.value })}
                    placeholder={formMode === 'create' ? 'Tối thiểu 8 ký tự' : '••••••••'}
                    required={formMode === 'create'}
                  />
                </div>
                <div className="space-y-1.5 md:col-span-2">
                  <Label className="text-xs font-medium text-muted-foreground">Vai trò</Label>
                  <div className="flex flex-wrap gap-4 pt-2">
                    {availableRoles.map((r) => (
                      <label key={r} className="flex items-center gap-2 text-sm cursor-pointer">
                        <Checkbox checked={formData.roles.includes(r)} onCheckedChange={() => toggleRole(r)} />
                        <span>{roleLabel(r)}</span>
                      </label>
                    ))}
                  </div>
                </div>
                <div className="space-y-1.5 flex items-end pb-2">
                  <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <Checkbox checked={formData.is_active} onCheckedChange={(v) => setFormData({ ...formData, is_active: !!v })} />
                    <span className="text-xs font-medium text-muted-foreground">Tài khoản hoạt động</span>
                  </label>
                </div>
                <div className="md:col-span-2 flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9" disabled={formData.roles.length === 0}>
                    {formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}
                  </Button>
                </div>
              </form>
        </FormDialog>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Họ tên</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Email</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Vai trò</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Khoa</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {users.data.map((u, index) => (
                  <tr key={u.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(users.current_page - 1) * users.per_page + index + 1}</td>
                    <td className="p-4">
                      <div className="font-medium">{u.full_name}</div>
                      {u.staff_code && <div className="text-xs text-muted-foreground">{u.staff_code}</div>}
                    </td>
                    <td className="p-4 text-muted-foreground">{u.email}</td>
                    <td className="p-4">
                      <div className="flex flex-wrap gap-1">
                        {u.roles.map((r) => (
                          <span key={r} className={`px-2 py-0.5 rounded text-xs font-medium ${
                            r === 'super_admin' ? 'bg-violet-50 text-violet-700' : 'bg-blue-50 text-blue-600'
                          }`}>{roleLabel(r)}</span>
                        ))}
                      </div>
                    </td>
                    <td className="p-4 text-muted-foreground">{u.department?.name ?? '—'}</td>
                    <td className="p-4 text-center">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                        u.is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-muted text-muted-foreground'
                      }`}>{u.is_active ? 'Hoạt động' : 'Đã khóa'}</span>
                    </td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => handleToggleActive(u)} className="p-1.5 text-slate-600 hover:bg-slate-100 rounded-lg transition" title={u.is_active ? 'Khóa' : 'Mở khóa'}>
                          {u.is_active ? <Lock className="w-4 h-4" /> : <Unlock className="w-4 h-4" />}
                        </button>
                        <button onClick={() => openEdit(u)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(u)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {users.data.length === 0 && (
            <ListEmptyState
              icon={ShieldCheck}
              what="người dùng"
              filters={currentFilters}
              routeName="manager.users.index"
              hint={<>Thêm tài khoản bằng nút <b>"Thêm người dùng"</b> ở trên.</>}
            />
          )}

          <PaginationControls
            currentPage={users.current_page}
            totalPages={users.last_page}
            onPageChange={handlePageChange}
            totalItems={users.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
