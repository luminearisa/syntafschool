import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CircleDollarSign, Scale, Shirt, UserRound } from 'lucide-react';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Money, PageHeader, Panel, Stat, StatusPill, Weight } from '../components/LaundryUi';

/**
 * Portal santri (§27, §62): jatah saya, order saya, statement saya.
 * Identitas dibaca dari akun — bukan parameter request.
 */
export default function StudentLaundryPage() {
  const quota = useQuery({ queryKey: ['laundry', 'me-quota'], queryFn: () => laundryApi.meQuota() });
  const orders = useQuery({ queryKey: ['laundry', 'me-orders'], queryFn: () => laundryApi.meOrders() });
  const statement = useQuery({ queryKey: ['laundry', 'me-statement'], queryFn: () => laundryApi.meStatement() });

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<UserRound className="h-6 w-6" />}
        title="Laundry Saya"
        description="Jatah gratis, riwayat order, dan pemakaian periode berjalan."
      />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <Stat
          label="Sisa jatah"
          value={<Weight value={quota.data?.remaining} />}
          icon={<Scale className="h-4 w-4" />}
          tone="good"
          hint={`${quota.data?.quota_type ?? ''} · ${quota.data?.period?.key ?? '—'}`}
        />
        <Stat label="Terpakai" value={<Weight value={quota.data?.used} />} hint={`dari ${quota.data?.allocated ?? 0}`} />
        <Stat
          label="Total dibayar"
          value={<Money value={statement.data?.totals.paid_amount} compact />}
          icon={<CircleDollarSign className="h-4 w-4" />}
          tone="brand"
          hint="periode berjalan"
        />
        <Stat label="Order" value={orders.data?.length ?? 0} icon={<Shirt className="h-4 w-4" />} hint="riwayat saya" />
      </div>

      <Panel title="Order saya">
        <DataTable
          loading={orders.isLoading}
          rows={orders.data ?? []}
          empty="Belum ada order laundry."
          columns={[
            { key: 'order', header: 'Order', render: (r) => <span className="font-mono text-xs font-semibold">{r.order_number}</span> },
            { key: 'date', header: 'Tanggal', render: (r) => <DateOnly value={r.order_date} /> },
            { key: 'split', header: 'Gratis / Bayar', render: (r) => (
              <span><Weight value={r.free_quantity} /> / <Weight value={r.paid_quantity} /></span>
            ) },
            { key: 'amount', header: 'Tagihan', render: (r) => <Money value={r.total_amount} /> },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
          ]}
        />
      </Panel>

      <Panel title="Statement periode" description="Transaksi + referensi wallet saya">
        <DataTable
          loading={statement.isLoading}
          rows={statement.data?.transactions ?? []}
          empty="Belum ada transaksi."
          columns={[
            { key: 'date', header: 'Tanggal', render: (r) => <DateOnly value={r.date} /> },
            { key: 'order', header: 'Order', render: (r) => <span className="font-mono text-xs">{r.order_number}</span> },
            { key: 'free', header: 'Gratis', render: (r) => <Weight value={r.free} /> },
            { key: 'charge', header: 'Bayar', render: (r) => <Money value={r.charge} /> },
            { key: 'ref', header: 'Ref wallet', render: (r) => <span className="font-mono text-xs">{r.wallet_ref ?? '—'}</span> },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
          ]}
        />
        <p className="mt-3 text-xs text-slate-500">
          Butuh bantuan? Hubungi petugas laundry — <Link to="/laundry/portal" className="text-brand-700 underline">lihat juga versi wali</Link>.
        </p>
      </Panel>
    </div>
  );
}
