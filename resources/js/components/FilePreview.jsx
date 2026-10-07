import React, { useEffect, useMemo, useState } from 'react';
import { Loader2, AlertTriangle, CheckCircle2, XCircle, FileSpreadsheet } from 'lucide-react';
import { normalizeHeader } from '@/config/importSchemas';
import { cn } from '@/lib/utils';

/** Độ giống giữa 2 tiêu đề đã chuẩn hoá (0..1) — để gợi ý cột bị đặt sai tên. */
function similarity(a, b) {
  if (!a || !b) return 0;
  if (a === b) return 1;
  if (a.includes(b) || b.includes(a)) return 0.85;
  const bigrams = (s) => { const r = []; for (let i = 0; i < s.length - 1; i++) r.push(s.slice(i, i + 2)); return r; };
  const A = bigrams(a), B = bigrams(b);
  if (!A.length || !B.length) return 0;
  const pool = [...B]; let inter = 0;
  for (const g of A) { const i = pool.indexOf(g); if (i >= 0) { inter++; pool.splice(i, 1); } }
  return (2 * inter) / (A.length + B.length);
}

/**
 * Xem trước dữ liệu file (.xlsx/.xls/.csv) dạng bảng TRƯỚC khi tải lên + đối chiếu cột.
 *  - mode="grade": schema = { required:[], optional:[] }
 *  - mode="structure": schema = STRUCTURE_SHEETS (mảng {sheet,label,required,optional}); có bộ chọn sheet
 * Chỉ XEM (client-side), không chặn tải lên (cảnh báo mềm).
 */
export default function FilePreview({ file, mode = 'grade', schema, maxRows = 20 }) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState(null);
  const [wb, setWb] = useState(null);
  const [sheetNames, setSheetNames] = useState([]);
  const [activeSheet, setActiveSheet] = useState(null);
  const [headers, setHeaders] = useState([]);
  const [rows, setRows] = useState([]);
  const [totalRows, setTotalRows] = useState(0);

  const parseInto = (XLSX, workbook, sheetName) => {
    if (!sheetName || !workbook?.Sheets?.[sheetName]) {
      setHeaders([]); setRows([]); setTotalRows(0); return;
    }
    const ws = workbook.Sheets[sheetName];
    const matrix = XLSX.utils.sheet_to_json(ws, { header: 1, blankrows: false, defval: '' });
    const hdr = (matrix[0] ?? []).map((c) => String(c ?? '').trim());
    const data = matrix.slice(1);
    setHeaders(hdr);
    setTotalRows(data.length);
    setRows(data.slice(0, maxRows));
  };

  useEffect(() => {
    let cancelled = false;
    if (!file) {
      setWb(null); setSheetNames([]); setActiveSheet(null);
      setHeaders([]); setRows([]); setTotalRows(0); setError(null);
      return;
    }
    (async () => {
      setLoading(true); setError(null);
      try {
        const XLSX = await import('xlsx');
        const buf = await file.arrayBuffer();
        const workbook = XLSX.read(new Uint8Array(buf), { type: 'array' });
        if (cancelled) return;
        const names = workbook.SheetNames || [];
        let initial = names[0] ?? null;
        if (mode === 'structure' && Array.isArray(schema)) {
          const match = names.find((n) => schema.some((s) => normalizeHeader(s.sheet) === normalizeHeader(n)));
          initial = match ?? names[0] ?? null;
        }
        setWb(workbook);
        setSheetNames(names);
        setActiveSheet(initial);
        parseInto(XLSX, workbook, initial);
      } catch (e) {
        if (!cancelled) setError('Không đọc được file để xem trước (vẫn có thể tải lên).');
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [file]);

  const handleSheet = async (name) => {
    setActiveSheet(name);
    const XLSX = await import('xlsx');
    parseInto(XLSX, wb, name);
  };

  const activeSchema = useMemo(() => {
    if (mode === 'grade') return schema || null;
    if (mode === 'structure' && Array.isArray(schema) && activeSheet) {
      return schema.find((s) => normalizeHeader(s.sheet) === normalizeHeader(activeSheet)) ?? null;
    }
    return null;
  }, [mode, schema, activeSheet]);

  const cols = useMemo(() => {
    if (!activeSchema) return null;
    const detected = headers.map(normalizeHeader).filter(Boolean);
    const req = activeSchema.required || [];
    const opt = activeSchema.optional || [];
    const known = [...req, ...opt].map(normalizeHeader);

    const reqStatus = req.map((c) => ({ label: c, ok: detected.includes(normalizeHeader(c)) }));
    const optStatus = opt.map((c) => ({ label: c, present: detected.includes(normalizeHeader(c)) }));
    const extraHeaders = headers.filter((h) => h && !known.includes(normalizeHeader(h)));
    const missing = reqStatus.filter((c) => !c.ok).map((c) => c.label);

    // Gợi ý: mỗi cột thiếu → tìm header "thừa" giống nhất (đặt sai tên).
    const usedExtra = new Set();
    const missingDetailed = missing.map((label) => {
      let best = null, score = 0;
      for (const h of extraHeaders) {
        if (usedExtra.has(h)) continue;
        const s = similarity(normalizeHeader(label), normalizeHeader(h));
        if (s > score) { score = s; best = h; }
      }
      const suggestion = score >= 0.5 ? best : null;
      if (suggestion) usedExtra.add(suggestion);
      return { label, suggestion };
    });
    const extraSuggestMap = {};
    missingDetailed.forEach((m) => { if (m.suggestion) extraSuggestMap[m.suggestion] = m.label; });

    return { reqStatus, optStatus, extraHeaders, missing, missingDetailed, extraSuggestMap };
  }, [activeSchema, headers]);

  if (!file) return null;

  return (
    <div className="rounded-lg border bg-muted/20 p-3 text-xs">
      <div className="flex flex-wrap items-center gap-2">
        <FileSpreadsheet className="h-4 w-4 text-primary shrink-0" />
        <span className="font-medium">Xem trước: <span className="font-normal">{file.name}</span></span>
        {loading && <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />}
        {!loading && !error && headers.length > 0 && (
          <span className="ml-auto text-muted-foreground">Hiển thị {rows.length}/{totalRows} dòng</span>
        )}
      </div>

      {error && (
        <div className="mt-2 flex items-start gap-1.5 text-amber-700">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" /> {error}
        </div>
      )}

      {/* Bộ chọn sheet (khung nhiều sheet) */}
      {!loading && !error && mode === 'structure' && sheetNames.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {sheetNames.map((n) => (
            <button
              key={n}
              type="button"
              onClick={() => handleSheet(n)}
              className={cn(
                'rounded border px-2 py-0.5 text-[11px] font-medium transition',
                activeSheet === n ? 'border-primary bg-primary/10 text-primary' : 'bg-card hover:bg-muted'
              )}
            >
              {n}
            </button>
          ))}
        </div>
      )}

      {/* Đối chiếu cột */}
      {!loading && !error && cols && headers.length > 0 && (
        <div className="mt-2 space-y-1.5">
          <div className="flex flex-wrap gap-1.5">
            {cols.reqStatus.map((c) => (
              <span key={c.label} className={cn(
                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium',
                c.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'
              )}>
                {c.ok ? <CheckCircle2 className="h-3 w-3" /> : <XCircle className="h-3 w-3" />} {c.label}
              </span>
            ))}
            {cols.optStatus.filter((c) => c.present).map((c) => (
              <span key={c.label} className="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-medium text-sky-700">
                <CheckCircle2 className="h-3 w-3" /> {c.label} <span className="opacity-60">(tùy chọn)</span>
              </span>
            ))}
            {cols.extraHeaders.map((c) => (
              cols.extraSuggestMap[c] ? (
                <span key={c} className="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">
                  {c} <span className="ml-1 opacity-80">(có thể là “{cols.extraSuggestMap[c]}”?)</span>
                </span>
              ) : (
                <span key={c} className="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-[11px] text-gray-500">
                  {c} <span className="ml-1 opacity-70">(thừa, bỏ qua)</span>
                </span>
              )
            ))}
          </div>
          {cols.missing.length > 0 && (
            <div className="flex items-start gap-1.5 rounded bg-amber-50 px-2 py-1.5 text-amber-800">
              <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
              <div className="space-y-0.5">
                {cols.missingDetailed.map((m) => (
                  <div key={m.label}>
                    Thiếu cột <b>{m.label}</b>
                    {m.suggestion
                      ? <> — có phải cột <b>“{m.suggestion}”</b>? Đổi tên thành “{m.label}” rồi tải lại.</>
                      : '.'}
                  </div>
                ))}
                <div className="opacity-80">Vẫn có thể tải lên, nhưng nên kiểm tra lại file.</div>
              </div>
            </div>
          )}
        </div>
      )}

      {/* Bảng dữ liệu */}
      {!loading && !error && headers.length > 0 && (
        <div className="mt-2 max-h-72 overflow-auto rounded border bg-card">
          <table className="w-full border-collapse text-[11px]">
            <thead className="sticky top-0 bg-muted/80">
              <tr>
                <th className="border-b px-2 py-1 text-left font-semibold text-muted-foreground">#</th>
                {headers.map((h, i) => (
                  <th key={i} className="border-b px-2 py-1 text-left font-semibold">{h || <span className="text-muted-foreground/50">(trống)</span>}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, ri) => (
                <tr key={ri} className="hover:bg-muted/30">
                  <td className="border-b px-2 py-1 text-muted-foreground">{ri + 1}</td>
                  {headers.map((_, ci) => (
                    <td key={ci} className="max-w-[160px] truncate border-b px-2 py-1" title={String(r[ci] ?? '')}>
                      {String(r[ci] ?? '')}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {!loading && !error && headers.length === 0 && (
        <div className="mt-2 text-muted-foreground">File trống hoặc không có dữ liệu để xem.</div>
      )}
    </div>
  );
}
