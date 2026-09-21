import { useEffect, useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { BookOpen, Library, Search } from 'lucide-react';
import { libraryOpacApi } from '../api/libraryApi';
import { availabilitySummary } from '../lib/catalogLabels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { getErrorMessage } from '@/lib/axios';
import type { OpacCreator, OpacListRow } from '@/types/libraryCatalog';

const SORT_OPTIONS = [
  { value: 'title:asc', label: 'Judul A–Z' },
  { value: 'title:desc', label: 'Judul Z–A' },
  { value: 'publication_year:desc', label: 'Tahun terbaru' },
  { value: 'publication_year:asc', label: 'Tahun terlama' },
] as const;

const PER_PAGE = 12;

type AvailabilityFilter = '' | 'available' | 'unavailable';

/** Gabungkan kreator dengan perannya, mis. "Budi (Penulis)". */
function creatorsLine(creators: OpacCreator[]): string {
  return creators
    .map((c) => {
      if (c.name && c.role) return `${c.name} (${c.role})`;
      return c.name ?? c.role ?? '';
    })
    .filter(Boolean)
    .join(', ');
}

/** Baris ketersediaan; jatuh ke jumlah eksemplar bila ringkasan tidak ada. */
function availabilityLine(row: OpacListRow): string {
  if (row.availability) {
    return availabilitySummary(row.availability.available, row.availability.total_copies);
  }
  if (typeof row.items_count === 'number') {
    return availabilitySummary(row.available_items_count ?? 0, row.items_count);
  }
  return 'Ketersediaan belum tersedia';
}

/** Header ringkas untuk halaman publik (di luar cangkang aplikasi). */
function OpacHeader() {
  return (
    <header className="border-b border-slate-200 bg-white">
      <div className="mx-auto flex max-w-5xl items-center gap-2 px-4 py-4">
        <Library className="h-6 w-6 text-brand-600" />
        <Link to="/library/opac" className="text-base font-semibold text-slate-900">
          Katalog Perpustakaan
        </Link>
        <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
          OPAC
        </span>
      </div>
    </header>
  );
}

/**
 * Katalog publik (OPAC) — daftar koleksi.
 *
 * Halaman publik di luar cangkang aplikasi: tanpa permission gate, tanpa
 * asumsi pengguna login. Seluruh kata kunci & filter disimpan pada query
 * string agar hasil pencarian dapat dibagikan.
 */
export function LibraryOpacPage() {
  const [params, setParams] = useSearchParams();

  const urlSearch = params.get('search') ?? '';
  const [term, setTerm] = useState(urlSearch);

  const availabilityRaw = params.get('availability') ?? '';
  const availability: AvailabilityFilter =
    availabilityRaw === 'available' || availabilityRaw === 'unavailable' ? availabilityRaw : '';

  const yearFrom = params.get('year_from') ?? '';
  const yearTo = params.get('year_to') ?? '';

  const rawSort = params.get('sort') ?? 'title:asc';
  const sortValue = SORT_OPTIONS.some((o) => o.value === rawSort) ? rawSort : 'title:asc';
  const [sortBy, sortDir] = sortValue.split(':') as [string, 'asc' | 'desc'];

  const page = Math.max(1, Number(params.get('page') ?? '1') || 1);

  // Sinkronkan kotak pencarian bila URL berubah (mis. tombol kembali).
  useEffect(() => {
    setTerm(urlSearch);
  }, [urlSearch]);

  const patch = (updates: Record<string, string | null>) => {
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        Object.entries(updates).forEach(([key, value]) => {
          if (value === null || value === '') next.delete(key);
          else next.set(key, value);
        });
        return next;
      },
      { replace: true },
    );
  };

  const listQuery = useQuery({
    queryKey: ['library', 'opac', 'search', urlSearch, availability, yearFrom, yearTo, sortBy, sortDir, page],
    queryFn: () =>
      libraryOpacApi.search({
        page,
        per_page: PER_PAGE,
        ...(urlSearch ? { search: urlSearch } : {}),
        ...(availability ? { availability } : {}),
        ...(yearFrom ? { year_from: Number(yearFrom) } : {}),
        ...(yearTo ? { year_to: Number(yearTo) } : {}),
        sort_by: sortBy,
        sort_dir: sortDir,
      }),
  });

  const rows = (listQuery.data?.data ?? []) as OpacListRow[];
  const meta = listQuery.data?.meta;

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    patch({ search: term.trim() || null, page: null });
  };

  return (
    <div className="min-h-screen bg-slate-50">
      <OpacHeader />

      <main className="mx-auto max-w-5xl px-4 py-6">
        <div className="mb-4">
          <h1 className="text-lg font-semibold text-slate-900">Katalog Publik</h1>
          <p className="text-sm text-slate-500">
            Telusuri koleksi perpustakaan berdasarkan judul, penulis, ISBN, subjek, penerbit, atau
            nomor klasifikasi.
          </p>
        </div>

        <Card className="p-4">
          <form onSubmit={handleSearch} className="flex flex-col gap-2 sm:flex-row">
            <Input
              value={term}
              onChange={(e) => setTerm(e.target.value)}
              placeholder="Cari judul, penulis, ISBN, subjek, penerbit, atau klasifikasi..."
              className="h-11 text-base"
              aria-label="Kata kunci pencarian"
            />
            <Button type="submit" size="lg" className="shrink-0">
              <Search className="h-4 w-4" />
              Cari
            </Button>
          </form>

          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <Label htmlFor="opac-availability">Ketersediaan</Label>
              <Select
                id="opac-availability"
                value={availability}
                onChange={(e) => patch({ availability: e.target.value, page: null })}
              >
                <option value="">Semua</option>
                <option value="available">Tersedia</option>
                <option value="unavailable">Tidak tersedia</option>
              </Select>
            </div>
            <div>
              <Label htmlFor="opac-year-from">Tahun dari</Label>
              <Input
                id="opac-year-from"
                type="number"
                inputMode="numeric"
                min={1000}
                max={2100}
                value={yearFrom}
                placeholder="mis. 2000"
                onChange={(e) => patch({ year_from: e.target.value, page: null })}
              />
            </div>
            <div>
              <Label htmlFor="opac-year-to">Tahun sampai</Label>
              <Input
                id="opac-year-to"
                type="number"
                inputMode="numeric"
                min={1000}
                max={2100}
                value={yearTo}
                placeholder="mis. 2024"
                onChange={(e) => patch({ year_to: e.target.value, page: null })}
              />
            </div>
            <div>
              <Label htmlFor="opac-sort">Urutkan</Label>
              <Select
                id="opac-sort"
                value={sortValue}
                onChange={(e) => patch({ sort: e.target.value, page: null })}
              >
                {SORT_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </div>
          </div>
        </Card>

        <div className="mt-4">
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
                  <Button
                    size="sm"
                    variant="outline"
                    className="mt-2"
                    onClick={() => listQuery.refetch()}
                  >
                    Coba lagi
                  </Button>
                }
              />
            </Card>
          ) : rows.length === 0 ? (
            <Card>
              <EmptyState
                title="Koleksi tidak ditemukan"
                description="Coba kata kunci lain atau ubah filter tahun dan ketersediaan."
              />
            </Card>
          ) : (
            <>
              <p className="mb-2 text-xs text-slate-500">
                <span className="font-medium text-slate-700">{meta?.total ?? rows.length}</span>{' '}
                koleksi ditemukan
              </p>

              <Card className="divide-y divide-slate-100 overflow-hidden">
                {rows.map((row) => {
                  const creators = creatorsLine(row.creators);
                  const available = row.availability
                    ? row.availability.is_available
                    : (row.available_items_count ?? 0) > 0;
                  const cover = row.thumbnail || row.cover_image;

                  return (
                    <Link
                      key={row.slug}
                      to={`/library/opac/${row.slug}`}
                      className="flex gap-4 p-4 transition-colors hover:bg-slate-50"
                    >
                      <div className="shrink-0">
                        {cover ? (
                          <img
                            src={cover}
                            alt={row.title}
                            className="h-24 w-16 rounded object-cover"
                          />
                        ) : (
                          <div className="flex h-24 w-16 items-center justify-center rounded bg-slate-100 text-slate-400">
                            <BookOpen className="h-6 w-6" />
                          </div>
                        )}
                      </div>

                      <div className="min-w-0 flex-1">
                        <h2 className="font-medium text-slate-800">{row.title}</h2>
                        {row.subtitle && (
                          <p className="mt-0.5 text-xs text-slate-500">{row.subtitle}</p>
                        )}
                        {creators && (
                          <p className="mt-1 text-xs text-slate-600">{creators}</p>
                        )}
                        <p className="mt-1 text-xs text-slate-500">
                          {row.publisher ?? '—'} · {row.publication_year ?? '—'}
                        </p>

                        {(row.category || row.language || row.collection_type) && (
                          <div className="mt-2 flex flex-wrap gap-1">
                            {row.category && <Badge tone="blue">{row.category}</Badge>}
                            {row.collection_type && <Badge tone="brand">{row.collection_type}</Badge>}
                            {row.language && <Badge tone="gray">{row.language}</Badge>}
                          </div>
                        )}

                        <p
                          className={
                            available
                              ? 'mt-2 text-xs font-medium text-green-700'
                              : 'mt-2 text-xs font-medium text-slate-500'
                          }
                        >
                          {availabilityLine(row)}
                        </p>
                      </div>
                    </Link>
                  );
                })}
              </Card>

              {meta && (
                <Card className="mt-3">
                  <Pagination meta={meta} onPageChange={(p) => patch({ page: String(p) })} />
                </Card>
              )}
            </>
          )}
        </div>

        <p className="mt-6 text-center text-xs text-slate-400">
          Ini katalog publik perpustakaan — hanya koleksi yang telah dipublikasikan yang ditampilkan.
        </p>
      </main>
    </div>
  );
}