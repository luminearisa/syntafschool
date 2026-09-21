import type { ReactNode } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, BookOpen, Library, Printer } from 'lucide-react';
import { libraryOpacApi } from '../api/libraryApi';
import { availabilitySummary, identifierTypeLabel } from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import type { OpacAvailability, OpacCreator, OpacRecord } from '@/types/libraryCatalog';

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

function Field({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div>
      <p className="text-xs font-medium text-slate-400">{label}</p>
      <div className="mt-0.5 text-sm text-slate-700">{value}</div>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-0.5 text-lg font-semibold text-slate-800">{value}</p>
    </div>
  );
}

/**
 * Detail koleksi publik (OPAC).
 *
 * Publik & tanpa cangkang aplikasi. Hanya memuat data yang memang tersedia
 * pada payload OPAC — tanpa id internal, harga, catatan, atau data peminjam.
 */
export function LibraryOpacDetailPage() {
  const { slug } = useParams<{ slug: string }>();

  const detailQuery = useQuery({
    queryKey: ['library', 'opac', 'detail', slug],
    queryFn: () => libraryOpacApi.show(slug as string),
    enabled: Boolean(slug),
    retry: false,
  });

  const detail = detailQuery.data;
  const needsAvailability = detail?.availability === null;

  const availabilityQuery = useQuery({
    queryKey: ['library', 'opac', 'availability', slug],
    queryFn: () => libraryOpacApi.availability(slug as string),
    enabled: Boolean(slug) && needsAvailability,
    retry: false,
  });

  const status = (detailQuery.error as { response?: { status?: number } } | null)?.response
    ?.status;
  const isNotFound = status === 404;

  return (
    <div className="min-h-screen bg-slate-50">
      <OpacHeader />

      <main className="mx-auto max-w-5xl px-4 py-6">
        {detailQuery.isLoading ? (
          <Card>
            <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
              <Spinner /> Memuat detail koleksi...
            </div>
          </Card>
        ) : detailQuery.isError || !detail ? (
          <Card>
            {isNotFound ? (
              <EmptyState
                title="Koleksi tidak ditemukan"
                description="Koleksi ini tidak tersedia pada katalog publik atau telah dihapus."
                action={
                  <Link to="/library/opac" className="mt-2">
                    <Button size="sm" variant="outline">
                      <ArrowLeft className="h-4 w-4" />
                      Kembali ke katalog
                    </Button>
                  </Link>
                }
              />
            ) : (
              <EmptyState
                title="Gagal memuat detail koleksi"
                description={getErrorMessage(detailQuery.error)}
                action={
                  <Button
                    size="sm"
                    variant="outline"
                    className="mt-2"
                    onClick={() => detailQuery.refetch()}
                  >
                    Coba lagi
                  </Button>
                }
              />
            )}
          </Card>
        ) : (
          <DetailBody
            slug={slug ?? detail.slug}
            detail={detail}
            availability={detail.availability ?? availabilityQuery.data ?? null}
            availabilityLoading={needsAvailability && availabilityQuery.isLoading}
          />
        )}
      </main>
    </div>
  );
}

function DetailBody({
  slug,
  detail,
  availability,
  availabilityLoading,
}: {
  slug: string;
  detail: OpacRecord;
  availability: OpacAvailability | null;
  availabilityLoading: boolean;
}) {
  const cover = detail.cover_image || detail.thumbnail;
  const creators: OpacCreator[] = [...detail.creators].sort(
    (a, b) => Number(b.is_primary) - Number(a.is_primary),
  );

  const hasDescription = Boolean(
    detail.abstract || detail.description || detail.table_of_contents,
  );

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between gap-3 print:hidden">
        <Link
          to="/library/opac"
          className="inline-flex items-center gap-1 text-sm text-slate-600 hover:text-brand-600"
        >
          <ArrowLeft className="h-4 w-4" />
          Kembali ke katalog
        </Link>
        <Button variant="outline" size="sm" onClick={() => window.print()}>
          <Printer className="h-4 w-4" />
          Cetak
        </Button>
      </div>

      <Card className="p-5">
        <div className="flex flex-col gap-4 sm:flex-row">
          <div className="shrink-0">
            {cover ? (
              <img
                src={cover}
                alt={detail.title}
                className="mx-auto h-48 w-32 rounded object-cover sm:mx-0"
              />
            ) : (
              <div className="mx-auto flex h-48 w-32 items-center justify-center rounded bg-slate-100 text-slate-400 sm:mx-0">
                <BookOpen className="h-8 w-8" />
              </div>
            )}
          </div>

          <div className="min-w-0 flex-1">
            <h1 className="text-xl font-semibold text-slate-900">{detail.title}</h1>
            {detail.subtitle && <p className="mt-1 text-sm text-slate-500">{detail.subtitle}</p>}

            {creators.length > 0 && (
              <ul className="mt-3 space-y-0.5">
                {creators.map((c, index) => (
                  <li key={`${c.name ?? 'creator'}-${index}`} className="text-sm text-slate-700">
                    {c.name ?? 'Tanpa nama'}
                    {c.role && <span className="text-slate-500"> ({c.role})</span>}
                    {c.is_primary && (
                      <Badge tone="brand" className="ml-2">
                        Utama
                      </Badge>
                    )}
                  </li>
                ))}
              </ul>
            )}

            <p className="mt-3 text-sm text-slate-500">
              {orDash(detail.publisher)} · {orDash(detail.publication_year)}
            </p>

            <div className="mt-3 flex flex-wrap gap-1">
              {detail.physical_available && <Badge tone="green">Eksemplar fisik</Badge>}
              {detail.digital_available && <Badge tone="blue">Digital</Badge>}
              {detail.category && <Badge tone="blue">{detail.category}</Badge>}
              {detail.language && <Badge tone="gray">{detail.language}</Badge>}
            </div>

            {detail.call_number && (
              <p className="mt-3 font-mono text-xs text-slate-500">
                No. Panggil: {detail.call_number}
              </p>
            )}
          </div>
        </div>
      </Card>

      <Card className="p-5">
        <h2 className="mb-4 text-base font-semibold text-slate-900">Informasi Bibliografi</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Penerbit" value={orDash(detail.publisher)} />
          <Field label="Tempat Terbit" value={orDash(detail.publication_place)} />
          <Field label="Tahun Terbit" value={orDash(detail.publication_year)} />
          <Field label="Edisi" value={orDash(detail.edition)} />
          <Field label="Bahasa" value={orDash(detail.language)} />
          <Field label="Jenis Koleksi" value={orDash(detail.collection_type)} />
          <Field label="Kategori" value={orDash(detail.category)} />
          <Field label="Jenis Materi" value={orDash(detail.material_type)} />
          <Field label="Seri" value={orDash(detail.series)} />
          <Field label="Klasifikasi" value={orDash(detail.classification)} />
          <Field label="Nomor Panggil" value={orDash(detail.call_number)} />
        </div>

        {(detail.subjects.length > 0 || detail.genres.length > 0 || detail.tags.length > 0) && (
          <div className="mt-5 space-y-3">
            {detail.subjects.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Subjek</p>
                <div className="flex flex-wrap gap-1">
                  {detail.subjects.map((s) => (
                    <Badge key={s} tone="blue">
                      {s}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
            {detail.genres.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Genre</p>
                <div className="flex flex-wrap gap-1">
                  {detail.genres.map((g) => (
                    <Badge key={g} tone="brand">
                      {g}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
            {detail.tags.length > 0 && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Tag</p>
                <div className="flex flex-wrap gap-1">
                  {detail.tags.map((t) => (
                    <Badge key={t} tone="gray">
                      {t}
                    </Badge>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}

        <div className="mt-5">
          <h3 className="mb-2 text-sm font-semibold text-slate-800">Identifiers</h3>
          {detail.identifiers.length === 0 ? (
            <p className="text-sm text-slate-500">—</p>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="px-3 py-2 font-medium">Jenis</th>
                    <th className="px-3 py-2 font-medium">Nilai</th>
                  </tr>
                </thead>
                <tbody>
                  {detail.identifiers.map((identifier, index) => (
                    <tr
                      key={`${identifier.type}-${identifier.value}-${index}`}
                      className="border-b border-slate-50 last:border-0"
                    >
                      <td className="px-3 py-2 text-slate-600">
                        {identifierTypeLabel(identifier.type)}
                      </td>
                      <td className="px-3 py-2 font-mono text-xs text-slate-700">
                        {identifier.value}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      </Card>

      {hasDescription && (
        <Card className="p-5">
          <h2 className="mb-4 text-base font-semibold text-slate-900">Deskripsi</h2>
          <div className="space-y-4">
            {detail.abstract && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Abstrak</p>
                <p className="whitespace-pre-line text-sm text-slate-700">{detail.abstract}</p>
              </div>
            )}
            {detail.description && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Deskripsi</p>
                <p className="whitespace-pre-line text-sm text-slate-700">{detail.description}</p>
              </div>
            )}
            {detail.table_of_contents && (
              <div>
                <p className="mb-1 text-xs font-medium text-slate-400">Daftar Isi</p>
                <p className="whitespace-pre-line text-sm text-slate-700">
                  {detail.table_of_contents}
                </p>
              </div>
            )}
          </div>
        </Card>
      )}

      <Card className="p-5">
        <h2 className="mb-4 text-base font-semibold text-slate-900">Ketersediaan</h2>

        {availabilityLoading && !availability ? (
          <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
            <Spinner /> Memuat ketersediaan...
          </div>
        ) : availability ? (
          <>
            <p className="mb-3 text-sm font-medium text-slate-700">
              {availabilitySummary(availability.available, availability.total_copies)}
              {availability.is_available ? (
                <Badge tone="green" className="ml-2">
                  Tersedia
                </Badge>
              ) : (
                <Badge tone="amber" className="ml-2">
                  Tidak tersedia
                </Badge>
              )}
            </p>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
              <Stat label="Total Eksemplar" value={availability.total_copies} />
              <Stat label="Tersedia" value={availability.available} />
              <Stat label="Dipinjam" value={availability.on_loan} />
              <Stat label="Hanya Baca di Tempat" value={availability.reference_only} />
              <Stat label="Tidak Tersedia" value={availability.unavailable} />
            </div>

            {detail.locations.length > 0 && (
              <div className="mt-4">
                <p className="mb-1 text-xs font-medium text-slate-400">Lokasi</p>
                <ul className="space-y-1">
                  {detail.locations.map((location) => (
                    <li key={`${location.name}-${location.type}`} className="text-sm text-slate-700">
                      <span className="text-slate-500">Rak/Lokasi:</span> {location.name} —{' '}
                      {location.copies} eksemplar
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </>
        ) : (
          <p className="text-sm text-slate-500">Data ketersediaan belum tersedia.</p>
        )}
      </Card>

      <p className="text-center text-xs text-slate-400">
        Sumber: {slug} · Katalog publik perpustakaan
      </p>
    </div>
  );
}