import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  Search, X, Pencil, Trash2, ArrowUpDown, ArrowUp, ArrowDown, Plus, CheckCircle2, ListChecks, ClipboardList
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

const ASSESSMENT_CATEGORIES = [
  { value: 'quiz', label: 'Quiz' },
  { value: 'midterm', label: 'Midterm' },
  { value: 'final', label: 'Final' },
  { value: 'project', label: 'Project' },
  { value: 'assignment', label: 'Assignment' },
  { value: 'practical', label: 'Practical' },
  { value: 'other', label: 'Other' },
];

export default function Index({ assessments, syllabi, currentFilters }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [sortField, setSortField] = useState(null);
  const [sortDir, setSortDir] = useState(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState({
    syllabus_id: '', name: '', assessment_category: '', weight_percent: '', is_rubric_based: false,
  });

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value || value === 'all') {
      delete params[key];
    } else {
      params[key] = value;
    }
    delete params.page;
    router.get(route('manager.assessments.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (!value) { handleFilterChange('q', ''); return; }
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.assessments.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const handleSort = (field) => {
    if (sortField === field) {
      if (sortDir === 'asc') setSortDir('desc');
      else if (sortDir === 'desc') { setSortField(null); setSortDir(null); }
    } else { setSortField(field); setSortDir('asc'); }
  };

  const sortedAssessments = useMemo(() => {
    if (!sortField || !sortDir) return assessments.data;
    return [...assessments.data].sort((a, b) => {
      let cmp;
      if (sortField === 'name') cmp = a.name.localeCompare(b.name);
      else if (sortField === 'category') cmp = a.assessment_category.localeCompare(b.assessment_category);
      else if (sortField === 'weight') cmp = parseFloat(a.weight_percent) - parseFloat(b.weight_percent);
      else cmp = 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
  }, [assessments.data, sortField, sortDir]);

  const SortIcon = ({ field }) => {
    if (sortField !== field) return <ArrowUpDown className="w-3 h-3 text-muted-foreground/40 ml-1 shrink-0" />;
    if (sortDir === 'asc') return <ArrowUp className="w-3 h-3 text-primary ml-1 shrink-0" />;
    return <ArrowDown className="w-3 h-3 text-primary ml-1 shrink-0" />;
  };

  const openCreate = () => {
    setFormData({ syllabus_id: '', name: '', assessment_category: '', weight_percent: '', is_rubric_based: false });
    setFormMode('create');
  };

  const openEdit = (item) => {
    setFormData({
      syllabus_id: item.syllabus_id != null ? String(item.syllabus_id) : '',
      name: item.name,
      assessment_category: item.assessment_category,
      weight_percent: String(item.weight_percent),
      is_rubric_based: !!item.is_rubric_based,
    });
    setFormMode(item.uuid);
  };

  const closeForm = () => {
    setFormMode(null);
    setFormData({ syllabus_id: '', name: '', assessment_category: '', weight_percent: '', is_rubric_based: false });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = { ...formData, weight_percent: parseFloat(formData.weight_percent) };
    if (formMode === 'create') {
      router.post(route('manager.assessments.store'), payload, {
        onSuccess: () => closeForm(),
      });
    } else {
      router.put(route('manager.assessments.update', formMode), payload, {
        onSuccess: () => closeForm(),
      });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa thành phần đánh giá này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.assessments.destroy', uuid));
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý thành phần đánh giá" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight">Quản lý thành phần đánh giá</h2>
            <p className="text-muted-foreground">Danh sách thành phần đánh giá / assessment</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <SearchableSelect
              value={currentFilters.syllabus_id ?? 'all'}
              onValueChange={(v) => handleFilterChange('syllabus_id', v)}
              className="w-full sm:w-[180px] h-9"
              placeholder="Tất cả đề cương"
              options={[
                { value: 'all', label: 'Tất cả đề cương' },
                ...syllabi.map((s) => ({ value: String(s.id), label: s.label })),
              ]}
            />
            <div className="relative w-full sm:w-[200px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm thành phần đánh giá..."
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
              Thêm thành phần đánh giá
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="lg"
          title={formMode === 'create' ? 'Thêm thành phần đánh giá mới' : 'Chỉnh sửa thành phần đánh giá'}
        >
              <form onSubmit={handleSubmit} className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Đề cương</Label>
                  <SearchableSelect
                    value={formData.syllabus_id}
                    onValueChange={(v) => setFormData({ ...formData, syllabus_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn đề cương --"
                    options={syllabi.map((s) => ({ value: String(s.id), label: s.label }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Tên thành phần đánh giá</Label>
                  <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Bài kiểm tra giữa kỳ" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Loại</Label>
                  <SearchableSelect
                    value={formData.assessment_category}
                    onValueChange={(v) => setFormData({ ...formData, assessment_category: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn loại --"
                    options={ASSESSMENT_CATEGORIES.map((cat) => ({ value: cat.value, label: cat.label }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Trọng số (%)</Label>
                  <Input type="number" step="0.01" value={formData.weight_percent} onChange={(e) => setFormData({ ...formData, weight_percent: e.target.value })} placeholder="VD: 20" required />
                </div>
                <div className="space-y-1.5 flex items-end pb-2">
                  <label className="flex items-center gap-2 text-sm cursor-pointer">
                    <Checkbox checked={formData.is_rubric_based} onCheckedChange={(v) => setFormData({ ...formData, is_rubric_based: !!v })} />
                    <span className="text-xs font-medium text-muted-foreground">Rubric-based</span>
                  </label>
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('name')}>
                    <div className="flex items-center">Tên <SortIcon field="name" /></div>
                  </th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('category')}>
                    <div className="flex items-center">Loại <SortIcon field="category" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground cursor-pointer select-none hover:bg-muted transition" onClick={() => handleSort('weight')}>
                    <div className="flex items-center justify-center">Trọng số (%) <SortIcon field="weight" /></div>
                  </th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Rubric?</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Học phần</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {sortedAssessments.map((assessment, index) => (
                  <tr key={assessment.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{(assessments.current_page - 1) * assessments.per_page + index + 1}</td>
                    <td className="p-4 font-medium">{assessment.name}</td>
                    <td className="p-4">
                      <span className="inline-block px-2 py-0.5 rounded text-xs font-medium bg-muted text-muted-foreground">
                        {assessment.assessment_category}
                      </span>
                    </td>
                    <td className="p-4 text-center font-semibold">{parseFloat(assessment.weight_percent)}%</td>
                    <td className="p-4 text-center">
                      <span className={`px-2 py-0.5 rounded text-xs font-medium ${
                        assessment.is_rubric_based ? 'bg-emerald-50 text-emerald-700' : 'bg-muted text-muted-foreground'
                      }`}>
                        {assessment.is_rubric_based ? 'Có' : 'Không'}
                      </span>
                    </td>
                    <td className="p-4">{assessment.syllabus?.course?.name ?? '—'}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <Link
                          href={route('manager.assessments.show', assessment.uuid)}
                          className={`inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1.5 rounded-lg transition ${assessment.mapped_clo_count > 0 ? 'text-indigo-600 bg-indigo-50 hover:bg-indigo-100' : 'text-amber-600 bg-amber-50 hover:bg-amber-100'}`}
                          title="Map CLO"
                        >
                          <ListChecks className="w-3.5 h-3.5" /> Map CLO ({assessment.mapped_clo_count})
                        </Link>
                        <button onClick={() => openEdit(assessment)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(assessment.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {assessments.data.length === 0 && (
            <ListEmptyState
              icon={ClipboardList}
              what="bài đánh giá"
              filters={currentFilters}
              routeName="manager.assessments.index"
              hint={<GoiYKhung>Bài đánh giá khai trong sheet <b>BaiDanhGia</b> của file khung, cùng loại (quá trình / cuối kỳ) và trọng số. Tải file khung lên tại</GoiYKhung>}
            />
          )}

          <PaginationControls
            currentPage={assessments.current_page}
            totalPages={assessments.last_page}
            onPageChange={handlePageChange}
            totalItems={assessments.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
