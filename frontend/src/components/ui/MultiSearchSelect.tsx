import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';

interface MultiSearchSelectProps {
  options: SelectOption[];
  value?: Array<string | number>;
  onChange: (values: Array<string | number>) => void;
  placeholder?: string;
  error?: string;
  disabled?: boolean;
  max?: number;
  className?: string;
}

/**
 * Combobox multi-pilih dengan pencarian — untuk memilih banyak item
 * (mis. beberapa santri sekaligus) pada satu kontrol.
 */
export function MultiSearchSelect({
  options,
  value = [],
  onChange,
  placeholder = '-- Pilih --',
  error,
  disabled,
  max,
  className,
}: MultiSearchSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);

  const selectedSet = useMemo(
    () => new Set(value.map((v) => String(v))),
    [value],
  );

  const selectedOptions = useMemo(
    () => options.filter((o) => selectedSet.has(String(o.value))),
    [options, selectedSet],
  );

  const filtered = useMemo(() => {
    if (!query.trim()) return options;
    const q = query.toLowerCase();
    return options.filter((o) => o.label.toLowerCase().includes(q));
  }, [options, query]);

  useEffect(() => {
    if (!open) return;
    const handleClick = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
        setQuery('');
      }
    };
    document.addEventListener('mousedown', handleClick);
    return () => document.removeEventListener('mousedown', handleClick);
  }, [open]);

  const toggle = (val: string | number) => {
    const key = String(val);
    if (selectedSet.has(key)) {
      onChange(value.filter((v) => String(v) !== key));
      return;
    }
    if (max !== undefined && value.length >= max) return;
    onChange([...value, val]);
  };

  const remove = (val: string | number) => {
    onChange(value.filter((v) => String(v) !== String(val)));
  };

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'ui-control flex min-h-9 w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 py-1.5 text-left text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-500/40 disabled:bg-slate-50',
          error ? 'border-red-400' : 'border-slate-300',
        )}
      >
        <span className="flex flex-1 flex-wrap items-center gap-1">
          {selectedOptions.length === 0 ? (
            <span className="text-slate-500">{placeholder}</span>
          ) : (
            selectedOptions.map((o) => (
              <span
                key={String(o.value)}
                className="inline-flex items-center gap-1 rounded bg-brand-50 px-1.5 py-0.5 text-xs text-brand-700"
              >
                {o.label}
                {!disabled && (
                  <X
                    className="h-3 w-3 cursor-pointer text-brand-400 hover:text-brand-700"
                    onClick={(e) => {
                      e.stopPropagation();
                      remove(o.value);
                    }}
                  />
                )}
              </span>
            ))
          )}
        </span>
        <span className="flex shrink-0 items-center gap-1">
          {selectedOptions.length > 0 && !disabled && (
            <X
              className="h-3.5 w-3.5 text-slate-400 hover:text-slate-600"
              onClick={(e) => {
                e.stopPropagation();
                onChange([]);
              }}
            />
          )}
          <ChevronDown className="h-4 w-4 text-slate-400" />
        </span>
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg">
          <div className="flex items-center gap-2 border-b border-slate-100 px-3 py-2">
            <Search className="h-4 w-4 text-slate-400" />
            <input
              autoFocus
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Cari..."
              className="h-6 w-full bg-transparent text-sm outline-none placeholder:text-slate-500"
            />
          </div>
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-center text-xs text-slate-400">
                Tidak ada hasil
              </li>
            ) : (
              filtered.map((o) => {
                const isSelected = selectedSet.has(String(o.value));
                const atMax = !isSelected && max !== undefined && value.length >= max;
                return (
                  <li key={String(o.value)}>
                    <button
                      type="button"
                      disabled={atMax}
                      onClick={() => toggle(o.value)}
                      className={cn(
                        'flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50',
                        atMax ? 'cursor-not-allowed text-slate-300' : 'text-slate-700',
                      )}
                    >
                      <span className="flex items-center gap-2 truncate">
                        <span
                          className={cn(
                            'flex h-4 w-4 shrink-0 items-center justify-center rounded border',
                            isSelected ? 'border-brand-600 bg-brand-600 text-white' : 'border-slate-300',
                          )}
                        >
                          {isSelected && <Check className="h-3 w-3" />}
                        </span>
                        <span className="truncate">{o.label}</span>
                      </span>
                    </button>
                  </li>
                );
              })
            )}
          </ul>
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
