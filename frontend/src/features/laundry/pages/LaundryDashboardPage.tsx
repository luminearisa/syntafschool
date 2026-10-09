import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import {
  ArrowRight, CircleDollarSign, Clock, PackageCheck, Shirt, Sparkles, Truck, TriangleAlert, WashingMachine, Waves,
} from 'lucide-react';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateTime, Money, PageHeader, Panel, Stat, StatusPill, Weight } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

/**
 * Dasbor laundry (§48).
 *
 * Angka yang ditampilkan adalah angka yang dipakai mengambil keputusan harian:
 * order masuk, berat, tagihan menunggu bayar, antrean per tahap, insiden
 * terbuka, dan order melewati due date.
 */
export default function LaundryDashboardPage() {
  const dashboard = useQuery({
    queryKey: ['laundry', 'dashboard'],
    queryFn: () => laundryApi.dashboard(),
    refetchInterval: 60_000,
  });
  const daily = useQuery({
    queryKey: ['laundry', 'daily'],
    queryFn: () => laundryApi.dailyOperations(),
    refetchInterval: 60_000,
  });
  const orders = useQuery({
    queryKey: ['laundry', 'orders', 'pending'],
    queryFn: () => laundryApi.orders({ per_page: 10 }),
  });

  if (dashboard.isLoading || !dashboard.data) {
    return <p className="text-sm text-slate-500">Memuat dasbor laundry…</p>;
  }

  const data = dashboard.data;
  const ops = daily.data;

  const pipeline: Array<{ label: string; value: number; icon: React.ReactNode }> = [
    { label: 'Mengantre', value: data.pipeline.queued, icon: <Clock className="h-4 w-4" /> },
    { label: 'Mencuci', value: data.pipeline.washing, icon: <Waves className="h-4 w-4" /> },
    { label: 'Mengeringkan', value: data.pipeline.drying, icon: <WashingMachine className="h-4 w-4" /> },
    { label: 'Menyetrika', value: data.pipeline.ironing, icon: <Sparkles className="h-4 w-4" /> },
    { label: 'Melipat', value: data.pipeline.folding, icon: <Shirt className="h-4 w-4" /> },
    { label: 'QC', value: data.pipeline.quality_check, icon: <PackageCheck className="h-4 w-4" /> },
    { label: 'Dikemas', value: data.pipeline.packaged, icon: <PackageCheck className="h-4 w-4" /> },
    { label: 'Terkirim', value: data.pipeline.delivered, icon: <Truck className="h-4 w-4" /> },
  ];

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<WashingMachine className="h-6 w-6" />}
        title="Laundry Control Center"
        description={<>Ringkasan operasional {data.date} · jatah gratis dipakai dulu, sisanya dibayar via Wallet/RFID.</>}
        actions={
          <>
            <Link
              to="/laundry/receive"
              className="inline-flex h-9 items-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white shadow-sm transition-colors hover:bg-brand-700"
            >
              Meja Penerimaan <ArrowRight className="h-4 w-4" />
            </Link>
            <Link
              to="/laundry/processing"
              className="inline-flex h-9 items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-50"
            >
              Papan Proses
            </Link>
          </>
        }
      />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
        <Stat
          label="Order hari ini"
          value={data.today.orders}
          icon={<Shirt className="h-4 w-4" />}
          hint={`${data.today.completed} selesai · ${data.today.cancelled} batal`}
        />
        <Stat
          label="Berat hari ini"
          value={<Weight value={data.today.weight_kg} />}
          icon={<Waves className="h-4 w-4" />}
          tone="info"
          hint={`${data.today.received} baru diterima`}
        />
        <Stat
          label="Menunggu bayar"
          value={data.today.payment_required}
          icon={<CircleDollarSign className="h-4 w-4" />}
          tone={data.today.payment_required > 0 ? 'warn' : 'good'}
          hint="porsi berbayar belum lunas"
        />
        <Stat
          label="Dalam proses"
          value={data.pipeline.processing}
          icon={<WashingMachine className="h-4 w-4" />}
          hint={`cuci ${data.pipeline.washing} · kering ${data.pipeline.drying}`}
        />
        <Stat
          label="Siap diambil"
          value={data.pipeline.ready}
          icon={<PackageCheck className="h-4 w-4" />}
          tone={data.pipeline.ready > 0 ? 'warn' : 'default'}
          hint={`${data.pipeline.packaged} dikemas`}
        />
        <Stat
          label="Insiden terbuka"
          value={data.incidents_open}
          icon={<TriangleAlert className="h-4 w-4" />}
          tone={data.incidents_open > 0 ? 'bad' : 'good'}
          hint={`${data.delayed} order telat`}
        />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel
          title="Operasional hari ini"
          description="Order, kilogram gratis vs berbayar, dan pendapatan wallet"
          action={<Link to="/laundry/reports" className="text-xs font-medium text-brand-700 hover:underline">Semua laporan →</Link>}
        >
          {daily.isLoading || !ops ? (
            <p className="text-sm text-slate-500">Memuat…</p>
          ) : (
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Total order" value={ops.total_orders} hint={`${ops.completed} selesai · ${ops.pending} berjalan`} />
              <Stat label="Total kg" value={<Weight value={ops.total_kg} />} hint={<span><Weight value={ops.free_kg} /> gratis · <Weight value={ops.paid_kg} /> bayar</span>} />
              <Stat label="Pendapatan" value={<Money value={ops.paid_revenue} compact />} tone="brand" hint={`${ops.wallet_transactions} transaksi wallet`} />
              <Stat label="Insiden" value={ops.incidents} tone={ops.incidents > 0 ? 'warn' : 'good'} hint="laporan hari ini" />
            </div>
          )}
        </Panel>

        <Panel
          title="Antrean pemrosesan"
          description="Order aktif per tahap — kelola lewat papan proses"
          action={<Link to="/laundry/processing" className="text-xs font-medium text-brand-700 hover:underline">Buka papan →</Link>}
        >
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            {pipeline.map((stage) => (
              <div
                key={stage.label}
                className={`rounded-xl border p-3 transition-colors ${stage.value > 0 ? 'border-brand-200 bg-brand-50/50' : 'border-slate-200 bg-slate-50/50'}`}
              >
                <div className="flex items-center gap-1.5 text-slate-500">
                  <span className={stage.value > 0 ? 'text-brand-600' : 'text-slate-400'}>{stage.icon}</span>
                  <p className="text-[11px] font-semibold uppercase tracking-wide">{stage.label}</p>
                </div>
                <p className={`mt-1.5 text-xl font-semibold tabular-nums ${stage.value > 0 ? 'text-brand-700' : 'text-slate-400'}`}>
                  {stage.value}
                </p>
              </div>
            ))}
          </div>
        </Panel>
      </div>

      <Panel
        title="Order terbaru"
        description="10 order terakhir — klik nomor untuk detail & timeline"
        action={<Link to="/laundry/orders" className="text-xs font-medium text-brand-700 hover:underline">Semua order →</Link>}
      >
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Belum ada order laundry."
          columns={[
            {
              key: 'order', header: 'Order', render: (row) => (
                <div>
                  <Link to={`/laundry/orders/${row.id}`} className="font-medium text-brand-700 hover:underline">{row.order_number}</Link>
                  <p className="text-xs text-slate-500">{row.service ?? '—'} · {row.order_date ?? '—'}</p>
                </div>
              ),
            },
            {
              key: 'weight', header: 'Berat', render: (row) => (
                <span><Weight value={row.net_weight} /> <span className="text-xs text-slate-400">({row.item_count} item)</span></span>
              ),
            },
            {
              key: 'split', header: 'Gratis / Bayar', render: (row) => (
                <span><Weight value={row.free_quantity} /> / <Weight value={row.paid_quantity} /></span>
              ),
            },
            { key: 'amount', header: 'Tagihan', render: (row) => <Money value={row.total_amount} /> },
            { key: 'payment', header: 'Bayar', render: (row) => <StatusPill status={row.payment_status} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'due', header: 'Jatuh tempo', render: (row) => <DateTime value={row.due_at} /> },
          ]}
        />
      </Panel>
    </div>
  );
}
