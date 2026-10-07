import React from 'react';
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Cell, ReferenceLine, Legend,
} from 'recharts';

/**
 * Biểu đồ cột mức đạt (% SV vượt ngưỡng) theo PLO hoặc PI, kèm đường mục tiêu.
 * Cột xanh = đạt mục tiêu, đỏ = chưa đạt. Thay cho việc chỉ có bảng số.
 *
 * @param {{code:string, attainment_percent:number|null, target_percent:number, met_target:boolean}[]} rows
 */
export default function AttainmentChart({ rows, target, title }) {
  const data = (rows ?? [])
    .filter((r) => r.attainment_percent !== null && r.attainment_percent !== undefined)
    .map((r) => ({ code: r.code, percent: r.attainment_percent, met: r.met_target }));

  if (data.length === 0) {
    return <p className="text-xs text-muted-foreground py-6 text-center">Chưa có dữ liệu mức đạt để vẽ biểu đồ.</p>;
  }

  return (
    <div className="w-full" style={{ height: 260 }}>
      {title && <p className="text-xs font-semibold text-muted-foreground mb-1">{title}</p>}
      <ResponsiveContainer width="100%" height="100%">
        <BarChart data={data} margin={{ top: 8, right: 12, left: -8, bottom: 4 }}>
          <CartesianGrid strokeDasharray="3 3" vertical={false} />
          <XAxis dataKey="code" tick={{ fontSize: 11 }} />
          <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} unit="%" />
          <Tooltip formatter={(v) => [`${v}%`, 'Mức đạt']} />
          {typeof target === 'number' && (
            <ReferenceLine y={target} stroke="#f59e0b" strokeDasharray="4 4" label={{ value: `Mục tiêu ${target}%`, position: 'right', fontSize: 10, fill: '#b45309' }} />
          )}
          <Legend wrapperStyle={{ fontSize: 11 }} />
          <Bar dataKey="percent" name="% đạt" radius={[3, 3, 0, 0]}>
            {data.map((d, i) => (
              <Cell key={i} fill={d.met ? '#10b981' : '#f43f5e'} />
            ))}
          </Bar>
        </BarChart>
      </ResponsiveContainer>
    </div>
  );
}
