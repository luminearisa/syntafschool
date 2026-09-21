import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import type { PaginationMeta } from '@/types';

export function Pagination({
  meta,
  onPageChange,
}: {
  meta: PaginationMeta;
  onPageChange: (page: number) => void;
}) {
  if (!meta || meta.last_page <= 1) return null;

  const page = meta.current_page;
  const last = meta.last_page;

  return (
    <div className="flex items-center justify-between gap-3 border-t border-slate-100 px-4 py-3">
      <p className="text-xs text-slate-500">
        Menampilkan{' '}
        <span className="font-medium text-slate-700">{meta.from ?? 0}</span>–
        <span className="font-medium text-slate-700">{meta.to ?? 0}</span> dari{' '}
        <span className="font-medium text-slate-700">{meta.total}</span> data
      </p>
      <div className="flex items-center gap-1">
        <Button
          variant="outline"
          size="icon"
          disabled={page <= 1}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft className="h-4 w-4" />
        </Button>
        <span className="px-2 text-sm text-slate-600">
          {page} / {last}
        </span>
        <Button
          variant="outline"
          size="icon"
          disabled={page >= last}
          onClick={() => onPageChange(page + 1)}
        >
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  );
}
