import { useEffect, useId, type ReactNode } from 'react';
import { X } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * SideModal — panel samping (drawer) untuk create & edit.
 *
 * Meluncur dari kanan, dengan header (judul + deskripsi + konteks aksi),
 * body yang dapat digulir, dan footer aksi yang menempel di bawah.
 * Dirancang agar form panjang tetap nyaman: konten halaman di belakang
 * tetap terlihat sehingga operator bisa mencocokkan data baris tabel.
 */
export function SideModal({
  open,
  onClose,
  title,
  description,
  children,
  footer,
  size = 'md',
  icon,
  tone = 'brand',
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children: ReactNode;
  footer?: ReactNode;
  size?: 'sm' | 'md' | 'lg';
  icon?: ReactNode;
  tone?: 'brand' | 'amber' | 'rose' | 'slate';
}) {
  const titleId = useId();

  useEffect(() => {
    const handler = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
    if (open) {
      document.addEventListener('keydown', handler);
      document.body.style.overflow = 'hidden';
    }
    return () => {
      document.removeEventListener('keydown', handler);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  const sizes = {
    sm: 'sm:max-w-md',
    md: 'sm:max-w-xl',
    lg: 'sm:max-w-3xl',
  };

  const toneClass = {
    brand: 'bg-brand-50 text-brand-700 ring-brand-200',
    amber: 'bg-amber-50 text-amber-700 ring-amber-200',
    rose: 'bg-rose-50 text-rose-700 ring-rose-200',
    slate: 'bg-slate-100 text-slate-700 ring-slate-200',
  }[tone];

  return (
    <div className="fixed inset-0 z-50 flex justify-end">
      <button
        type="button"
        aria-label="Tutup panel"
        tabIndex={-1}
        onClick={onClose}
        className="ui-side-modal-scrim absolute inset-0 h-full w-full cursor-default bg-slate-950/45 backdrop-blur-[2px]"
      />
      <div
        className={cn('ui-side-modal relative flex h-full w-full flex-col bg-white shadow-2xl', sizes[size])}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
      >
        {/* Grip handle — affordansi geser (mobile) */}
        <div className="flex justify-center pt-2 sm:hidden">
          <span className="h-1.5 w-10 rounded-full bg-slate-200" />
        </div>

        <div className="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4 sm:px-6">
          <div className="flex items-start gap-3">
            {icon ? (
              <span className={cn('mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1', toneClass)}>
                {icon}
              </span>
            ) : null}
            <div>
              <h2 id={titleId} className="text-base font-semibold leading-tight text-slate-900">{title}</h2>
              {description ? <p className="mt-1 text-xs leading-relaxed text-slate-500">{description}</p> : null}
            </div>
          </div>
          <button
            type="button"
            aria-label="Tutup dialog"
            title="Tutup (Esc)"
            onClick={onClose}
            className="rounded-lg p-1.5 text-slate-400 transition-colors hover:bg-slate-100 hover:text-slate-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto px-5 py-5 sm:px-6">{children}</div>

        {footer ? (
          <div className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 bg-slate-50/70 px-5 py-3.5 sm:px-6">
            {footer}
          </div>
        ) : null}
      </div>
    </div>
  );
}
