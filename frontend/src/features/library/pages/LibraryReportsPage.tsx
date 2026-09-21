import { useMemo, useState, type ReactNode } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useSearchParams } from 'react-router-dom';
import { Download, FileText, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import {
  libraryDocumentsApi,
  libraryLocationsApi,
  libraryMastersApi,
  libraryReportsApi,
} from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { cn } from '@/lib/utils';
import { getErrorMessage } from '@/lib/axios';
import type { SelectOption } from '@/constants/masterData';
import type {
  AcquisitionReport,
  CirculationReport,
  CollectionReport,
  ExportType,
  FineReport,
  InventoryReportBundle,
  MemberReport,
} from '@/types/libraryCirculation';

const TABS = [
  { key: 'koleksi', label: 'Koleksi' },
  { key: 'sirkulasi', label: 'Sirkulasi' },
  { key: 'anggota', label: 'Anggota' },
  { key: 'denda', label: 'Denda' },
  { key: 'pengadaan', label: 'Pengadaan' },
  { key: 'inventaris', label: 'Inventaris' },
  { key: 'reservasi', label: 'Reservasi' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

/** Jenis ekspor CSV per tab + label tombol yang menyebut apa yang diunduh. */
const EXPORT_BY_TAB: Record<TabKey, { type: ExportType; label: string }> = {
  koleksi: { type: 'catalog', label: 'Ekspor Katalog (CSV)' },
  sirkulasi: { type: 'loans', label: 'Ekspor Peminjaman (CSV)' },
  anggota: { type: 'members', label: 'Ekspor Anggota (CSV)' },
  denda: { type: 'fines', label: 'Ekspor Denda (CSV)' },
  pengadaan: { type: 'acquisitions', label: 'Ekspor Pengadaan (CSV)' },
  inventaris: { type: 'items', label: 'Ekspor Eksemplar (CSV)' },
  reservasi: { type: 'reservations', label: 'Ekspor Reservasi (CSV)' },
};

/** Jenis laporan PDF per tab — harus cocok dengan daftar putih backend. */
const REPORT_PDF_TYPE_BY_TAB: Record<TabKey, string> = {
  koleksi: 'collection',
  sirkulasi: 'circulation',
  anggota: 'member',
  denda: 'fine',
  pengadaan: 'acquisition',
  inventaris: 'inventory',
  reservasi: 'reservation',
};

const currencyFormat = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

function formatCurrency(value: number | null | undefined): string {
  return currencyFormat.format(Number(value ?? 0));
}

interface ReservationQueueRow {
  reservation_code?: string;
  title?: string | null;
  member?: string | null;
  status?: string;
  queue_position?: number | null;
  expires_at?: string | null;
}

function Stat({
  label,
  value,
  tone = 'default',
}: {
  label: string;
  value: ReactNode;
  tone?: 'default' | 'red' | 'green' | 'amber';
}) {
  const toneClass = {
    default: 'text-slate-800',
    red: 'text-red-600',
    green: 'text-green-600',
    amber: 'text-amber-600',
  }[tone];
  return (
    <Card className="p-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</p>
      <p className={cn('mt-1 text-lg font-semibold', toneClass)}>{value}</p>
    </Card>
  );
}

function BarList({
  items,
  formatValue,
}: {
  items: { label: string; value: number }[];
  formatValue?: (n: number) => string;
}) {
  const max = Math.max(1, ...items.map((i) => i.value));
  if (items.length === 0) return <p className="text-sm text-slate-400">Tidak ada data.</p>;
  return (
    <ul className="space-y-2">
      {items.map((item, idx) => (
        <li key={`${item.label}-${idx}`}>
          <div className="flex items-center justify-between gap-2 text-xs text-slate-600">
            <span className="truncate">{item.label}</span>
            <span className="shrink-0 font-medium text-slate-700">
              {formatValue ? formatValue(item.value) : item.value}
            </span>
          </div>
          <div className="mt-1 h-2 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              className="h-2 rounded-full bg-brand-500"
              style={{ width: `${Math.round((item.value / max) * 100)}%` }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}

function MonthBars({ items }: { items: { month: string; borrowed: number; returned: number }[] }) {
  const max = Math.max(1, ...items.flatMap((i) => [i.borrowed, i.returned]));
  if (items.length === 0) return <p className="text-sm text-slate-400">Tidak ada data.</p>;
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

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Card className="p-4">
      <h3 className="text-sm font-semibold text-slate-900">{title}</h3>
      <div className="mt-3">{children}</div>
    </Card>
  );
}

function CollectionBody({ data }: { data: CollectionReport }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="Judul" value={data.totals.titles} />
        <Stat label="Judul Aktif" value={data.totals.titles_active} tone="green" />
        <Stat label="Eksemplar" value={data.totals.copies} />
        <Stat label="Eksemplar Tersedia" value={data.totals.copies_available} tone="green" />
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Menurut Kategori">
          <BarList items={data.by_category.map((r) => ({ label: r.label, value: r.total }))} />
        </Section>
        <Section title="Menurut Jenis Koleksi">
          <BarList
            items={data.by_collection_type.map((r) => ({ label: r.label, value: r.total }))}
          />
        </Section>
        <Section title="Menurut Lokasi">
          <BarList items={data.by_location.map((r) => ({ label: r.label, value: r.total }))} />
        </Section>
        <Section title="Menurut Tahun Terbit">
          <BarList items={data.by_year.map((r) => ({ label: String(r.year), value: r.total }))} />
        </Section>
      </div>
      <Section title="Menurut Status">
        <BarList
          items={Object.entries(data.by_status).map(([label, value]) => ({ label, value }))}
        />
      </Section>
    </div>
  );
}

function CirculationBody({ data }: { data: CirculationReport }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
        <Stat label="Dipinjam" value={data.borrowed} />
        <Stat label="Dikembalikan" value={data.returned} tone="green" />
        <Stat label="Aktif" value={data.active} />
        <Stat label="Terlambat" value={data.overdue} tone={data.overdue > 0 ? 'red' : 'default'} />
        <Stat label="Perpanjangan" value={data.renewals} />
      </div>
      <Section title="Tren Bulanan">
        <MonthBars items={data.by_month} />
      </Section>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Koleksi Terpopuler">
          {data.popular.length === 0 ? (
            <p className="text-sm text-slate-400">Tidak ada data.</p>
          ) : (
            <ul className="divide-y divide-slate-50">
              {data.popular.map((r, idx) => (
                <li key={idx} className="flex items-center justify-between gap-2 py-1.5 text-sm">
                  <span className="truncate text-slate-700">{r.title ?? '—'}</span>
                  <span className="shrink-0 text-xs text-slate-400">{r.loans} pinjam</span>
                </li>
              ))}
            </ul>
          )}
        </Section>
        <Section title="Anggota Paling Aktif">
          {data.active_members.length === 0 ? (
            <p className="text-sm text-slate-400">Tidak ada data.</p>
          ) : (
            <ul className="divide-y divide-slate-50">
              {data.active_members.map((r, idx) => (
                <li key={idx} className="flex items-center justify-between gap-2 py-1.5 text-sm">
                  <span className="truncate text-slate-700">
                    {r.name ?? r.member_number ?? '—'}
                  </span>
                  <span className="shrink-0 text-xs text-slate-400">{r.loans} pinjam</span>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </div>
  );
}

function MemberBody({ data }: { data: MemberReport }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <Stat label="Total" value={data.total} />
        <Stat label="Aktif" value={data.active} tone="green" />
        <Stat label="Kedaluwarsa" value={data.expired} tone="amber" />
        <Stat label="Menunggu" value={data.pending} />
        <Stat label="Ditangguhkan" value={data.suspended} tone="amber" />
        <Stat label="Belum Disinkronkan" value={data.summary.due_expired} />
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Menurut Tipe Keanggotaan">
          <BarList items={data.by_type.map((r) => ({ label: r.label, value: r.total }))} />
        </Section>
        <Section title="Menurut Jenis Terhubung">
          <BarList
            items={Object.entries(data.by_linked_type).map(([label, value]) => ({
              label,
              value,
            }))}
          />
        </Section>
      </div>
    </div>
  );
}
function FineBody({ data }: { data: FineReport }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <Stat label="Total Denda" value={data.summary.total} />
        <Stat
          label="Belum Bayar"
          value={data.summary.unpaid}
          tone={data.summary.unpaid > 0 ? 'red' : 'default'}
        />
        <Stat label="Lunas" value={data.summary.paid} tone="green" />
        <Stat label="Nilai Dinilai" value={formatCurrency(data.assessed_amount)} />
        <Stat
          label="Tunggakan"
          value={formatCurrency(data.outstanding_amount)}
          tone={data.outstanding_amount > 0 ? 'red' : 'default'}
        />
      </div>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Stat label="Terbayar" value={formatCurrency(data.paid_amount)} tone="green" />
        <Stat label="Dibebaskan" value={formatCurrency(data.waived_amount)} />
        <Stat label="Jumlah Denda Dinilai" value={data.assessed} />
      </div>
      <Section title="Menurut Jenis Denda">
        <BarList items={data.by_type.map((r) => ({ label: r.type, value: r.total }))} />
        <ul className="mt-3 divide-y divide-slate-50">
          {data.by_type.map((r) => (
            <li key={r.type} className="flex items-center justify-between gap-2 py-1.5 text-sm">
              <span className="text-slate-700">{r.type}</span>
              <span className="text-xs text-slate-500">
                {r.total} × · {formatCurrency(r.amount)}
              </span>
            </li>
          ))}
        </ul>
      </Section>
    </div>
  );
}

function AcquisitionBody({ data }: { data: AcquisitionReport }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <Stat label="Total Permintaan" value={data.total_requests} />
        <Stat label="Eksemplar Diterima" value={data.received_items} tone="green" />
        <Stat label="Total Nilai" value={formatCurrency(data.total_amount)} />
      </div>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Section title="Menurut Status">
          <BarList
            items={Object.entries(data.by_status).map(([label, value]) => ({ label, value }))}
          />
        </Section>
        <Section title="Menurut Pemasok">
          <BarList items={data.by_supplier.map((r) => ({ label: r.label, value: r.total }))} />
          <ul className="mt-3 divide-y divide-slate-50">
            {data.by_supplier.map((r) => (
              <li key={r.label} className="flex items-center justify-between gap-2 py-1.5 text-sm">
                <span className="truncate text-slate-700">{r.label}</span>
                <span className="shrink-0 text-xs text-slate-500">
                  {r.total} · {formatCurrency(r.amount)}
                </span>
              </li>
            ))}
          </ul>
        </Section>
      </div>
    </div>
  );
}

function InventoryBody({ data }: { data: InventoryReportBundle }) {
  const { sessions, current } = data;
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <Stat label="Tersedia" value={current.available} tone="green" />
        <Stat label="Dipinjam" value={current.on_loan} />
        <Stat label="Hilang" value={current.lost} tone={current.lost > 0 ? 'red' : 'default'} />
        <Stat
          label="Rusak"
          value={current.damaged}
          tone={current.damaged > 0 ? 'amber' : 'default'}
        />
        <Stat label="Ditarik" value={current.withdrawn} />
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <Stat label="Sesi" value={sessions.totals.sessions} />
        <Stat label="Diharapkan" value={sessions.totals.expected} />
        <Stat label="Ditemukan" value={sessions.totals.found} tone="green" />
        <Stat
          label="Hilang"
          value={sessions.totals.missing}
          tone={sessions.totals.missing > 0 ? 'red' : 'default'}
        />
        <Stat label="Tak Terduga" value={sessions.totals.unexpected} tone="amber" />
      </div>
      <Section title="Ringkasan Sesi Stock Opname">
        {sessions.sessions.length === 0 ? (
          <EmptyState title="Belum ada sesi inventaris" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Kode</th>
                  <th className="px-3 py-2 font-medium">Nama</th>
                  <th className="px-3 py-2 font-medium">Diharapkan</th>
                  <th className="px-3 py-2 font-medium">Ditemukan</th>
                  <th className="px-3 py-2 font-medium">Hilang</th>
                  <th className="px-3 py-2 font-medium">Tak Terduga</th>
                  <th className="px-3 py-2 font-medium">Rusak</th>
                </tr>
              </thead>
              <tbody>
                {sessions.sessions.map((s) => (
                  <tr key={s.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-3 py-2 font-mono text-xs text-slate-700">{s.code}</td>
                    <td className="px-3 py-2 text-slate-700">{s.name}</td>
                    <td className="px-3 py-2 text-slate-600">{s.expected}</td>
                    <td className="px-3 py-2 text-green-600">{s.found}</td>
                    <td
                      className={cn(
                        'px-3 py-2',
                        s.missing > 0 ? 'text-red-600' : 'text-slate-600',
                      )}
                    >
                      {s.missing}
                    </td>
                    <td className="px-3 py-2 text-slate-600">{s.unexpected}</td>
                    <td className="px-3 py-2 text-slate-600">{s.damaged}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>
    </div>
  );
}

function ReservationBody({
  summary,
  queue,
}: {
  summary: { waiting: number; available: number; open: number; expired: number };
  queue: ReservationQueueRow[];
}) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat
          label="Menunggu"
          value={summary.waiting}
          tone={summary.waiting > 0 ? 'amber' : 'default'}
        />
        <Stat label="Tersedia" value={summary.available} tone="green" />
        <Stat label="Terbuka" value={summary.open} />
        <Stat label="Kedaluwarsa" value={summary.expired} />
      </div>
      <Section title="Antrian Reservasi">
        {queue.length === 0 ? (
          <EmptyState title="Tidak ada antrian reservasi" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-3 py-2 font-medium">Antrian</th>
                  <th className="px-3 py-2 font-medium">Kode</th>
                  <th className="px-3 py-2 font-medium">Judul</th>
                  <th className="px-3 py-2 font-medium">Anggota</th>
                  <th className="px-3 py-2 font-medium">Status</th>
                  <th className="px-3 py-2 font-medium">Kedaluwarsa</th>
                </tr>
              </thead>
              <tbody>
                {queue.map((row, idx) => (
                  <tr
                    key={row.reservation_code ?? idx}
                    className="border-b border-slate-50 last:border-0"
                  >
                    <td className="px-3 py-2 text-slate-600">{row.queue_position ?? '—'}</td>
                    <td className="px-3 py-2 font-mono text-xs text-slate-700">
                      {row.reservation_code ?? '—'}
                    </td>
                    <td className="px-3 py-2 text-slate-700">{row.title ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-600">{row.member ?? '—'}</td>
                    <td className="px-3 py-2">
                      <Badge tone="blue">{row.status ?? '—'}</Badge>
                    </td>
                    <td className="px-3 py-2 text-xs text-slate-500">{row.expires_at ?? '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Section>
    </div>
  );
}

export function LibraryReportsPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const rawTab = searchParams.get('tab');
  const activeTab: TabKey = (TABS.some((t) => t.key === rawTab) ? rawTab : 'koleksi') as TabKey;
  const { options: libraryOptions } = useLibraryOptions();

  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [locationId, setLocationId] = useState<number | ''>('');
  const [categoryId, setCategoryId] = useState<number | ''>('');
  const [collectionTypeId, setCollectionTypeId] = useState<number | ''>('');
  const [membershipTypeId, setMembershipTypeId] = useState<number | ''>('');

  const setTab = (key: TabKey) => {
    const next = new URLSearchParams(searchParams);
    next.set('tab', key);
    setSearchParams(next, { replace: true });
  };

  const reportParams = useMemo<Record<string, string | number>>(() => {
    const out: Record<string, string | number> = {};
    if (from) out.from = from;
    if (to) out.to = to;
    if (libraryId !== '') out.library_id = libraryId;
    if (locationId !== '') out.location_id = locationId;
    if (categoryId !== '') out.category_id = categoryId;
    if (collectionTypeId !== '') out.collection_type_id = collectionTypeId;
    if (membershipTypeId !== '') out.membership_type_id = membershipTypeId;
    return out;
  }, [from, to, libraryId, locationId, categoryId, collectionTypeId, membershipTypeId]);

  const needsLocation = activeTab === 'koleksi' || activeTab === 'inventaris';
  const needsCategory = activeTab === 'koleksi';
  const needsMemberType = activeTab === 'anggota';

  const locationsQuery = useQuery({
    queryKey: ['library', 'reports', 'options', 'locations', libraryId],
    enabled: libraryId !== '' && needsLocation,
    queryFn: () => libraryLocationsApi.list(Number(libraryId), { all: true }),
  });
  const locationOptions: SelectOption[] = useMemo(
    () => (locationsQuery.data?.data ?? []).map((l) => ({ value: l.id, label: l.name })),
    [locationsQuery.data],
  );

  const categoriesQuery = useQuery({
    queryKey: ['library', 'reports', 'options', 'categories'],
    enabled: needsCategory,
    queryFn: () => libraryMastersApi.list('categories', { all: true }),
  });
  const categoryOptions: SelectOption[] = useMemo(
    () => (categoriesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [categoriesQuery.data],
  );

  const collectionTypesQuery = useQuery({
    queryKey: ['library', 'reports', 'options', 'collection-types'],
    enabled: needsCategory,
    queryFn: () => libraryMastersApi.list('collection-types', { all: true }),
  });
  const collectionTypeOptions: SelectOption[] = useMemo(
    () => (collectionTypesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [collectionTypesQuery.data],
  );

  const membershipTypesQuery = useQuery({
    queryKey: ['library', 'reports', 'options', 'membership-types'],
    enabled: needsMemberType,
    queryFn: () => libraryMastersApi.list('membership-types', { all: true }),
  });
  const membershipTypeOptions: SelectOption[] = useMemo(
    () => (membershipTypesQuery.data?.data ?? []).map((m) => ({ value: m.id, label: m.name })),
    [membershipTypesQuery.data],
  );

  const collectionQuery = useQuery({
    queryKey: ['library', 'reports', 'collection', reportParams],
    enabled: activeTab === 'koleksi',
    queryFn: () => libraryReportsApi.collection(reportParams),
  });
  const circulationQuery = useQuery({
    queryKey: ['library', 'reports', 'circulation', reportParams],
    enabled: activeTab === 'sirkulasi',
    queryFn: () => libraryReportsApi.circulation(reportParams),
  });
  const memberQuery = useQuery({
    queryKey: ['library', 'reports', 'member', reportParams],
    enabled: activeTab === 'anggota',
    queryFn: () => libraryReportsApi.member(reportParams),
  });
  const fineQuery = useQuery({
    queryKey: ['library', 'reports', 'fine', reportParams],
    enabled: activeTab === 'denda',
    queryFn: () => libraryReportsApi.fine(reportParams),
  });
  const acquisitionQuery = useQuery({
    queryKey: ['library', 'reports', 'acquisition', reportParams],
    enabled: activeTab === 'pengadaan',
    queryFn: () => libraryReportsApi.acquisition(reportParams),
  });
  const inventoryQuery = useQuery({
    queryKey: ['library', 'reports', 'inventory', reportParams],
    enabled: activeTab === 'inventaris',
    queryFn: () => libraryReportsApi.inventory(reportParams),
  });
  const reservationQuery = useQuery({
    queryKey: ['library', 'reports', 'reservation', libraryId],
    enabled: activeTab === 'reservasi',
    queryFn: () => libraryReportsApi.reservation(libraryId),
  });

  const currentQuery = {
    koleksi: collectionQuery,
    sirkulasi: circulationQuery,
    anggota: memberQuery,
    denda: fineQuery,
    pengadaan: acquisitionQuery,
    inventaris: inventoryQuery,
    reservasi: reservationQuery,
  }[activeTab];

  const exportMutation = useMutation({
    mutationFn: () =>
      libraryReportsApi.exportCsv(
        EXPORT_BY_TAB[activeTab].type,
        activeTab === 'reservasi' && libraryId !== '' ? { library_id: libraryId } : reportParams,
      ),
    onSuccess: () => toast.success('Ekspor CSV dimulai — cek unduhan Anda.'),
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const pdfMutation = useMutation({
    mutationFn: () =>
      libraryDocumentsApi.reportPdf(
        REPORT_PDF_TYPE_BY_TAB[activeTab],
        activeTab === 'reservasi' && libraryId !== '' ? { library_id: libraryId } : reportParams,
      ),
    onSuccess: () => toast.success('PDF laporan diunduh.'),
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Laporan Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Ringkasan koleksi, sirkulasi, keanggotaan, denda, pengadaan, inventaris, dan reservasi.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => currentQuery.refetch()}
          >
            <RefreshCw className={cn('h-4 w-4', currentQuery.isFetching && 'animate-spin')} />
          </Button>
          <Can perm="library.report.view">
            <Button
              variant="outline"
              loading={exportMutation.isPending}
              onClick={() => exportMutation.mutate()}
            >
              <Download className="h-4 w-4" /> {EXPORT_BY_TAB[activeTab].label}
            </Button>
            <Button
              variant="outline"
              loading={pdfMutation.isPending}
              onClick={() => pdfMutation.mutate()}
            >
              <FileText className="h-4 w-4" /> Unduh PDF
            </Button>
          </Can>
        </div>
      </div>

      <Can
        perm="library.report.view"
        fallback={
          <Card>
            <EmptyState
              title="Tidak punya akses"
              description="Anda tidak berwenang melihat laporan perpustakaan."
            />
          </Card>
        }
      >
        <Card className="p-3">
          <div className="flex flex-wrap gap-1.5">
            {TABS.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => setTab(tab.key)}
                className={cn(
                  'rounded-lg px-3 py-1.5 text-sm font-medium transition-colors',
                  tab.key === activeTab
                    ? 'bg-brand-600 text-white'
                    : 'text-slate-600 hover:bg-slate-100',
                )}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div className="mt-3 grid grid-cols-1 gap-3 border-t border-slate-100 pt-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <Label>Perpustakaan</Label>
              <SearchSelect
                options={libraryOptions}
                value={libraryId}
                onChange={(v) => {
                  setLibraryId(v === '' ? '' : Number(v));
                  setLocationId('');
                }}
                placeholder="-- Semua perpustakaan --"
              />
            </div>
            <div>
              <Label>Tanggal dari</Label>
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </div>
            <div>
              <Label>Tanggal sampai</Label>
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
            {needsLocation && (
              <div>
                <Label>Lokasi</Label>
                <SearchSelect
                  options={locationOptions}
                  value={locationId}
                  disabled={libraryId === ''}
                  onChange={(v) => setLocationId(v === '' ? '' : Number(v))}
                  placeholder={libraryId === '' ? 'Pilih perpustakaan dulu' : '-- Semua lokasi --'}
                />
              </div>
            )}
            {needsCategory && (
              <>
                <div>
                  <Label>Kategori</Label>
                  <SearchSelect
                    options={categoryOptions}
                    value={categoryId}
                    onChange={(v) => setCategoryId(v === '' ? '' : Number(v))}
                    placeholder="-- Semua kategori --"
                  />
                </div>
                <div>
                  <Label>Jenis Koleksi</Label>
                  <SearchSelect
                    options={collectionTypeOptions}
                    value={collectionTypeId}
                    onChange={(v) => setCollectionTypeId(v === '' ? '' : Number(v))}
                    placeholder="-- Semua jenis --"
                  />
                </div>
              </>
            )}
            {needsMemberType && (
              <div>
                <Label>Tipe Keanggotaan</Label>
                <SearchSelect
                  options={membershipTypeOptions}
                  value={membershipTypeId}
                  onChange={(v) => setMembershipTypeId(v === '' ? '' : Number(v))}
                  placeholder="-- Semua tipe --"
                />
              </div>
            )}
          </div>
        </Card>

        {currentQuery.isLoading ? (
          <Card>
            <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
              <Spinner /> Memuat laporan...
            </div>
          </Card>
        ) : currentQuery.isError ? (
          <Card>
            <EmptyState
              title="Gagal memuat laporan"
              description={getErrorMessage(currentQuery.error)}
              action={
                <Button
                  size="sm"
                  variant="outline"
                  className="mt-2"
                  onClick={() => currentQuery.refetch()}
                >
                  Coba lagi
                </Button>
              }
            />
          </Card>
        ) : (
          <div className="space-y-4">
            {activeTab === 'koleksi' && collectionQuery.data && (
              <CollectionBody data={collectionQuery.data} />
            )}
            {activeTab === 'sirkulasi' && circulationQuery.data && (
              <CirculationBody data={circulationQuery.data} />
            )}
            {activeTab === 'anggota' && memberQuery.data && <MemberBody data={memberQuery.data} />}
            {activeTab === 'denda' && fineQuery.data && <FineBody data={fineQuery.data} />}
            {activeTab === 'pengadaan' && acquisitionQuery.data && (
              <AcquisitionBody data={acquisitionQuery.data} />
            )}
            {activeTab === 'inventaris' && inventoryQuery.data && (
              <InventoryBody data={inventoryQuery.data} />
            )}
            {activeTab === 'reservasi' && reservationQuery.data && (
              <ReservationBody
                summary={reservationQuery.data.summary}
                queue={reservationQuery.data.queue as ReservationQueueRow[]}
              />
            )}
          </div>
        )}
      </Can>
    </div>
  );
}