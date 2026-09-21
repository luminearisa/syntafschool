import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Bell, CheckCheck, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { notificationsApi } from '../api/tahfizApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { NOTIFICATION_AUDIENCE_LABELS } from '../lib/labels';
import { getErrorMessage } from '@/lib/axios';
import type { TahfizNotification } from '@/types/tahfiz';

/**
 * Notifikasi Tahfiz milik pengguna (diri sendiri atau anak).
 *
 * Tidak memerlukan permission modul — daftar sudah dibatasi per pengguna di
 * sisi server.
 */
export function TahfizNotificationsPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [unreadOnly, setUnreadOnly] = useState(false);

  const listQuery = useQuery({
    queryKey: ['tahfiz', 'notifications', page, unreadOnly],
    queryFn: () =>
      notificationsApi.list({
        page,
        per_page: 15,
        filter: unreadOnly ? { unread: 1 } : undefined,
      }),
  });

  const unreadQuery = useQuery({
    queryKey: ['tahfiz', 'notifications', 'unread-count'],
    queryFn: () => notificationsApi.unreadCount(),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'notifications'] });

  const markReadMutation = useMutation({
    mutationFn: (id: number) => notificationsApi.markAsRead(id),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const markAllMutation = useMutation({
    mutationFn: () => notificationsApi.markAllAsRead(),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => notificationsApi.remove(id),
    onSuccess: () => {
      toast.success('Notifikasi dihapus');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const unreadCount = unreadQuery.data?.count ?? 0;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-900">Notifikasi Tahfiz</h1>
            {unreadCount > 0 && <Badge tone="red">{unreadCount} belum dibaca</Badge>}
          </div>
          <p className="text-sm text-slate-500">
            Pemberitahuan setoran, target, ujian, dan intervensi untuk Anda dan anak Anda.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={unreadOnly}
              onChange={(e) => {
                setUnreadOnly(e.target.checked);
                setPage(1);
              }}
            />
            Belum dibaca saja
          </label>
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          {unreadCount > 0 && (
            <Button
              variant="outline"
              loading={markAllMutation.isPending}
              onClick={() => markAllMutation.mutate()}
            >
              <CheckCheck className="h-4 w-4" /> Tandai semua dibaca
            </Button>
          )}
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
            title={unreadOnly ? 'Tidak ada notifikasi belum dibaca' : 'Belum ada notifikasi'}
            description="Notifikasi baru akan muncul di sini."
          />
        </Card>
      ) : (
        <Card>
          <div className="divide-y divide-slate-50">
            {rows.map((row: TahfizNotification) => (
              <div key={row.id} className={row.is_read ? 'px-4 py-3' : 'bg-brand-50/40 px-4 py-3'}>
                <div className="flex items-start justify-between gap-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-medium text-slate-800">{row.title}</p>
                      <Badge tone="blue">
                        {NOTIFICATION_AUDIENCE_LABELS[row.audience] ?? row.audience_label}
                      </Badge>
                      <Badge tone="gray">{row.type}</Badge>
                      {!row.is_read && <Badge tone="red">Belum dibaca</Badge>}
                    </div>
                    {row.body && <p className="mt-1 text-sm text-slate-600">{row.body}</p>}
                    <div className="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-slate-400">
                      {row.participant && <span>Peserta: {row.participant.name}</span>}
                      <span>{row.created_at ?? '—'}</span>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-1">
                    {!row.is_read && (
                      <Button
                        variant="outline"
                        size="sm"
                        loading={markReadMutation.isPending}
                        onClick={() => markReadMutation.mutate(row.id)}
                      >
                        <Bell className="h-3.5 w-3.5" /> Tandai dibaca
                      </Button>
                    )}
                    <Button
                      variant="ghost"
                      size="icon"
                      title="Hapus"
                      onClick={() => removeMutation.mutate(row.id)}
                    >
                      <Trash2 className="h-3.5 w-3.5 text-red-500" />
                    </Button>
                  </div>
                </div>
              </div>
            ))}
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}
    </div>
  );
}