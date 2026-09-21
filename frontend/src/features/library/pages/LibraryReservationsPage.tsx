import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Hourglass, PackageCheck, Plus, RefreshCw, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { libraryReservationsApi } from '../api/libraryApi';
import { ReservationCreateModal } from '../components/ReservationCreateModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LibraryReservation, ReservationStatus } from '@/types/libraryCirculation';

const RESERVATION_STATUS_LABELS: Record<ReservationStatus, string> = {
  waiting: 'Menunggu',
  available: 'Siap Diambil',
  picked_up: 'Sudah Diambil',
  completed: 'Selesai',
  expired: 'Kedaluwarsa',
  cancelled: 'Dibatalkan',
};

const STATUS_OPTIONS = Object.keys(RESERVATION_STATUS_LABELS) as ReservationStatus[];

function reservationTone(status: ReservationStatus): 'gray' | 'green' | 'red' | 'amber' | 'blue' | 'brand' {
  switch (status) {
    case 'available':
      return 'green';
    case 'waiting':
      return 'blue';
    case 'picked_up':
      return 'brand';
    case 'expired':
      return 'amber';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

/** Sisa hari sampai masa berlaku hold kedaluwarsa (untuk sorotan). */
function daysUntil(value: string | null): number | null {
  if (!value) return null;
  const target = new Date(value);
  if (Number.isNaN(target.getTime())) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  target.setHours(0, 0, 0, 0);
  return Math.ceil((target.getTime() - today.getTime()) / 86400000);
}

function SummaryTile({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className={`mt-0.5 text-lg font-semibold ${tone ?? 'text-slate-900'}`}>{value}</div>
    </div>
  );
}

/**
 * Daftar reservasi (§7).
 *
 * Reservasi yang siap diambil (`available`) dapat langsung dipinjamkan; salinan
 * yang dikembalikan ditahan (`reserved`) untuk pemegang antrean berikutnya.
 */
export function LibraryReservationsPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<ReservationStatus | ''>('');
  const [openOnly, setOpenOnly] = useState(false);
  const [libraryId, setLibraryId] = useState<number | ''>('');

  const [createOpen, setCreateOpen] = useState(false);
  const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({});
  const [cancelling, setCancelling] = useState<LibraryReservation | null>(null);
  const [cancelReason, setCancelReason] = useState('');

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (status) out.status = status;
    if (libraryId !== '') out.library_id = libraryId;
    return out;
  }, [status, libraryId]);

  const listQuery = useQuery({
    queryKey: ['library', 'reservations', page, search, status, openOnly, libraryId],
    queryFn: () =>
      libraryReservationsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        open: openOnly,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const summaryQuery = useQuery({
    queryKey: ['library', 'reservations', 'summary', libraryId],
    queryFn: () => libraryReservationsApi.summary(libraryId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const createMutation = useMutation({
    mutationFn: (payload: { member_id?: number | ''; bibliography_id: number | ''; notes?: string }) =>
      libraryReservationsApi.create(payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setCreateOpen(false);
      setCreateErrors({});
      invalidate();
    },
    onError: (err) => {
      setCreateErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const pickupMutation = useMutation({
    mutationFn: (id: number) => libraryReservationsApi.pickup(id),
    onSuccess: (res) => {
      toast.success(`${res.message} — Pinjaman ${res.data.loan.loan_code}`);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const cancelMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      libraryReservationsApi.cancel(id, reason),
    onSuccess: (res) => {
      toast.success(res.message);
      setCancelling(null);
      setCancelReason('');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const expireMutation = useMutation({
    mutationFn: () => libraryReservationsApi.expire(libraryId),
    onSuccess: (res) => {
      toast.success(`${res.message} (${res.data.expired} reservasi)`);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const summary = summaryQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Reservasi</h1>
          <p className="text-sm text-slate-500">
            Antrean pemesanan judul; salinan yang dikembalikan ditahan untuk pemegang berikutnya.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Can perm="library.reservation.manage">
            <Button
              variant="outline"
              loading={expireMutation.isPending}
              onClick={() => expireMutation.mutate()}
            >
              <Hourglass className="h-4 w-4" /> Kedaluwarsakan
            </Button>
          </Can>
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.reservation.create">
            <Button
              onClick={() => {
                setCreateErrors({});
                setCreateOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Buat Reservasi
            </Button>
          </Can>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <SummaryTile label="Menunggu" value={summary?.waiting ?? 0} tone="text-blue-600" />
        <SummaryTile label="Siap Diambil" value={summary?.available ?? 0} tone="text-green-600" />
        <SummaryTile label="Aktif" value={summary?.open ?? 0} />
        <SummaryTile label="Kedaluwarsa" value={summary?.expired ?? 0} tone="text-amber-600" />
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Kode reservasi / judul / anggota..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as ReservationStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {RESERVATION_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              placeholder="-- Semua --"
              onChange={(v) => {
                setLibraryId(v === '' ? '' : Number(v));
                setPage(1);
              }}
            />
          </div>
          <div className="flex items-end">
            <label className="inline-flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={openOnly}
                onChange={(e) => {
                  setOpenOnly(e.target.checked);
                  setPage(1);
                }}
              />
              Hanya yang masih aktif
            </label>
          </div>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat reservasi...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat reservasi"
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
          <EmptyState title="Belum ada reservasi" description="Tidak ada reservasi yang cocok dengan filter." />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Judul</th>
                  <th className="px-4 py-2 font-medium">Anggota</th>
                  <th className="px-4 py-2 font-medium">Antrean</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Dipesan</th>
                  <th className="px-4 py-2 font-medium">Kedaluwarsa</th>
                  <th className="w-52 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const days = daysUntil(row.expires_at);
                  const expiringSoon = row.status === 'available' && days != null && days <= 2;
                  return (
                    <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                      <td className="px-4 py-2 font-mono text-xs text-slate-700">{row.reservation_code}</td>
                      <td className="max-w-[16rem] px-4 py-2">
                        {row.bibliography ? (
                          <Link
                            to={`/library/catalog/${row.bibliography.id}`}
                            className="text-brand-700 hover:underline"
                          >
                            {row.bibliography.title}
                          </Link>
                        ) : (
                          <span className="text-xs text-slate-400">—</span>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <Link
                          to={`/library/members/${row.member_id}`}
                          className="text-brand-700 hover:underline"
                        >
                          {row.member?.name ?? row.member?.member_number ?? '—'}
                        </Link>
                        {row.member?.member_number && (
                          <div className="font-mono text-xs text-slate-400">{row.member.member_number}</div>
                        )}
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {row.queue_position != null ? `#${row.queue_position}` : '—'}
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone={reservationTone(row.status)}>
                          {RESERVATION_STATUS_LABELS[row.status] ?? row.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.reserved_at)}</td>
                      <td className="px-4 py-2 text-xs">
                        <span className={expiringSoon ? 'font-semibold text-red-600' : 'text-slate-600'}>
                          {orDash(row.expires_at)}
                        </span>
                        {expiringSoon && (
                          <div className="text-xs font-medium text-red-600">
                            {days != null && days >= 0 ? `sisa ${days} hari` : 'segera berakhir'}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap items-center gap-1">
                          {row.status === 'available' && (
                            <Can perm="library.reservation.manage">
                              <Button
                                variant="outline"
                                size="sm"
                                loading={pickupMutation.isPending && pickupMutation.variables === row.id}
                                onClick={() => pickupMutation.mutate(row.id)}
                              >
                                <PackageCheck className="h-3.5 w-3.5" /> Ambil &amp; Pinjamkan
                              </Button>
                            </Can>
                          )}
                          {row.is_open && (
                            <Can perm="library.reservation.manage">
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                  setCancelReason('');
                                  setCancelling(row);
                                }}
                              >
                                <XCircle className="h-3.5 w-3.5 text-red-500" /> Batalkan
                              </Button>
                            </Can>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <ReservationCreateModal
        open={createOpen}
        submitting={createMutation.isPending}
        serverErrors={createErrors}
        onClose={() => setCreateOpen(false)}
        onSubmit={(payload) => createMutation.mutate(payload)}
      />

      <Modal
        open={cancelling != null}
        onClose={() => setCancelling(null)}
        title="Batalkan Reservasi"
        size="sm"
        footer={
          <>
            <Button variant="outline" onClick={() => setCancelling(null)} disabled={cancelMutation.isPending}>
              Batal
            </Button>
            <Button
              variant="danger"
              disabled={!cancelReason.trim()}
              loading={cancelMutation.isPending}
              onClick={() => {
                if (cancelling) cancelMutation.mutate({ id: cancelling.id, reason: cancelReason.trim() });
              }}
            >
              Batalkan
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <p className="text-sm text-slate-600">
            Batalkan reservasi {cancelling?.reservation_code}? Alasan wajib diisi.
          </p>
          <div>
            <Label required>Alasan</Label>
            <Textarea
              value={cancelReason}
              placeholder="Alasan pembatalan..."
              onChange={(e) => setCancelReason(e.target.value)}
            />
          </div>
        </div>
      </Modal>
    </div>
  );
}