import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, FilterBar, Money, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

/**
 * Meja penerimaan (§9, §17, §29–§31, §54).
 *
 * Alur kerja resepsionis: tap RFID/QR → profil santri (jatah + saldo + order
 * terbuka) → buat order → terima → timbang → kalkulasi split free/paid →
 * bayar bila ada porsi berbayar.
 */
export default function LaundryReceivePage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [uid, setUid] = useState('');
  const [orderForm, setOrderForm] = useState({ student_id: '', laundry_service_id: '', submitted_by: '', notes: '' });
  const [weighForm, setWeighForm] = useState({ order_id: '', gross_weight: '', bag_weight: '0', item_count: '' });
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);

  const services = useQuery({ queryKey: ['laundry', 'services'], queryFn: () => laundryApi.services() });
  const identify = useMutation({
    mutationFn: () => laundryApi.identify(uid.trim()),
    onError: (error: unknown) => toast.error(readError(error, 'RFID/QR tidak dikenali')),
  });
  const orders = useQuery({
    queryKey: ['laundry', 'orders', 'recent'],
    queryFn: () => laundryApi.orders({ per_page: 15 }),
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['laundry', 'orders'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'dashboard'] });
  };

  const createOrder = useMutation({
    mutationFn: () => laundryApi.createOrder({
      student_id: Number(orderForm.student_id),
      laundry_service_id: Number(orderForm.laundry_service_id),
      submitted_by: orderForm.submitted_by || undefined,
      notes: orderForm.notes || undefined,
    }),
    onSuccess: (order) => {
      toast.success(`Order ${order.order_number} dibuat`);
      setSelectedOrderId(order.id);
      setOrderForm({ student_id: '', laundry_service_id: '', submitted_by: '', notes: '' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal membuat order')),
  });

  const receiveOrder = useMutation({
    mutationFn: (id: number) => laundryApi.receiveOrder(id, {}),
    onSuccess: (order) => { toast.success(`Order ${order.order_number} diterima`); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menerima order')),
  });

  const weighOrder = useMutation({
    mutationFn: () => laundryApi.weighOrder(Number(weighForm.order_id), {
      gross_weight: weighForm.gross_weight ? Number(weighForm.gross_weight) : undefined,
      bag_weight: weighForm.bag_weight ? Number(weighForm.bag_weight) : undefined,
      item_count: weighForm.item_count ? Number(weighForm.item_count) : undefined,
    }),
    onSuccess: (order) => { toast.success(`Ditimbang: ${order.net_weight ?? 0} kg netto`); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menimbang')),
  });

  const calculateOrder = useMutation({
    mutationFn: (id: number) => laundryApi.calculateOrder(id),
    onSuccess: (order) => {
      toast.success(`Terkalkulasi: gratis ${order.free_quantity} · bayar ${order.paid_quantity} · Rp${order.total_amount}`);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal kalkulasi')),
  });

  const payOrder = useMutation({
    mutationFn: (id: number) => laundryApi.payOrder(id, { idempotency_key: newIdempotencyKey('pay') }),
    onSuccess: (result) => {
      const payment = result.payment as { amount?: number };
      toast.success(`Dibayar Rp${payment?.amount ?? 0}${result.replayed ? ' (replay)' : ''}`);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Pembayaran gagal — cek saldo wallet')),
  });

  const profile = identify.data;

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Meja Penerimaan</h1>
        <p className="text-sm text-slate-500">Tap RFID/QR → buat order → terima → timbang → kalkulasi → bayar.</p>
      </div>

      <Panel title="Identifikasi santri" description="Kartu wallet santri, laundry bag, atau label order (§17)">
        <div className="flex flex-wrap gap-2">
          <Input
            placeholder="UID kartu / kode QR / kode tas…"
            value={uid}
            onChange={(e) => setUid(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && uid.trim()) identify.mutate(); }}
            className="max-w-sm"
          />
          <Button onClick={() => uid.trim() && identify.mutate()} loading={identify.isPending} disabled={!uid.trim()}>
            Identifikasi
          </Button>
        </div>
        {profile ? (
          <div className="mt-3 grid gap-3 md:grid-cols-4">
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Santri</p>
              <p className="font-medium text-slate-900">{String((profile.student as Record<string, unknown>)?.full_name ?? '—')}</p>
              <p className="text-xs text-slate-500">NIS {String((profile.student as Record<string, unknown>)?.nis ?? '—')}</p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Sisa jatah</p>
              <p className="text-lg font-semibold text-slate-900"><Weight value={profile.quota?.remaining} /></p>
              <p className="text-xs text-slate-500">{profile.quota?.quota_type ?? 'tanpa policy'} · periode {profile.quota?.period?.key ?? '—'}</p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Saldo wallet</p>
              <p className="text-lg font-semibold text-slate-900"><Money value={profile.wallet?.balance} /></p>
              <p className="text-xs text-slate-500">{profile.wallet?.number ?? 'belum punya wallet'}</p>
            </div>
            <div className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="text-xs uppercase tracking-wide text-slate-500">Order terbuka</p>
              <p className="text-lg font-semibold text-slate-900">{profile.open_orders.length}</p>
              <p className="text-xs text-slate-500">{profile.open_orders.slice(0, 2).map((o) => o.order_number).join(', ') || '—'}</p>
            </div>
          </div>
        ) : null}
      </Panel>

      {can('laundry.create') ? (
        <Panel title="Buat order baru" description="Santri wajib aktif (Rule 2)">
          <div className="grid gap-3 md:grid-cols-4">
            <Field label="ID santri" required>
              <Input
                placeholder="cth. 123"
                value={orderForm.student_id}
                onChange={(e) => setOrderForm({ ...orderForm, student_id: e.target.value })}
              />
            </Field>
            <Field label="Layanan" required>
              <Select value={orderForm.laundry_service_id} onChange={(e) => setOrderForm({ ...orderForm, laundry_service_id: e.target.value })}>
                <option value="">— pilih —</option>
                {(services.data ?? []).map((s) => (
                  <option key={s.id} value={s.id}>{s.name} ({s.pricing_model})</option>
                ))}
              </Select>
            </Field>
            <Field label="Diserahkan oleh">
              <Input
                placeholder="santri / wali / petugas"
                value={orderForm.submitted_by}
                onChange={(e) => setOrderForm({ ...orderForm, submitted_by: e.target.value })}
              />
            </Field>
            <Field label="Catatan">
              <Input value={orderForm.notes} onChange={(e) => setOrderForm({ ...orderForm, notes: e.target.value })} />
            </Field>
          </div>
          <div className="mt-3">
            <Button
              onClick={() => createOrder.mutate()}
              loading={createOrder.isPending}
              disabled={!orderForm.student_id || !orderForm.laundry_service_id}
            >
              Buat order (DRAFT)
            </Button>
          </div>
        </Panel>
      ) : null}

      {can('laundry.weigh') ? (
        <Panel title="Timbang & hitung" description="Netto = bruto − berat tas (§30)">
          <div className="grid gap-3 md:grid-cols-4">
            <Field label="ID order" required>
              <Input value={weighForm.order_id} onChange={(e) => setWeighForm({ ...weighForm, order_id: e.target.value })} placeholder="cth. 45" />
            </Field>
            <Field label="Berat bruto (kg)">
              <Input type="number" min="0" step="0.001" value={weighForm.gross_weight} onChange={(e) => setWeighForm({ ...weighForm, gross_weight: e.target.value })} />
            </Field>
            <Field label="Berat tas (kg)">
              <Input type="number" min="0" step="0.001" value={weighForm.bag_weight} onChange={(e) => setWeighForm({ ...weighForm, bag_weight: e.target.value })} />
            </Field>
            <Field label="Jumlah item">
              <Input type="number" min="0" value={weighForm.item_count} onChange={(e) => setWeighForm({ ...weighForm, item_count: e.target.value })} />
            </Field>
          </div>
          <div className="mt-3">
            <Button onClick={() => weighOrder.mutate()} loading={weighOrder.isPending} disabled={!weighForm.order_id}>
              Simpan timbangan
            </Button>
          </div>
        </Panel>
      ) : null}

      <Panel title="Order terbaru" description="Terima → kalkulasi → bayar dari sini">
        <FilterBar>
          <span className="text-xs text-slate-500">Klik nomor order untuk detail & timeline lengkap.</span>
        </FilterBar>
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Belum ada order."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <div>
                <button type="button" className="font-medium text-slate-800 underline" onClick={() => setSelectedOrderId(row.id)}>
                  {row.order_number}
                </button>
                <p className="text-xs text-slate-500">{row.status_group}</p>
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
                {can('laundry.receive') && (row.status === 'DRAFT' || row.status === 'RECEIVED') ? (
                  <Button size="sm" variant="outline" onClick={() => receiveOrder.mutate(row.id)} loading={receiveOrder.isPending}>Terima</Button>
                ) : null}
                {can('laundry.weigh') && (row.status === 'WEIGHED' || row.status === 'RECEIVED' || row.status === 'DRAFT') ? (
                  <Button size="sm" variant="outline" onClick={() => calculateOrder.mutate(row.id)} loading={calculateOrder.isPending}>Kalkulasi</Button>
                ) : null}
                {can('laundry.payment.view') && row.payment_status === 'PAYMENT_REQUIRED' ? (
                  <Button size="sm" onClick={() => payOrder.mutate(row.id)} loading={payOrder.isPending}>Bayar wallet</Button>
                ) : null}
              </div>
            ) },
          ]}
        />
        {selectedOrderId ? (
          <p className="mt-2 text-xs text-slate-500">
            Order #{selectedOrderId} dipilih — buka detail & timeline di{' '}
            <a className="underline" href={`/laundry/orders/${selectedOrderId}`}>halaman order</a>.
          </p>
        ) : null}
      </Panel>

      <Panel title="Pratinjau harga" description="Cek tagihan + jatah + saldo sebelum konfirmasi (§54)">
        <Textarea placeholder="Gunakan halaman order untuk pratinjau per order (payment-preview)." disabled rows={2} />
      </Panel>
    </div>
  );
}
