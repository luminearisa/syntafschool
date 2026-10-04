import { useQuery } from '@tanstack/react-query';
import {
  AlertTriangle,
  ClipboardList,
  FileText,
  PackageCheck,
  Receipt,
  Send,
  ShieldCheck,
  ShoppingCart,
  TrendingUp,
  Truck,
} from 'lucide-react';
import { Link } from 'react-router-dom';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { cn, formatRupiah } from '@/lib/utils';
import { metaApi } from '../api/procurementApi';
import { useIntegrationHealth } from '../components/ProcurementUi';

/**
 * Dasbor pengadaan.
 *
 * Angka di sini adalah **petunjuk kerja hari ini**, bukan laporan. Yang penting
 * adalah berapa dokumen menunggu tindakan — karena itulah yang membuat proses
 * macet bila dibiarkan.
 */
export default function ProcurementDashboardPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'dashboard'],
    queryFn: () => metaApi.dashboard(),
    refetchInterval: 60_000,
  });

  const { health } = useIntegrationHealth();

  if (isLoading) {
    return (
      <div className="flex justify-center py-24">
        <Spinner />
      </div>
    );
  }

  if (!data) {
    return <EmptyState title="Dasbor tidak tersedia" description="Data pengadaan belum dapat dimuat." />;
  }

  const kpi = (data.kpi ?? {}) as Record<string, number>;
  const vendors = (data.vendors ?? {}) as Record<string, number>;
  const matching = (data.matching ?? {}) as Record<string, number>;
  const monthly = (data.monthly ?? []) as { period?: string; label?: string; total?: number | string }[];

  const cards = [
    { key: 'open_pr', label: 'PR terbuka', icon: ClipboardList, tone: 'text-blue-600', to: '/procurement/purchase-requests' },
    { key: 'pending_approval_pr', label: 'PR menunggu persetujuan', icon: ShieldCheck, tone: 'text-amber-600', to: '/procurement/purchase-requests' },
    { key: 'open_rfq', label: 'RFQ terbuka', icon: Send, tone: 'text-brand-600', to: '/procurement/rfqs' },
    { key: 'open_po', label: 'PO berjalan', icon: ShoppingCart, tone: 'text-blue-600', to: '/procurement/purchase-orders' },
    { key: 'overdue_delivery', label: 'Pengiriman terlambat', icon: Truck, tone: 'text-red-600', to: '/procurement/purchase-orders' },
    { key: 'pending_invoice', label: 'Invoice menunggu', icon: Receipt, tone: 'text-amber-600', to: '/procurement/invoices' },
    { key: 'matching_exceptions', label: 'Pengecualian matching', icon: AlertTriangle, tone: 'text-red-600', to: '/procurement/invoice-exceptions' },
    { key: 'pending_accounting', label: 'Menunggu akuntansi', icon: FileText, tone: 'text-amber-600', to: '/procurement/invoices' },
  ];

  const offlineProviders = Object.entries(health?.providers ?? {})
    .filter(([, installed]) => !installed)
    .map(([name]) => name);

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Dasbor Pengadaan</h1>
        <p className="text-xs text-slate-500">
          Titik perhatian proses pengadaan — dokumen yang menunggu tindakan, bukan sekadar statistik.
        </p>
      </header>

      {offlineProviders.length > 0 && (
        <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
          <span>
            Modul integrasi belum terpasang: <strong>{offlineProviders.join(', ')}</strong>. Alur pengadaan
            tetap berjalan, tetapi langkah yang bergantung padanya (jurnal, kartu stok, aset) tidak akan
            tercatat otomatis.
          </span>
        </div>
      )}

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {cards.map((c) => {
          const Icon = c.icon;
          return (
            <Link key={c.key} to={c.to}>
              <Card className="p-4 transition-shadow hover:shadow-md">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-slate-500">{c.label}</span>
                  <Icon className={cn('h-4 w-4', c.tone)} />
                </div>
                <p className="mt-2 text-2xl font-semibold tabular-nums text-slate-800">{kpi[c.key] ?? 0}</p>
              </Card>
            </Link>
          );
        })}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="p-5">
          <h2 className="text-sm font-semibold text-slate-800">Vendor</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Row label="Aktif" value={vendors.active ?? 0} />
            <Row label="Total" value={vendors.total ?? 0} />
            <Row label="Dibatasi / daftar hitam" value={vendors.restricted ?? 0} tone="text-red-600" />
          </dl>
          <Link to="/procurement/vendors" className="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">
            Kelola vendor →
          </Link>
        </Card>

        <Card className="p-5">
          <h2 className="text-sm font-semibold text-slate-800">Pencocokan invoice</h2>
          <dl className="mt-3 space-y-2 text-sm">
            <Row label="Cocok" value={matching.matched ?? 0} tone="text-green-600" />
            <Row label="Tidak cocok" value={matching.mismatch ?? 0} tone="text-red-600" />
            <Row
              label="Tingkat kecocokan"
              value={`${Math.round((matching.match_rate ?? 0) * 100)}%`}
            />
          </dl>
          <Link to="/procurement/invoice-exceptions" className="mt-3 inline-block text-xs font-medium text-brand-600 hover:underline">
            Lihat pengecualian →
          </Link>
        </Card>

        <Card className="p-5">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-semibold text-slate-800">Belanja pengadaan</h2>
            <TrendingUp className="h-4 w-4 text-brand-600" />
          </div>
          <p className="mt-2 text-2xl font-semibold tabular-nums text-slate-800">
            {formatRupiah(kpi.procurement_spend ?? 0)}
          </p>
          <p className="text-[11px] text-slate-500">
            Akumulasi nilai PO yang tidak dibatalkan/ditolak.
          </p>
        </Card>
      </div>

      <Card className="p-5">
        <h2 className="text-sm font-semibold text-slate-800">Tren belanja bulanan</h2>
        {monthly.length === 0 ? (
          <EmptyState title="Belum ada data belanja" description="Belanja muncul setelah ada PO terbit." />
        ) : (
          <div className="mt-4 flex items-end gap-2 overflow-x-auto">
            {monthly.map((m, i) => {
              const values = monthly.map((x) => Number(x.total ?? 0));
              const max = Math.max(...values, 1);
              const height = Math.max(4, Math.round((Number(m.total ?? 0) / max) * 120));
              return (
                <div key={i} className="flex min-w-[48px] flex-1 flex-col items-center gap-1">
                  <span className="text-[10px] tabular-nums text-slate-500">{formatRupiah(m.total ?? 0)}</span>
                  <div className="w-full rounded-t bg-brand-500/80" style={{ height }} />
                  <span className="text-[10px] text-slate-500">{m.label ?? m.period ?? ''}</span>
                </div>
              );
            })}
          </div>
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Link to="/procurement/receiving">
          <Card className="flex items-center gap-3 p-4 transition-shadow hover:shadow-md">
            <PackageCheck className="h-5 w-5 text-brand-600" />
            <div>
              <p className="text-sm font-medium text-slate-800">Penerimaan barang</p>
              <p className="text-[11px] text-slate-500">Catat penerimaan & inspeksi terhadap PO berjalan.</p>
            </div>
          </Card>
        </Link>
        <Link to="/procurement/reports">
          <Card className="flex items-center gap-3 p-4 transition-shadow hover:shadow-md">
            <FileText className="h-5 w-5 text-brand-600" />
            <div>
              <p className="text-sm font-medium text-slate-800">Laporan pengadaan</p>
              <p className="text-[11px] text-slate-500">Belanja per vendor, siklus waktu, dan kinerja vendor.</p>
            </div>
          </Card>
        </Link>
      </div>
    </div>
  );
}

function Row({ label, value, tone }: { label: string; value: number | string; tone?: string }) {
  return (
    <div className="flex items-center justify-between">
      <dt className="text-slate-500">{label}</dt>
      <dd className={cn('font-medium tabular-nums text-slate-800', tone)}>{value}</dd>
    </div>
  );
}
