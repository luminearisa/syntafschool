import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, FileSpreadsheet, PlayCircle, Scale } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { usePermission } from '@/hooks/usePermission';
import { formatRupiah } from '@/lib/utils';
import { depreciationApi, dashboardApi, reportsApi } from '../api/assetApi';
import { FormField, RestrictedNotice } from '../components/AssetUi';
import { fmtPeriod } from '../lib/format';
/** Daftar laporan yang benar-benar disediakan backend (`GET /reports/{kind}`). */
const REPORT_LABELS: Record<string, string> = {
  'asset-register': 'Daftar Aset',
  'by-category': 'Per Kategori',
  'by-location': 'Per Lokasi',
  'by-condition': 'Per Kondisi',
  'by-status': 'Per Status',
  depreciation: 'Penyusutan',
  'maintenance-cost': 'Biaya Pemeliharaan',
  'maintenance-history': 'Riwayat Pemeliharaan',
  valuation: 'Penilaian & Nilai Buku',
  'disposed-assets': 'Aset Dihapusbukukan',
  'loan-history': 'Riwayat Peminjaman',
  'facility-booking': 'Pemanfaatan Fasilitas',
  'stock-opname': 'Hasil Stok Opname',
  'insurance-warranty': 'Garansi & Asuransi',
};

const KINDS = [
  'asset-register',
  'by-category',
  'by-location',
  'by-condition',
  'by-status',
  'depreciation',
  'maintenance-cost',
  'maintenance-history',
  'valuation',
  'disposed-assets',
  'loan-history',
  'facility-booking',
  'stock-opname',
  'insurance-warranty',
];

/**
 * Laporan aset & sarpras.
 *
 * Laporan dihitung langsung dari data operasional — tidak ada tabel salinan
 * yang bisa basi. Untuk keperluan audit, tersedia juga rekonsiliasi antara
 * nilai di modul aset dan buku besar Keuangan.
 */
export default function AssetReportPage() {
  const { can } = usePermission();
  const [kind, setKind] = useState('asset-register');
  const [periode, setPeriode] = useState(fmtPeriod(new Date()));
  const [unduh, setUnduh] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'report', kind],
    queryFn: () => reportsApi.get(kind),
  });

  const { data: rekonsiliasi } = useQuery({
    queryKey: ['asset', 'reconciliation'],
    queryFn: () => dashboardApi.reconciliation(),
    enabled: can('asset.financial.view'),
  });

  const { data: pratinjau, refetch: pratinjauUlang, isFetching } = useQuery({
    queryKey: ['asset', 'depreciation-preview', periode],
    queryFn: () => depreciationApi.preview({ period: periode }),
    enabled: false,
  });

  const ekspor = async () => {
    setUnduh(true);
    try {
      const blob = await reportsApi.download(kind);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${kind}.csv`;
      a.click();
      URL.revokeObjectURL(url);
      toast.success('Laporan diunduh');
    } catch (err) {
      toast.error(getErrorMessage(err));
    } finally {
      setUnduh(false);
    }
  };

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Laporan Aset &amp; Sarpras</h1>
          <p className="text-xs text-slate-500">Setiap ekspor tercatat pada jejak audit — siapa mengambil data apa, dan kapan.</p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={kind} onChange={(e) => setKind(e.target.value)} className="min-w-[14rem]">
            {KINDS.map((k) => (
              <option key={k} value={k}>
                {REPORT_LABELS[k] ?? k}
              </option>
            ))}
          </Select>
          <Button size="sm" onClick={ekspor} disabled={unduh}>
            <Download className="mr-1 h-4 w-4" /> Ekspor CSV
          </Button>
        </div>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : (data?.rows ?? []).length === 0 ? (
          <EmptyState title="Belum ada data untuk laporan ini" description="Pilih laporan lain, atau daftarkan aset lebih dulu." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[600px] text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-4 py-3">Kelompok</th>
                  <th className="px-4 py-3 text-right">Jumlah</th>
                  <th className="px-4 py-3 text-right">Nilai perolehan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {(data?.rows ?? []).map((row) => (
                  <tr key={row.label}>
                    <td className="px-4 py-2.5 text-slate-800">{row.label}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-700">{row.total}</td>
                    <td className="px-4 py-2.5 text-right tabular-nums text-slate-700">
                      {can('asset.financial.view') ? formatRupiah(row.value ?? 0) : '—'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Can perm="asset.financial.view">
        <RestrictedNotice perm="asset.financial.view">
          <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
            <Card className="p-4">
              <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                <FileSpreadsheet className="h-4 w-4" /> Penyusutan
              </h2>
              <p className="mt-1 text-xs text-slate-500">
                Pratinjau dulu, baru dijalankan. Nilai yang sudah diproses tidak dihitung ulang — menjalankan dua kali tidak menggandakan beban.
              </p>
              <div className="mt-3 flex flex-wrap items-end gap-2">
                <FormField label="Periode">
                  <Input type="month" value={periode} onChange={(e) => setPeriode(e.target.value)} />
                </FormField>
                <Button variant="outline" size="sm" onClick={() => pratinjauUlang()} disabled={isFetching}>
                  {isFetching ? <Spinner /> : <PlayCircle className="mr-1 h-4 w-4" />} Pratinjau
                </Button>
                <Can perm="asset.financial.manage">
                  <Button
                    size="sm"
                    onClick={() =>
                      depreciationApi
                        .run({ period: periode })
                        .then((hasil) => toast.success(`Penyusutan ${hasil.period}: ${hasil.processed} aset, total ${formatRupiah(hasil.total)}`))
                        .catch((err) => toast.error(getErrorMessage(err)))
                    }
                  >
                    Jalankan penyusutan
                  </Button>
                </Can>
              </div>
              {pratinjau && (
                <p className="mt-3 text-xs text-slate-600">
                  {pratinjau.rows?.length ?? 0} aset akan disusutkan pada {pratinjau.period} — total {formatRupiah(pratinjau.total ?? 0)}.
                </p>
              )}
            </Card>

            <Card className="p-4">
              <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                <Scale className="h-4 w-4" /> Rekonsiliasi dengan Keuangan
              </h2>
              <p className="mt-1 text-xs text-slate-500">
                Bila angka modul aset dan buku besar berbeda, selisihnya harus terlihat — bukan disamarkan.
              </p>
              {(rekonsiliasi?.rows ?? []).length === 0 ? (
                <p className="mt-3 text-xs text-slate-500">Belum ada data untuk dibandingkan (modul Keuangan belum terpasang).</p>
              ) : (
                <table className="mt-3 w-full text-sm">
                  <thead className="text-left text-xs uppercase text-slate-500">
                    <tr>
                      <th className="py-2">Kelompok</th>
                      <th className="py-2 text-right">Modul Aset</th>
                      <th className="py-2 text-right">Buku Besar</th>
                      <th className="py-2 text-right">Selisih</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {(rekonsiliasi?.rows ?? []).map((row) => (
                      <tr key={row.label}>
                        <td className="py-2 text-slate-700">{row.label}</td>
                        <td className="py-2 text-right tabular-nums">{formatRupiah(row.subledger)}</td>
                        <td className="py-2 text-right tabular-nums">{formatRupiah(row.general_ledger)}</td>
                        <td className={`py-2 text-right tabular-nums ${Number(row.difference) === 0 ? 'text-green-700' : 'text-red-700'}`}>
                          {formatRupiah(row.difference)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </Card>
          </div>
        </RestrictedNotice>
      </Can>
    </div>
  );
}
