import React, { useState } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { Search, X, Pencil, Trash2, Plus, CheckCircle2, AlertTriangle, Building2, SlidersHorizontal } from 'lucide-react';
import { Card } from '@/components/ui/card';
import FormDialog from '@/components/FormDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import PaginationControls from '@/components/PaginationControls';
import { useConfirm } from '@/components/ConfirmDialog';
import { Select, SelectTrigger, SelectValue, SelectContent, SelectItem } from '@/components/ui/select';

const EMPTY = { code: '', name: '', short_name: '', contact_name: '', contact_email: '', contact_phone: '', is_active: true, obe_method: 'weighted_attainment' };

// Tham số đo được phép khai riêng theo trường. Bỏ trống = dùng mặc định dùng chung.
const OBE_FIELDS = [
  ['obe.clo_pass_threshold', 'Ngưỡng đạt CLO', 'mặc định 5'],
  ['obe.plo_attainment_ratio_pass', 'Ngưỡng đạt PLO (tỉ lệ 0..1)', 'mặc định 0.5'],
  ['obe.target_attainment_percent', 'Mục tiêu mức đạt (%)', 'mặc định 70'],
  ['obe.course_pass_threshold', 'Ngưỡng đạt môn (tính tín chỉ)', 'mặc định 5.5'],
  ['obe.measurement_min_completion_percent', '% tích lũy tối thiểu để gộp thống kê', 'mặc định 80'],
  // Hai hệ số này được CHUẨN HOÁ theo tổng của chúng, nên khai thẳng % của trường là được:
  // trường chấm 10% chuyên cần + 40% giữa kỳ + 50% cuối kỳ thì khai 0.4 / 0.5 (chuyên cần không
  // đo được bằng câu hỏi nên không tham gia), hệ thống tự chia 4/9 : 5/9.
  ['obe.process_weight', 'Hệ số quá trình', 'mặc định 0.4 — khai thẳng % của trường'],
  ['obe.final_weight', 'Hệ số cuối kỳ', 'mặc định 0.6 — hai hệ số tự chuẩn hóa theo tổng'],
];

export default function Index({ schools, currentFilters }) {
  const [obeSchool, setObeSchool] = useState(null);
  const [obeForm, setObeForm] = useState({ settings: {}, scale_weights: {} });

  const openObe = (s) => {
    setObeForm({ settings: { ...(s.obe_settings ?? {}) }, scale_weights: { ...(s.scale_weights ?? {}) } });
    setObeSchool(s);
  };

  const submitObe = (e) => {
    e.preventDefault();
    router.put(route('manager.schools.obe-settings', obeSchool.uuid), obeForm, {
      onSuccess: () => setObeSchool(null),
    });
  };

  const { flash } = usePage().props;
  const confirm = useConfirm();
  const searchTimeout = React.useRef(null);
  const [formMode, setFormMode] = useState(null);
  const [formData, setFormData] = useState(EMPTY);

  const handleFilterChange = (key, value) => {
    const params = { ...currentFilters };
    if (!value) delete params[key]; else params[key] = value;
    delete params.page;
    router.get(route('manager.schools.index'), params, { preserveState: true, replace: true });
  };

  const handleSearchChange = (value) => {
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    searchTimeout.current = setTimeout(() => handleFilterChange('q', value), 300);
  };

  const handlePageChange = (page) => {
    router.get(route('manager.schools.index'), { ...currentFilters, page }, { preserveState: true, replace: true });
  };

  const openCreate = () => { setFormData(EMPTY); setFormMode('create'); };
  const openEdit = (s) => {
    setFormData({
      code: s.code, name: s.name, short_name: s.short_name ?? '',
      contact_name: s.contact_name ?? '', contact_email: s.contact_email ?? '', contact_phone: s.contact_phone ?? '',
      is_active: !!s.is_active,
      obe_method: s.obe_method ?? 'weighted_attainment',
    });
    setFormMode(s.uuid);
  };
  const closeForm = () => { setFormMode(null); setFormData(EMPTY); };

  const handleSubmit = (e) => {
    e.preventDefault();
    const opts = { onSuccess: () => closeForm(), preserveScroll: true };
    if (formMode === 'create') router.post(route('manager.schools.store'), formData, opts);
    else router.put(route('manager.schools.update', formMode), formData, opts);
  };

  const handleDelete = async (s) => {
    if (await confirm({ title: 'Xác nhận xóa trường', description: `Xóa "${s.name}"? Chỉ xóa được trường chưa có dữ liệu.`, confirmText: 'Xóa', variant: 'danger' })) {
      router.delete(route('manager.schools.destroy', s.uuid), { preserveScroll: true });
    }
  };

  return (
    <DashboardLayout>
      <Head title="Quản lý Trường" />

      <div className="space-y-6">
        {flash?.success && (
          <div className="p-3 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200 flex items-start gap-3 text-sm">
            <CheckCircle2 className="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.success}</span>
          </div>
        )}
        {flash?.error && (
          <div className="p-3 rounded-lg bg-rose-50 text-rose-800 border border-rose-200 flex items-start gap-3 text-sm">
            <AlertTriangle className="w-5 h-5 text-rose-600 shrink-0 mt-0.5" />
            <span className="font-medium">{flash.error}</span>
          </div>
        )}

        <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
          <div>
            <h2 className="text-2xl font-bold tracking-tight flex items-center gap-2"><Building2 className="w-6 h-6 text-primary" /> Quản lý Trường</h2>
            <p className="text-muted-foreground">Mỗi Trường là 1 không gian dữ liệu riêng (đa-trường). Mã ngành/môn/MSSV/lớp unique trong từng trường.</p>
          </div>
          <div className="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2">
            <div className="relative w-full sm:w-[220px]">
              <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <input
                type="text"
                placeholder="Tìm mã/tên trường..."
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
            <Button onClick={openCreate} className="h-9 gap-1"><Plus className="w-4 h-4" /> Thêm trường</Button>
          </div>
        </div>

        <FormDialog open={!!formMode} onClose={closeForm} size="lg" title={formMode === 'create' ? 'Thêm trường mới' : 'Chỉnh sửa trường'}>
          <form onSubmit={handleSubmit} className="flex flex-col gap-4">
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Mã trường <span className="text-rose-500">*</span></Label>
                <Input value={formData.code} onChange={(e) => setFormData({ ...formData, code: e.target.value })} placeholder="VD: DHV" required />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Tên ngắn</Label>
                <Input value={formData.short_name} onChange={(e) => setFormData({ ...formData, short_name: e.target.value })} placeholder="VD: ĐH Hùng Vương" />
              </div>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Tên trường <span className="text-rose-500">*</span></Label>
              <Input value={formData.name} onChange={(e) => setFormData({ ...formData, name: e.target.value })} placeholder="VD: Trường Đại học Hùng Vương TP.HCM" required />
            </div>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Người liên hệ</Label>
                <Input value={formData.contact_name} onChange={(e) => setFormData({ ...formData, contact_name: e.target.value })} placeholder="Họ tên" />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Email</Label>
                <Input type="email" value={formData.contact_email} onChange={(e) => setFormData({ ...formData, contact_email: e.target.value })} placeholder="email@truong.edu.vn" />
              </div>
              <div className="space-y-1.5">
                <Label className="text-xs font-medium text-muted-foreground">Điện thoại</Label>
                <Input value={formData.contact_phone} onChange={(e) => setFormData({ ...formData, contact_phone: e.target.value })} placeholder="SĐT" />
              </div>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-medium text-muted-foreground">Phương pháp đo (OBE)</Label>
              <Select value={formData.obe_method} onValueChange={(v) => setFormData({ ...formData, obe_method: v })}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="Chọn phương pháp đo" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="weighted_attainment">Tỉ lệ đạt — chuẩn (tín chỉ × mức I/R/M/A × trọng số PI)</SelectItem>
                  <SelectItem value="standard_average">Điểm trung bình (cũ, thang 10)</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <label className="flex items-center gap-2 text-sm cursor-pointer">
              <input type="checkbox" checked={formData.is_active} onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })} className="h-4 w-4 rounded border-input" />
              <span>Đang hoạt động (hiện trong ô “Trường đang làm việc”)</span>
            </label>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={closeForm} className="h-9">Hủy</Button>
              <Button type="submit" className="h-9">{formMode === 'create' ? 'Thêm mới' : 'Cập nhật'}</Button>
            </div>
          </form>
        </FormDialog>

        {/* Tham số đo riêng theo trường — bỏ trống một ô là quay về mặc định dùng chung. */}
        <FormDialog
          open={!!obeSchool}
          onClose={() => setObeSchool(null)}
          size="lg"
          title={obeSchool ? `Tham số đo — ${obeSchool.name}` : ''}
        >
          <form onSubmit={submitObe} className="space-y-5">
            <p className="text-xs text-muted-foreground">
              Bỏ trống một ô ⇒ trường này dùng <b>mặc định dùng chung</b>. Sau khi lưu cần bấm
              <b> "Tính lại"</b> để áp dụng cho kết quả đã đo.
            </p>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              {OBE_FIELDS.map(([key, label, hint]) => (
                <div key={key} className="space-y-1">
                  <Label className="text-xs font-medium text-muted-foreground">{label}</Label>
                  <Input
                    type="number"
                    step="any"
                    placeholder={hint}
                    value={obeForm.settings[key] ?? ''}
                    onChange={(e) =>
                      setObeForm({ ...obeForm, settings: { ...obeForm.settings, [key]: e.target.value } })
                    }
                  />
                </div>
              ))}
            </div>

            <div>
              <Label className="text-xs font-medium text-muted-foreground">
                Trọng số mức I / R / M / A (dùng cho công thức PI)
              </Label>
              <div className="grid grid-cols-4 gap-3 mt-1">
                {['I', 'R', 'M', 'A'].map((lv) => (
                  <div key={lv} className="space-y-1">
                    <div className="text-xs text-center font-semibold">{lv}</div>
                    <Input
                      type="number"
                      step="any"
                      min="0"
                      placeholder={lv === 'I' ? '1' : lv === 'R' ? '2' : '3'}
                      value={obeForm.scale_weights[lv] ?? ''}
                      onChange={(e) =>
                        setObeForm({ ...obeForm, scale_weights: { ...obeForm.scale_weights, [lv]: e.target.value } })
                      }
                    />
                  </div>
                ))}
              </div>
            </div>

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setObeSchool(null)}>Đóng</Button>
              <Button type="submit">Lưu tham số</Button>
            </div>
          </form>
        </FormDialog>

        <Card className="shadow-none">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/50">
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">#</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Mã</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Tên trường</th>
                  <th className="p-4 text-left font-semibold text-xs uppercase tracking-wider text-muted-foreground">Liên hệ</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Ngành</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">SV</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Lớp</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Trạng thái</th>
                  <th className="p-4 text-center font-semibold text-xs uppercase tracking-wider text-muted-foreground">Hành động</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {schools.data.map((s, index) => {
                  const hasData = (s.programs_count + s.students_count + s.classes_count) > 0;
                  return (
                    <tr key={s.uuid} className="hover:bg-muted/30 transition-colors">
                      <td className="p-4 text-muted-foreground">{(schools.current_page - 1) * schools.per_page + index + 1}</td>
                      <td className="p-4"><span className="font-bold text-blue-600 bg-blue-50 px-2 py-0.5 rounded text-xs">{s.code}</span></td>
                      <td className="p-4">
                        <div className="font-medium">{s.name}</div>
                        {s.short_name && <div className="text-xs text-muted-foreground">{s.short_name}</div>}
                        <div className="text-[11px] text-muted-foreground/80 mt-0.5">{s.obe_method === 'standard_average' ? 'PP: Điểm TB' : 'PP: Tỉ lệ đạt'}</div>
                      </td>
                      <td className="p-4 text-xs text-muted-foreground">
                        {s.contact_name && <div>{s.contact_name}</div>}
                        {s.contact_email && <div>{s.contact_email}</div>}
                        {s.contact_phone && <div>{s.contact_phone}</div>}
                        {!s.contact_name && !s.contact_email && !s.contact_phone && <span>—</span>}
                      </td>
                      <td className="p-4 text-center font-medium">{s.programs_count}</td>
                      <td className="p-4 text-center font-medium">{s.students_count}</td>
                      <td className="p-4 text-center font-medium">{s.classes_count}</td>
                      <td className="p-4 text-center">
                        {s.is_active
                          ? <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700"><CheckCircle2 className="w-3 h-3" /> Hoạt động</span>
                          : <span className="inline-block px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500">Ngừng</span>}
                      </td>
                      <td className="p-4">
                        <div className="flex items-center justify-center gap-1">
                          <button
                            onClick={() => openObe(s)}
                            className="p-1.5 text-blue-600 hover:bg-blue-50 rounded-lg transition"
                            title="Tham số đo riêng của trường"
                          >
                            <SlidersHorizontal className="w-4 h-4" />
                          </button>
                          <button onClick={() => openEdit(s)} className="p-1.5 text-amber-600 hover:bg-amber-50 rounded-lg transition" title="Sửa">
                            <Pencil className="w-4 h-4" />
                          </button>
                          <button
                            onClick={() => handleDelete(s)}
                            disabled={hasData}
                            className="p-1.5 text-rose-600 hover:bg-rose-50 rounded-lg transition disabled:opacity-30 disabled:cursor-not-allowed"
                            title={hasData ? 'Không xóa được — trường còn dữ liệu (tắt “Hoạt động” thay vì xóa)' : 'Xóa'}
                          >
                            <Trash2 className="w-4 h-4" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>

          {schools.data.length === 0 && (
            <div className="p-12 text-center">
              <Building2 className="w-12 h-12 text-muted-foreground/50 mx-auto mb-2" />
              <p className="text-sm font-medium text-muted-foreground">Chưa có trường nào</p>
              <p className="text-xs text-muted-foreground/70 mt-1">Bấm “Thêm trường” để tạo trường đầu tiên</p>
            </div>
          )}

          <PaginationControls
            currentPage={schools.current_page}
            totalPages={schools.last_page}
            onPageChange={handlePageChange}
            totalItems={schools.total}
          />
        </Card>
      </div>
    </DashboardLayout>
  );
}
