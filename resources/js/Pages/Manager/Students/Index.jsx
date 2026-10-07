import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Eye, Pencil, Trash2, Plus, CheckCircle2, ArrowUpDown, ArrowUp, ArrowDown, Users, Download
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card, CardContent } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import FieldError from '@/components/FieldError';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import { studentStatusMeta, STUDENT_STATUS_OPTIONS } from '@/lib/status';
import { cn } from '@/lib/utils';
import ListEmptyState, { GoiYKhung } from '@/components/ListEmptyState';

export default function Index({ students, programs, cohorts, classGroups = [], currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({
    student_code: '', full_name: '', email: '', program_id: '',
    cohort_id: '', class_group: '', gender: '', phone: '', status: 'studying',
  });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.students.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.students.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedStudents = useMemo(() => {
    if (!sortField || !sortDir) return students.data;
    return [...students.data].sort((a, b) => {
      let cmp;
      if (sortField === 'student_code') cmp = a.student_code.localeCompare(b.student_code);
      else if (sortField === 'full_name') cmp = a.full_name.localeCompare(b.full_name);
      else if (sortField === 'email') cmp = a.email.localeCompare(b.email);
      else if (sortField === 'program') cmp = (a.program?.code ?? '').localeCompare(b.program?.code ?? '');
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [students.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({
      student_code: '', full_name: '', email: '', program_id: '',
      cohort_id: '', class_group: '', gender: '', phone: '', status: 'studying',
    });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({
      student_code: item.student_code,
      full_name: item.full_name,
      email: item.email,
      program_id: item.program_id ?? item.program?.uuid ?? '',
      cohort_id: item.cohort_id ?? item.cohort?.uuid ?? '',
      class_group: item.class_group ?? '',
      gender: item.gender ?? '',
      phone: item.phone ?? '',
      status: item.status ?? 'studying',
    });
    setFormMode(item.uuid);
  };

  const closeForm = () => {
    setFormMode(null);
    setFormData({
      student_code: '', full_name: '', email: '', program_id: '',
      cohort_id: '', class_group: '', gender: '', phone: '', status: 'studying',
    });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.students.store'), formData, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.students.update', formMode), formData, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa sinh viên này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.students.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Danh sách sinh viên" />

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
            <h2 className="text-2xl font-bold tracking-tight">Danh sách sinh viên</h2>
            <p className="text-muted-foreground">Quản lý thông tin sinh viên toàn trường</p>
          </div>

          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.program_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('program_id', v)}
              className="w-full sm:w-[180px] h-9"
              placeholder="Tất cả ngành"
              options={[
                { value: 'all', label: 'Tất cả ngành' },
                ...programs.map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` })),
              ]}
            />

            <SearchableSelect
              value={currentFilters.cohort_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('cohort_id', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Tất cả khóa"
              options={[
                { value: 'all', label: 'Tất cả khóa' },
                ...cohorts.map((c) => ({ value: String(c.id), label: c.name })),
              ]}
            />

            {classGroups.length > 0 && (
              <SearchableSelect
                value={currentFilters.class_group ?? 'all'}
                onValueChange={(v) => handleFilterChange('class_group', v)}
                className="w-full sm:w-[140px] h-9"
                placeholder="Tất cả lớp"
                options={[
                  { value: 'all', label: 'Tất cả lớp' },
                  ...classGroups.map((g) => ({ value: g, label: g })),
                ]}
              />
            )}

            <SearchableSelect
              value={currentFilters.status ?? 'all'}
              onValueChange={(v) => handleFilterChange('status', v)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Trạng thái"
              options={[
                { value: 'all', label: 'Tất cả trạng thái' },
                ...STUDENT_STATUS_OPTIONS,
              ]}
            />

            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm MSSV, họ tên..."
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

            {/* Xuất phiếu kết quả cho từng SV (1 CSV/SV, gói .zip) — khoa phát cho sinh viên
                mà không cần cấp tài khoản. Bắt buộc chọn Ngành để biết phạm vi. */}
            <a
              href={
                currentFilters.program_id
                  ? route('manager.students.export-plo-bulk', {
                      program_id: currentFilters.program_id,
                      ...(currentFilters.cohort_id ? { cohort_id: currentFilters.cohort_id } : {}),
                    })
                  : undefined
              }
              onClick={(e) => {
                if (!currentFilters.program_id) {
                  e.preventDefault();
                  alert('Chọn Ngành trước để biết xuất phiếu cho những sinh viên nào.');
                }
              }}
              className={cn(
                'inline-flex h-9 items-center gap-1 rounded-md border border-border px-3 text-sm font-medium transition',
                currentFilters.program_id
                  ? 'bg-card hover:bg-muted/50 text-foreground'
                  : 'bg-muted/40 text-muted-foreground cursor-not-allowed'
              )}
              title="Mỗi sinh viên 1 phiếu CSV: PLO + PI + tiến độ tích lũy"
            >
              <Download className="w-4 h-4" />
              Xuất phiếu SV (.zip)
            </a>

            {/* Bảng 2: bảng tổng của lớp cho khoa/CVHT. `on_track=1` = chỉ gộp thống kê các SV
                đúng tiến độ trở lên; SV chậm vẫn nằm trong danh sách. */}
            <a
              href={
                currentFilters.program_id
                  ? route('manager.students.export-class-summary', {
                      program_id: currentFilters.program_id,
                      ...(currentFilters.cohort_id ? { cohort_id: currentFilters.cohort_id } : {}),
                      ...(currentFilters.class_group ? { class_group: currentFilters.class_group } : {}),
                      on_track: 1,
                    })
                  : undefined
              }
              onClick={(e) => {
                if (!currentFilters.program_id) {
                  e.preventDefault();
                  alert('Chọn Ngành trước để biết xuất bảng tổng của lớp nào.');
                }
              }}
              className={cn(
                'inline-flex h-9 items-center gap-1 rounded-md border border-border px-3 text-sm font-medium transition',
                currentFilters.program_id
                  ? 'bg-card hover:bg-muted/50 text-foreground'
                  : 'bg-muted/40 text-muted-foreground cursor-not-allowed'
              )}
              title="PLO/PI + tín chỉ của từng SV và mức đạt chung của lớp (chỉ gộp SV đúng tiến độ trở lên)"
            >
              <Users className="w-4 h-4" />
              Bảng tổng lớp (.csv)
            </a>

            <Button onClick={openCreate} className="h-9 gap-1">
              <Plus className="w-4 h-4" />
              Thêm SV
            </Button>
          </div>
        </div>

        {/* Form */}
        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="lg"
          title={formMode === 'create' ? 'Thêm sinh viên mới' : 'Chỉnh sửa sinh viên'}
        >
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">MSSV</Label>
                  <Input value={formData.student_code} onChange={(e) => setFormData({ ...formData, student_code: e.target.value })} placeholder="VD: SV001" required />
                  <FieldError name="student_code" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Họ tên</Label>
                  <Input value={formData.full_name} onChange={(e) => setFormData({ ...formData, full_name: e.target.value })} placeholder="VD: Nguyễn Văn A" required />
                  <FieldError name="full_name" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Email</Label>
                  <Input type="email" value={formData.email} onChange={(e) => setFormData({ ...formData, email: e.target.value })} placeholder="VD: a.nguyen@example.com" />
                  <FieldError name="email" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Ngành</Label>
                  <SearchableSelect
                    value={formData.program_id}
                    onValueChange={(v) => setFormData({ ...formData, program_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn ngành --"
                    options={programs.map((p) => ({ value: String(p.id), label: `${p.code} - ${p.name}` }))}
                  />
                  <FieldError name="program_id" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Khóa</Label>
                  <SearchableSelect
                    value={formData.cohort_id}
                    onValueChange={(v) => setFormData({ ...formData, cohort_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn khóa --"
                    options={cohorts.map((c) => ({ value: String(c.id), label: c.name }))}
                  />
                  <FieldError name="cohort_id" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Lớp sinh hoạt</Label>
                  <Input
                    value={formData.class_group}
                    onChange={(e) => setFormData({ ...formData, class_group: e.target.value })}
                    placeholder="VD: QK1 (bỏ trống nếu trường không chia lớp)"
                  />
                  <FieldError name="class_group" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Giới tính</Label>
                  <SearchableSelect
                    value={formData.gender}
                    onValueChange={(v) => setFormData({ ...formData, gender: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn giới tính --"
                    options={[
                      { value: 'Nam', label: 'Nam' },
                      { value: 'Nữ', label: 'Nữ' },
                      { value: 'Khác', label: 'Khác' },
                    ]}
                  />
                  <FieldError name="gender" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Số điện thoại</Label>
                  <Input value={formData.phone} onChange={(e) => setFormData({ ...formData, phone: e.target.value })} placeholder="VD: 0901234567" />
                  <FieldError name="phone" />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Trạng thái</Label>
                  <SearchableSelect
                    value={formData.status}
                    onValueChange={(v) => setFormData({ ...formData, status: v })}
                    className="w-full h-9"
                    placeholder="Trạng thái"
                    options={STUDENT_STATUS_OPTIONS}
                  />
                  <FieldError name="status" />
                </div>
                <div className="md:col-span-2 flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9">{formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}</Button>
                </div>
              </form>
        </FormDialog>

        {/* Student Table */}
        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('student_code')}>
                    <div className="flex items-center">MSSV <SortIcon field="student_code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('full_name')}>
                    <div className="flex items-center">Họ tên <SortIcon field="full_name" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Giới tính</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('email')}>
                    <div className="flex items-center">Email <SortIcon field="email" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('program')}>
                    <div className="flex items-center">Ngành <SortIcon field="program" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Khóa</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedStudents.map((student, index) => (
                  <tr key={student.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(students.current_page - 1) * students.per_page + index + 1}</td>
                    <td className="p-4">
                      <Link href={route('manager.students.show', student.uuid)}>
                        <span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{student.student_code}</span>
                      </Link>
                    </td>
                    <td className="p-4 font-medium">{student.full_name}</td>
                    <td className="p-4 text-center text-muted-foreground">{student.gender ?? '—'}</td>
                    <td className="p-4 text-muted-foreground">{student.email}</td>
                    <td className="p-4">{student.program?.code ?? '—'}</td>
                    <td className="p-4 text-muted-foreground">{student.cohort?.name ?? '—'}</td>
                    <td className="p-4 text-center">
                      <span className={`inline-block px-2 py-0.5 rounded text-xs font-medium ${studentStatusMeta(student.status).className}`}>
                        {studentStatusMeta(student.status).label}
                      </span>
                    </td>
                    <td className="p-4 text-center">
                      <div className="flex items-center justify-center gap-1">
                        <Link
                          href={route('manager.students.show', student.uuid)}
                          className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700 transition bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg"
                          title="Xem chi tiết"
                        >
                          <Eye className="w-3.5 h-3.5" />
                          Chi tiết
                        </Link>
                        <button onClick={() => openEdit(student)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(student.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {students.data.length === 0 && (
            <ListEmptyState
              icon={Users}
              what="sinh viên"
              filters={currentFilters}
              routeName="manager.students.index"
              hint={<GoiYKhung>Nhập danh sách sinh viên bằng file Excel tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={students.current_page}
            totalPages={students.last_page}
            onPageChange={handlePageChange}
            totalItems={students.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
