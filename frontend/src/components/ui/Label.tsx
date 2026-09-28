import type { LabelHTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export function Label({
  className,
  required,
  children,
  ...props
}: LabelHTMLAttributes<HTMLLabelElement> & { required?: boolean }) {
  return (
    <label
      className={cn('ui-label mb-1 block text-sm font-medium text-slate-700', className)}
      {...props}
    >
      {children}
      {required && <span className="ml-0.5 font-semibold text-red-600">*</span>}
    </label>
  );
}
