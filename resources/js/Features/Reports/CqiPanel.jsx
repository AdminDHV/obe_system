import React, { useEffect, useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { Wrench, Loader2, Save, Sparkles, ArrowRight, Ban } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

const STATUS = {
  open: { label: 'Mở', cls: 'bg-rose-50 text-rose-700' },
  in_progress: { label: 'Đang làm', cls: 'bg-amber-50 text-amber-700' },
  done: { label: 'Hoàn thành', cls: 'bg-emerald-50 text-emerald-700' },
  cancelled: { label: 'Đã huỷ', cls: 'bg-gray-100 text-gray-500' },
};

/**
 * Vòng cải tiến (CQI/PDCA) — Feature A3. Tự tạo việc cho PLO chưa đạt, ghi
 * phát hiện·nguyên nhân·hành động·phụ trách·trạng thái, đối chiếu trước→nay.
 */
export default function CqiPanel({ programId, cohortId }) {
  const [actions, setActions] = useState([]);
  const [summary, setSummary] = useState(null);
  const [loading, setLoading] = useState(false);
  const [seeding, setSeeding] = useState(false);

  const load = useCallback(() => {
    if (!programId) { setActions([]); setSummary(null); return; }
    setLoading(true);
    const qs = `program_id=${programId}${cohortId ? `&cohort_id=${cohortId}` : ''}`;
    fetch(`${route('manager.reports.cqi.index')}?${qs}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((d) => {
        setActions((d.actions || []).map((a) => ({ ...a, dirty: false })));
        setSummary(d.summary || null);
      })
      .catch(() => { setActions([]); setSummary(null); })
      .finally(() => setLoading(false));
  }, [programId, cohortId]);

  useEffect(() => { load(); }, [load]);

  const seed = () => {
    setSeeding(true);
    router.post(route('manager.reports.cqi.seed'), { program_id: programId, cohort_id: cohortId || null }, {
      preserveScroll: true, preserveState: true,
      onSuccess: () => load(),
      onFinish: () => setSeeding(false),
    });
  };

  const edit = (uuid, field, v) =>
    setActions((prev) => prev.map((a) => (a.uuid === uuid ? { ...a, [field]: v, dirty: true } : a)));

  const patch = (a, status) => {
    router.patch(route('manager.reports.cqi.update', a.uuid), {
      finding: a.finding ?? '', root_cause: a.root_cause ?? '', action_plan: a.action_plan ?? '',
      owner: a.owner ?? '', status,
    }, { preserveScroll: true, preserveState: true, onSuccess: () => load() });
  };

  const save = (a) => patch(a, a.status);

  // Huỷ nhanh 1 việc lỡ tạo nhầm (giữ dòng, đánh dấu "Đã huỷ"). Có xác nhận.
  const cancel = (a) => {
    if (window.confirm(`Huỷ việc cải tiến cho ${a.plo_code}? (giữ lại trong lịch sử, có thể mở lại bằng cách đổi trạng thái)`)) {
      patch(a, 'cancelled');
    }
  };

  if (!programId) {
    return <p className="text-center py-4 text-xs text-muted-foreground">Chọn Ngành (+Khóa) để xem/cải tiến.</p>;
  }

  const s = summary;
  const noPlo = !s || s.total === 0;
  const noneMeasured = s && s.total > 0 && s.measured === 0;
  const allMet = s && s.measured > 0 && s.unmet === 0 && s.unmeasured === 0;
  const canSeed = !!s && s.unmet > 0;

  return (
    <div className="space-y-4">
      {/* Trạng thái PLO — nói rõ vì sao tạo/không tạo được việc */}
      <div className="flex flex-wrap items-center gap-3">
        {noPlo ? (
          <p className="text-sm text-muted-foreground">Ngành này chưa có PLO — khai ở <b>Bước 1</b>.</p>
        ) : noneMeasured ? (
          <p className="text-sm text-amber-700">
            <b>Chưa đo lường ngành/khóa này</b> ({s.total} PLO chưa có kết quả). Vào <b>Bước 3</b> bấm “Tính lại toàn trường” trước, rồi mới có PLO chưa đạt để tạo việc.
          </p>
        ) : (
          <p className="text-sm text-muted-foreground inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            {s.unmet > 0 && <span><b className="text-rose-600">{s.unmet}</b> chưa đạt</span>}
            {s.met > 0 && <span>· <b className="text-emerald-600">{s.met}</b> đã đạt</span>}
            {s.unmeasured > 0 && <span>· <b>{s.unmeasured}</b> chưa đo</span>}
            {allMet && <span className="text-emerald-600">Tất cả PLO đã đạt mục tiêu — chưa cần việc cải tiến.</span>}
          </p>
        )}
        <div className="ml-auto flex flex-col items-end gap-0.5">
          <Button type="button" size="sm" onClick={seed} disabled={seeding || !canSeed} className="h-8">
            {seeding ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Sparkles className="w-3.5 h-3.5" />}
            <span className="ml-1">Tự tạo việc cho PLO chưa đạt</span>
          </Button>
          {!canSeed && !noneMeasured && !noPlo && (
            <span className="text-[11px] text-muted-foreground">Không có PLO chưa đạt để tạo việc.</span>
          )}
        </div>
      </div>

      {loading ? (
        <p className="text-xs text-muted-foreground flex items-center gap-2 py-2"><Loader2 className="w-3.5 h-3.5 animate-spin" /> Đang tải…</p>
      ) : actions.length === 0 ? (
        <p className="text-xs text-muted-foreground py-2">
          {noneMeasured
            ? 'Chưa đo lường nên chưa có việc cải tiến. Đo ở Bước 3 trước.'
            : canSeed
              ? 'Chưa có việc cải tiến. Bấm “Tự tạo việc cho PLO chưa đạt” ở trên.'
              : 'Không có việc cải tiến nào (chưa có PLO chưa đạt).'}
        </p>
      ) : (
        actions.map((a) => {
          const st = STATUS[a.status] ?? STATUS.open;
          const isCancelled = a.status === 'cancelled';
          return (
            <Card key={a.uuid} className={`shadow-none ${isCancelled ? 'opacity-70' : ''}`}>
              <CardContent className="p-4 space-y-3">
                <div className="flex flex-wrap items-center gap-2">
                  <span className="font-bold text-blue-600">{a.plo_code}</span>
                  <span className={`inline-block px-2 py-0.5 rounded text-[11px] font-semibold ${st.cls}`}>{st.label}</span>
                  <span className="text-xs text-muted-foreground inline-flex items-center gap-1">
                    Trước <b>{a.before_percent != null ? `${a.before_percent}%` : '—'}</b>
                    <ArrowRight className="w-3 h-3" />
                    Nay <b>{a.now_percent != null ? `${a.now_percent}%` : '—'}</b>
                    {a.delta != null && (
                      <span className={a.delta > 0 ? 'text-emerald-600' : a.delta < 0 ? 'text-rose-600' : ''}>
                        ({a.delta > 0 ? `+${a.delta}` : a.delta})
                      </span>
                    )}
                    {a.target_percent != null && <span className="text-muted-foreground">· mục tiêu {a.target_percent}%</span>}
                  </span>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-3">
                  <div className="space-y-1">
                    <Label className="text-xs text-muted-foreground">Phát hiện</Label>
                    <Textarea rows={2} value={a.finding ?? ''} onChange={(e) => edit(a.uuid, 'finding', e.target.value)} className="text-sm" />
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs text-muted-foreground">Nguyên nhân gốc</Label>
                    <Textarea rows={2} value={a.root_cause ?? ''} onChange={(e) => edit(a.uuid, 'root_cause', e.target.value)} className="text-sm" />
                  </div>
                  <div className="space-y-1">
                    <Label className="text-xs text-muted-foreground">Kế hoạch hành động</Label>
                    <Textarea rows={2} value={a.action_plan ?? ''} onChange={(e) => edit(a.uuid, 'action_plan', e.target.value)} className="text-sm" />
                  </div>
                </div>
                <div className="flex flex-wrap items-end gap-3">
                  <div className="space-y-1 w-full sm:w-56">
                    <Label className="text-xs text-muted-foreground">Phụ trách</Label>
                    <Input value={a.owner ?? ''} onChange={(e) => edit(a.uuid, 'owner', e.target.value)} className="h-8" />
                  </div>
                  <div className="space-y-1 w-full sm:w-44">
                    <Label className="text-xs text-muted-foreground">Trạng thái</Label>
                    <select
                      value={a.status}
                      onChange={(e) => edit(a.uuid, 'status', e.target.value)}
                      className="h-8 w-full rounded-md border border-input bg-background px-2 text-sm"
                    >
                      <option value="open">Mở</option>
                      <option value="in_progress">Đang làm</option>
                      <option value="done">Hoàn thành</option>
                      <option value="cancelled">Đã huỷ</option>
                    </select>
                  </div>
                  <Button type="button" size="sm" onClick={() => save(a)} disabled={!a.dirty} className="h-8">
                    <Save className="w-3.5 h-3.5" /><span className="ml-1">Lưu</span>
                  </Button>
                  {!isCancelled && (
                    <Button type="button" size="sm" variant="outline" onClick={() => cancel(a)} className="h-8 text-muted-foreground">
                      <Ban className="w-3.5 h-3.5" /><span className="ml-1">Huỷ việc</span>
                    </Button>
                  )}
                </div>
              </CardContent>
            </Card>
          );
        })
      )}
    </div>
  );
}
