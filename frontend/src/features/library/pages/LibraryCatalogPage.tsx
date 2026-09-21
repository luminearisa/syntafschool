import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { BookOpen, Eye, Pencil, Plus, Power, PowerOff, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryCatalogApi, libraryMastersApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import {
  BIBLIOGRAPHY_STATUS_LABELS,
  availabilitySummary,
  bibliographyStatusTone,
} from '../lib/catalogLabels';
import { toOptions } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  BibliographyCreator,
  BibliographyStatus,
  LibraryBibliography,
} from '@/types/libraryCatalog';

const statusOptions = toOptions(BIBLIOGRAPHY_STATUS_LABELS);

/** Pisahkan nama kreator dan perannya agar ringkas di kolom tabel. */
function creatorParts(creators?: BibliographyCreator[]): string[] {
  if (!creators) return [];
  return creators
    .map((c) => (c.role ? `${c.name ?? ''} (${c.role})` : c.name ?? ''))
    .filter((v) => v.trim() !== '');
}

/** Alasan error paling informatif (validasi 422 diutamakan). */
function describeError(err: unknown): string {
  const errors = getValidationErrors(err);
  const first = errors ? Object.values(errors)[0] : undefined;
  if (first && first.length > 0) return first[0];
  return getErrorMessage(err);
}

/**
 * Pemilih master data Library (categories, collection-types, ...).
 * Opsi diambil dari `libraryMastersApi.list(key, { all: true })`.
 */
function MasterFilterSelect({
  masterKey,
  value,
  placeholder = '-- Semua --',
  onChange,
}: {
  masterKey: string;
  value: number | '';
  placeholder?: string;
  onChange: (value: number | '') => void;
}) {
  const { data } = useQuery({
    queryKey: ['library', 'masters', masterKey, 'options'],
    staleTime: 1000 * 60 * 5,
    queryFn: () => libraryMastersApi.list(masterKey, { all: true }),
  });

  const options = useMemo(
    () =>
      (data?.data ?? []).map((row) => ({
        value: row.id,
        label: row.code ? `${row.code} — ${row.name}` : row.name,
      })),
    [data],
  );

  return (
    <SearchSelect
      options={options}
      value={value}
      placeholder={placeholder}
      onChange={(v) => onChange(v === '' ? '' : Number(v))}
    />
  );
}

/**
 * Daftar bibliografi (katalog).
 *
 * Satu baris di sini adalah satu **record bibliografi**, bukan satu buku fisik —
 * jumlah dan ketersediaan eksemplar ditampilkan sebagai ringkasan (`items_count`).
 */
export function LibraryCatalogPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<BibliographyStatus | ''>('');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [categoryId, setCategoryId] = useState<number | ''>('');
  const [collectionTypeId, setCollectionTypeId] = useState<number | ''>('');
  const [languageId, setLanguageId] = useState<number | ''>('');
  const [yearFrom, setYearFrom] = useState<number | ''>('');
  const [yearTo, setYearTo] = useState<number | ''>('');

  const [selected, setSelected] = useState<number[]>([]);
  const [deleting, setDeleting] = useState<LibraryBibliography | null>(null);

  const { options: libraryOptions } = useLibraryOptions();

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (status) out.status = status;
    if (libraryId !== '') out.library_id = Number(libraryId);
    if (categoryId !== '') out.category_id = Number(categoryId);
    if (collectionTypeId !== '') out.collection_type_id = Number(collectionTypeId);
    if (languageId !== '') out.language_id = Number(languageId);
    return out;
  }, [status, libraryId, categoryId, collectionTypeId, languageId]);

  const listQuery = useQuery({
    queryKey: ['library', 'catalog', page, search, filters, yearFrom, yearTo],
    queryFn: () =>
      libraryCatalogApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
        year_from: yearFrom === '' ? undefined : Number(yearFrom),
        year_to: yearTo === '' ? undefined : Number(yearTo),
      }),
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['library', 'catalog'] });

  const bulkMutation = useMutation({
    mutationFn: ({ ids, next }: { ids: number[]; next: BibliographyStatus }) =>
      libraryCatalogApi.bulkStatus(ids, next),
    onSuccess: (res) => {
      toast.success(res.message);
      setSelected([]);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => libraryCatalogApi.remove(id),
    onSuccess: (_res, id) => {
      toast.success('Katalog dihapus');
      setDeleting(null);
      setSelected((prev) => prev.filter((x) => x !== id));
      invalidate();
    },
    onError: (err) => {
      // 422 "masih memiliki N eksemplar" muncul sebagai error validasi pertama.
      toast.error(describeError(err));
      setDeleting(null);
    },
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const allSelected = rows.length > 0 && rows.every((row) => selected.includes(row.id));

  const toggleAll = () => {
    if (allSelected) {
      setSelected((prev) => prev.filter((id) => !rows.some((r) => r.id === id)));
    } else {
      setSelected((prev) => [...new Set([...prev, ...rows.map((r) => r.id)])]);
    }
  };

  const toggleRow = (id: number) =>
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Katalog Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Record bibliografi (judul) — satu judul bisa memiliki banyak eksemplar.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.catalog.create">
            <Link to="/library/catalog/new">
              <Button>
                <Plus className="h-4 w-4" /> Tambah Katalog
              </Button>
            </Link>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="Judul, kreator, atau ISBN..."
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
                setStatus(e.target.value as BibliographyStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {statusOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
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
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Kategori</label>
            <MasterFilterSelect
              masterKey="categories"
              value={categoryId}
              onChange={(v) => {
                setCategoryId(v);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis Koleksi</label>
            <MasterFilterSelect
              masterKey="collection-types"
              value={collectionTypeId}
              onChange={(v) => {
                setCollectionTypeId(v);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Bahasa</label>
            <MasterFilterSelect
              masterKey="languages"
              value={languageId}
              onChange={(v) => {
                setLanguageId(v);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tahun Dari</label>
            <Input
              type="number"
              value={yearFrom === '' ? '' : String(yearFrom)}
              placeholder="mis. 2000"
              onChange={(e) => {
                setYearFrom(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tahun Sampai</label>
            <Input
              type="number"
              value={yearTo === '' ? '' : String(yearTo)}
              placeholder="mis. 2025"
              onChange={(e) => {
                setYearTo(e.target.value === '' ? '' : Number(e.target.value));
                setPage(1);
              }}
            />
          </div>
        </div>
      </Card>

      {selected.length > 0 && (
        <Can perm="library.catalog.update">
          <Card className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
            <span className="text-sm text-slate-600">{selected.length} katalog dipilih</span>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                loading={bulkMutation.isPending}
                onClick={() => bulkMutation.mutate({ ids: selected, next: 'active' })}
              >
                <Power className="h-3.5 w-3.5" /> Aktifkan
              </Button>
              <Button
                size="sm"
                variant="outline"
                loading={bulkMutation.isPending}
                onClick={() => bulkMutation.mutate({ ids: selected, next: 'inactive' })}
              >
                <PowerOff className="h-3.5 w-3.5" /> Nonaktifkan
              </Button>
            </div>
          </Card>
        </Can>
      )}

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat katalog...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat katalog"
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
            title="Belum ada katalog"
            description="Tambahkan record bibliografi pertama untuk mulai mengoleksi eksemplar."
            action={
              <Can perm="library.catalog.create">
                <Link to="/library/catalog/new">
                  <Button size="sm" className="mt-2">
                    <Plus className="h-4 w-4" /> Tambah Katalog
                  </Button>
                </Link>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="w-10 px-4 py-2">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300"
                      checked={allSelected}
                      onChange={toggleAll}
                    />
                  </th>
                  <th className="w-14 px-4 py-2 font-medium">Cover</th>
                  <th className="px-4 py-2 font-medium">No. Kontrol</th>
                  <th className="px-4 py-2 font-medium">Judul</th>
                  <th className="px-4 py-2 font-medium">Kreator</th>
                  <th className="px-4 py-2 font-medium">Penerbit</th>
                  <th className="px-3 py-2 text-center font-medium">Tahun</th>
                  <th className="px-4 py-2 font-medium">Jenis Koleksi</th>
                  <th className="px-4 py-2 font-medium">Ketersediaan</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => {
                  const parts = creatorParts(row.creators);
                  return (
                    <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                      <td className="px-4 py-2">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300"
                          checked={selected.includes(row.id)}
                          onChange={() => toggleRow(row.id)}
                        />
                      </td>
                      <td className="px-4 py-2">
                        {row.cover_image ? (
                          <img
                            src={row.cover_image}
                            alt={row.title}
                            className="h-12 w-9 rounded object-cover"
                          />
                        ) : (
                          <div className="flex h-12 w-9 items-center justify-center rounded bg-slate-100 text-slate-400">
                            <BookOpen className="h-4 w-4" />
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-2 font-mono text-xs text-slate-600">
                        {row.control_number}
                      </td>
                      <td className="max-w-[18rem] px-4 py-2">
                        <Link
                          to={`/library/catalog/${row.id}`}
                          className="font-medium text-slate-800 hover:text-brand-600 hover:underline"
                        >
                          {row.title}
                        </Link>
                        {row.subtitle && (
                          <div className="mt-0.5 text-xs text-slate-400">{row.subtitle}</div>
                        )}
                      </td>
                      <td className="max-w-[14rem] px-4 py-2 text-xs text-slate-600">
                        {parts.length === 0 ? (
                          '—'
                        ) : (
                          <>
                            {parts.slice(0, 2).join(', ')}
                            {parts.length > 2 && (
                              <span className="text-slate-400"> +{parts.length - 2}</span>
                            )}
                          </>
                        )}
                      </td>
                      <td className="px-4 py-2 text-slate-600">{row.publisher?.name ?? '—'}</td>
                      <td className="px-3 py-2 text-center text-slate-700">
                        {row.publication_year ?? '—'}
                      </td>
                      <td className="px-4 py-2 text-slate-600">{row.collection_type?.name ?? '—'}</td>
                      <td className="px-4 py-2 text-xs text-slate-600">
                        {availabilitySummary(row.available_items_count, row.items_count)}
                      </td>
                      <td className="px-4 py-2">
                        <Badge tone={bibliographyStatusTone(row.status)}>
                          {BIBLIOGRAPHY_STATUS_LABELS[row.status] ?? row.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-2">
                        <div className="flex flex-wrap items-center gap-1">
                          <Link
                            to={`/library/catalog/${row.id}`}
                            className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
                            title="Detail"
                          >
                            <Eye className="h-3.5 w-3.5" /> Detail
                          </Link>
                          <Can perm="library.catalog.update">
                            <Link
                              to={`/library/catalog/${row.id}/edit`}
                              className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs text-slate-600 hover:bg-slate-100"
                              title="Edit"
                            >
                              <Pencil className="h-3.5 w-3.5" /> Edit
                            </Link>
                          </Can>
                          <Can perm="library.catalog.delete">
                            <Button
                              variant="ghost"
                              size="sm"
                              title="Hapus"
                              onClick={() => setDeleting(row)}
                            >
                              <Trash2 className="h-3.5 w-3.5 text-red-500" /> Hapus
                            </Button>
                          </Can>
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

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Katalog"
        message={`Hapus "${deleting?.title ?? ''}" (${deleting?.control_number ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}