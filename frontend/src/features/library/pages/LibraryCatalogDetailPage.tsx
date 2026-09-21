import { useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, Eye, Pencil, Power, PowerOff, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { api, getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import { libraryCatalogApi, libraryItemsApi } from '../api/libraryApi';
import {
  BIBLIOGRAPHY_STATUS_LABELS,
  IDENTIFIER_TYPE_LABELS,
  ITEM_STATUS_LABELS,
  availabilitySummary,
  bibliographyStatusTone,
  itemStatusTone,
} from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { cn } from '@/lib/utils';
import type {
  BibliographyCreator,
  BibliographyStatus,
  ItemStatus,
  OpacRecord,
} from '@/types/libraryCatalog';

type TabKey = 'biblio' | 'items' | 'availability' | 'opac';

const TABS: { key: TabKey; label: string }[] = [
  { key: 'biblio', label: 'Bibliografi' },
  { key: 'items', label: 'Eksemplar' },
  { key: 'availability', label: 'Ketersediaan' },
  { key: 'opac', label: 'Pratinjau OPAC' },
];

const itemStatusOptions = Object.keys(ITEM_STATUS_LABELS) as ItemStatus[];

function creatorLabel(c: BibliographyCreator): string {
  const name = c.name ?? '—';
  return c.role ? `${name} (${c.role})` : name;
}

/** Ambil payload publik untuk pratinjau OPAC. */
async function fetchOpacPreview(id: number): Promise<OpacRecord> {
  const res = await api.get<ApiResponse<OpacRecord>>(`/library/catalog/${id}/opac-preview`);
  return res.data.data;
}

function InfoRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium text-slate-400">{label}</p>
      <div className="mt-0.5 text-sm text-slate-700">{children}</div>
    </div>
  );
}

/** Tampilan payload publik — dipakai di modal dan tab Pratinjau OPAC. */
function OpacPreview({ data }: { data: OpacRecord }) {
  return (
    <div className="space-y-4">
      <div className="flex gap-4">
        {data.cover_image ? (
          <img
            src={data.cover_image}
            alt={data.title}
            className="h-32 w-24 shrink-0 rounded object-cover"
          />
        ) : (
          <div className="flex h-32 w-24 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400">
            <BookOpen className="h-6 w-6" />
          </div>
        )}
        <div>
          <h3 className="text-base font-semibold text-slate-900">{data.title}</h3>
          {data.subtitle && <p className="text-sm text-slate-500">{data.subtitle}</p>}
          <p className="mt-1 text-xs text-slate-400">
            {orDash(data.publisher)} · {orDash(data.publication_year)}
          </p>
          {data.call_number && (
            <p className="mt-1 font-mono text-xs text-slate-500">{data.call_number}</p>
          )}
        </div>
      </div>

      {data.creators.length > 0 && (
        <InfoRow label="Kreator">
          {data.creators.map((c, i) => (
            <div key={i}>
              {c.name ?? '—'}
              {c.role ? ` (${c.role})` : ''}
            </div>
          ))}
        </InfoRow>
      )}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <InfoRow label="Bahasa">{orDash(data.language)}</InfoRow>
        <InfoRow label="Jenis Koleksi">{orDash(data.collection_type)}</InfoRow>
        <InfoRow label="Kategori">{orDash(data.category)}</InfoRow>
        <InfoRow label="Klasifikasi">{orDash(data.classification)}</InfoRow>
      </div>

      {data.subjects.length > 0 && (
        <InfoRow label="Subjek">
          <div className="flex flex-wrap gap-1">
            {data.subjects.map((s) => (
              <Badge key={s} tone="blue">
                {s}
              </Badge>
            ))}
          </div>
        </InfoRow>
      )}

      {data.abstract && <InfoRow label="Abstrak">{data.abstract}</InfoRow>}
      {data.description && <InfoRow label="Deskripsi">{data.description}</InfoRow>}

      {data.identifiers.length > 0 && (
        <InfoRow label="Identifier">
          <div className="space-y-0.5">
            {data.identifiers.map((i, idx) => (
              <div key={idx} className="text-sm">
                <span className="text-slate-400">
                  {IDENTIFIER_TYPE_LABELS[i.type as keyof typeof IDENTIFIER_TYPE_LABELS] ?? i.type}
                </span>{' '}
                <span className="font-mono">{i.value}</span>
              </div>
            ))}
          </div>
        </InfoRow>
      )}

      {data.availability && (
        <InfoRow label="Ketersediaan">
          {availabilitySummary(data.availability.available, data.availability.total_copies)}
        </InfoRow>
      )}
    </div>
  );
}

/**
 * Detail record bibliografi.
 *
 * Menampilkan bibliografi, daftar eksemplar, ringkasan ketersediaan, dan
 * pratinjau OPAC. Penambahan eksemplar dilakukan di halaman Koleksi
 * (`/library/items`) — record ini tidak memiliki data fisik sendiri.
 */
export function LibraryCatalogDetailPage() {
  const { id: idParam } = useParams<{ id: string }>();
  const id = idParam ? Number(idParam) : null;
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<TabKey>('biblio');
  const [opacModalOpen, setOpacModalOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const detailQuery = useQuery({
    queryKey: ['library', 'catalog', id],
    enabled: id !== null,
    queryFn: () => libraryCatalogApi.show(id as number),
  });

  const itemsQuery = useQuery({
    queryKey: ['library', 'items', 'by-bibliography', id],
    enabled: id !== null,
    queryFn: () => libraryItemsApi.ofBibliography(id as number),
  });

  const availabilityQuery = useQuery({
    queryKey: ['library', 'catalog', id, 'availability'],
    enabled: id !== null,
    queryFn: () => libraryCatalogApi.availability(id as number),
  });

  const opacQuery = useQuery({
    queryKey: ['library', 'catalog', id, 'opac-preview'],
    enabled: id !== null,
    queryFn: () => fetchOpacPreview(id as number),
    retry: false,
  });

  const record = detailQuery.data;

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['library', 'catalog'] });
    queryClient.invalidateQueries({ queryKey: ['library', 'items'] });
  };

  const statusMutation = useMutation({
    mutationFn: (next: BibliographyStatus) =>
      libraryCatalogApi.update(id as number, { title: record?.title ?? '', status: next }),
    onSuccess: () => {
      toast.success('Status katalog diperbarui');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: () => libraryCatalogApi.remove(id as number),
    onSuccess: () => {
      toast.success('Katalog dihapus');
      navigate('/library/catalog');
    },
    onError: (err) => {
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
      setDeleting(false);
    },
  });

  const itemStatusMutation = useMutation({
    mutationFn: ({ itemId, status }: { itemId: number; status: ItemStatus }) =>
      libraryItemsApi.setStatus(itemId, status),
    onSuccess: (res) => {
      toast.success(res.message);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (id === null || Number.isNaN(id)) {
    return (
      <Card>
        <EmptyState title="Katalog tidak ditemukan" description="ID katalog tidak valid." />
      </Card>
    );
  }

  if (detailQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat katalog...
        </div>
      </Card>
    );
  }

  if (detailQuery.isError || !record) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat katalog"
          description={detailQuery.error ? getErrorMessage(detailQuery.error) : undefined}
          action={
            <Button size="sm" variant="outline" className="mt-2" onClick={() => detailQuery.refetch()}>
              Coba lagi
            </Button>
          }
        />
      </Card>
    );
  }

  const items = itemsQuery.data ?? [];
  const availability = availabilityQuery.data;

  return (
    <div className="space-y-4">
      {/* Header */}
      <Card className="p-4">
        <div className="flex flex-col gap-4 sm:flex-row">
          {record.cover_image ? (
            <img
              src={record.cover_image}
              alt={record.title}
              className="h-40 w-28 shrink-0 rounded object-cover"
            />
          ) : (
            <div className="flex h-40 w-28 shrink-0 items-center justify-center rounded bg-slate-100 text-slate-400">
              <BookOpen className="h-8 w-8" />
            </div>
          )}
          <div className="min-w-0 flex-1 space-y-2">
            <div>
              <Link
                to="/library/catalog"
                className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-slate-700"
              >
                <ArrowLeft className="h-3.5 w-3.5" /> Katalog
              </Link>
              <h1 className="text-lg font-semibold text-slate-900">{record.title}</h1>
              {record.subtitle && <p className="text-sm text-slate-500">{record.subtitle}</p>}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
              <span className="font-mono">{record.control_number}</span>
              <span className="text-slate-300">•</span>
              <span className="font-mono">{record.slug}</span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <Badge tone={bibliographyStatusTone(record.status)}>
                {BIBLIOGRAPHY_STATUS_LABELS[record.status] ?? record.status}
              </Badge>
              <Badge tone={record.is_public ? 'green' : 'gray'}>
                {record.is_public ? 'Publik' : 'Privat'}
              </Badge>
              <span className="text-xs text-slate-500">
                {availabilitySummary(record.available_items_count, record.items_count)}
              </span>
            </div>
            <div className="flex flex-wrap items-center gap-2 pt-1">
              <Can perm="library.catalog.update">
                <Link to={`/library/catalog/${record.id}/edit`}>
                  <Button size="sm" variant="outline">
                    <Pencil className="h-3.5 w-3.5" /> Edit
                  </Button>
                </Link>
              </Can>
              <Can perm="library.catalog.update">
                {record.status === 'active' ? (
                  <Button
                    size="sm"
                    variant="outline"
                    loading={statusMutation.isPending}
                    onClick={() => statusMutation.mutate('inactive')}
                  >
                    <PowerOff className="h-3.5 w-3.5" /> Nonaktifkan
                  </Button>
                ) : (
                  <Button
                    size="sm"
                    variant="outline"
                    loading={statusMutation.isPending}
                    onClick={() => statusMutation.mutate('active')}
                  >
                    <Power className="h-3.5 w-3.5" /> Aktifkan
                  </Button>
                )}
              </Can>
              <Can perm="library.opac.view">
                <Button
                  size="sm"
                  variant="outline"
                  disabled={opacQuery.isLoading}
                  onClick={() => setOpacModalOpen(true)}
                >
                  <Eye className="h-3.5 w-3.5" /> Pratinjau OPAC
                </Button>
              </Can>
              <Can perm="library.catalog.delete">
                <Button
                  size="sm"
                  variant="ghost"
                  className="text-red-500"
                  onClick={() => setDeleting(true)}
                >
                  <Trash2 className="h-3.5 w-3.5" /> Hapus
                </Button>
              </Can>
            </div>
          </div>
        </div>
      </Card>

      {/* Tabs */}
      <Card className="p-1">
        <div className="flex flex-wrap gap-1">
          {TABS.map((t) => (
            <button
              key={t.key}
              type="button"
              onClick={() => setTab(t.key)}
              className={cn(
                'rounded-lg px-3 py-1.5 text-sm transition-colors',
                tab === t.key
                  ? 'bg-brand-50 font-medium text-brand-700'
                  : 'text-slate-600 hover:bg-slate-50',
              )}
            >
              {t.label}
            </button>
          ))}
        </div>
      </Card>

      {tab === 'biblio' && (
        <Card className="space-y-4 p-4">
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <InfoRow label="Judul Seragam">{orDash(record.uniform_title)}</InfoRow>
            <InfoRow label="Jenis Koleksi">{orDash(record.collection_type?.name)}</InfoRow>
            <InfoRow label="Jenis Materi">{orDash(record.material_type?.name)}</InfoRow>
            <InfoRow label="Kategori">{orDash(record.category?.name)}</InfoRow>
            <InfoRow label="Bahasa">{orDash(record.language?.name)}</InfoRow>
            <InfoRow label="Seri">{orDash(record.series?.name)}</InfoRow>
            <InfoRow label="Penerbit">{orDash(record.publisher?.name)}</InfoRow>
            <InfoRow label="Tempat Terbit">{orDash(record.publication_place)}</InfoRow>
            <InfoRow label="Tahun Terbit">{orDash(record.publication_year)}</InfoRow>
            <InfoRow label="Pernyataan Edisi">{orDash(record.edition_statement)}</InfoRow>
            <InfoRow label="Nomor Edisi">{orDash(record.edition_number)}</InfoRow>
            <InfoRow label="Tahun Edisi">{orDash(record.edition_year)}</InfoRow>
            <InfoRow label="Klasifikasi">{orDash(record.classification?.name)}</InfoRow>
            <InfoRow label="Nomor Panggil">{orDash(record.call_number)}</InfoRow>
            <InfoRow label="Ketersediaan Digital">{record.digital_available ? 'Ya' : 'Tidak'}</InfoRow>
            <InfoRow label="Ketersediaan Fisik">{record.physical_available ? 'Ya' : 'Tidak'}</InfoRow>
          </div>

          {/* Kreator */}
          <div className="border-t border-slate-100 pt-3">
            <p className="mb-2 text-sm font-medium text-slate-700">Kreator</p>
            {!record.creators || record.creators.length === 0 ? (
              <p className="text-sm text-slate-400">Belum ada kreator.</p>
            ) : (
              <ul className="space-y-1">
                {record.creators.map((c) => (
                  <li key={c.id} className="flex items-center gap-2 text-sm text-slate-700">
                    <span>{creatorLabel(c)}</span>
                    {c.is_primary && <Badge tone="brand">Utama</Badge>}
                  </li>
                ))}
              </ul>
            )}
          </div>

          {/* Subjek / Genre / Tag */}
          <div className="grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-3">
            <InfoRow label="Subjek">
              <div className="flex flex-wrap gap-1">
                {(record.subjects ?? []).length === 0
                  ? '—'
                  : (record.subjects ?? []).map((s) => (
                      <Badge key={s.id} tone="blue">
                        {s.name}
                      </Badge>
                    ))}
              </div>
            </InfoRow>
            <InfoRow label="Genre">
              <div className="flex flex-wrap gap-1">
                {(record.genres ?? []).length === 0
                  ? '—'
                  : (record.genres ?? []).map((g) => (
                      <Badge key={g.id} tone="green">
                        {g.name}
                      </Badge>
                    ))}
              </div>
            </InfoRow>
            <InfoRow label="Tag">
              <div className="flex flex-wrap gap-1">
                {(record.tags ?? []).length === 0
                  ? '—'
                  : (record.tags ?? []).map((t) => (
                      <Badge key={t.id} tone="gray">
                        {t.name}
                      </Badge>
                    ))}
              </div>
            </InfoRow>
          </div>

          {/* Identifier */}
          <div className="border-t border-slate-100 pt-3">
            <p className="mb-2 text-sm font-medium text-slate-700">Identifier</p>
            {!record.identifiers || record.identifiers.length === 0 ? (
              <p className="text-sm text-slate-400">Belum ada identifier.</p>
            ) : (
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="py-1.5 font-medium">Jenis</th>
                    <th className="py-1.5 font-medium">Nilai</th>
                    <th className="py-1.5 font-medium">Utama</th>
                  </tr>
                </thead>
                <tbody>
                  {record.identifiers.map((i, idx) => (
                    <tr key={i.id ?? idx} className="border-b border-slate-50 last:border-0">
                      <td className="py-1.5 text-slate-600">
                        {IDENTIFIER_TYPE_LABELS[i.identifier_type] ?? i.identifier_type}
                      </td>
                      <td className="py-1.5 font-mono text-slate-700">{i.value}</td>
                      <td className="py-1.5">{i.is_primary ? 'Ya' : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>

          {/* Berkas */}
          <div className="border-t border-slate-100 pt-3">
            <p className="mb-2 text-sm font-medium text-slate-700">Berkas</p>
            {!record.files || record.files.length === 0 ? (
              <p className="text-sm text-slate-400">Belum ada berkas.</p>
            ) : (
              <ul className="space-y-1">
                {record.files.map((f, idx) => (
                  <li key={f.id ?? idx} className="text-sm text-slate-700">
                    <span className="text-slate-400">{f.file_type}</span> —{' '}
                    <span className="font-mono text-xs">{f.file_path}</span>
                    {f.caption && <span className="text-slate-400"> · {f.caption}</span>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </Card>
      )}

      {tab === 'items' && (
        <Card className="p-4">
          <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
              <p className="text-sm font-medium text-slate-700">Eksemplar</p>
              <p className="text-xs text-slate-400">
                Tambah/kelola eksemplar massal di halaman Koleksi.
              </p>
            </div>
            <Can perm="library.item.create">
              <Link to={`/library/items?bibliography_id=${record.id}`}>
                <Button size="sm" variant="outline">
                  Tambah Eksemplar
                </Button>
              </Link>
            </Can>
          </div>

          {itemsQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
              <Spinner /> Memuat eksemplar...
            </div>
          ) : itemsQuery.isError ? (
            <EmptyState
              title="Gagal memuat eksemplar"
              description={getErrorMessage(itemsQuery.error)}
              action={
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={() => itemsQuery.refetch()}
                >
                  Coba lagi
                </Button>
              }
            />
          ) : items.length === 0 ? (
            <EmptyState
              title="Belum ada eksemplar"
              description="Record bibliografi ini belum memiliki salinan fisik."
              action={
                <Can perm="library.item.create">
                  <Link to={`/library/items?bibliography_id=${record.id}`}>
                    <Button size="sm" className="mt-2">
                      Tambah Eksemplar
                    </Button>
                  </Link>
                </Can>
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="py-2 pr-3 font-medium">Kode</th>
                    <th className="py-2 pr-3 font-medium">Aksesi</th>
                    <th className="py-2 pr-3 font-medium">Barcode</th>
                    <th className="py-2 pr-3 font-medium">RFID</th>
                    <th className="py-2 pr-3 font-medium">Lokasi</th>
                    <th className="py-2 pr-3 font-medium">Kondisi</th>
                    <th className="py-2 pr-3 font-medium">Status</th>
                    <th className="py-2 pr-3 font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item) => (
                    <tr key={item.id} className="border-b border-slate-50 last:border-0 align-top">
                      <td className="py-2 pr-3 font-mono text-xs text-slate-600">{item.item_code}</td>
                      <td className="py-2 pr-3 text-slate-600">{orDash(item.accession_number)}</td>
                      <td className="py-2 pr-3 font-mono text-xs text-slate-600">
                        {orDash(item.barcode)}
                      </td>
                      <td className="py-2 pr-3 font-mono text-xs text-slate-600">
                        {orDash(item.rfid_tag)}
                      </td>
                      <td className="py-2 pr-3 text-slate-600">{orDash(item.location?.name)}</td>
                      <td className="py-2 pr-3 text-slate-600">{orDash(item.condition?.name)}</td>
                      <td className="py-2 pr-3">
                        <Badge tone={itemStatusTone(item.status)}>
                          {ITEM_STATUS_LABELS[item.status] ?? item.status}
                        </Badge>
                      </td>
                      <td className="py-2 pr-3">
                        <div className="flex flex-wrap items-center gap-2">
                          <Can perm="library.item.update">
                            <Select
                              className="h-8 w-40 text-xs"
                              value={item.status}
                              onChange={(e) =>
                                itemStatusMutation.mutate({
                                  itemId: item.id,
                                  status: e.target.value as ItemStatus,
                                })
                              }
                            >
                              {itemStatusOptions.map((s) => (
                                <option key={s} value={s}>
                                  {ITEM_STATUS_LABELS[s]}
                                </option>
                              ))}
                            </Select>
                          </Can>
                          <Link
                            to={`/library/items/${item.id}`}
                            className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
                          >
                            <Pencil className="h-3.5 w-3.5" /> Edit
                          </Link>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      {tab === 'availability' && (
        <Card className="p-4">
          {availabilityQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
              <Spinner /> Memuat ketersediaan...
            </div>
          ) : availabilityQuery.isError || !availability ? (
            <EmptyState
              title="Gagal memuat ketersediaan"
              description={
                availabilityQuery.error ? getErrorMessage(availabilityQuery.error) : undefined
              }
              action={
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={() => availabilityQuery.refetch()}
                >
                  Coba lagi
                </Button>
              }
            />
          ) : (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                <InfoRow label="Total">{availability.total_copies}</InfoRow>
                <InfoRow label="Tersedia">{availability.available}</InfoRow>
                <InfoRow label="Tidak Tersedia">{availability.unavailable}</InfoRow>
                <InfoRow label="Dipinjam">{availability.on_loan}</InfoRow>
                <InfoRow label="Hanya Baca di Tempat">{availability.reference_only}</InfoRow>
              </div>
              <div className="border-t border-slate-100 pt-3">
                <p className="mb-2 text-sm font-medium text-slate-700">Sebaran Lokasi</p>
                {availability.locations.length === 0 ? (
                  <p className="text-sm text-slate-400">Belum ada lokasi tercatat.</p>
                ) : (
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th className="py-1.5 font-medium">Lokasi</th>
                        <th className="py-1.5 font-medium">Tipe</th>
                        <th className="py-1.5 font-medium">Eksemplar</th>
                      </tr>
                    </thead>
                    <tbody>
                      {availability.locations.map((loc, idx) => (
                        <tr key={idx} className="border-b border-slate-50 last:border-0">
                          <td className="py-1.5 text-slate-700">{loc.name}</td>
                          <td className="py-1.5 text-slate-500">{loc.type}</td>
                          <td className="py-1.5 text-slate-700">{loc.copies}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>
            </div>
          )}
        </Card>
      )}

      {tab === 'opac' && (
        <Card className="p-4">
          {opacQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
              <Spinner /> Memuat pratinjau...
            </div>
          ) : opacQuery.isError || !opacQuery.data ? (
            <EmptyState
              title="Pratinjau tidak tersedia"
              description={
                opacQuery.error ? getErrorMessage(opacQuery.error) : undefined
              }
              action={
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={() => opacQuery.refetch()}
                >
                  Coba lagi
                </Button>
              }
            />
          ) : (
            <OpacPreview data={opacQuery.data} />
          )}
        </Card>
      )}

      {/* Modal pratinjau OPAC */}
      <Modal
        open={opacModalOpen}
        onClose={() => setOpacModalOpen(false)}
        title="Pratinjau OPAC"
        size="lg"
        footer={
          <Button variant="outline" onClick={() => setOpacModalOpen(false)}>
            Tutup
          </Button>
        }
      >
        {opacQuery.data ? (
          <OpacPreview data={opacQuery.data} />
        ) : (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat pratinjau...
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={deleting}
        title="Hapus Katalog"
        message={`Hapus "${record.title}" (${record.control_number})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(false)}
        onConfirm={() => deleteMutation.mutate()}
      />
    </div>
  );
}