import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2, AlertCircle, ListChecks,
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';

export default function Index({ rubrics, assessments, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({ assessment_id: '', name: '' });

  // Assessments that do not yet own a rubric — only these can be picked when creating.
  const availableAssessments = useMemo(
    () => assessments.filter((a) => !a.has_rubric),
    [assessments],
  );

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.rubrics.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.rubrics.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedRubrics = useMemo(() => {
    if (!sortField || !sortDir) return rubrics.data;
    return [...rubrics.data].sort((a, b) => {
      let cmp;
      if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else if (sortField === 'criteria') cmp = a.criteria_count - b.criteria_count;
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [rubrics.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ assessment_id: '', name: '' });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({ assessment_id: String(item.assessment?.id ?? ''), name: item.name });
    setFormMode(item.uuid);
  };

  const closeForm = () => {
    setFormMode(null);
    setFormData({ assessment_id: '', name: '' });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    if (formMode === 'create') {
      router.post(route('manager.rubrics.store'), formData, { onSuccess: () => closeForm() });
    } else {
      router.put(route('manager.rubrics.update', formMode), { name: formData.name }, { onSuccess: () => closeForm() });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa rubric này? Toàn bộ tiêu chí bên trong cũng sẽ bị xóa.', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.rubrics.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý Rubric" />

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
            <h2 className="text-2xl font-bold tracking-tight">Quản lý Rubric</h2>
            <p className="text-muted-foreground">Rubric chấm điểm gắn với từng thành phần đánh giá (assessment)</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.assessment_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('assessment_id', v)}
              className="w-full sm:w-[220px] h-9"
              placeholder="Tất cả thành phần đánh giá"
              options={[
                { value: 'all', label: 'Tất cả thành phần đánh giá' },
                ...assessments.map((a) => ({ value: String(a.id), label: a.label })),
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm rubric..."
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
              Thêm rubric
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Thêm rubric mới' : 'Chỉnh sửa rubric'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Thành phần đánh giá (assessment)</Label>
                  {formMode === 'create' ? (
                    <SearchableSelect
                      value={formData.assessment_id}
                      onValueChange={(v) => setFormData({ ...formData, assessment_id: v })}
                      className="w-full h-9"
                      placeholder="-- Chọn thành phần đánh giá --"
                      options={availableAssessments.map((a) => ({ value: String(a.id), label: a.label }))}
                    />
                  ) : (
                    <Input
                      value={assessments.find((a) => String(a.id) === formData.assessment_id)?.label ?? '—'}
                      disabled
                    />
                  )}
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên rubric</Label>
                  <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Rubric thuyết trình nhóm" required />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9" disabled={formMode === 'create' && !formData.assessment_id}>
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên rubric <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Thành phần đánh giá</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Học phần</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('criteria')}>
                    <div className="flex items-center justify-center">Số tiêu chí <SortIcon field="criteria" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedRubrics.map((rubric, index) => (
                  <tr key={rubric.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(rubrics.current_page - 1) * rubrics.per_page + index + 1}</td>
                    <td className="p-4 font-medium">
                      <Link href={route('manager.rubrics.show', rubric.uuid)} className="text-primary hover:underline">
                        {rubric.name}
                      </Link>
                    </td>
                    <td className="p-4">{rubric.assessment?.name ?? '—'}</td>
                    <td className="p-4 text-muted-foreground">
                      {rubric.assessment?.syllabus?.course?.code
                        ? `${rubric.assessment.syllabus.course.code} — ${rubric.assessment.syllabus.course.name ?? ''}`
                        : '—'}
                    </td>
                    <td className="p-4 text-center">
                      <span className="inline-block px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-600">
                        {rubric.criteria_count}
                      </span>
                    </td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link href={route('manager.rubrics.show', rubric.uuid)} className="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-lg transition text-indigo-600 bg-indigo-50 hover:bg-indigo-100" title="Quản lý tiêu chí">
                          <ListChecks className="w-3.5 h-3.5" /> Tiêu chí ({rubric.criteria_count})
                        </Link>
                        <button onClick={() => openEdit(rubric)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(rubric.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {rubrics.data.length === 0 && (
            <div className="p-12 text-center">
              <Search className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa có rubric nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">Tạo rubric cho một thành phần đánh giá rubric-based để bắt đầu</p>
            </div>
          )}

          <PaginationControls
            currentPage={rubrics.current_page}
            totalPages={rubrics.last_page}
            onPageChange={handlePageChange}
            totalItems={rubrics.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
