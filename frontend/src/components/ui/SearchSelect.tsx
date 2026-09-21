import { useEffect, useMemo, useRef, useState } from 'react';
import { Check, ChevronDown, Search, X } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';

interface SearchSelectProps {
  options: SelectOption[];
  value?: string | number | null;
  onChange: (value: string | number | '') => void;
  placeholder?: string;
  error?: string;
  disabled?: boolean;
  clearable?: boolean;
  className?: string;
}

/**
 * Combobox dengan pencarian untuk daftar opsi panjang.
 * Minimalis, compact, dan responsif.
 */
export function SearchSelect({
  options,
  value,
  onChange,
  placeholder = '-- Pilih --',
  error,
  disabled,
  clearable = true,
  className,
}: SearchSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);

  const selected = useMemo(
    () => options.find((o) => String(o.value) === String(value ?? '')),
    [options, value],
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

  const handleSelect = (val: string | number) => {
    onChange(val);
    setOpen(false);
    setQuery('');
  };

  return (
    <div ref={containerRef} className={cn('relative', className)}>
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((v) => !v)}
        className={cn(
          'flex h-9 w-full items-center justify-between gap-2 rounded-lg border bg-white px-3 text-left text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-brand-500/40 disabled:bg-slate-50',
          error ? 'border-red-400' : 'border-slate-300',
        )}
      >
        <span className={cn('truncate', !selected && 'text-slate-400')}>
          {selected ? selected.label : placeholder}
        </span>
        <span className="flex items-center gap-1">
          {clearable && selected && !disabled && (
            <X
              className="h-3.5 w-3.5 text-slate-400 hover:text-slate-600"
              onClick={(e) => {
                e.stopPropagation();
                onChange('');
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
              className="h-6 w-full bg-transparent text-sm outline-none placeholder:text-slate-400"
            />
          </div>
          <ul className="max-h-56 overflow-y-auto py-1">
            {filtered.length === 0 ? (
              <li className="px-3 py-2 text-center text-xs text-slate-400">
                Tidak ada hasil
              </li>
            ) : (
              filtered.map((o) => (
                <li key={String(o.value)}>
                  <button
                    type="button"
                    onClick={() => handleSelect(o.value)}
                    className="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-sm text-slate-700 hover:bg-slate-50"
                  >
                    <span className="truncate">{o.label}</span>
                    {String(o.value) === String(value ?? '') && (
                      <Check className="h-4 w-4 text-brand-600" />
                    )}
                  </button>
                </li>
              ))
            )}
          </ul>
        </div>
      )}

      {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
    </div>
  );
}
