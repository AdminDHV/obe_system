import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2, Plus, CheckCircle2, AlertCircle, ListChecks, Info } from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import FormDialog from '@/components/FormDialog';
import { useConfirm } from '@/components/ConfirmDialog';

const CATEGORY_LABEL = { process: 'Quá trình', final: 'Cuối kỳ' };

export default function Show({ assessment, clos }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();
  const [formMode, setFormMode] = useState(null); // 'create' | mapping uuid | null
  const [formData, setFormData] = useState({ clo_id: '', weight_in_assessment: '100' });

  const openCreate = () => { setFormData({ clo_id: '', weight_in_assessment: '100' }); setFormMode('create'); };
  const openEdit = (m) => {
    setFormData({ clo_id: String(m.clo?.id ?? ''), weight_in_assessment: String(m.weight_in_assessment) });
    setFormMode(m.uuid);
  };
  const closeForm = () => { setFormMode(null); setFormData({ clo_id: '', weight_in_assessment: '100' }); };

  const handleSubmit = (e) => {
    e.preventDefault();
    const payload = { clo_id: formData.clo_id, weight_in_assessment: parseFloat(formData.weight_in_assessment) };
    if (formMode === 'create') {
      router.post(route('manager.assessment-clo-mappings.store'), { ...payload, assessment_id: assessment.id }, { onSuccess: () => closeForm(), preserveScroll: true });
    } else {
      router.put(route('manager.assessment-clo-mappings.update', formMode), payload, { onSuccess: () => closeForm(), preserveScroll: true });
    }
  };

  const handleDelete = async (uuid) => {
    if (await confirm({ title: 'Xác nhận gỡ', description: 'Gỡ CLO này khỏi thành phần đánh giá?', confirmText: 'Gỡ', variant: 'danger' })) {
      router.delete(route('manager.assessment-clo-mappings.destroy', uuid), { preserveScroll: true });
    }
  };

  const course = assessment.syllabus?.course;

  return (
    <DashboardLayout>
      <Head title={`Map CLO · ${assessment.name}`} />

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
          <Link href={route('manager.assessments.index')} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground mb-2">
            <ArrowLeft className="w-4 h-4" /> Quay lại danh sách Thành phần đánh giá
          </Link>
          <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div className="flex items-start gap-3">
              <div className="p-2.5 rounded-lg bg-primary/10">
                <ListChecks className="w-6 h-6 text-primary" />
              </div>
              <div>
                <h2 className="text-2xl font-bold tracking-tight">{assessment.name}</h2>
                <p className="text-muted-foreground text-sm">
                  {CATEGORY_LABEL[assessment.assessment_category] ?? assessment.assessment_category}
                  {assessment.weight_percent != null && ` · ${parseFloat(assessment.weight_percent)}%`}
                  {course?.code && ` · ${course.code} — ${course.name ?? ''}`}
                </p>
              </div>
            </div>
            <Button onClick={openCreate} className="h-9 gap-1">
              <Plus className="w-4 h-4" /> Thêm CLO
            </Button>
          </div>
        </div>

        {/* Rubric-derived CLOs (read-only) */}
        {assessment.is_rubric_based && (
          <div className="p-3 rounded-lg bg-blue-50/60 border border-blue-200 text-sm flex items-start gap-3">
            <Info className="w-5 h-5 text-blue-600 shrink-0 mt-0.5" />
            <div className="space-y-1">
              <p className="text-blue-900">
                Đây là thành phần <b>chấm theo Phiếu chấm Rubric</b>. CLO đã được lấy <b>tự động</b> từ các tiêu chí Rubric{assessment.rubric_uuid && (
                  <> — <Link href={route('manager.rubrics.show', assessment.rubric_uuid)} className="font-semibold text-blue-700 hover:underline">quản lý tại Rubric</Link></>
                )}. Engine vẫn đo CLO đúng dù không map thủ công bên dưới.
              </p>
              {assessment.rubric_clos.length > 0 && (
                <div className="flex flex-wrap gap-1 pt-1">
                  {assessment.rubric_clos.map((c) => (
                    <span key={c.code} className="px-2 py-0.5 rounded text-xs font-bold bg-blue-100 text-blue-700" title={c.description}>{c.code}</span>
                  ))}
                </div>
              )}
            </div>
          </div>
        )}

        <FormDialog
          open={!!formMode}
          onClose={closeForm}
          size="md"
          title={formMode === 'create' ? 'Map CLO vào thành phần' : 'Sửa map CLO'}
        >
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">CLO (Chuẩn đầu ra học phần)</Label>
              <SearchableSelect
                value={formData.clo_id}
                onValueChange={(v) => setFormData({ ...formData, clo_id: v })}
                options={clos.map((c) => ({ value: String(c.id), label: `${c.code} — ${c.description}` }))}
                placeholder="-- Chọn CLO --"
                searchPlaceholder="Tìm CLO..."
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Trọng số trong thành phần (%)</Label>
              <Input type="number" step="0.01" min="0" max="100" value={formData.weight_in_assessment} onChange={(e) => setFormData({ ...formData, weight_in_assessment: e.target.value })} required />
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
              <Button type="submit" className="h-9" disabled={!formData.clo_id}>{formMode === 'create' ? 'Thêm' : 'Cập nhật'}</Button>
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
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mô tả</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trọng số (%)</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {assessment.mappings.map((m, index) => (
                  <tr key={m.uuid} className="hover:bg-muted/30 transition-colors">
                    <td className="p-4 text-muted-foreground">{index + 1}</td>
                    <td className="p-4">
                      <span className="inline-block px-2 py-0.5 rounded text-xs font-bold bg-blue-50 text-blue-600" title={m.clo?.description}>{m.clo?.code ?? '—'}</span>
                    </td>
                    <td className="p-4 text-muted-foreground line-clamp-1 max-w-md">{m.clo?.description ?? '—'}</td>
                    <td className="p-4 text-center font-semibold">{parseFloat(m.weight_in_assessment)}</td>
                    <td className="p-4">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openEdit(m)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa"><Pencil className="w-4 h-4" /></button>
                        <button onClick={() => handleDelete(m.uuid)} className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition" title="Gỡ"><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {assessment.mappings.length === 0 && (
            <div className="p-12 text-center">
              <ListChecks className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa map CLO thủ công nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">
                {assessment.is_rubric_based
                  ? 'Thành phần rubric đã tự lấy CLO từ tiêu chí — có thể không cần map thủ công.'
                  : 'Bắt buộc map CLO để hệ thống tính được Chuẩn đầu ra.'}
              </p>
            </div>
          )}
        </Card>
      </div>
    </DashboardLayout>
  );
}
