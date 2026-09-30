import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Activity, AlertTriangle, HeartPulse, Pill, Siren } from 'lucide-react';
import { uksApi } from '../api/uksApi';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';

function Stat({ label, value, hint }: { label: string; value: number | string; hint?: string }) {
  return (
    <Card>
      <CardBody>
        <p className="text-xs text-slate-500">{label}</p>
        <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
        {hint ? <p className="mt-1 text-xs text-slate-400">{hint}</p> : null}
      </CardBody>
    </Card>
  );
}

/** Beranda UKS: angka mengikuti cakupan pengguna (sekolah/kelas/anak/diri). */
export default function UksDashboardPage() {
  const dashboard = useQuery({ queryKey: ['uks', 'dashboard'], queryFn: () => uksApi.dashboard({}) });
  const me = useQuery({ queryKey: ['uks', 'me'], queryFn: () => uksApi.me() });

  if (dashboard.isLoading) return <Spinner />;
  const data = dashboard.data;

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <HeartPulse className="h-5 w-5 text-rose-500" /> Usaha Kesehatan Sekolah
          </h1>
          <p className="text-sm text-slate-500">
            {data?.window.from} s.d. {data?.window.to}
          </p>
        </div>
        {data ? <Badge tone="blue">{data.scope.label}</Badge> : null}
      </div>

      {data ? (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
            <Stat label="Kunjungan" value={data.visits.total} hint={`${data.visits.open_now} masih terbuka`} />
            <Stat label="Dipulangkan" value={data.visits.sent_home} />
            <Stat label="Dirujuk" value={data.visits.referred} />
            <Stat label="Insiden" value={data.incidents.total} />
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            <Card>
              <CardHeader><CardTitle>Keluhan terbanyak</CardTitle></CardHeader>
              <CardBody>
                {data.visits.top_complaints.length === 0 ? (
                  <p className="text-sm text-slate-400">Belum ada kunjungan pada periode ini.</p>
                ) : (
                  <ul className="space-y-1 text-sm">
                    {data.visits.top_complaints.map((row) => (
                      <li key={row.complaint} className="flex justify-between">
                        <span>{row.complaint}</span>
                        <span className="font-medium">{row.total}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardBody>
            </Card>

            {data.stock ? (
              <Card>
                <CardHeader><CardTitle>Persediaan obat</CardTitle></CardHeader>
                <CardBody className="space-y-1 text-sm">
                  <p>{data.stock.medicines} jenis obat aktif</p>
                  <p className={data.stock.low_stock > 0 ? 'text-amber-600' : ''}>
                    {data.stock.low_stock} di bawah stok minimum
                  </p>
                  <p className={data.stock.expiring_soon > 0 ? 'text-amber-600' : ''}>
                    {data.stock.expiring_soon} menjelang kedaluwarsa
                  </p>
                  {data.stock.expired > 0 ? (
                    <p className="font-medium text-red-600">{data.stock.expired} sudah kedaluwarsa — jangan diberikan</p>
                  ) : null}
                </CardBody>
              </Card>
            ) : null}
          </div>
        </>
      ) : null}

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Link to="/uks/visits" className="rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
          <Activity className="mb-1 h-4 w-4 text-slate-500" /> Kunjungan UKS
        </Link>
        {me.data?.can_operate ? (
          <Link to="/uks/medicines" className="rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
            <Pill className="mb-1 h-4 w-4 text-slate-500" /> Obat & Stok
          </Link>
        ) : null}
        <Link to="/uks/records" className="rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
          <HeartPulse className="mb-1 h-4 w-4 text-slate-500" /> Rekam Kesehatan
        </Link>
        <Link to="/uks/incidents" className="rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
          <Siren className="mb-1 h-4 w-4 text-slate-500" /> Insiden
        </Link>
        <Link to="/uks/letters" className="rounded-lg border border-slate-200 p-3 text-sm hover:bg-slate-50">
          <AlertTriangle className="mb-1 h-4 w-4 text-slate-500" /> Surat Keterangan
        </Link>
      </div>
    </div>
  );
}
