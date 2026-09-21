import { useState, type ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  ArrowLeftRight,
  BookOpen,
  Building2,
  CheckCircle2,
  Clock,
  MapPin,
  RefreshCw,
  Settings2,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { librariesApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import {
  LIBRARY_STATUS_LABELS,
  OPERATING_STATUS_LABELS,
  SETTING_GROUP_LABELS,
  SETUP_STATUS_LABELS,
  checkStatusTone,
  libraryStatusTone,
  operatingStatusTone,
  orDash,
  setupStatusTone,
} from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import type { LibraryDashboard, LibrarySetupCheck } from '@/types/library';
import type { CirculationStats, CirculationSummary } from '@/types/libraryCirculation';

/**
 * Dashboard kini juga mengembalikan blok sirkulasi, denda, reservasi, tren, dan
 * koleksi populer (Fase 4/5). Tipe kontraknya dilengkapi lokal agar berkas
 * tipe bersama tidak perlu diubah.
 */
type DashboardCirculation = {
  circulation: CirculationSummary;
  fines: { outstanding_amount: number; unpaid: number; paid: number };
  reservations: { waiting: number; available: number; open: number };
  trend: CirculationStats['trend'];
  popular: CirculationStats['popular'];
};

type DashboardView = LibraryDashboard & Partial<DashboardCirculation>;

const currencyFormat = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

function formatCurrency(value: number): string {
  return currencyFormat.format(value);
}

function TrendBars({ items }: { items: CirculationStats['trend'] }) {
  const max = Math.max(1, ...items.flatMap((i) => [i.borrowed, i.returned]));

  if (items.length === 0) {
    return <p className="text-sm text-slate-400">Belum ada data tren.</p>;
  }

  return (
    <ul className="space-y-2.5">
      {items.map((item, idx) => (
        <li key={`${item.month}-${idx}`}>
          <div className="flex items-center justify-between text-xs text-slate-600">
            <span>{item.month}</span>
            <span className="text-slate-400">
              pinjam {item.borrowed} · kembali {item.returned}
            </span>
          </div>
          <div className="mt-1 space-y-1">
            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
              <div
                className="h-2 rounded-full bg-blue-500"
                style={{ width: `${Math.round((item.borrowed / max) * 100)}%` }}
              />
            </div>
            <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
              <div
                className="h-2 rounded-full bg-green-500"
                style={{ width: `${Math.round((item.returned / max) * 100)}%` }}
              />
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

const dateTimeFormat = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

function formatDateTime(value: string | null): string {
  if (!value) return '—';
  const d = new Date(value);
  return Number.isNaN(d.getTime()) ? '—' : dateTimeFormat.format(d);
}

function StatCard({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <div className="mt-2">{children}</div>
    </Card>
  );
}

function CheckIcon({ status }: { status: LibrarySetupCheck['status'] }) {
  if (status === 'complete') return <CheckCircle2 className="h-4 w-4 text-green-600" />;
  if (status === 'warning') return <AlertTriangle className="h-4 w-4 text-amber-500" />;
  return <AlertTriangle className="h-4 w-4 text-red-500" />;
}

/**
 * Ringkasan operasional satu perpustakaan.
 *
 * Statistik koleksi/anggota bernilai `null` pada Step 1 (entitas buku & anggota
 * belum ada) → ditampilkan sebagai "—", bukan angka palsu.
 */
export function LibraryDashboardPage() {
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const { options: libraryOptions, isLoading: optionsLoading } = useLibraryOptions();

  const dashboardQuery = useQuery({
    queryKey: ['library', 'dashboard', libraryId],
    enabled: libraryId !== '',
    queryFn: () => librariesApi.dashboard(Number(libraryId)),
  });

  const data: DashboardView | undefined = dashboardQuery.data as DashboardView | undefined;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Dashboard Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Status operasional, kelengkapan konfigurasi, dan statistik kunci.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <div className="w-64">
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              disabled={optionsLoading}
              onChange={(v) => setLibraryId(v === '' ? '' : Number(v))}
              placeholder="-- Pilih perpustakaan --"
            />
          </div>
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            disabled={libraryId === ''}
            onClick={() => dashboardQuery.refetch()}
          >
            <RefreshCw
              className={cn('h-4 w-4', dashboardQuery.isFetching && 'animate-spin')}
            />
          </Button>
        </div>
      </div>

      <Can
        perm="library.dashboard.view"
        fallback={
          <Card>
            <EmptyState
              title="Tidak punya akses"
              description="Anda tidak berwenang melihat dashboard perpustakaan."
            />
          </Card>
        }
      >
        {libraryId === '' ? (
          <Card>
            <EmptyState
              title="Pilih perpustakaan"
              description="Pilih perpustakaan pada pemilih di atas untuk melihat ringkasannya."
            />
          </Card>
        ) : dashboardQuery.isLoading ? (
          <Card>
            <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
              <Spinner /> Memuat dashboard...
            </div>
          </Card>
        ) : dashboardQuery.isError ? (
          <Card>
            <EmptyState
              title="Gagal memuat dashboard"
              description={getErrorMessage(dashboardQuery.error)}
              action={
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={() => dashboardQuery.refetch()}
                >
                  Coba lagi
                </Button>
              }
            />
          </Card>
        ) : !data ? null : (
          <div className="space-y-4">
            <Card className="p-4">
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-base font-semibold text-slate-900">{data.library.name}</h2>
                <span className="text-sm text-slate-400">·</span>
                <span className="font-mono text-xs text-slate-600">{data.library.code}</span>
                <span className="text-sm text-slate-400">·</span>
                <Badge tone={libraryStatusTone(data.library.status)}>
                  {LIBRARY_STATUS_LABELS[data.library.status] ?? data.library.status}
                </Badge>
              </div>
              <p className="mt-1 text-xs text-slate-500">Zona waktu: {data.library.timezone}</p>
            </Card>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <StatCard label="Status Operasional">
                <Badge tone={operatingStatusTone(data.operating.status)}>
                  {OPERATING_STATUS_LABELS[data.operating.status] ?? data.operating.status}
                </Badge>
                {data.operating.today_label && (
                  <p className="mt-1 text-xs text-slate-500">{data.operating.today_label}</p>
                )}
              </StatCard>

              <StatCard label="Jam Hari Ini">
                <div className="flex items-center gap-2">
                  <Clock className="h-4 w-4 text-slate-400" />
                  <span className="text-sm font-medium text-slate-800">
                    {orDash(data.operating.today_hours)}
                  </span>
                </div>
                {data.operating.today_label && (
                  <p className="mt-1 text-xs text-slate-500">{data.operating.today_label}</p>
                )}
              </StatCard>

              <StatCard label="Konfigurasi">
                <div className="flex items-center gap-2">
                  <span className="text-sm font-semibold text-slate-800">
                    {data.configuration.percent}%
                  </span>
                  <Badge tone={setupStatusTone(data.configuration.status)}>
                    {SETUP_STATUS_LABELS[data.configuration.status] ?? data.configuration.status}
                  </Badge>
                </div>
                <div className="mt-2 h-2 w-full overflow-hidden rounded-full bg-slate-100">
                  <div
                    className={cn(
                      'h-2 rounded-full',
                      data.configuration.percent >= 100
                        ? 'bg-green-500'
                        : data.configuration.percent >= 50
                          ? 'bg-amber-500'
                          : 'bg-red-500',
                    )}
                    style={{ width: `${Math.min(100, Math.max(0, data.configuration.percent))}%` }}
                  />
                </div>
              </StatCard>

              <StatCard label="Lokasi">
                <div className="flex items-center gap-2">
                  <MapPin className="h-4 w-4 text-slate-400" />
                  <span className="text-sm font-semibold text-slate-800">{data.location.count}</span>
                </div>
              </StatCard>
            </div>

            {data.operating.closures_today.length > 0 && (
              <Card className="p-4">
                <div className="flex items-center gap-2">
                  <AlertTriangle className="h-4 w-4 text-amber-500" />
                  <h3 className="text-sm font-semibold text-slate-900">Penutupan Hari Ini</h3>
                </div>
                <ul className="mt-2 space-y-1">
                  {data.operating.closures_today.map((c, i) => (
                    <li key={i} className="text-sm text-slate-600">
                      <span className="font-medium text-slate-700">{c.reason}</span>
                      {c.start_time && c.end_time ? ` (${c.start_time}–${c.end_time})` : ' (sepanjang hari)'}
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <Card className="p-4">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Settings2 className="h-4 w-4 text-slate-400" />
                  <h3 className="text-sm font-semibold text-slate-900">Setup Checklist</h3>
                </div>
                <Badge tone={setupStatusTone(data.configuration.status)}>
                  {SETUP_STATUS_LABELS[data.configuration.status] ?? data.configuration.status}
                </Badge>
              </div>
              {data.setup.length === 0 ? (
                <EmptyState title="Belum ada pemeriksaan" />
              ) : (
                <ul className="mt-3 space-y-2">
                  {data.setup.map((check) => (
                    <li key={check.key} className="flex items-start gap-2">
                      <span className="mt-0.5">
                        <CheckIcon status={check.status} />
                      </span>
                      <div className="min-w-0">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium text-slate-800">{check.label}</span>
                          <Badge tone={checkStatusTone(check.status)}>
                            {checkStatusTone(check.status) === 'green'
                              ? 'Lengkap'
                              : checkStatusTone(check.status) === 'amber'
                                ? 'Perhatian'
                                : 'Kurang'}
                          </Badge>
                        </div>
                        <p className="text-xs text-slate-500">{check.detail}</p>
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card className="p-4">
              <div className="flex items-center gap-2">
                <Building2 className="h-4 w-4 text-slate-400" />
                <h3 className="text-sm font-semibold text-slate-900">Statistik</h3>
              </div>
              <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                <StatCard label="Buku">
                  <span className="text-lg font-semibold text-slate-800">
                    {orDash(data.statistics.books)}
                  </span>
                </StatCard>
                <StatCard label="Eksemplar">
                  <span className="text-lg font-semibold text-slate-800">
                    {orDash(data.statistics.items)}
                  </span>
                </StatCard>
                <StatCard label="Anggota">
                  <span className="text-lg font-semibold text-slate-800">
                    {orDash(data.statistics.members)}
                  </span>
                </StatCard>
                <StatCard label="Peminjaman Aktif">
                  <span className="text-lg font-semibold text-slate-800">
                    {orDash(data.statistics.active_loans)}
                  </span>
                </StatCard>
                <StatCard label="Terlambat">
                  <span
                    className={cn(
                      'text-lg font-semibold',
                      (data.statistics.overdue_loans ?? 0) > 0
                        ? 'text-red-600'
                        : 'text-slate-800',
                    )}
                  >
                    {orDash(data.statistics.overdue_loans)}
                  </span>
                  {(data.statistics.overdue_loans ?? 0) > 0 && (
                    <p className="mt-0.5 text-xs text-red-500">Perlu tindak lanjut</p>
                  )}
                </StatCard>
              </div>
              <p className="mt-3 text-xs text-slate-500">
                Angka sirkulasi diambil dari data peminjaman, keterlambatan, dan reservasi terkini.
              </p>
            </Card>

            {/* Sirkulasi (Fase 4/5) — angka nyata dari peminjaman, denda, & reservasi. */}
            <Card className="p-4">
              <div className="flex items-center gap-2">
                <ArrowLeftRight className="h-4 w-4 text-slate-400" />
                <h3 className="text-sm font-semibold text-slate-900">Sirkulasi</h3>
              </div>

              {data.circulation ? (
                <>
                  <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
                    <StatCard label="Peminjaman Aktif">
                      <span className="text-lg font-semibold text-slate-800">
                        {data.circulation.active_loans}
                      </span>
                    </StatCard>
                    <StatCard label="Terlambat">
                      <span
                        className={cn(
                          'text-lg font-semibold',
                          data.circulation.overdue_loans > 0 ? 'text-red-600' : 'text-slate-800',
                        )}
                      >
                        {data.circulation.overdue_loans}
                      </span>
                    </StatCard>
                    <StatCard label="Jatuh Tempo Hari Ini">
                      <span
                        className={cn(
                          'text-lg font-semibold',
                          data.circulation.due_today > 0 ? 'text-amber-600' : 'text-slate-800',
                        )}
                      >
                        {data.circulation.due_today}
                      </span>
                    </StatCard>
                    <StatCard label="Dipinjam Bulan Ini">
                      <span className="text-lg font-semibold text-slate-800">
                        {data.circulation.borrowed_this_month}
                      </span>
                    </StatCard>
                    <StatCard label="Dikembalikan Bulan Ini">
                      <span className="text-lg font-semibold text-green-600">
                        {data.circulation.returned_this_month}
                      </span>
                    </StatCard>
                    <StatCard label="Eksemplar Dipinjam">
                      <span className="text-lg font-semibold text-slate-800">
                        {data.circulation.on_loan_items}
                      </span>
                    </StatCard>
                    <StatCard label="Eksemplar Dipesan">
                      <span className="text-lg font-semibold text-slate-800">
                        {data.circulation.reserved_items}
                      </span>
                    </StatCard>
                    <StatCard label="Eksemplar Baru">
                      <span className="text-lg font-semibold text-slate-800">
                        {data.circulation.new_items_this_month}
                      </span>
                    </StatCard>
                  </div>

                  <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div>
                      <h4 className="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Denda
                      </h4>
                      <ul className="mt-2 space-y-1 text-sm text-slate-600">
                        <li className="flex items-center justify-between gap-2">
                          <span>Tunggakan</span>
                          <span
                            className={cn(
                              'font-medium',
                              (data.fines?.outstanding_amount ?? 0) > 0
                                ? 'text-red-600'
                                : 'text-slate-700',
                            )}
                          >
                            {formatCurrency(data.fines?.outstanding_amount ?? 0)}
                          </span>
                        </li>
                        <li className="flex items-center justify-between gap-2">
                          <span>Belum bayar</span>
                          <span className="font-medium text-slate-700">
                            {data.fines?.unpaid ?? 0} denda
                          </span>
                        </li>
                        <li className="flex items-center justify-between gap-2">
                          <span>Lunas</span>
                          <span className="font-medium text-green-600">
                            {data.fines?.paid ?? 0} denda
                          </span>
                        </li>
                      </ul>

                      <h4 className="mt-4 text-xs font-medium uppercase tracking-wide text-slate-400">
                        Reservasi
                      </h4>
                      <ul className="mt-2 space-y-1 text-sm text-slate-600">
                        <li className="flex items-center justify-between gap-2">
                          <span>Menunggu</span>
                          <span className="font-medium text-slate-700">
                            {data.reservations?.waiting ?? 0}
                          </span>
                        </li>
                        <li className="flex items-center justify-between gap-2">
                          <span>Tersedia</span>
                          <span className="font-medium text-green-600">
                            {data.reservations?.available ?? 0}
                          </span>
                        </li>
                        <li className="flex items-center justify-between gap-2">
                          <span>Terbuka</span>
                          <span className="font-medium text-slate-700">
                            {data.reservations?.open ?? 0}
                          </span>
                        </li>
                      </ul>
                    </div>

                    <div className="lg:col-span-2">
                      <h4 className="text-xs font-medium uppercase tracking-wide text-slate-400">
                        Tren 6 Bulan
                      </h4>
                      <div className="mt-2">
                        <TrendBars items={data.trend ?? []} />
                      </div>
                    </div>
                  </div>

                  <div className="mt-4">
                    <h4 className="text-xs font-medium uppercase tracking-wide text-slate-400">
                      Koleksi populer
                    </h4>
                    {(data.popular ?? []).length === 0 ? (
                      <p className="mt-2 text-sm text-slate-400">Belum ada data peminjaman.</p>
                    ) : (
                      <ul className="mt-2 divide-y divide-slate-50">
                        {(data.popular ?? []).map((item, index) => (
                          <li
                            key={`${item.bibliography_id ?? index}`}
                            className="flex items-center justify-between gap-3 py-1.5"
                          >
                            {item.bibliography_id ? (
                              <Link
                                to={`/library/catalog/${item.bibliography_id}`}
                                className="truncate text-sm text-brand-700 hover:underline"
                              >
                                {item.title ?? '—'}
                              </Link>
                            ) : (
                              <span className="truncate text-sm text-slate-700">
                                {item.title ?? '—'}
                              </span>
                            )}
                            <span className="shrink-0 text-xs text-slate-400">
                              {item.loans} pinjam
                            </span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </>
              ) : (
                <p className="mt-2 text-sm text-slate-400">Data sirkulasi belum tersedia.</p>
              )}
            </Card>

            {/* Katalog & keanggotaan (Fase 3) — angka nyata dari data. */}
            <Card className="p-4">
              <div className="flex items-center gap-2">
                <BookOpen className="h-4 w-4 text-slate-400" />
                <h3 className="text-sm font-semibold text-slate-900">Katalog & Keanggotaan</h3>
              </div>

              <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <StatCard label="Judul (bibliografi)">
                  <span className="text-lg font-semibold text-slate-800">
                    {data.catalog.bibliographies}
                  </span>
                </StatCard>
                <StatCard label="Judul Aktif (OPAC)">
                  <span className="text-lg font-semibold text-slate-800">
                    {data.catalog.bibliographies_active}
                  </span>
                </StatCard>
                <StatCard label="Eksemplar">
                  <span className="text-lg font-semibold text-slate-800">{data.catalog.items}</span>
                </StatCard>
                <StatCard label="Eksemplar Tersedia">
                  <span className="text-lg font-semibold text-green-600">
                    {data.catalog.items_available}
                  </span>
                </StatCard>
                <StatCard label="Anggota Aktif">
                  <span className="text-lg font-semibold text-slate-800">{data.members.active}</span>
                </StatCard>
                <StatCard label="Keanggotaan Kedaluwarsa">
                  <span className="text-lg font-semibold text-amber-600">
                    {data.members.expired}
                    {data.members.due_expired > 0 && (
                      <span className="ml-1 text-xs font-normal text-slate-400">
                        (+{data.members.due_expired} belum disinkronkan)
                      </span>
                    )}
                  </span>
                </StatCard>
              </div>

              <div className="mt-4">
                <h4 className="text-xs font-medium uppercase tracking-wide text-slate-400">
                  Penambahan katalog terbaru
                </h4>
                {data.catalog.recent.length === 0 ? (
                  <p className="mt-2 text-sm text-slate-400">
                    Belum ada record katalog pada perpustakaan ini.
                  </p>
                ) : (
                  <ul className="mt-2 divide-y divide-slate-50">
                    {data.catalog.recent.map((record) => (
                      <li key={record.id} className="flex items-center justify-between gap-3 py-1.5">
                        <Link
                          to={`/library/catalog/${record.id}`}
                          className="truncate text-sm text-brand-700 hover:underline"
                        >
                          {record.title}
                        </Link>
                        <span className="shrink-0 text-xs text-slate-400">
                          {formatDateTime(record.created_at)}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            </Card>

            <Card className="p-4">
              <h3 className="text-sm font-semibold text-slate-900">Sistem</h3>
              <div className="mt-2 flex flex-wrap items-center gap-4 text-sm text-slate-600">
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Status data</span>
                  <Badge tone={data.system.is_active ? 'green' : 'gray'}>
                    {data.system.is_active ? 'Aktif' : 'Tidak Aktif'}
                  </Badge>
                </div>
                <div className="flex items-center gap-2">
                  <span className="text-xs text-slate-400">Pembaruan terakhir</span>
                  <span className="font-medium text-slate-700">
                    {formatDateTime(data.system.last_update)}
                  </span>
                </div>
              </div>
            </Card>

            <p className="text-xs text-slate-400">
              Kelompok konfigurasi: {Object.values(SETTING_GROUP_LABELS).join(', ')}.
            </p>
          </div>
        )}
      </Can>
    </div>
  );
}