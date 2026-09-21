import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { Barcode, Layers, Pencil, Plus, RefreshCw, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';
import {
  libraryCatalogApi,
  libraryItemsApi,
  libraryLocationsApi,
  libraryMastersApi,
} from '../api/libraryApi';
import { ItemFormModal } from '../components/ItemFormModal';
import { ItemBulkModal } from '../components/ItemBulkModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import {
  ITEM_STATUS_LABELS,
  RFID_STATUS_LABELS,
  itemStatusTone,
} from '../lib/catalogLabels';
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
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type {
  ItemStatus,
  LibraryItem,
  LibraryItemBulkPayload,
  LibraryItemPayload,
  RfidStatus,
} from '@/types/libraryCatalog';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/** Pesan validasi pertama (mis. penolakan 422 "sedang dalam sirkulasi"). */
function firstValidationMessage(err: unknown): string | null {
  const errors = getValidationErrors(err);
  if (!errors) return null;
  const first = Object.values(errors)[0];
  return first && first.length > 0 ? first[0] : null;
}

/* ============================ MODAL OPERASI MASSAL ============================ */

function BulkAssignModal({
  open,
  count,
  libraryId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  count: number;
  libraryId: number | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: {
    ids: number[];
    location_id?: number | '';
    condition_id?: number | '';
    status_id?: number | '';
    status?: ItemStatus | '';
  }) => void;
}) {
  const [locationId, setLocationId] = useState<number | ''>('');
  const [conditionId, setConditionId] = useState<number | ''>('');
  const [statusId, setStatusId] = useState<number | ''>('');
  const [status, setStatus] = useState<ItemStatus | ''>('');

  const conditionsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'conditions'],
    enabled: open,
    queryFn: () => libraryMastersApi.list('conditions', { all: true }),
  });
  const collectionStatusesQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'collection-statuses'],
    enabled: open,
    queryFn: () => libraryMastersApi.list('collection-statuses', { all: true }),
  });
  const locationsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'locations', libraryId],
    enabled: open && libraryId != null,
    queryFn: () => libraryLocationsApi.list(libraryId as number, { all: true }),
  });

  const conditionOptions: SelectOption[] = useMemo(
    () => (conditionsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [conditionsQuery.data],
  );
  const collectionStatusOptions: SelectOption[] = useMemo(
    () => (collectionStatusesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [collectionStatusesQuery.data],
  );
  const locationOptions: SelectOption[] = useMemo(
    () => (locationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [locationsQuery.data],
  );

  // Reset setiap kali dibuka.
  const reset = () => {
    setLocationId('');
    setConditionId('');
    setStatusId('');
    setStatus('');
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit({
      ids: [],
      location_id: locationId,
      condition_id: conditionId,
      status_id: statusId,
      status,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={`Ubah Massal ${count} Eksemplar`}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-item-bulk-assign-form" loading={submitting}>
            Terapkan
          </Button>
        </>
      }
    >
      <form
        id="library-item-bulk-assign-form"
        onSubmit={handleSubmit}
        className="space-y-4"
        onReset={reset}
      >
        <p className="text-xs text-slate-500">
          Hanya atribut yang diisi yang akan diubah. Kosongkan yang tidak ingin diubah.
        </p>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label>Lokasi</Label>
            <SearchSelect
              options={locationOptions}
              value={locationId}
              disabled={libraryId == null}
              error={serverErrors?.location_id?.[0]}
              onChange={(v) => setLocationId(toId(v))}
              placeholder={libraryId == null ? 'Pilih perpustakaan dulu' : '-- Biarkan --'}
            />
          </div>
          <div>
            <Label>Kondisi</Label>
            <SearchSelect
              options={conditionOptions}
              value={conditionId}
              error={serverErrors?.condition_id?.[0]}
              onChange={(v) => setConditionId(toId(v))}
              placeholder="-- Biarkan --"
            />
          </div>
          <div>
            <Label>Status Koleksi</Label>
            <SearchSelect
              options={collectionStatusOptions}
              value={statusId}
              error={serverErrors?.status_id?.[0]}
              onChange={(v) => setStatusId(toId(v))}
              placeholder="-- Biarkan --"
            />
          </div>
          <div>
            <Label>Status Eksemplar</Label>
            <Select
              value={status}
              error={serverErrors?.status?.[0]}
              onChange={(e) => setStatus(e.target.value as ItemStatus | '')}
            >
              <option value="">-- Biarkan --</option>
              {(Object.keys(ITEM_STATUS_LABELS) as ItemStatus[]).map((key) => (
                <option key={key} value={key}>
                  {ITEM_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Daftar eksemplar (koleksi fisik/digital).
 *
 * Eksemplar berbeda dari judul: satu record bibliografi dapat memiliki banyak
 * eksemplar, masing-masing dengan kode, nomor inventaris, barcode, dan RFID sendiri.
 */
export function LibraryItemsPage() {
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const bibliographyId = Number(searchParams.get('bibliography_id')) || '';

  const { options: libraryOptions } = useLibraryOptions();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [bibFilter, setBibFilter] = useState<number | ''>('');
  const [statusFilter, setStatusFilter] = useState<ItemStatus | ''>('');
  const [rfidFilter, setRfidFilter] = useState<RfidStatus | ''>('');
  const [locationFilter, setLocationFilter] = useState<number | ''>('');
  const [conditionFilter, setConditionFilter] = useState<number | ''>('');
  const [libraryFilter, setLibraryFilter] = useState<number | ''>('');

  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryItem | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [bulkOpen, setBulkOpen] = useState(false);
  const [bulkErrors, setBulkErrors] = useState<Record<string, string[]>>({});
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignErrors, setAssignErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryItem | null>(null);

  const recordsQuery = useQuery({
    queryKey: ['library', 'items', 'record-options'],
    queryFn: () => libraryCatalogApi.list({ all: true }),
  });
  const recordOptions: SelectOption[] = useMemo(
    () =>
      (recordsQuery.data?.data ?? []).map((r) => ({
        value: r.id,
        label: r.control_number ? `${r.control_number} — ${r.title}` : r.title,
      })),
    [recordsQuery.data],
  );

  const conditionsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'conditions'],
    queryFn: () => libraryMastersApi.list('conditions', { all: true }),
  });
  const conditionOptions: SelectOption[] = useMemo(
    () => (conditionsQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [conditionsQuery.data],
  );

  const filterLocationsQuery = useQuery({
    queryKey: ['library', 'items', 'options', 'locations', libraryFilter || null],
    enabled: libraryFilter !== '',
    queryFn: () => libraryLocationsApi.list(Number(libraryFilter), { all: true }),
  });
  const locationOptions: SelectOption[] = useMemo(
    () => (filterLocationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [filterLocationsQuery.data],
  );

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (bibliographyId) out.bibliography_id = bibliographyId;
    if (bibFilter !== '') out.bibliography_id = bibFilter;
    if (statusFilter) out.status = statusFilter;
    if (rfidFilter) out.rfid_status = rfidFilter;
    if (locationFilter !== '') out.location_id = locationFilter;
    if (conditionFilter !== '') out.condition_id = conditionFilter;
    if (libraryFilter !== '') out.library_id = libraryFilter;
    return out;
  }, [
    bibliographyId,
    bibFilter,
    statusFilter,
    rfidFilter,
    locationFilter,
    conditionFilter,
    libraryFilter,
  ]);

  const listQuery = useQuery({
    queryKey: ['library', 'items', page, search, filters],
    queryFn: () =>
      libraryItemsApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
      }),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });
  const clearSelection = () => setSelected(new Set());

  const selectedRows = useMemo(() => rows.filter((r) => selected.has(r.id)), [rows, selected]);
  const selectedIds = useMemo(() => Array.from(selected), [selected]);
  const selectedLibraryId = selectedRows[0]?.library_id ?? null;

  const saveMutation = useMutation({
    mutationFn: ({
      payload,
      bibId,
    }: {
      payload: LibraryItemPayload;
      bibId?: number;
    }) => {
      if (editing) return libraryItemsApi.update(editing.id, payload);
      if (bibId) return libraryItemsApi.createFor(bibId, payload);
      throw new Error('Judul tidak diketahui');
    },
    onSuccess: () => {
      toast.success(editing ? 'Eksemplar diperbarui' : 'Eksemplar dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const bulkMutation = useMutation({
    mutationFn: ({ bibId, payload }: { bibId: number; payload: LibraryItemBulkPayload }) =>
      libraryItemsApi.bulkCreateFor(bibId, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setBulkOpen(false);
      setBulkErrors({});
      invalidate();
    },
    onError: (err) => {
      setBulkErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const assignMutation = useMutation({
    mutationFn: (payload: {
      ids: number[];
      location_id?: number | '';
      condition_id?: number | '';
      status_id?: number | '';
      status?: ItemStatus | '';
    }) => libraryItemsApi.bulkAssign({ ...payload, ids: selectedIds }),
    onSuccess: (res) => {
      toast.success(res.message);
      setAssignOpen(false);
      setAssignErrors({});
      clearSelection();
      invalidate();
    },
    onError: (err) => {
      setAssignErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const barcodeMutation = useMutation({
    mutationFn: () => libraryItemsApi.bulkGenerateBarcode(selectedIds),
    onSuccess: (res) => {
      toast.success(res.message);
      clearSelection();
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => libraryItemsApi.remove(id),
    onSuccess: () => {
      toast.success('Eksemplar dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => {
      // Penolakan 422 (mis. "sedang dalam sirkulasi") membawa pesan yang lebih
      // spesifik pada errors — pesan umum mungkin menyesatkan.
      toast.error(firstValidationMessage(err) ?? getErrorMessage(err));
    },
  });

  const selectedRecord = useMemo(
    () => recordsQuery.data?.data.find((r) => r.id === (bibFilter || bibliographyId)),
    [recordsQuery.data, bibFilter, bibliographyId],
  );
  const contextBibId = bibFilter !== '' ? bibFilter : bibliographyId;
  const contextLibraryId = selectedRecord?.library_id ?? null;

  const toggleRow = (id: number) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const toggleAll = () => {
    setSelected((prev) => {
      if (prev.size === rows.length && rows.every((r) => prev.has(r.id))) return new Set();
      return new Set(rows.map((r) => r.id));
    });
  };

  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));

  const chipLabel = selectedRecord
    ? `${selectedRecord.control_number ? `${selectedRecord.control_number} — ` : ''}${selectedRecord.title}`
    : `#${bibliographyId}`;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Koleksi (Eksemplar)</h1>
          <p className="text-sm text-slate-500">
            Eksemplar fisik/digital per judul, lengkap dengan kode, nomor inventaris, barcode, dan RFID.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.item.create">
            <Button
              disabled={contextBibId === ''}
              title={contextBibId === '' ? 'Pilih judul pada filter untuk menambah eksemplar' : undefined}
              onClick={() => {
                setEditing(null);
                setServerErrors({});
                setFormOpen(true);
              }}
            >
              <Plus className="h-4 w-4" /> Tambah Eksemplar
            </Button>
          </Can>
          <Can perm="library.item.create">
            <Button
              variant="outline"
              disabled={contextBibId === ''}
              onClick={() => {
                setBulkErrors({});
                setBulkOpen(true);
              }}
            >
              <Layers className="h-4 w-4" /> Tambah Banyak
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Kode / inventaris / barcode / RFID / judul..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Judul</label>
            <SearchSelect
              options={recordOptions}
              value={bibFilter}
              onChange={(v) => {
                setBibFilter(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua judul --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={statusFilter}
              onChange={(e) => {
                setStatusFilter(e.target.value as ItemStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {(Object.keys(ITEM_STATUS_LABELS) as ItemStatus[]).map((key) => (
                <option key={key} value={key}>
                  {ITEM_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status RFID</label>
            <Select
              value={rfidFilter}
              onChange={(e) => {
                setRfidFilter(e.target.value as RfidStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status RFID</option>
              {(Object.keys(RFID_STATUS_LABELS) as RfidStatus[]).map((key) => (
                <option key={key} value={key}>
                  {RFID_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryFilter}
              onChange={(v) => {
                setLibraryFilter(toId(v));
                setLocationFilter('');
                setPage(1);
              }}
              placeholder="-- Semua perpustakaan --"
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Lokasi</label>
            <SearchSelect
              options={locationOptions}
              value={locationFilter}
              disabled={libraryFilter === ''}
              onChange={(v) => {
                setLocationFilter(toId(v));
                setPage(1);
              }}
              placeholder={libraryFilter === '' ? 'Pilih perpustakaan dulu' : '-- Semua lokasi --'}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Kondisi</label>
            <SearchSelect
              options={conditionOptions}
              value={conditionFilter}
              onChange={(v) => {
                setConditionFilter(toId(v));
                setPage(1);
              }}
              placeholder="-- Semua kondisi --"
            />
          </div>
        </div>

        {bibliographyId !== '' && (
          <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
            <span className="text-xs text-slate-500">Difilter dari halaman judul:</span>
            <span className="inline-flex items-center gap-1 rounded-full bg-brand-100 px-2 py-0.5 text-xs font-medium text-brand-700">
              {chipLabel}
              <button
                type="button"
                title="Hapus filter"
                onClick={() => {
                  const next = new URLSearchParams(searchParams);
                  next.delete('bibliography_id');
                  setSearchParams(next, { replace: true });
                  setPage(1);
                }}
              >
                <X className="h-3 w-3" />
              </button>
            </span>
          </div>
        )}
      </Card>

      {selected.size > 0 && (
        <Card className="flex flex-wrap items-center justify-between gap-2 border-brand-200 bg-brand-50/60 p-3">
          <span className="text-sm text-slate-700">
            <span className="font-semibold">{selected.size}</span> eksemplar dipilih
          </span>
          <div className="flex flex-wrap items-center gap-2">
            <Can perm="library.item.update">
              <Button
                size="sm"
                variant="outline"
                onClick={() => {
                  setAssignErrors({});
                  setAssignOpen(true);
                }}
              >
                Pindah Lokasi / Ubah Kondisi / Ubah Status
              </Button>
              <Button
                size="sm"
                variant="outline"
                loading={barcodeMutation.isPending}
                onClick={() => barcodeMutation.mutate()}
              >
                <Barcode className="h-3.5 w-3.5" /> Bangkitkan Barcode
              </Button>
            </Can>
            <Button size="sm" variant="ghost" onClick={clearSelection}>
              Bersihkan
            </Button>
          </div>
        </Card>
      )}

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat eksemplar...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat eksemplar"
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
            title="Belum ada eksemplar"
            description="Tambahkan eksemplar dari halaman judul, atau pilih judul pada filter lalu tambah eksemplar."
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-8 px-3 py-2">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      onChange={toggleAll}
                      aria-label="Pilih semua"
                    />
                  </th>
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">No. Inventaris</th>
                  <th className="px-4 py-2 font-medium">Barcode</th>
                  <th className="px-4 py-2 font-medium">RFID</th>
                  <th className="px-4 py-2 font-medium">Judul</th>
                  <th className="px-4 py-2 font-medium">Lokasi</th>
                  <th className="px-4 py-2 font-medium">Kondisi</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-3 py-2">
                      <input
                        type="checkbox"
                        checked={selected.has(row.id)}
                        onChange={() => toggleRow(row.id)}
                        aria-label={`Pilih ${row.item_code}`}
                      />
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-700">{row.item_code}</td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">
                      {orDash(row.accession_number)}
                    </td>
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">
                      {orDash(row.barcode)}
                    </td>
                    <td className="px-4 py-2">
                      {row.rfid_tag ? (
                        <div className="space-y-1">
                          <div className="font-mono text-xs text-slate-700">{row.rfid_tag}</div>
                          <Badge tone={row.rfid_status === 'assigned' || row.rfid_status === 'active' ? 'green' : 'gray'}>
                            {RFID_STATUS_LABELS[row.rfid_status] ?? row.rfid_status}
                          </Badge>
                        </div>
                      ) : (
                        <span className="text-xs text-slate-400">—</span>
                      )}
                    </td>
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
                      {row.is_circulation_controlled && (
                        <div className="mt-0.5 text-xs text-slate-400">dikendalikan sirkulasi</div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.location?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {row.condition?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={itemStatusTone(row.status)}>
                        {ITEM_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Link
                          to={`/library/items/${row.id}`}
                          className="inline-flex h-8 items-center rounded-lg px-2 text-xs font-medium text-slate-600 hover:bg-slate-100"
                        >
                          Detail
                        </Link>
                        <Can perm="library.item.update">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => {
                              setEditing(row);
                              setServerErrors({});
                              setFormOpen(true);
                            }}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                        </Can>
                        <Can perm="library.item.delete">
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(row)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <ItemFormModal
        open={formOpen}
        editing={editing}
        bibliographyId={contextBibId !== '' ? contextBibId : undefined}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload, bibId) => saveMutation.mutate({ payload, bibId })}
      />

      <ItemBulkModal
        open={bulkOpen}
        libraryId={contextLibraryId}
        submitting={bulkMutation.isPending}
        serverErrors={bulkErrors}
        onClose={() => setBulkOpen(false)}
        onSubmit={(payload) => {
          if (contextBibId === '') return;
          bulkMutation.mutate({ bibId: contextBibId, payload });
        }}
      />

      <BulkAssignModal
        open={assignOpen}
        count={selected.size}
        libraryId={selectedLibraryId}
        submitting={assignMutation.isPending}
        serverErrors={assignErrors}
        onClose={() => setAssignOpen(false)}
        onSubmit={(payload) => assignMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Eksemplar"
        message={`Hapus eksemplar "${deleting?.item_code ?? ''}"? Eksemplar yang sedang dalam sirkulasi tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}