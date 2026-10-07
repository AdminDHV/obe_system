import React, { useMemo, useRef, useState } from 'react';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Combobox thay cho <Select>: cùng API value/onValueChange + mảng `options`.
 * Ô tìm kiếm tự ẩn khi số mục < minForSearch (tránh thừa với select ngắn).
 *
 * options: Array<{ value: string, label: string }>
 */
export default function SearchableSelect({
  value,
  onValueChange,
  options = [],
  placeholder = 'Chọn...',
  searchPlaceholder = 'Tìm...',
  disabled = false,
  className,
  contentClassName,
  minForSearch = 7,
  allowClear = false,
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const inputRef = useRef(null);

  const selected = options.find((o) => String(o.value) === String(value));
  const showSearch = options.length >= minForSearch;

  const filtered = useMemo(() => {
    if (!query) return options;
    const q = query.toLowerCase();
    return options.filter((o) => String(o.label).toLowerCase().includes(q));
  }, [options, query]);

  const choose = (v) => {
    onValueChange?.(v);
    setOpen(false);
    setQuery('');
  };

  return (
    <Popover
      open={open}
      onOpenChange={(o) => {
        setOpen(o);
        if (!o) setQuery('');
        if (o && showSearch) setTimeout(() => inputRef.current?.focus(), 30);
      }}
    >
      <PopoverTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className={cn(
            'flex h-9 w-full items-center justify-between gap-2 whitespace-nowrap rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm ring-offset-background focus:outline-none focus:ring-1 focus:ring-ring disabled:cursor-not-allowed disabled:opacity-50',
            className,
          )}
        >
          <span className={cn('truncate text-left', !selected && 'text-muted-foreground')}>
            {selected ? selected.label : placeholder}
          </span>
          <span className="flex items-center gap-1 shrink-0">
            {allowClear && selected && (
              <X
                className="h-4 w-4 text-muted-foreground hover:text-foreground"
                onClick={(e) => { e.stopPropagation(); choose(''); }}
              />
            )}
            <ChevronDown className="h-4 w-4 opacity-50" />
          </span>
        </button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className={cn('p-0', contentClassName)}
        style={{ width: 'var(--radix-popover-trigger-width)', minWidth: '12rem' }}
      >
        {showSearch && (
          <div className="relative border-b">
            <Search className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <input
              ref={inputRef}
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter' && filtered.length > 0) { e.preventDefault(); choose(filtered[0].value); }
              }}
              placeholder={searchPlaceholder}
              className="h-9 w-full bg-transparent pl-9 pr-3 text-sm outline-none ring-0 focus:outline-none focus:ring-0 placeholder:text-muted-foreground"
            />
          </div>
        )}
        <div className="max-h-64 overflow-y-auto p-1">
          {filtered.length === 0 ? (
            <div className="px-2 py-3 text-center text-xs text-muted-foreground">Không có kết quả</div>
          ) : (
            filtered.map((o) => {
              const isSel = String(o.value) === String(value);
              return (
                <button
                  type="button"
                  key={o.value}
                  onClick={() => choose(o.value)}
                  className={cn(
                    'flex w-full items-center justify-between gap-2 rounded-sm px-2.5 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground',
                    isSel && 'bg-accent/60 font-medium text-foreground',
                  )}
                >
                  <span className="truncate">{o.label}</span>
                  {isSel && <Check className="h-4 w-4 shrink-0 text-primary" />}
                </button>
              );
            })
          )}
        </div>
      </PopoverContent>
    </Popover>
  );
}
