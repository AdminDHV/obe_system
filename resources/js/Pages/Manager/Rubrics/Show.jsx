import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
  ArrowLeft, Pencil, Trash2, Plus, CheckCircle2, AlertCircle, ClipboardList,
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useConfirm } from '@/components/ConfirmDialog';

export default function Show({ rubric, clos }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const [formMode, setFormMode] = useState(null); // 'create' | criterion uuid | null
  const [formData, setFormData] = useState({ clo_id: '', max_score: '', description: '' });

  const openCreate = () => {
    setFormData({ clo_id: '', max_score: '', description: '' });
    setFormMode('create');
  };

  const openEdit = (c) => {
    setFormData({
      clo_id: String(c.clo?.id ?? ''),
      max_score: String(c.max_score),
      description: c.description,
    });
    setFormMode(c.uuid);
  };

  const closeForm = () => {
    setFormMode(null);
    setFormData({ clo_id: '', max_score: '', description: '' });
  };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = {
      clo_id: formData.clo_id,
      max_score: parseFloat(formData.max_score),
      description: formData.description,
    };
    if (formMode === 'create') {
      router.post(route('manager.rubric-criteria.store'), { ...payload, rubric_id: rubric.id }, { onSuccess: () => closeForm() });
    } else {
      router.put(route('manager.rubric-criteria.update', formMode), payload, { onSuccess: () => closeForm() });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận xóa', description: 'Xóa tiêu chí này?', confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.rubric-criteria.destroy', uuid));
    }
  };

  const course = rubric.assessment?.syllabus?.course;

  return (
    <DashboardLayout>
      <Head title={`Rubric · ${rubric.name}`} />

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

        <div>
          <Link href={route('manager.rubrics.index')} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground mb-2">
            <ArrowLeft className="w-4 h-4" /> Quay lại danh sách Rubric
          </Link>
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div className="flex items-start gap-3">
              <div className="p-2.5 rounded-lg bg-primary/10">
                <ClipboardList className="w-6 h-6 text-primary" />
              </div>
              <div>
                <h2 className="text-2xl font-bold tracking-tight">{rubric.name}</h2>
                <p className="text-muted-foreground text-sm">
                  Thành phần đánh giá: <span className="font-medium text-foreground">{rubric.assessment?.name ?? '—'}</span>
                  {rubric.assessment?.weight_percent != null && ` (${parseFloat(rubric.assessment.weight_percent)}%)`}
                  {course?.code && ` · ${course.code} — ${course.name ?? ''}`}
                </p>
              </div>
            </div>
            <Button onClick={openCreate} className="h-9 gap-1">
              <Plus className="w-4 h-4" /> Thêm tiêu chí
            </Button>
          </div>
        </div>

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="lg"
          title={formMode === 'create' ? 'Thêm tiêu chí mới' : 'Chỉnh sửa tiêu chí'}
        >
              <form onSubmit={handleSubmit} className="flex flex-col gap-4">
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">CLO được đo lường</Label>
                  <SearchableSelect
                    value={formData.clo_id}
                    onValueChange={(v) => setFormData({ ...formData, clo_id: v })}
                    className="w-full h-9"
                    placeholder="-- Chọn CLO --"
                    options={clos.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.description}` }))}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Điểm tối đa</Label>
                  <Input type="number" step="0.01" min="0" value={formData.max_score} onChange={(e) => setFormData({ ...formData, max_score: e.target.value })} placeholder="VD: 10" required />
                </div>
                <div className="space-y-1.5">
                  <Label className="text-xs font-medium text-muted-foreground">Mô tả tiêu chí</Label>
                  <Textarea value={formData.description} onChange={(e) => setFormData({ ...formData, description: e.target.value })} placeholder="VD: Kỹ năng thuyết trình, trả lời câu hỏi phản biện..." rows={2} required />
                </div>
                <div className="flex justify-end gap-2 pt-2">
                  <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
                  <Button type="submit" className="h-9" disabled={!formData.clo_id}>{formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}</Button>
                </div>
              </form>
        </FormDialog>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">CLO</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mô tả tiêu chí</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Điểm tối đa</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {rubric.criteria.map((c, index) => (
                  <tr key={c.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{index + 1}</td>
                    <td className="p-4">
                      {c.clo ? (
                        <span className="inline-block px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-blue-600" title={c.clo.description}>
                          {c.clo.code}
                        </span>
                      ) : '—'}
                    </td>
                    <td className="p-4">{c.description}</td>
                    <td className="p-4 text-center font-semibold">{parseFloat(c.max_score)}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openEdit(c)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                          <Pencil className="w-4 h-4" />
                        </button>
                        <button onClick={() => handleDelete(c.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Xóa">
                          <Trash2 className="w-4 h-4" />
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {rubric.criteria.length === 0 && (
            <div className="p-12 text-center">
              <ClipboardList className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Rubric này chưa có tiêu chí nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">Thêm tiêu chí và ánh xạ tới một CLO để đo lường</p>
            </div>
          )}
        </Card>
      </div>
    </DashboardLayout>
  );
}
