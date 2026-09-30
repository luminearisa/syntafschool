import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

type Tone = 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand';

/**
 * Alias semantik: modul memilih nama yang menyatakan *maksud* (danger,
 * warning, success, info, neutral), bukan warna, sehingga penerjemahan ke
 * warna tetap satu tempat di sini.
 */
type ToneAlias = 'neutral' | 'danger' | 'warning' | 'success' | 'info';

const aliases: Record<ToneAlias, Tone> = {
  neutral: 'gray',
  danger: 'red',
  warning: 'amber',
  success: 'green',
  info: 'blue',
};

const tones: Record<Tone, string> = {
  gray: 'bg-slate-100 text-slate-700',
  green: 'bg-green-100 text-green-700',
  red: 'bg-red-100 text-red-700',
  amber: 'bg-amber-100 text-amber-700',
  blue: 'bg-blue-100 text-blue-700',
  brand: 'bg-brand-100 text-brand-700',
};

export function Badge({
  className,
  tone = 'gray',
  ...props
}: HTMLAttributes<HTMLSpanElement> & { tone?: Tone | ToneAlias }) {
  const resolved: Tone = (tone as ToneAlias) in aliases ? aliases[tone as ToneAlias] : (tone as Tone);

  return (
    <span
      className={cn(
        'ui-badge inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
        tones[resolved],
        className,
      )}
      {...props}
    />
  );
}
