import React from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Info } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Chú thích khái niệm ngay tại field: 1 icon ℹ️, bấm vào hiện popover giải thích.
 *
 *   <InfoHint title="Mức I/R/M/A">Giải thích...</InfoHint>
 */
export default function InfoHint({ title, children, className, side = 'top' }) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          aria-label="Giải thích"
          className={cn(
            'inline-flex items-center text-muted-foreground transition-colors hover:text-primary',
            className
          )}
        >
          <Info className="h-3.5 w-3.5" />
        </button>
      </PopoverTrigger>
      <PopoverContent side={side} align="start" className="w-72 text-sm">
        {title && <p className="mb-1 font-semibold">{title}</p>}
        <div className="space-y-1 text-muted-foreground">{children}</div>
      </PopoverContent>
    </Popover>
  );
}
