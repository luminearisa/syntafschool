import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ClipboardList, Plus, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { libraryInventoryApi, libraryLocationsApi } from '../api/libraryApi';
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
import { formatDate } from '@/lib/utils';
import type { SelectOption } from '@/constants/masterData';
import type { InventoryStatus, LibraryInventorySession } from '@/types/libraryCirculation';

const INVENTORY_STATUS_LABELS: Record<InventoryStatus, string> = {
  draft: 'Draf',
  ongoing: 'Berjalan',
  finalized: 'Final',
  cancelled: 'Dibatalkan',
};

const INVENTORY_STATUS_OPTIONS = Object.keys(INVENTORY_STATUS_LABELS) as InventoryStatus[];

function inventoryStatusTone(status: InventoryStatus): 'gray' | 'blue' | 'green' | 'red' {
  switch (status) {
    case 'ongoing':
      return 'blue';
    case 'finalized':
      return 'green';
    case 'cancelled':
      return 'red';
    default:
      return 'gray';
  }
}

/* ============================ MODAL BUAT SESI ============================ */

function CreateSessionModal({
  open,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: {
    library_id: number | '';
    name: string;
    location_id: number | '';
    started_at?: string;
    notes?: string;
  }) => void;
}) {
  const { options: libraryOptions } = useLibraryOptions();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [name, setName] = useState('');
  const [locationId, setLocationId] = useState<number | ''>('');
  const [startedAt, setStartedAt] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState('');

  const locationsQuery = useQuery({
    queryKey: ['library', 'inventory', 'locations', libraryId],
    enabled: open && libraryId !== '',
    queryFn: () => libraryLocationsApi.list(Number(libraryId), { all: true }),
  });

  const locationOptions: SelectOption[] = useMemo(
    () => (locationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [locationsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setLibraryId('');
    setName('');
    setLocationId('');
    setStartedAt('');
    setNotes('');
    setError('');
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (libraryId === '') {
      setError('Perpustakaan wajib dipilih');
      return;
    }
    if (!name.trim()) {
      setError('Nama sesi wajib diisi');
      return;
    }
    setError('');
    onSubmit({
      library_id: libraryId,
      name: name.trim(),
      location_id: locationId,
      started_at: startedAt || undefined,
      notes: notes.trim() || undefined,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Buat Sesi Stock Opname"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-inventory-create-form" loading={submitting}>
            Buat Sesi
          </Button>
        </>
      }
    >
      <form id="library-inventory-create-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Perpustakaan</Label>
          <SearchSelect
            options={libraryOptions}
            value={libraryId}
            error={error || serverErrors?.library_id?.[0]}
            onChange={(v) => {
              setLibraryId(v === '' ? '' : Number(v));
              setLocationId('');
            }}
            placeholder="-- Pilih perpustakaan --"
          />
        </div>
        <div>
          <Label required>Nama Sesi</Label>
          <Input
            value={name}
            maxLength={150}
            placeholder="mis. Stock opname akhir semester"
            error={serverErrors?.name?.[0]}
            onChange={(e) => setName(e.target.value)}
          />
        </div>
        <div>
          <Label>Lokasi</Label>
          <SearchSelect
            options={locationOptions}
            value={locationId}
            disabled={libraryId === ''}
            error={serverErrors?.location_id?.[0]}
            onChange={(v) => setLocationId(v === '' ? '' : Number(v))}
            placeholder={
              libraryId === '' ? 'Pilih perpustakaan dulu' : '-- Seluruh perpustakaan --'
            }
          />
          <p className="mt-1 text-xs text-slate-400">
            Kosongkan untuk memeriksa seluruh koleksi perpustakaan. Bila diisi, kode di luar lokasi
            tersebut dicatat sebagai tak terduga.
          </p>
        </div>
        <div>
          <Label>Tanggal Mulai</Label>
          <Input
            type="date"
            value={startedAt}
            error={serverErrors?.started_at?.[0]}
            onChange={(e) => setStartedAt(e.target.value)}
          />
        </div>
        <div>
          <Label>Catatan</Label>
          <Textarea
            rows={2}
            value={notes}
            error={serverErrors?.notes?.[0]}
            onChange={(e) => setNotes(e.target.value)}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Sesi stock opname (inventarisasi koleksi).
 *
 * Ringkasan agregat ditampilkan pada kartu laporan; tiap sesi menautkan ke
 * halaman pemindaian.
 */
export function LibraryInventoryPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<InventoryStatus | ''>('');
  const [libraryId, setLibraryId] = useState<number | ''>('');

  const [createOpen, setCreateOpen] = useState(false);
  const [createErrors, setCreateErrors] = useState<Record<string, string[]>>({});

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (libraryId !== '') out.library_id = libraryId;
    if (status) out.status = status;
    return out;
  }, [libraryId, status]);

  const listQuery = useQuery({
    queryKey: ['library', 'inventory', 'sessions', page, search, filters],
    queryFn: () =>
      libraryInventoryApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const reportQuery = useQuery({
    queryKey: ['library', 'inventory', 'report', libraryId],
    queryFn: () => libraryInventoryApi.report({ library_id: libraryId }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const createMutation = useMutation({
    mutationFn: (payload: {
      library_id: number | '';
      name: string;
      location_id: number | '';
      started_at?: string;
      notes?: string;
    }) => libraryInventoryApi.create(payload),
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

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const totals = reportQuery.data?.totals;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Stock Opname</h1>
          <p className="text-sm text-slate-500">
            Sesi inventarisasi koleksi: pindai kode, hitung selisih, lalu finalkan hasilnya.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.inventory.manage">
            <Button
              onClick={() => {
                setCreateErrors({});
                setCreateOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Buat Sesi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-4">
        <div className="mb-3 flex items-center gap-2">
          <ClipboardList className="h-4 w-4 text-slate-400" />
          <h2 className="text-sm font-semibold text-slate-900">Laporan Agregat</h2>
          {reportQuery.isFetching && <Spinner />}
        </div>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Sesi</div>
            <div className="mt-0.5 text-base font-semibold text-slate-900">
              {totals?.sessions ?? 0}
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Diharapkan</div>
            <div className="mt-0.5 text-base font-semibold text-slate-900">
              {totals?.expected ?? 0}
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Ditemukan</div>
            <div className="mt-0.5 text-base font-semibold text-green-600">
              {totals?.found ?? 0}
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Hilang</div>
            <div className="mt-0.5 text-base font-semibold text-red-600">{totals?.missing ?? 0}</div>
          </div>
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Tak Terduga</div>
            <div className="mt-0.5 text-base font-semibold text-amber-600">
              {totals?.unexpected ?? 0}
            </div>
          </div>
          <div className="rounded-lg border border-slate-200 px-3 py-2">
            <div className="text-xs text-slate-500">Rusak</div>
            <div className="mt-0.5 text-base font-semibold text-amber-600">
              {totals?.damaged ?? 0}
            </div>
          </div>
        </div>
      </Card>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Kode / nama sesi..."
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
                setStatus(e.target.value as InventoryStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {INVENTORY_STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {INVENTORY_STATUS_LABELS[key]}
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
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat sesi...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat sesi"
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
            title="Belum ada sesi stock opname"
            description="Buat sesi baru untuk mulai memindai koleksi."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Lokasi</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="px-4 py-2 font-medium">Mulai</th>
                  <th className="px-4 py-2 font-medium">Final</th>
                  <th className="px-4 py-2 text-center font-medium">Harap</th>
                  <th className="px-4 py-2 text-center font-medium">Temu</th>
                  <th className="px-4 py-2 text-center font-medium">Hilang</th>
                  <th className="px-4 py-2 text-center font-medium">Tak Terduga</th>
                  <th className="px-4 py-2 text-center font-medium">Rusak</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row: LibraryInventorySession) => (
                  <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2">
                      <Link
                        to={`/library/inventory/${row.id}`}
                        className="font-mono text-xs font-medium text-brand-700 hover:underline"
                      >
                        {row.code}
                      </Link>
                    </td>
                    <td className="px-4 py-2 text-slate-800">{row.name}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{orDash(row.location?.name)}</td>
                    <td className="px-4 py-2">
                      <Badge tone={inventoryStatusTone(row.status)}>
                        {INVENTORY_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">{formatDate(row.started_at)}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{formatDate(row.finalized_at)}</td>
                    <td className="px-4 py-2 text-center text-slate-600">{row.expected_count}</td>
                    <td className="px-4 py-2 text-center font-medium text-green-600">
                      {row.found_count}
                    </td>
                    <td className="px-4 py-2 text-center font-medium text-red-600">
                      {row.missing_count}
                    </td>
                    <td className="px-4 py-2 text-center font-medium text-amber-600">
                      {row.unexpected_count}
                    </td>
                    <td className="px-4 py-2 text-center font-medium text-amber-600">
                      {row.damaged_count}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <CreateSessionModal
        open={createOpen}
        submitting={createMutation.isPending}
        serverErrors={createErrors}
        onClose={() => {
          setCreateOpen(false);
          setCreateErrors({});
        }}
        onSubmit={(payload) => createMutation.mutate(payload)}
      />
    </div>
  );
}