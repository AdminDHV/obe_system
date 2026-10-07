import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { laVanHanh } from '@/lib/permissions';
import {
  BookOpen, Users, ArrowRight, BarChart3, Target, Eye, Search, X, GraduationCap,
  ArrowUpDown, ArrowUp, ArrowDown, Plus, Pencil, Trash2, CheckCircle2, AlertCircle
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card, CardContent } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';

const EMPTY_FORM = {
  syllabus_id: '', cohort_id: '', lecturer_id: '', class_code: '',
  academic_year: '', semester: '1', max_capacity: '', start_date: '', end_date: '',
};

export default function Index({ classes, stats, semesterOptions, yearOptions, cohortOptions, lecturerOptions, syllabusOptions, currentFilters }) {
  const { flash, auth } = usePage().props;
  // GV chỉ theo dõi & nhập điểm — tạo/sửa/xoá lớp là việc của người vận hành.
  const vanHanh = laVanHanh(auth?.user);
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null); // 'create' | uuid | null
  const [formData, setFormData] = useState(EMPTY_FORM);

  const openCreate = () => { setFormData(EMPTY_FORM); setFormMode('create'); };
  const openEdit = (cls) => {
    setFormData({
      syllabus_id: cls.syllabus_id != null ? String(cls.syllabus_id) : '',
      cohort_id: cls.cohort_id != null ? String(cls.cohort_id) : '',
      lecturer_id: cls.lecturer_id != null ? String(cls.lecturer_id) : '',
      class_code: cls.class_code ?? '',
      academic_year: cls.academic_year ?? '',
      semester: String(cls.semester ?? '1'),
      max_capacity: cls.max_capacity != null ? String(cls.max_capacity) : '',
      start_date: cls.start_date ?? '',
      end_date: cls.end_date ?? '',
    });
    setFormMode(cls.uuid);
  };
  const closeForm = () => { setFormMode(null); setFormData(EMPTY_FORM); };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = {
      ...formData,
      semester: parseInt(formData.semester, 10),
      max_capacity: formData.max_capacity === '' ? null : parseInt(formData.max_capacity, 10),
      start_date: formData.start_date || null,
      end_date: formData.end_date || null,
    };
    if (formMode === 'create') {
      router.post(route('manager.classes.store'), payload, { onSuccess: () => closeForm(), preserveScroll: true });
    } else {
      router.put(route('manager.classes.update', formMode), payload, { onSuccess: () => closeForm(), preserveScroll: true });
    }
  };

  const handleDelete = async (cls) => {
    if (await confirm({ title: 'Xác nhận xóa', description: `Xóa lớp ${cls.class_code}?`, confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.classes.destroy', cls.uuid), { preserveScroll: true });
    }
  };

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (value === 'all' || value === '') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.classes.index'), params, {
      preserveState: true,
      replace: true
    });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) {
      handleFilterChange('q', '');
      return;
    }
    searchTimeout.current = setTimeout(() => {
      handleFilterChange('q', value);
    }, 300);
  };

  const handlePageChange = (page) => {
    const params = { ...currentFilters, page };
    router.get(route('manager.classes.index'), params, {
      preserveState: true,
      replace: true,
    });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') {
        setSortDir('desc');
      } else if (sortDir === 'desc') {
        setSortField(null);
        setSortDir(null);
      }
    } else {
      setSortField(field);
      setSortDir('asc');
    }
  };

  const sortedClasses = useMemo(() => {
    if (!sortField || !sortDir) return classes.data;
    return [...classes.data].sort((a, b) => {
      let cmp;
      if (sortField === 'class_code') cmp = a.class_code.localeCompare(b.class_code);
      else if (sortField === 'course_name') cmp = a.course_name.localeCompare(b.course_name);
      else if (sortField === 'lecturer_name') cmp = a.lecturer_name.localeCompare(b.lecturer_name);
      else if (sortField === 'student_count') cmp = a.student_count - b.student_count;
      else if (sortField === 'semester') cmp = a.semester - b.semester;
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [classes.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý lớp học phần" />

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
            <h2 className="text-2xl font-bold tracking-tight">Quản lý lớp học phần</h2>
            <p className="text-muted-foreground">
              {vanHanh ? 'Danh sách lớp học phần toàn trường' : 'Các lớp học phần bạn phụ trách'}
            </p>
          </div>

          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.academic_year || 'all'}
              onValueChange={(val) => handleFilterChange('academic_year', val)}
              className="w-full sm:w-[140px] h-9"
              placeholder="Tất cả năm"
              options={[
                { value: 'all', label: 'Tất cả năm' },
                ...yearOptions.map((year) => ({ value: year, label: year })),
              ]}
            />
            <SearchableSelect
              value={currentFilters.semester || 'all'}
              onValueChange={(val) => handleFilterChange('semester', val)}
              className="w-full sm:w-[120px] h-9"
              placeholder="Tất cả HK"
              options={[
                { value: 'all', label: 'Tất cả HK' },
                { value: '1', label: 'Học kỳ 1' },
                { value: '2', label: 'Học kỳ 2' },
              ]}
            />
            <SearchableSelect
              value={currentFilters.cohort_id || 'all'}
              onValueChange={(val) => handleFilterChange('cohort_id', val)}
              className="w-full sm:w-[120px] h-9"
              placeholder="Tất cả khóa"
              options={[
                { value: 'all', label: 'Tất cả khóa' },
                ...cohortOptions.map((cohort) => ({ value: String(cohort.value), label: cohort.label })),
              ]}
            />
            <SearchableSelect
              value={currentFilters.lecturer_id || 'all'}
              onValueChange={(val) => handleFilterChange('lecturer_id', val)}
              className="w-full sm:w-[160px] h-9"
              placeholder="Tất cả GV"
              options={[
                { value: 'all', label: 'Tất cả GV' },
                ...lecturerOptions.map((lecturer) => ({ value: String(lecturer.value), label: lecturer.label })),
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm mã lớp, môn..."
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
            {vanHanh && (
              <Button onClick={openCreate} className="h-9 gap-1">
                <Plus className="w-4 h-4" />
                Thêm lớp
              </Button>
            )}
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="xl"
          title={formMode === 'create' ? 'Mở lớp học phần mới' : 'Chỉnh sửa lớp học phần'}
        >
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5 md:col-span-2">
                  <Label className="text-xs font-medium text-muted-foreground">Đề cương (học phần)</Label>
                  <SearchableSelect
                    value={formData.syllabus_id}
                    onValueChange={(v) => setFormData({ ...formData, syllabus_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn đề cương --"
                    options={syllabusOptions.map((s) => ({ value: String(s.value), label: s.label }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mã lớp</Label>
                  <Input value={formData.class_code} onChange={(e) => setFormData({ ...formData, class_code: e.target.value })} placeholder="VD: IT4060.01" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Khóa</Label>
                  <SearchableSelect
                    value={formData.cohort_id}
                    onValueChange={(v) => setFormData({ ...formData, cohort_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn khóa --"
                    options={cohortOptions.map((c) => ({ value: String(c.value), label: c.label }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Giảng viên</Label>
                  <SearchableSelect
                    value={formData.lecturer_id}
                    onValueChange={(v) => setFormData({ ...formData, lecturer_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn GV --"
                    options={lecturerOptions.map((l) => ({ value: String(l.value), label: l.label }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Học kỳ</Label>
                  <SearchableSelect
                    value={formData.semester}
                    onValueChange={(v) => setFormData({ ...formData, semester: v })}
                    className="w-full h-9"
                    options={[
                      { value: '1', label: 'Học kỳ 1' },
                      { value: '2', label: 'Học kỳ 2' },
                      { value: '3', label: 'Học kỳ hè' },
                    ]}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Năm học</Label>
                  <Input value={formData.academic_year} onChange={(e) => setFormData({ ...formData, academic_year: e.target.value })} placeholder="VD: 2025-2026" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Sĩ số tối đa</Label>
                  <Input type="number" min="0" value={formData.max_capacity} onChange={(e) => setFormData({ ...formData, max_capacity: e.target.value })} placeholder="VD: 50" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Ngày bắt đầu</Label>
                  <Input type="date" value={formData.start_date} onChange={(e) => setFormData({ ...formData, start_date: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Ngày kết thúc</Label>
                  <Input type="date" value={formData.end_date} onChange={(e) => setFormData({ ...formData, end_date: e.target.value })} />
                </div>
                <div className="md:col-span-2 flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9" disabled={!formData.syllabus_id || !formData.cohort_id || !formData.lecturer_id}>
                    {formMode === 'create' ? 'Mở lớp' : 'Cập nhật'}
                  </Button>
                </div>
              </form>
        </FormDialog>

        <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <BookOpen className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Tổng lớp</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.total_classes}</span>
                  <span className="text-base font-medium text-muted-foreground">lớp</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <Users className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Tổng SV</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.total_students}</span>
                  <span className="text-base font-medium text-muted-foreground">/ {stats.total_capacity}</span>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card className="shadow-none">
            <CardContent className="p-6 flex items-center gap-4">
              <div className="flex items-center justify-center size-12 rounded-xl bg-primary/10 text-primary shrink-0">
                <BarChart3 className="size-6" />
              </div>
              <div className="min-w-0">
                <p className="text-sm font-medium text-muted-foreground">Giảng viên</p>
                <div className="flex items-baseline gap-1.5 mt-0.5">
                  <span className="text-3xl font-bold tracking-tight">{stats.total_lecturers}</span>
                  <span className="text-base font-medium text-muted-foreground">GV</span>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('class_code')}>
                    <div className="flex items-center">Mã lớp <SortIcon field="class_code" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('course_name')}>
                    <div className="flex items-center">Học phần <SortIcon field="course_name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('lecturer_name')}>
                    <div className="flex items-center">GV <SortIcon field="lecturer_name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('semester')}>
                    <div className="flex items-center">Khóa / HK <SortIcon field="semester" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('student_count')}>
                    <div className="flex items-center justify-center">SV <SortIcon field="student_count" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedClasses.map((cls, index) => (
                  <tr key={cls.id} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(classes.current_page - 1) * classes.per_page + index + 1}</td>
                    <td className="p-4">
                      <span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{cls.class_code}</span>
                    </td>
                    <td className="p-4">
                      <div className="font-semibold line-clamp-1">{cls.course_name}</div>
                      <div className="text-xs text-muted-foreground">{cls.course_code} • {cls.credits} TC</div>
                    </td>
                    <td className="p-4">
                      <div className="flex items-center gap-1.5">
                        <GraduationCap className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                        <span className="text-xs font-medium">{cls.lecturer_name}</span>
                      </div>
                    </td>
                    <td className="p-4">
                      <div className="text-xs font-medium">{cls.cohort_name}</div>
                      <div className="text-xs text-muted-foreground">HK{cls.semester} • {cls.academic_year}</div>
                    </td>
                    <td className="p-4 text-center">
                      <span className="font-semibold">{cls.student_count}</span>
                      <span className="text-xs text-muted-foreground">/{cls.max_capacity}</span>
                    </td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link
                          href={route('manager.classes.show', cls.uuid)}
                          className="inline-flex items-center gap-1.5 text-xs font-medium text-indigo-600 hover:text-indigo-700 transition bg-indigo-50 hover:bg-indigo-100 px-3 py-1.5 rounded-lg"
                        >
                          Chi tiết
                          <ArrowRight className="w-3.5 h-3.5" />
                        </Link>
                        {vanHanh && (
                          <>
                            <button onClick={() => openEdit(cls)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                              <Pencil className="w-4 h-4" />
                            </button>
                            <button onClick={() => handleDelete(cls)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                              <Trash2 className="w-4 h-4" />
                            </button>
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {classes.data.length === 0 && (
            <div className="p-12 text-center">
              <BookOpen className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Không có lớp học phần nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">Thử đổi bộ lọc</p>
            </div>
          )}

          <PaginationControls
            currentPage={classes.current_page}
            totalPages={classes.last_page}
            onPageChange={handlePageChange}
            totalItems={classes.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
