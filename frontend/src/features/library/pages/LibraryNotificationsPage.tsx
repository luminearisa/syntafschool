import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, BellOff, CheckCheck, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryNotificationsApi } from '../api/libraryApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import type { LibraryNotification } from '@/types/libraryCirculation';

const dateTimeFormat = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '—' : dateTimeFormat.format(d);
}

const NOTIFICATION_TYPE_LABELS: Record<string, string> = {
  loan_due_soon: 'Jatuh Tempo Dekat',
  loan_overdue: 'Terlambat',
  reservation_available: 'Reservasi Tersedia',
  reservation_expired: 'Reservasi Kedaluwarsa',
  membership_expiring: 'Keanggotaan Akan Berakhir',
  fine_created: 'Denda',
};

function notificationTone(type: string): 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand' {
  switch (type) {
    case 'loan_overdue':
      return 'red';
    case 'loan_due_soon':
      return 'amber';
    case 'reservation_available':
      return 'green';
    case 'reservation_expired':
      return 'gray';
    case 'membership_expiring':
      return 'brand';
    case 'fine_created':
      return 'red';
    default:
      return 'blue';
  }
}

function relatedReference(notification: LibraryNotification): string | null {
  const data = notification.data;
  if (data.loan_code) return `Pinjaman ${data.loan_code}`;
  if (data.reservation_code) return `Reservasi ${data.reservation_code}`;
  if (data.member_number) return `Anggota ${data.member_number}`;
  return null;
}

export function LibraryNotificationsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);

  const listQuery = useQuery({
    queryKey: ['library', 'notifications', page],
    queryFn: () => libraryNotificationsApi.list({ page, per_page: 20 }),
  });

  const unreadQuery = useQuery({
    queryKey: ['library', 'notifications', 'unread-count'],
    queryFn: () => libraryNotificationsApi.unreadCount(),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library', 'notifications'] });

  const markReadMutation = useMutation({
    mutationFn: (id: string) => libraryNotificationsApi.markAsRead(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const markAllMutation = useMutation({
    mutationFn: () => libraryNotificationsApi.markAllAsRead(),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const unread = unreadQuery.data?.count ?? 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-900">Notifikasi Perpustakaan</h1>
            {unread > 0 && <Badge tone="red">{unread} belum dibaca</Badge>}
          </div>
          <p className="text-sm text-slate-500">
            Pemberitahuan jatuh tempo, keterlambatan, reservasi, keanggotaan, dan denda untuk akun
            Anda.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => {
              listQuery.refetch();
              unreadQuery.refetch();
            }}
          >
            <RefreshCw
              className={cn('h-4 w-4', listQuery.isFetching && 'animate-spin')}
            />
          </Button>
          <Button
            variant="outline"
            disabled={unread === 0}
            loading={markAllMutation.isPending}
            onClick={() => markAllMutation.mutate()}
          >
            <CheckCheck className="h-4 w-4" /> Tandai semua dibaca
          </Button>
        </div>
      </div>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat notifikasi...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat notifikasi"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Tidak ada notifikasi"
            description="Anda akan menerima pemberitahuan di sini saat ada aktivitas perpustakaan."
          />
        </Card>
      ) : (
        <Card>
          <ul className="divide-y divide-slate-50">
            {rows.map((notification) => {
              const isUnread = notification.read_at === null;
              const reference = relatedReference(notification);
              const typeKey = notification.data.type || notification.type;
              return (
                <li
                  key={notification.id}
                  className={cn('px-4 py-3', isUnread && 'bg-brand-50/40')}
                >
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="flex min-w-0 items-start gap-2">
                      <span className="mt-1">
                        {isUnread ? (
                          <Bell className="h-4 w-4 text-brand-600" />
                        ) : (
                          <BellOff className="h-4 w-4 text-slate-300" />
                        )}
                      </span>
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-sm font-medium text-slate-800">
                            {notification.data.title || '(Tanpa judul)'}
                          </span>
                          <Badge tone={notificationTone(typeKey)}>
                            {NOTIFICATION_TYPE_LABELS[typeKey] ?? typeKey}
                          </Badge>
                          {isUnread && <Badge tone="brand">Baru</Badge>}
                        </div>
                        {notification.data.body && (
                          <p className="mt-0.5 text-sm text-slate-600">{notification.data.body}</p>
                        )}
                        <div className="mt-1 flex flex-wrap items-center gap-3 text-xs text-slate-400">
                          {reference && <span className="font-mono">{reference}</span>}
                          <span>{formatDateTime(notification.created_at)}</span>
                        </div>
                      </div>
                    </div>
                    {isUnread && (
                      <Button
                        size="sm"
                        variant="ghost"
                        loading={
                          markReadMutation.isPending &&
                          markReadMutation.variables === notification.id
                        }
                        onClick={() => markReadMutation.mutate(notification.id)}
                      >
                        Tandai dibaca
                      </Button>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}
    </div>
  );
}