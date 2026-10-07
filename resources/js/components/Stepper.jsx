import React from 'react';
import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Thanh bước (stepper) dẫn dắt luồng vận hành. Nhẹ, thuần React + Tailwind.
 *
 *   <Stepper steps={[{title, desc}, ...]} current={step} onChange={setStep} />
 *
 * - current: số bước đang chọn (1-based)
 * - onChange(n): bấm vào 1 bước để chuyển
 */
export default function Stepper({ steps, current, onChange }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      {steps.map((s, i) => {
        const n = i + 1;
        const isActive = n === current;
        const isDone = n < current;
        return (
          <button
            key={n}
            type="button"
            onClick={() => onChange(n)}
            className={cn(
              'flex items-center gap-3 rounded-lg border px-4 py-3 text-left transition',
              isActive
                ? 'border-primary bg-primary/5 shadow-sm'
                : 'border-border bg-card hover:bg-muted/50'
            )}
          >
            <span
              className={cn(
                'flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-sm font-bold',
                isActive
                  ? 'bg-primary text-primary-foreground'
                  : isDone
                    ? 'bg-emerald-100 text-emerald-700'
                    : 'bg-muted text-muted-foreground'
              )}
            >
              {isDone ? <Check className="h-4 w-4" /> : n}
            </span>
            <span className="min-w-0">
              <span
                className={cn(
                  'block text-sm font-semibold leading-tight',
                  isActive ? 'text-primary' : 'text-foreground'
                )}
              >
                {s.title}
              </span>
              {s.desc && (
                <span className="hidden truncate text-xs text-muted-foreground sm:block">{s.desc}</span>
              )}
              {s.badge && (
                <span
                  className={cn(
                    'mt-1 inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold',
                    s.badgeClass || 'bg-muted text-muted-foreground'
                  )}
                >
                  {s.badge}
                </span>
              )}
            </span>
          </button>
        );
      })}
    </div>
  );
}
