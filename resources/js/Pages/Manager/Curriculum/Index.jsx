import React, { useState, useMemo } from 'react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import { Head, router, usePage } from '@inertiajs/react';
import {
  Layers, Plus, Pencil, Trash2, CheckCircle2, AlertTriangle, Wand2, Users, BookOpen,
} from 'lucide-react';
import SearchableSelect from '@/components/SearchableSelect';
import FormDialog from '@/components/FormDialog';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useConfirm } from '@/components/ConfirmDialog';
import { cn } from '@/lib/utils';

/**
 * Cấu trúc chương trình đào tạo theo KHỐI học phần.
 *
 * Đây là nơi khai "45 tín chỉ bắt buộc + chọn 1 hướng chuyên ngành 15 tín chỉ". Không khai thì hệ
 * thống không biết lộ trình riêng của từng học viên, và độ phủ "PI1.1 (1/3)" buộc phải đếm cả CTĐT
 * — sai với mọi học viên, vì không ai học hết mọi hướng.
 */

const FORM_RONG = { code: '', name: '', kind: 'elective', required_credits: '', sort_order: 0 };

export default function Index({ programs, program, groups = [], courses = [], ungrouped = [], students = [] }) {
  const { flash } = usePage().props;
  const confirm = useConfirm();

  const [form, setForm] = useState(null);          // null | {mode, uuid, ...FORM_RONG}
  const [monDaChon, setMonDaChon] = useState([]);  // course_id[]
  const [svDaChon, setSvDaChon] = useState([]);    // student_id[]

  const doiNganh = (uuid) => router.get(route('manager.curriculum.index'), { program: uuid }, { preserveState: false });

  const nhomTuChon = groups.filter((g) => g.kind === 'elective');
  const monTheoNhom = useMemo(() => {
    const map = {};
    courses.forEach((c) => { if (c.group_id) (map[c.group_id] ??= []).push(c); });
    return map;
  }, [courses]);

  /**
   * Tín chỉ của MỘT lộ trình = khối bắt buộc + đúng MỘT hướng chuyên ngành.
   * Cộng hết mọi khối là sai: ba hướng là ba lựa chọn thay thế nhau, không ai học cả ba. Cộng hết
   * ra 90 TC trong khi tốt nghiệp chỉ cần 60 — nhìn như lỗi dữ liệu dù dữ liệu hoàn toàn đúng.
   */
  const tinChiMotLoTrinh = useMemo(() => {
    const batBuoc = groups
      .filter((g) => g.kind === 'mandatory')
      .reduce((s, g) => s + Number(g.credits_total || 0), 0);
    const huong = groups
      .filter((g) => g.kind === 'elective')
      .map((g) => Number(g.required_credits ?? g.credits_total ?? 0));

    return batBuoc + (huong.length ? Math.max(...huong) : 0);
  }, [groups]);

  const chuaChonHuong = students.filter((s) => !s.group_id).length;

  const luuNhom = (e) => {
    e.preventDefault();
    const payload = { ...form, program: program.uuid };
    const done = { onSuccess: () => setForm(null) };
    if (form.mode === 'edit') router.put(route('manager.curriculum.update', form.uuid), payload, done);
    else router.post(route('manager.curriculum.store'), payload, done);
  };

  const xoaNhom = async (g) => {
    const ok = await confirm({
      title: `Xóa khối "${g.name}"?`,
      description: g.students_count > 0
        ? `${g.students_count} học viên đang theo khối này sẽ mất hướng chuyên ngành, và ${g.courses_count} học phần trở về mục chưa xếp nhóm. Học phần và điểm KHÔNG bị xóa.`
        : `${g.courses_count} học phần trong khối sẽ trở về mục chưa xếp nhóm. Học phần và điểm KHÔNG bị xóa.`,
      confirmText: 'Xóa khối',
      variant: 'danger',
    });
    if (ok) router.delete(route('manager.curriculum.destroy', g.uuid), { data: { program: program.uuid } });
  };

  const xepMon = (groupUuid) => {
    router.post(route('manager.curriculum.assign-courses'), {
      program: program.uuid, group: groupUuid, course_ids: monDaChon,
    }, { onSuccess: () => setMonDaChon([]) });
  };

  const ganHuong = (groupUuid) => {
    router.post(route('manager.curriculum.assign-students'), {
      program: program.uuid, group: groupUuid, student_ids: svDaChon,
    }, { onSuccess: () => setSvDaChon([]) });
  };

  const suyHuong = async () => {
    const ok = await confirm({
      title: 'Suy hướng chuyên ngành từ điểm đã có?',
      description: 'Hệ thống xét học phần từng học viên đã có điểm và gán vào hướng tương ứng. '
        + 'Học viên đã có hướng được giữ nguyên. Ai có điểm ở nhiều hướng sẽ bị bỏ qua và liệt kê ra để bạn xem lại.',
      confirmText: 'Suy hướng',
    });
    if (ok) router.post(route('manager.curriculum.infer-tracks'), { program: program.uuid });
  };

  return (
    <DashboardLayout>
      <Head title="Cấu trúc chương trình đào tạo" />

      <div className="space-y-6">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-bold flex items-center gap-2">
              <Layers className="w-6 h-6 text-primary" />
              Cấu trúc chương trình đào tạo
            </h1>
            <p className="text-sm text-muted-foreground mt-1 max-w-3xl">
              Khai các khối học phần: khối bắt buộc ai cũng học, và các hướng chuyên ngành để học viên chọn.
              Đây là căn cứ để hệ thống biết mỗi học viên phải học những học phần nào, từ đó hiện được
              &ldquo;đã đo 1 trên 3 học phần&rdquo; cho từng chỉ số PI.
            </p>
          </div>
          <div className="w-72">
            <Label className="text-xs">Ngành</Label>
            <SearchableSelect
              value={program?.uuid ?? ''}
              onValueChange={doiNganh}
              options={programs.map((p) => ({ value: p.uuid, label: `${p.code} — ${p.name}` }))}
              placeholder="Chọn ngành"
            />
          </div>
        </div>

        {flash?.success && (
          <div className="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
            <CheckCircle2 className="w-4 h-4 mt-0.5 shrink-0" />
            <span>{flash.success}</span>
          </div>
        )}

        {!program && (
          <Card><CardContent className="p-6 text-sm text-muted-foreground">
            Chưa có ngành nào trong trường đang làm việc.
          </CardContent></Card>
        )}

        {program && (
          <>
            <div className="grid gap-3 sm:grid-cols-4">
              <ThongSo nhan="Tín chỉ tốt nghiệp" giaTri={program.required_credits ?? '—'} />
              <ThongSo
                nhan="Tín chỉ một lộ trình"
                giaTri={tinChiMotLoTrinh}
                phu="bắt buộc + 1 hướng"
                canhBao={!!program.required_credits && tinChiMotLoTrinh !== Number(program.required_credits)}
              />
              <ThongSo nhan="Học phần chưa xếp khối" giaTri={ungrouped.length} canhBao={ungrouped.length > 0} />
              <ThongSo nhan="Học viên chưa chọn hướng" giaTri={chuaChonHuong}
                canhBao={chuaChonHuong > 0 && nhomTuChon.length > 0} />
            </div>

            {/* ---- Các khối học phần ---- */}
            <Card>
              <CardHeader className="flex flex-row items-center justify-between space-y-0">
                <CardTitle className="text-base">Các khối học phần</CardTitle>
                <Button size="sm" className="gap-2" onClick={() => setForm({ mode: 'create', ...FORM_RONG })}>
                  <Plus className="w-4 h-4" /> Thêm khối
                </Button>
              </CardHeader>
              <CardContent className="space-y-3">
                {groups.length === 0 && (
                  <p className="text-sm text-muted-foreground">
                    Chưa khai khối nào. Khi chưa khai, hệ thống không hiện độ phủ &ldquo;1/3 học phần&rdquo;
                    — thà không hiện còn hơn hiện một mẫu số sai.
                  </p>
                )}

                {groups.map((g) => (
                  <div key={g.uuid} className="rounded-lg border">
                    <div className="flex flex-wrap items-center gap-3 p-3">
                      <span className={cn(
                        'rounded px-2 py-0.5 text-[11px] font-medium',
                        g.kind === 'mandatory' ? 'bg-slate-100 text-slate-700' : 'bg-sky-50 text-sky-700',
                      )}>
                        {g.kind === 'mandatory' ? 'Bắt buộc' : 'Hướng chuyên ngành'}
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{g.name}</p>
                        <p className="text-xs text-muted-foreground">
                          {g.code} · {g.courses_count} học phần · {g.credits_total} tín chỉ
                          {g.required_credits !== null && <> · yêu cầu {g.required_credits} tín chỉ</>}
                          {g.kind === 'elective' && <> · {g.students_count} học viên</>}
                        </p>
                      </div>
                      <div className="flex gap-1">
                        <Button variant="ghost" size="icon" onClick={() => setForm({
                          mode: 'edit', uuid: g.uuid, code: g.code, name: g.name, kind: g.kind,
                          required_credits: g.required_credits ?? '', sort_order: g.sort_order,
                        })}>
                          <Pencil className="w-4 h-4" />
                        </Button>
                        <Button variant="ghost" size="icon" onClick={() => xoaNhom(g)}>
                          <Trash2 className="w-4 h-4 text-rose-600" />
                        </Button>
                      </div>
                    </div>

                    {/* Khai 15 tín chỉ mà chỉ xếp được 12 nghĩa là CTĐT còn thiếu học phần — nói
                        thẳng ở đây, đừng để tới lúc học viên thấy mẫu số hụt mới phát hiện. */}
                    {g.shortfall !== null && (
                      <div className="mx-3 mb-3 flex items-start gap-2 rounded border border-amber-200 bg-amber-50 p-2.5 text-xs text-amber-800">
                        <AlertTriangle className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                        <span>
                          Khối này yêu cầu {g.required_credits} tín chỉ nhưng học phần đã xếp chỉ có {g.credits_total} tín chỉ
                          (thiếu {g.shortfall}). Kiểm tra lại: chương trình còn học phần chưa khai, hoặc số tín chỉ yêu cầu ghi sai.
                        </span>
                      </div>
                    )}

                    {(monTheoNhom[g.id] ?? []).length > 0 && (
                      <div className="border-t px-3 py-2 text-xs text-muted-foreground">
                        {(monTheoNhom[g.id] ?? []).map((c) => `${c.code} (${c.credits})`).join(' · ')}
                      </div>
                    )}
                  </div>
                ))}
              </CardContent>
            </Card>

            {/* ---- Xếp học phần vào khối ---- */}
            <Card>
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <BookOpen className="w-4 h-4 text-primary" /> Xếp học phần vào khối
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <BangChon
                  items={courses}
                  daChon={monDaChon}
                  setDaChon={setMonDaChon}
                  moTa={(c) => (
                    <>
                      <span className="font-medium">{c.code}</span>
                      <span className="text-muted-foreground"> — {c.name} · {c.credits} TC</span>
                      {!c.group_id && <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">chưa xếp</span>}
                    </>
                  )}
                />
                <ThanhHanhDong
                  soLuong={monDaChon.length}
                  donVi="học phần"
                  groups={groups}
                  onChon={xepMon}
                  nhanBo="Bỏ khỏi khối"
                />
              </CardContent>
            </Card>

            {/* ---- Gán hướng cho học viên ---- */}
            {nhomTuChon.length > 0 && (
              <Card>
                <CardHeader className="flex flex-row items-center justify-between space-y-0">
                  <CardTitle className="text-base flex items-center gap-2">
                    <Users className="w-4 h-4 text-primary" /> Hướng chuyên ngành của học viên
                  </CardTitle>
                  <Button variant="outline" size="sm" className="gap-2" onClick={suyHuong}>
                    <Wand2 className="w-4 h-4" /> Suy từ điểm đã có
                  </Button>
                </CardHeader>
                <CardContent className="space-y-3">
                  <p className="text-xs text-muted-foreground">
                    Cao học thường chọn hướng ngay từ đầu khóa, đại học chọn từ năm 3. Học viên chưa chọn
                    hướng vẫn xem được kết quả, chỉ là mẫu số độ phủ mới tính phần bắt buộc và màn hình
                    có ghi rõ điều đó.
                  </p>
                  <BangChon
                    items={students}
                    daChon={svDaChon}
                    setDaChon={setSvDaChon}
                    moTa={(s) => {
                      const g = groups.find((x) => x.id === s.group_id);
                      return (
                        <>
                          <span className="font-medium">{s.student_code}</span>
                          <span className="text-muted-foreground"> — {s.full_name}</span>
                          {g
                            ? <span className="ml-2 rounded bg-sky-50 px-1.5 py-0.5 text-[10px] text-sky-700">{g.name}</span>
                            : <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] text-amber-700">chưa chọn hướng</span>}
                        </>
                      );
                    }}
                  />
                  <ThanhHanhDong
                    soLuong={svDaChon.length}
                    donVi="học viên"
                    groups={nhomTuChon}
                    onChon={ganHuong}
                    nhanBo="Bỏ hướng"
                  />
                </CardContent>
              </Card>
            )}
          </>
        )}
      </div>

      <FormDialog
        open={!!form}
        onClose={() => setForm(null)}
        title={form?.mode === 'edit' ? 'Sửa khối học phần' : 'Thêm khối học phần'}
        size="md"
      >
        {form && (
          <form onSubmit={luuNhom} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="code">Mã khối *</Label>
                <Input id="code" value={form.code} maxLength={50}
                  onChange={(e) => setForm({ ...form, code: e.target.value })} />
              </div>
              <div>
                <Label htmlFor="kind">Loại khối *</Label>
                <SearchableSelect
                  value={form.kind}
                  onValueChange={(v) => setForm({ ...form, kind: v })}
                  options={[
                    { value: 'mandatory', label: 'Bắt buộc — mọi học viên đều học' },
                    { value: 'elective', label: 'Hướng chuyên ngành — học viên chọn' },
                  ]}
                />
              </div>
            </div>
            <div>
              <Label htmlFor="name">Tên khối *</Label>
              <Input id="name" value={form.name} maxLength={255}
                onChange={(e) => setForm({ ...form, name: e.target.value })} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <div>
                <Label htmlFor="rc">Tín chỉ yêu cầu</Label>
                <Input id="rc" type="number" step="0.5" min="0" value={form.required_credits}
                  onChange={(e) => setForm({ ...form, required_credits: e.target.value })} />
                <p className="mt-1 text-[11px] text-muted-foreground">
                  Số tín chỉ phải tích lũy trong khối, không phải số môn. Khối mở 5 môn mà chỉ yêu cầu
                  4 thì ghi số tín chỉ của 4 môn.
                </p>
              </div>
              <div>
                <Label htmlFor="so">Thứ tự hiển thị</Label>
                <Input id="so" type="number" min="0" value={form.sort_order}
                  onChange={(e) => setForm({ ...form, sort_order: e.target.value })} />
              </div>
            </div>
            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="outline" onClick={() => setForm(null)}>Hủy</Button>
              <Button type="submit">Lưu</Button>
            </div>
          </form>
        )}
      </FormDialog>
    </DashboardLayout>
  );
}

function ThongSo({ nhan, giaTri, phu, canhBao = false }) {
  return (
    <Card>
      <CardContent className="p-4">
        <p className="text-xs text-muted-foreground">{nhan}</p>
        <p className={cn('mt-1 text-2xl font-bold tabular-nums', canhBao && 'text-amber-600')}>{giaTri}</p>
        {phu && <p className="text-[11px] text-muted-foreground">{phu}</p>}
      </CardContent>
    </Card>
  );
}

/** Danh sách có ô tick + tìm nhanh, dùng chung cho học phần và học viên. */
function BangChon({ items, daChon, setDaChon, moTa }) {
  const [tim, setTim] = useState('');

  const loc = useMemo(() => {
    const q = tim.trim().toLowerCase();
    if (!q) return items;
    return items.filter((i) => JSON.stringify(i).toLowerCase().includes(q));
  }, [items, tim]);

  const tatCaDaChon = loc.length > 0 && loc.every((i) => daChon.includes(i.id));

  return (
    <div className="rounded-lg border">
      <div className="flex items-center gap-3 border-b p-2">
        <Checkbox
          checked={tatCaDaChon}
          onCheckedChange={(v) => setDaChon(v
            ? [...new Set([...daChon, ...loc.map((i) => i.id)])]
            : daChon.filter((id) => !loc.some((i) => i.id === id)))}
        />
        <Input value={tim} onChange={(e) => setTim(e.target.value)} placeholder="Tìm nhanh…" className="h-8" />
        <span className="shrink-0 text-xs text-muted-foreground">{loc.length} mục</span>
      </div>
      <div className="max-h-72 overflow-y-auto divide-y">
        {loc.map((i) => (
          <label key={i.id} className="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-muted/50">
            <Checkbox
              checked={daChon.includes(i.id)}
              onCheckedChange={(v) => setDaChon(v ? [...daChon, i.id] : daChon.filter((x) => x !== i.id))}
            />
            <span className="min-w-0 flex-1 truncate">{moTa(i)}</span>
          </label>
        ))}
        {loc.length === 0 && <p className="p-3 text-sm text-muted-foreground">Không có mục nào.</p>}
      </div>
    </div>
  );
}

function ThanhHanhDong({ soLuong, donVi, groups, onChon, nhanBo }) {
  const [nhom, setNhom] = useState('');

  if (soLuong === 0) {
    return <p className="text-xs text-muted-foreground">Tick chọn {donVi} rồi chọn khối để xếp.</p>;
  }

  return (
    <div className="flex flex-wrap items-end gap-2 rounded-lg border bg-muted/30 p-3">
      <span className="text-sm font-medium">Đã chọn {soLuong} {donVi}</span>
      <div className="w-64">
        <SearchableSelect
          value={nhom}
          onValueChange={setNhom}
          options={groups.map((g) => ({ value: g.uuid, label: g.name }))}
          placeholder="Chọn khối"
        />
      </div>
      <Button size="sm" disabled={!nhom} onClick={() => onChon(nhom)}>Xếp vào khối</Button>
      <Button size="sm" variant="outline" onClick={() => onChon(null)}>{nhanBo}</Button>
    </div>
  );
}
