import type { ReactNode } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { AlertTriangle, Boxes, Building2, ClipboardCheck, Wrench } from 'lucide-react';
import { Card, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Badge } from '@/components/ui/Badge';
import { usePermission } from '@/hooks/usePermission';
import { dashboardApi } from '../api/assetApi';
import { assetCondition, assetStatus } from '../lib/labels';
import { Money } from '../components/AssetUi';

function StatCard({
  label,
  value,
  hint,
  icon,
  to,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  icon: ReactNode;
  to?: string;
}) {
  const body = (
    <Card className="h-full p-4 transition hover:border-brand-300">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="text-xs text-slate-500">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-800">{value}</p>
          {hint && <p className="mt-1 text-[11px] text-slate-500">{hint}</p>}
        </div>
        <span className="rounded-lg bg-brand-50 p-2 text-brand-600">{icon}</span>
      </div>
    </Card>
  );

  return to ? <Link to={to}>{body}</Link> : body;
}

/**
 * Dasbor Aset & Sarpras.
 *
 * Menjawab pertanyaan yang benar-benar ditanyakan kepala sekolah tiap bulan:
 * berapa aset kita, berapa nilainya (bagi yang berhak), apa yang rusak,
 * apa yang menunggu dikerjakan, dan barang siapa yang belum kembali.
 */
export default function AssetDashboardPage() {
  const { can } = usePermission();
  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'dashboard', 'overview'],
    queryFn: () => dashboardApi.overview(),
    staleTime: 60 * 1000,
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (!data) {
    return <EmptyState title="Belum ada data" description="Ringkasan aset akan muncul setelah ada barang terdaftar." />;
  }

  const totalAset = data.assets?.total ?? 0;
  const nilai = data.value?.acquisition_cost ?? '0';
  const buku = data.value?.book_value ?? '0';

  return (
    <div className="space-y-5">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Dasbor Aset &amp; Sarpras</h1>
        <p className="text-xs text-slate-500">
          Gambaran keadaan aset, fasilitas, dan pemeliharaan. Angka dihitung dari data operasional — bukan salinan lama.
        </p>
      </header>

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total aset" value={totalAset} icon={<Boxes className="h-5 w-5" />} to="/assets" />
        <StatCard
          label="Nilai perolehan"
          value={can('asset.financial.view') ? <Money value={nilai} /> : '—'}
          hint={can('asset.financial.view') ? `Nilai buku: ${new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(buku))}` : 'Perlu izin keuangan'}
          icon={<Boxes className="h-5 w-5" />}
        />
        <StatCard
          label="Perbaikan terbuka"
          value={data.maintenance?.open_requests ?? 0}
          hint={data.maintenance?.overdue_work_orders ? `${data.maintenance.overdue_work_orders} melewati tenggat` : 'Tidak ada yang melewati tenggat'}
          icon={<Wrench className="h-5 w-5" />}
          to="/assets/maintenance"
        />
        <StatCard
          label="Peminjaman berjalan"
          value={data.loans?.active ?? 0}
          hint={data.loans?.overdue ? `${data.loans.overdue} terlambat` : 'Semua tepat waktu'}
          icon={<ClipboardCheck className="h-5 w-5" />}
          to="/assets/loans"
        />
        <StatCard
          label="Fasilitas"
          value={data.facilities?.total ?? 0}
          hint={data.facilities?.bookings_this_week ? `${data.facilities.bookings_this_week} booking pekan ini` : undefined}
          icon={<Building2 className="h-5 w-5" />}
          to="/assets/facilities"
        />
        <StatCard
          label="Stok opname terbuka"
          value={data.opname?.open_sessions ?? 0}
          hint={data.opname?.pending_decisions ? `${data.opname.pending_decisions} selisih menunggu keputusan` : 'Tidak ada selisih menggantung'}
          icon={<ClipboardCheck className="h-5 w-5" />}
          to="/assets/stock-opnames"
        />
      </div>

      {(data.alerts ?? []).length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Perlu perhatian</CardTitle>
          </CardHeader>
          <ul className="divide-y divide-slate-100">
            {(data.alerts ?? []).map((alert, index) => (
              <li key={`${alert.type}-${index}`} className="flex items-start gap-2 px-5 py-3 text-sm">
                <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
                <span className="text-slate-700">{alert.message}</span>
                {alert.severity && <Badge tone={alert.severity === 'HIGH' ? 'red' : 'amber'}>{alert.severity}</Badge>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Menurut status</CardTitle>
          </CardHeader>
          <div className="divide-y divide-slate-100">
            {(data.assets?.by_status ?? []).map((row) => (
              <div key={row.status} className="flex items-center justify-between px-5 py-2.5 text-sm">
                <Badge tone={assetStatus.tone(row.status)}>{assetStatus.label(row.status)}</Badge>
                <span className="tabular-nums text-slate-700">{row.total}</span>
              </div>
            ))}
            {(data.assets?.by_status ?? []).length === 0 && <EmptyState title="Belum ada aset" />}
          </div>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Menurut kondisi</CardTitle>
          </CardHeader>
          <div className="divide-y divide-slate-100">
            {(data.assets?.by_condition ?? []).map((row) => (
              <div key={row.condition} className="flex items-center justify-between px-5 py-2.5 text-sm">
                <Badge tone={assetCondition.tone(row.condition)}>{assetCondition.label(row.condition)}</Badge>
                <span className="tabular-nums text-slate-700">{row.total}</span>
              </div>
            ))}
            {(data.assets?.by_condition ?? []).length === 0 && <EmptyState title="Belum ada aset" />}
          </div>
        </Card>
      </div>
    </div>
  );
}
