import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, FilterBar, Money, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

const STATUSES = ['', 'DRAFT', 'RECEIVED', 'WEIGHED', 'PAYMENT_REQUIRED', 'PAID', 'QUEUED', 'WASHING', 'DRYING', 'IRONING', 'FOLDING', 'QUALITY_CHECK', 'PACKAGED', 'READY', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'LOST', 'DAMAGED'];
const PAYMENT_STATUSES = ['', 'PENDING', 'NOT_REQUIRED', 'PAYMENT_REQUIRED', 'PAID', 'FAILED', 'REFUNDED', 'WAIVED'];

/**
 * Daftar & pencarian order (§61).
 * Filter: status, status bayar, layanan, rentang tanggal, pencarian nomor/NIS/nama.
 */
export default function LaundryOrdersPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [search, setSearch] = useState(params.get('search') ?? '');

  const filters = {
    status: params.get('status') || undefined,
    payment_status: params.get('payment_status') || undefined,
    laundry_service_id: params.get('laundry_service_id') ? Number(params.get('laundry_service_id')) : undefined,
    from: params.get('from') || undefined,
    to: params.get('to') || undefined,
    search: params.get('search') || undefined,
  };

  const services = useQuery({ queryKey: ['laundry', 'services'], queryFn: () => laundryApi.services() });
  const orders = useQuery({
    queryKey: ['laundry', 'orders', filters],
    queryFn: () => laundryApi.orders({ ...filters, per_page: 25 }),
  });

  const set = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value); else next.delete(key);
    setParams(next, { replace: true });
  };

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['laundry', 'orders'] });

  const payOrder = useMutation({
    mutationFn: (id: number) => laundryApi.payOrder(id, { idempotency_key: newIdempotencyKey('pay') }),
    onSuccess: (result) => {
      const payment = result.payment as { amount?: number };
      toast.success(`Dibayar Rp${payment?.amount ?? 0}${result.replayed ? ' (replay idempoten)' : ''}`);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Pembayaran gagal')),
  });

  const queueOrder = useMutation({
    mutationFn: (id: number) => laundryApi.queueOrder(id),
    onSuccess: () => { toast.success('Order masuk antrean proses'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengantrekan — pastikan sudah lunas / override')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Order Laundry</h1>
        <p className="text-sm text-slate-500">Seluruh transaksi: terima → timbang → jatah/bayar → proses → serah terima.</p>
      </div>

      <Panel title="Filter">
        <FilterBar>
          <Select value={params.get('status') ?? ''} onChange={(e) => set('status', e.target.value)} aria-label="Status">
            {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : s}</option>)}
          </Select>
          <Select value={params.get('payment_status') ?? ''} onChange={(e) => set('payment_status', e.target.value)} aria-label="Status bayar">
            {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua pembayaran' : s}</option>)}
          </Select>
          <Select value={params.get('laundry_service_id') ?? ''} onChange={(e) => set('laundry_service_id', e.target.value)} aria-label="Layanan">
            <option value="">Semua layanan</option>
            {(services.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
          <Input type="date" value={params.get('from') ?? ''} onChange={(e) => set('from', e.target.value)} aria-label="Dari" />
          <Input type="date" value={params.get('to') ?? ''} onChange={(e) => set('to', e.target.value)} aria-label="Sampai" />
          <Input
            placeholder="Cari nomor / nama / NIS…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') set('search', search.trim()); }}
            className="w-56"
          />
          <Button variant="secondary" onClick={() => set('search', search.trim())}>Cari</Button>
        </FilterBar>
      </Panel>

      <Panel title={`Hasil (${orders.data?.meta.total ?? 0} order)`}>
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Tidak ada order pada filter ini."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <div>
                <Link to={`/laundry/orders/${row.id}`} className="font-medium text-slate-800 underline">{row.order_number}</Link>
                <p className="text-xs text-slate-500">{row.service ?? '—'} · {row.order_date ?? '—'}</p>
              </div>
            ) },
            { key: 'weight', header: 'Netto', render: (row) => <Weight value={row.net_weight} /> },
            { key: 'split', header: 'Gratis / Bayar', render: (row) => (
              <span><Weight value={row.free_quantity} /> / <Weight value={row.paid_quantity} /></span>
            ) },
            { key: 'amount', header: 'Tagihan', render: (row) => <Money value={row.total_amount} /> },
            { key: 'payment', header: 'Bayar', render: (row) => <StatusPill status={row.payment_status} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'aksi', header: 'Aksi', render: (row) => (
              <div className="flex flex-wrap gap-1">
                {can('laundry.payment.view') && row.payment_status === 'PAYMENT_REQUIRED' ? (
                  <Button size="sm" onClick={() => payOrder.mutate(row.id)} loading={payOrder.isPending}>Bayar</Button>
                ) : null}
                {can('laundry.process') && (row.status === 'PAID' || row.status === 'PAYMENT_REQUIRED') ? (
                  <Button size="sm" variant="outline" onClick={() => queueOrder.mutate(row.id)} loading={queueOrder.isPending}>Antrekan</Button>
                ) : null}
              </div>
            ) },
          ]}
        />
      </Panel>
    </div>
  );
}
