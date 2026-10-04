import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { BarChart3, Clock, Wallet } from 'lucide-react';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { cn, formatRupiah } from '@/lib/utils';
import { reportsApi } from '../api/procurementApi';
import { DetailRow, FormField, SectionCard } from '../components/ProcurementUi';

const REPORT_TYPES = [
  { value: 'spend-by-vendor', label: 'Belanja per vendor' },
  { value: 'spend-by-category', label: 'Belanja per kategori' },
  { value: 'purchase-orders', label: 'Rekap purchase order' },
  { value: 'vendor-performance', label: 'Kinerja vendor' },
  { value: 'invoice-exceptions', label: 'Pengecualian invoice' },
];

/** Laporan pengadaan: belanja, siklus waktu, dan ketersediaan anggaran. */
export default function ProcurementReportPage() {
  const [type, setType] = useState(REPORT_TYPES[0].value);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'report', type, from, to],
    queryFn: () => reportsApi.get(type, { from, to }),
    enabled: !!type,
  });

  const { data: spend } = useQuery({
    queryKey: ['procurement', 'report', 'spend'],
    queryFn: () => reportsApi.spend(),
    retry: false,
  });

  const { data: cycles } = useQuery({
    queryKey: ['procurement', 'report', 'cycle-times'],
    queryFn: () => reportsApi.cycleTimes(),
    retry: false,
  });

  const { data: budget } = useQuery({
    queryKey: ['procurement', 'report', 'budget'],
    queryFn: () => reportsApi.budgetAvailability(),
    retry: false,
  });

  const rows = data?.rows ?? [];

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Laporan Pengadaan</h1>
        <p className="text-xs text-slate-500">
          Angka belanja berasal dari PO; angka pembayaran tetap milik modul Akuntansi.
        </p>
      </header>

      <Card className="p-5">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Jenis laporan">
            <Select value={type} onChange={(e) => setType(e.target.value)}>
              {REPORT_TYPES.map((r) => (
                <option key={r.value} value={r.value}>
                  {r.label}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Dari tanggal">
            <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          </FormField>
          <FormField label="Sampai tanggal">
            <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
          </FormField>
        </div>
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <SectionCard title="Ringkasan belanja" className="lg:col-span-1">
          {!spend ? (
            <p className="text-xs text-slate-500">Belum tersedia.</p>
          ) : (
            <>
              <DetailRow label="Total belanja">
                <span className="text-base font-semibold tabular-nums">
                  {formatRupiah((spend.summary?.total as number) ?? 0)}
                </span>
              </DetailRow>
              {Object.entries(spend.summary ?? {})
                .filter(([k]) => k !== 'total')
                .map(([k, v]) => (
                  <DetailRow key={k} label={k.replace(/_/g, ' ')}>
                    {typeof v === 'number' ? formatRupiah(v) : String(v)}
                  </DetailRow>
                ))}
            </>
          )}
        </SectionCard>

        <SectionCard title="Waktu siklus" className="lg:col-span-1" description="Rata-rata jam antar tahap pengadaan.">
          {!cycles ? (
            <p className="text-xs text-slate-500">Belum tersedia.</p>
          ) : (
            <div className="space-y-2">
              {Object.entries(cycles)
                .filter(([, v]) => typeof v === 'number')
                .map(([k, v]) => (
                  <div key={k} className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-1.5 text-slate-500">
                      <Clock className="h-3.5 w-3.5" /> {k.replace(/_/g, ' ')}
                    </span>
                    <span className="tabular-nums text-slate-700">{Math.round(Number(v))} jam</span>
                  </div>
                ))}
              {Object.values(cycles).every((v) => typeof v !== 'number') && (
                <p className="text-xs text-slate-500">Belum ada dokumen yang selesai untuk diukur.</p>
              )}
            </div>
          )}
        </SectionCard>

        <SectionCard title="Ketersediaan anggaran" className="lg:col-span-1">
          {!budget ? (
            <p className="text-xs text-slate-500">Modul Anggaran belum terpasang.</p>
          ) : (
            <>
              <DetailRow label="Kode">{budget.code ?? '-'}</DetailRow>
              <DetailRow label="Pagu">{formatRupiah(budget.allocated ?? 0)}</DetailRow>
              <DetailRow label="Terpakai">{formatRupiah(budget.committed ?? 0)}</DetailRow>
              <DetailRow label="Tersedia">
                <span className={cn('font-semibold tabular-nums', budget.is_sufficient === false ? 'text-red-600' : 'text-green-700')}>
                  {formatRupiah(budget.available ?? 0)}
                </span>
              </DetailRow>
              {budget.is_sufficient === false && (
                <p className="mt-2 text-[11px] text-red-600">Anggaran tidak mencukupi permintaan saat ini.</p>
              )}
            </>
          )}
        </SectionCard>
      </div>

      <SectionCard
        title={REPORT_TYPES.find((r) => r.value === type)?.label ?? 'Laporan'}
        action={<BarChart3 className="h-4 w-4 text-slate-400" />}
      >
        {isLoading ? (
          <div className="flex justify-center py-12">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Tidak ada data" description="Ubah rentang tanggal atau jenis laporan." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-[11px] uppercase tracking-wide text-slate-500">
                  {Object.keys(rows[0]).map((k) => (
                    <th key={k} className="px-3 py-2 font-medium">
                      {k.replace(/_/g, ' ')}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.map((row, i) => (
                  <tr key={i}>
                    {Object.values(row).map((v, j) => (
                      <td key={j} className="px-3 py-2 text-slate-700">
                        {typeof v === 'number' ? v.toLocaleString('id-ID') : String(v ?? '-')}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </SectionCard>

      <p className="flex items-center gap-1 text-[11px] text-slate-400">
        <Wallet className="h-3 w-3" /> Ekspor laporan mengikuti format modul Laporan lain di aplikasi.
      </p>
    </div>
  );
}
