import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateTime, Money, Panel, Stat, StatusPill, Weight } from '../components/LaundryUi';
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

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Laundry Control Center</h1>
          <p className="text-sm text-slate-500">
            {data.date} · jatah gratis dipakai dulu, sisanya dibayar via Wallet/RFID
          </p>
        </div>
        <div className="flex gap-2">
          <Link to="/laundry/receive" className="rounded-lg bg-slate-900 px-3 py-2 text-sm font-medium text-white">
            Meja Penerimaan
          </Link>
          <Link to="/laundry/processing" className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">
            Papan Proses
          </Link>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 lg:grid-cols-6">
        <Stat label="Order hari ini" value={data.today.orders} hint={`${data.today.completed} selesai · ${data.today.cancelled} batal`} />
        <Stat label="Berat hari ini" value={<Weight value={data.today.weight_kg} />} hint={`${data.today.received} baru diterima`} />
        <Stat
          label="Menunggu bayar"
          value={data.today.payment_required}
          tone={data.today.payment_required > 0 ? 'warn' : 'good'}
          hint="porsi berbayar belum lunas"
        />
        <Stat label="Dalam proses" value={data.pipeline.processing} hint={`cuci ${data.pipeline.washing} · kering ${data.pipeline.drying}`} />
        <Stat label="Siap diambil" value={data.pipeline.ready} tone={data.pipeline.ready > 0 ? 'warn' : 'default'} hint={`${data.pipeline.packaged} dikemas`} />
        <Stat label="Insiden terbuka" value={data.incidents_open} tone={data.incidents_open > 0 ? 'bad' : 'good'} hint={`${data.delayed} order telat`} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel
          title="Operasional hari ini"
          description="Order, kilogram gratis vs berbayar, dan pendapatan wallet"
          action={<Link to="/laundry/reports" className="text-xs font-medium text-slate-600 underline">Semua laporan</Link>}
        >
          {daily.isLoading || !ops ? (
            <p className="text-sm text-slate-500">Memuat…</p>
          ) : (
            <div className="grid grid-cols-2 gap-3">
              <Stat label="Total order" value={ops.total_orders} hint={`${ops.completed} selesai · ${ops.pending} berjalan`} />
              <Stat label="Total kg" value={<Weight value={ops.total_kg} />} hint={<span><Weight value={ops.free_kg} /> gratis · <Weight value={ops.paid_kg} /> bayar</span>} />
              <Stat label="Pendapatan" value={<Money value={ops.paid_revenue} compact />} hint={`${ops.wallet_transactions} transaksi wallet`} />
              <Stat label="Insiden" value={ops.incidents} tone={ops.incidents > 0 ? 'warn' : 'good'} />
            </div>
          )}
        </Panel>

        <Panel
          title="Antrean pemrosesan"
          description="Order aktif per tahap — masuk lewat papan proses"
          action={<Link to="/laundry/processing" className="text-xs font-medium text-slate-600 underline">Buka papan</Link>}
        >
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Stat label="Antre" value={data.pipeline.queued} />
            <Stat label="Cuci" value={data.pipeline.washing} />
            <Stat label="Kering" value={data.pipeline.drying} />
            <Stat label="Setrika" value={data.pipeline.ironing} />
            <Stat label="Lipat" value={data.pipeline.folding} />
            <Stat label="QC" value={data.pipeline.quality_check} />
            <Stat label="Dikemas" value={data.pipeline.packaged} />
            <Stat label="Terkirim" value={data.pipeline.delivered} />
          </div>
        </Panel>
      </div>

      <Panel
        title="Order terbaru"
        description="10 order terakhir — klik nomor untuk detail & timeline"
        action={<Link to="/laundry/orders" className="text-xs font-medium text-slate-600 underline">Semua order</Link>}
      >
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Belum ada order laundry."
          columns={[
            {
              key: 'order', header: 'Order', render: (row) => (
                <div>
                  <Link to={`/laundry/orders/${row.id}`} className="font-medium text-slate-800 underline">{row.order_number}</Link>
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
