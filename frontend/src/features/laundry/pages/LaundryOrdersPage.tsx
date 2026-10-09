import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import { toast } from 'sonner';
import { ClipboardList, ListFilter, PackagePlus, Search, Wallet, X } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { usePermission } from '@/hooks/usePermission';
import { laundryApi } from '../api/laundryApi';
import { DataTable, FilterBar, Money, PageHeader, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

const STATUSES = ['', 'DRAFT', 'RECEIVED', 'WEIGHED', 'PAYMENT_REQUIRED', 'PAID', 'QUEUED', 'WASHING', 'DRYING', 'IRONING', 'FOLDING', 'QUALITY_CHECK', 'PACKAGED', 'READY', 'DELIVERED', 'COMPLETED', 'CANCELLED', 'LOST', 'DAMAGED'];
const PAYMENT_STATUSES = ['', 'PENDING', 'NOT_REQUIRED', 'PAYMENT_REQUIRED', 'PAID', 'FAILED', 'REFUNDED', 'WAIVED'];

const STATUS_LABELS: Record<string, string> = {
  DRAFT: 'Draf', RECEIVED: 'Diterima', WEIGHED: 'Ditimbang', PAYMENT_REQUIRED: 'Perlu Bayar',
  PAID: 'Lunas', QUEUED: 'Mengantre', WASHING: 'Mencuci', DRYING: 'Mengeringkan',
  IRONING: 'Menyetrika', FOLDING: 'Melipat', QUALITY_CHECK: 'QC', PACKAGED: 'Dikemas',
  READY: 'Siap Diambil', DELIVERED: 'Terkirim', COMPLETED: 'Selesai',
  CANCELLED: 'Dibatalkan', LOST: 'Hilang', DAMAGED: 'Rusak',
  PENDING: 'Menunggu', NOT_REQUIRED: 'Tanpa Biaya', FAILED: 'Gagal', REFUNDED: 'Direfund', WAIVED: 'Dibebaskan',
};

/**
 * Daftar & pencarian order (§61).
 * Filter: status, status bayar, layanan, rentang tanggal, pencarian nomor/NIS/nama.
 */
export default function LaundryOrdersPage() {
  const { can } = usePermission();
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

  const hasFilter = Boolean(filters.status || filters.payment_status || filters.laundry_service_id || filters.from || filters.to || filters.search);

  const resetFilter = () => {
    setSearch('');
    setParams(new URLSearchParams(), { replace: true });
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
    <div className="space-y-5">
      <PageHeader
        icon={<ClipboardList className="h-6 w-6" />}
        title="Order Laundry"
        description="Seluruh transaksi: terima → timbang → jatah/bayar → proses → serah terima."
        actions={can('laundry.create') ? (
          <Link to="/laundry/receive">
            <Button>
              <PackagePlus className="h-4 w-4" /> Buat Order
            </Button>
          </Link>
        ) : undefined}
      />

      <Panel title="Filter & pencarian" description="Kombinasikan status, layanan, dan rentang tanggal — tersimpan di URL untuk dibagikan.">
        <FilterBar>
          <div className="min-w-40 flex-1 sm:max-w-xs">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input
                placeholder="Cari nomor / nama / NIS…"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') set('search', search.trim()); }}
                className="pl-9"
              />
            </div>
          </div>
          <Select value={params.get('status') ?? ''} onChange={(e) => set('status', e.target.value)} aria-label="Status" className="w-44">
            {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : STATUS_LABELS[s] ?? s}</option>)}
          </Select>
          <Select value={params.get('payment_status') ?? ''} onChange={(e) => set('payment_status', e.target.value)} aria-label="Status bayar" className="w-44">
            {PAYMENT_STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua pembayaran' : STATUS_LABELS[s] ?? s}</option>)}
          </Select>
          <Select value={params.get('laundry_service_id') ?? ''} onChange={(e) => set('laundry_service_id', e.target.value)} aria-label="Layanan" className="w-44">
            <option value="">Semua layanan</option>
            {(services.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </Select>
          <Input type="date" value={params.get('from') ?? ''} onChange={(e) => set('from', e.target.value)} aria-label="Dari" className="w-40" />
          <Input type="date" value={params.get('to') ?? ''} onChange={(e) => set('to', e.target.value)} aria-label="Sampai" className="w-40" />
          <Button variant="secondary" onClick={() => set('search', search.trim())}>Cari</Button>
          {hasFilter ? (
            <Button variant="ghost" onClick={resetFilter}>
              <X className="h-4 w-4" /> Reset
            </Button>
          ) : null}
        </FilterBar>
        {hasFilter ? (
          <p className="flex items-center gap-1.5 text-xs text-slate-500">
            <ListFilter className="h-3.5 w-3.5" /> Filter aktif — hasil {orders.data?.meta.total ?? 0} order.
          </p>
        ) : null}
      </Panel>

      <Panel title={`Hasil (${orders.data?.meta.total ?? 0} order)`}>
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Tidak ada order pada filter ini."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <div>
                <Link to={`/laundry/orders/${row.id}`} className="font-medium text-brand-700 hover:underline">{row.order_number}</Link>
                <p className="text-xs text-slate-500">{row.service ?? '—'} · {row.order_date ?? '—'}</p>
                <p className="text-xs text-slate-400">{row.student_name ?? `santri #${row.student_id}`}{row.nis ? ` · NIS ${row.nis}` : ''}</p>
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
              <div className="flex flex-wrap gap-1.5">
                {can('laundry.payment.view') && row.payment_status === 'PAYMENT_REQUIRED' ? (
                  <Button size="sm" onClick={() => payOrder.mutate(row.id)} loading={payOrder.isPending}>
                    <Wallet className="h-3.5 w-3.5" /> Bayar
                  </Button>
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
