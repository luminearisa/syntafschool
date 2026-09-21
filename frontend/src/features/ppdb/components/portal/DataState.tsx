import type { ReactNode } from 'react';
import { AlertTriangle, Inbox } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';

interface Props {
  isLoading?: boolean;
  isError?: boolean;
  error?: unknown;
  onRetry?: () => void;
  isEmpty?: boolean;
  emptyTitle?: string;
  emptyDescription?: string;
  emptyAction?: ReactNode;
  loadingLabel?: string;
  errorTitle?: string;
  children: ReactNode;
}

/** Rangka muat / kosong / gagal yang sama untuk semua daftar & detail portal. */
export function DataState({
  isLoading,
  isError,
  error,
  onRetry,
  isEmpty,
  emptyTitle = 'Tidak ada data',
  emptyDescription,
  emptyAction,
  loadingLabel = 'Memuat data...',
  errorTitle = 'Gagal memuat data',
  children,
}: Props) {
  if (isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-14 text-sm text-slate-500">
          <Spinner />
          {loadingLabel}
        </div>
      </Card>
    );
  }

  if (isError) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-2 py-12 text-center">
          <div className="rounded-full bg-red-50 p-3 text-red-500">
            <AlertTriangle className="h-6 w-6" />
          </div>
          <p className="text-sm font-medium text-slate-800">{errorTitle}</p>
          <p className="max-w-md text-xs text-slate-500">{getErrorMessage(error)}</p>
          {onRetry && (
            <Button size="sm" variant="outline" className="mt-2" onClick={onRetry}>
              Coba lagi
            </Button>
          )}
        </div>
      </Card>
    );
  }

  if (isEmpty) {
    return (
      <Card>
        <div className="flex flex-col items-center gap-2 py-12 text-center">
          <div className="rounded-full bg-slate-100 p-3 text-slate-400">
            <Inbox className="h-6 w-6" />
          </div>
          <p className="text-sm font-medium text-slate-700">{emptyTitle}</p>
          {emptyDescription && <p className="max-w-md text-xs text-slate-500">{emptyDescription}</p>}
          {emptyAction}
        </div>
      </Card>
    );
  }

  return <>{children}</>;
}
