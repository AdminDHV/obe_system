import React, { useEffect, useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { Target, ChevronDown, Loader2, Save } from 'lucide-react';
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from '@/components/ui/collapsible';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

/**
 * Khai mục tiêu mức đạt (%) theo từng PLO cho ngành đang xem (Feature A1).
 * Để trống = PLO đó dùng mục tiêu mặc định (global). Lưu xong reload để
 * bảng "mức đạt" tính lại Gap theo mục tiêu mới.
 */
export default function PloTargetsEditor({ programId }) {
  const [plos, setPlos] = useState([]);
  const [globalTarget, setGlobalTarget] = useState(70);
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [open, setOpen] = useState(false);

  const load = useCallback(() => {
    if (!programId) { setPlos([]); return; }
    setLoading(true);
    fetch(`${route('manager.reports.targets.index')}?program_id=${programId}`, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    })
      .then((r) => (r.ok ? r.json() : Promise.reject(r)))
      .then((d) => {
        setPlos((d.plos || []).map((p) => ({ ...p, value: p.target_percent ?? '' })));
        setGlobalTarget(d.global_target ?? 70);
      })
      .catch(() => setPlos([]))
      .finally(() => setLoading(false));
  }, [programId]);

  useEffect(() => { if (open) load(); }, [open, load]);

  const setValue = (ploId, v) =>
    setPlos((prev) => prev.map((p) => (p.plo_id === ploId ? { ...p, value: v } : p)));

  const save = () => {
    setSaving(true);
    const targets = plos.map((p) => ({
      plo_id: p.plo_id,
      target_percent: p.value === '' ? null : Number(p.value),
    }));
    router.post(route('manager.reports.targets.store'), { program_id: programId, targets }, {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => load(),
      onFinish: () => setSaving(false),
    });
  };

  if (!programId) return null;

  return (
    <Collapsible open={open} onOpenChange={setOpen} className="rounded-lg border">
      <CollapsibleTrigger className="group flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm font-medium">
        <Target className="w-4 h-4 text-primary shrink-0" />
        <span>Mục tiêu mức đạt theo PLO</span>
        <span className="text-xs text-muted-foreground font-normal">(để trống = dùng mặc định {globalTarget}%)</span>
        <ChevronDown className="w-4 h-4 ml-auto text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
      </CollapsibleTrigger>
      <CollapsibleContent className="px-3 pb-3 space-y-3">
        {loading ? (
          <p className="text-xs text-muted-foreground flex items-center gap-2 py-2"><Loader2 className="w-3.5 h-3.5 animate-spin" /> Đang tải…</p>
        ) : plos.length === 0 ? (
          <p className="text-xs text-muted-foreground py-2">Ngành này chưa có PLO.</p>
        ) : (
          <>
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
              {plos.map((p) => (
                <div key={p.plo_id} className="flex items-center gap-2">
                  <span className="font-semibold text-blue-600 text-sm w-16 shrink-0" title={p.description}>{p.code}</span>
                  <Input
                    type="number" min="0" max="100" step="0.1"
                    value={p.value}
                    onChange={(e) => setValue(p.plo_id, e.target.value)}
                    placeholder={`${globalTarget}`}
                    className="h-8 w-24"
                  />
                  <span className="text-xs text-muted-foreground">%</span>
                </div>
              ))}
            </div>
            <Button type="button" size="sm" onClick={save} disabled={saving} className="h-8">
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
              <span className="ml-1">Lưu mục tiêu</span>
            </Button>
          </>
        )}
      </CollapsibleContent>
    </Collapsible>
  );
}
