import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeftRight, BellRing, CheckCheck, ChevronLeft, ChevronRight } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { getErrorMessage } from '@/lib/axios';
import { DataState } from '../../components/portal/DataState';
import { formatDateTime } from '../../components/portal/format';
import { NOTIFICATION_TYPE_LABELS, NOTIFICATION_TYPE_TONES } from '../../components/portal/labels';
import { ppdbPortalNotificationsApi } from '../../api/ppdbApi';
import type { PpdbNotification } from '@/types/ppdbPortal';

const PER_PAGE = 20;

/** Tautan tindakan berdasarkan jenis notifikasi — membawa orang tua ke tempat yang tepat. */
function targetOf(item: PpdbNotification): string | null {
  const registrationId = item.data.registration_id;
  if (!registrationId) return null;
  const base = `/ppdb/portal/registrations/${registrationId}`;
  switch (item.type) {
    case 'document_rejected':
      return `${base}?langkah=dokumen`;
    case 'payment_paid':
    case 'payment_failed':
    case 'payment_reminder':
      return `${base}?langkah=pembayaran`;
    case 'selection_scheduled':
    case 'selection_result':
      return `${base}/seleksi`;
    case 're_registration_open':
      return `${base}/seleksi`;
    case 'revision_requested':
      return `${base}?langkah=review`;
    default:
      return base;
  }
}

/** Pusat notifikasi portal orang tua. */
export function PpdbPortalNotificationsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [unreadOnly, setUnreadOnly] = useState(false);

  const listQuery = useQuery({
    queryKey: ['ppdb', 'portal', 'notifications', { page, unreadOnly }],
    queryFn: () =>
      ppdbPortalNotificationsApi.list({
        page,
        per_page: PER_PAGE,
        ...(unreadOnly ? { filter: 'unread' } : {}),
      }),
  });

  const readMutation = useMutation({
    mutationFn: (id: string) => ppdbPortalNotificationsApi.markRead(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'notifications'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const readAllMutation = useMutation({
    mutationFn: () => ppdbPortalNotificationsApi.markAllRead(),
    onSuccess: (count) => {
      queryClient.invalidateQueries({ queryKey: ['ppdb', 'portal', 'notifications'] });
      toast.success(count > 0 ? `${count} notifikasi ditandai sudah dibaca.` : 'Tidak ada notifikasi baru.');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const unreadOnPage = rows.filter((item) => !item.read_at).length;

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Notifikasi</h1>
          <p className="text-sm text-slate-500">
            Setiap perubahan status pendaftaran, dokumen, pembayaran, dan hasil seleksi diumumkan di sini.
          </p>
        </div>
        <Button
          size="sm"
          variant="outline"
          loading={readAllMutation.isPending}
          disabled={unreadOnly ? rows.length === 0 : unreadOnPage === 0 && page === 1}
          onClick={() => readAllMutation.mutate()}
        >
          <CheckCheck className="h-4 w-4" />
          Tandai semua dibaca
        </Button>
      </div>

      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={() => {
            setUnreadOnly((prev) => !prev);
            setPage(1);
          }}
          className="inline-flex min-h-9 items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-700 hover:bg-slate-50"
        >
          <ArrowLeftRight className="h-4 w-4" />
          {unreadOnly ? 'Tampilkan semua' : 'Hanya belum dibaca'}
        </button>
        {meta && <span className="text-xs text-slate-500">Total {meta.total} pemberitahuan</span>}
      </div>

      <DataState
        isLoading={listQuery.isLoading}
        isError={listQuery.isError}
        error={listQuery.error}
        onRetry={() => listQuery.refetch()}
        isEmpty={rows.length === 0}
        loadingLabel="Memuat notifikasi..."
        errorTitle="Notifikasi gagal dimuat"
        emptyTitle={unreadOnly ? 'Tidak ada notifikasi belum dibaca' : 'Belum ada notifikasi'}
        emptyDescription={
          unreadOnly
            ? 'Semua pemberitahuan pada halaman ini sudah Anda baca.'
            : 'Notifikasi akan muncul begitu Anda mengirim pendaftaran dan saat panitia menindakinya.'
        }
      >
        <ul className="space-y-2">
          {rows.map((item) => {
            const unread = !item.read_at;
            const target = targetOf(item);
            const tone = NOTIFICATION_TYPE_TONES[item.type] ?? 'gray';

            return (
              <li key={item.id}>
                <Card className={unread ? 'border-brand-500/40 bg-brand-50/40 p-3' : 'p-3'}>
                  <div className="flex items-start gap-3">
                    <span
                      className={
                        unread
                          ? 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-brand-600'
                          : 'mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-200'
                      }
                      aria-hidden
                    />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={tone}>{NOTIFICATION_TYPE_LABELS[item.type] ?? item.type}</Badge>
                        <p className="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{item.data.title}</p>
                      </div>
                      <p className="mt-1 whitespace-pre-line text-xs text-slate-600">{item.data.body}</p>
                      <p className="mt-1 text-[11px] text-slate-400">{formatDateTime(item.created_at)}</p>

                      <div className="mt-2 flex flex-wrap items-center gap-2">
                        {target && (
                          <Link
                            to={target}
                            onClick={() => {
                              if (unread) readMutation.mutate(item.id);
                            }}
                          >
                            <Button size="sm" variant={unread ? 'primary' : 'outline'} loading={readMutation.isPending && unread}>
                              Buka berkas terkait
                            </Button>
                          </Link>
                        )}
                        {unread && (
                          <Button size="sm" variant="ghost" onClick={() => readMutation.mutate(item.id)}>
                            Tandai dibaca
                          </Button>
                        )}
                      </div>
                    </div>
                  </div>
                </Card>
              </li>
            );
          })}
        </ul>

        {meta && meta.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between gap-2">
            <p className="text-xs text-slate-500">
              Halaman {meta.current_page} dari {meta.last_page}
            </p>
            <div className="flex items-center gap-1">
              <Button
                size="sm"
                variant="outline"
                disabled={meta.current_page <= 1}
                onClick={() => setPage((prev) => Math.max(prev - 1, 1))}
              >
                <ChevronLeft className="h-4 w-4" />
                Sebelumnya
              </Button>
              <Button
                size="sm"
                variant="outline"
                disabled={meta.current_page >= meta.last_page}
                onClick={() => setPage((prev) => prev + 1)}
              >
                Berikutnya
                <ChevronRight className="h-4 w-4" />
              </Button>
            </div>
          </div>
        )}
      </DataState>

      <Card className="flex items-start gap-2 p-3 text-xs text-slate-500">
        <BellRing className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" />
        <p>
          Notifikasi bersifat arsip resmi alur PPDB. Bila isinya janggal — misalnya permintaan biaya ke rekening di
          luar rekening yang tercantum pada halaman tagihan — hubungi panitia melalui kanal kontak periode sebelum
          mentransfer.
        </p>
      </Card>
    </div>
  );
}

export default PpdbPortalNotificationsPage;
