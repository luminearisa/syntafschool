import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { toast } from 'sonner';
import { CircleDollarSign, PackagePlus, ScanLine, Scale, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SideModal } from '@/components/ui/SideModal';
import { usePermission } from '@/hooks/usePermission';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, Money, PageHeader, Panel, StatusPill, Weight, newIdempotencyKey, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

/**
 * Meja penerimaan (§9, §17, §29–§31, §54).
 *
 * Alur kerja resepsionis: tap RFID/QR → profil santri (jatah + saldo + order
 * terbuka) → buat order → terima → timbang → kalkulasi split free/paid →
 * bayar bila ada porsi berbayar. Form buat order & timbangan hidup di
 * drawer samping agar daftar order tetap terlihat sebagai konteks.
 */
export default function LaundryReceivePage() {
  const { can } = usePermission();
  const queryClient = useQueryClient();
  const [uid, setUid] = useState('');
  const [orderForm, setOrderForm] = useState({ student_id: '', laundry_service_id: '', submitted_by: '', notes: '' });
  const [weighForm, setWeighForm] = useState({ order_id: '', gross_weight: '', bag_weight: '0', item_count: '' });
  const [selectedOrderId, setSelectedOrderId] = useState<number | null>(null);
  const [orderModalOpen, setOrderModalOpen] = useState(false);
  const [weighModalOpen, setWeighModalOpen] = useState(false);
  const [weighTarget, setWeighTarget] = useState<OrderSummary | null>(null);

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
      setOrderModalOpen(false);
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
    onSuccess: (order) => {
      toast.success(`Ditimbang: ${order.net_weight ?? 0} kg netto`);
      setWeighModalOpen(false);
      setWeighForm({ order_id: '', gross_weight: '', bag_weight: '0', item_count: '' });
      refresh();
    },
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
  const openWeigh = (row: OrderSummary) => {
    setWeighTarget(row);
    setWeighForm({ order_id: String(row.id), gross_weight: '', bag_weight: '0', item_count: '' });
    setWeighModalOpen(true);
  };

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<ScanLine className="h-6 w-6" />}
        title="Meja Penerimaan"
        description="Tap RFID/QR → buat order → terima → timbang → kalkulasi → bayar."
        actions={can('laundry.create') ? (
          <Button onClick={() => setOrderModalOpen(true)}>
            <PackagePlus className="h-4 w-4" /> Buat Order
          </Button>
        ) : undefined}
      />

      <Panel title="Identifikasi santri" description="Kartu wallet santri, laundry bag, atau label order (§17)">
        <div className="flex flex-wrap gap-2">
          <Input
            placeholder="UID kartu / kode QR / kode tas…"
            value={uid}
            onChange={(e) => setUid(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter' && uid.trim()) identify.mutate(); }}
            className="max-w-sm font-mono"
          />
          <Button onClick={() => uid.trim() && identify.mutate()} loading={identify.isPending} disabled={!uid.trim()}>
            <ScanLine className="h-4 w-4" /> Identifikasi
          </Button>
        </div>
        {profile ? (
          <div className="mt-4 grid gap-3 md:grid-cols-4">
            <div className="rounded-xl border border-brand-200 bg-brand-50/60 p-3.5">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-brand-700">Santri</p>
              <p className="mt-1 font-semibold text-slate-900">{String((profile.student as Record<string, unknown>)?.full_name ?? '—')}</p>
              <p className="text-xs text-slate-500">NIS {String((profile.student as Record<string, unknown>)?.nis ?? '—')}</p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-white p-3.5">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Sisa jatah</p>
              <p className="mt-1 text-lg font-semibold text-emerald-600"><Weight value={profile.quota?.remaining} /></p>
              <p className="text-xs text-slate-500">{profile.quota?.quota_type ?? 'tanpa policy'} · periode {profile.quota?.period?.key ?? '—'}</p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-white p-3.5">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Saldo wallet</p>
              <p className="mt-1 text-lg font-semibold text-slate-900"><Money value={profile.wallet?.balance} /></p>
              <p className="font-mono text-xs text-slate-500">{profile.wallet?.number ?? 'belum punya wallet'}</p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-white p-3.5">
              <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Order terbuka</p>
              <p className="mt-1 text-lg font-semibold text-slate-900">{profile.open_orders.length}</p>
              <p className="truncate text-xs text-slate-500">{profile.open_orders.slice(0, 2).map((o) => o.order_number).join(', ') || '—'}</p>
            </div>
          </div>
        ) : null}
      </Panel>

      <Panel title="Order terbaru" description="Terima → timbang → kalkulasi → bayar dari baris ini">
        <DataTable<OrderSummary>
          loading={orders.isLoading}
          rows={orders.data?.rows ?? []}
          empty="Belum ada order."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <div>
                <Link to={`/laundry/orders/${row.id}`} className="font-medium text-brand-700 hover:underline">
                  {row.order_number}
                </Link>
                <p className="text-xs text-slate-500">{row.service ?? '—'} · {row.student_name ?? `santri #${row.student_id}`}</p>
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
                {can('laundry.receive') && (row.status === 'DRAFT' || row.status === 'RECEIVED') ? (
                  <Button size="sm" variant="outline" onClick={() => receiveOrder.mutate(row.id)} loading={receiveOrder.isPending}>Terima</Button>
                ) : null}
                {can('laundry.weigh') && (row.status === 'WEIGHED' || row.status === 'RECEIVED' || row.status === 'DRAFT') ? (
                  <Button size="sm" variant="outline" onClick={() => openWeigh(row)}>
                    <Scale className="h-3.5 w-3.5" /> Timbang
                  </Button>
                ) : null}
                {can('laundry.weigh') && (row.status === 'WEIGHED' || row.status === 'RECEIVED' || row.status === 'DRAFT') ? (
                  <Button size="sm" variant="outline" onClick={() => calculateOrder.mutate(row.id)} loading={calculateOrder.isPending}>Kalkulasi</Button>
                ) : null}
                {can('laundry.payment.view') && row.payment_status === 'PAYMENT_REQUIRED' ? (
                  <Button size="sm" onClick={() => payOrder.mutate(row.id)} loading={payOrder.isPending}>
                    <Wallet className="h-3.5 w-3.5" /> Bayar
                  </Button>
                ) : null}
              </div>
            ) },
          ]}
        />
        {selectedOrderId ? (
          <p className="mt-3 text-xs text-slate-500">
            Order terakhir #{selectedOrderId} — buka detail & timeline di{' '}
            <Link className="text-brand-700 underline" to={`/laundry/orders/${selectedOrderId}`}>halaman order</Link>.
          </p>
        ) : null}
      </Panel>

      {/* ── Drawer: buat order baru ─────────────────────────────────── */}
      <SideModal
        open={orderModalOpen}
        onClose={() => setOrderModalOpen(false)}
        title="Buat Order Baru"
        description="Order dibuat sebagai DRAFT — lanjutkan dengan terima & timbang. Santri wajib berstatus aktif (Rule 2)."
        icon={<PackagePlus className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setOrderModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => createOrder.mutate()}
              loading={createOrder.isPending}
              disabled={!orderForm.student_id || !orderForm.laundry_service_id}
            >
              Buat Order (DRAFT)
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID santri" required hint="Angka unik santri, cth. 123 — atau identifikasi lewat RFID di atas.">
            <Input
              placeholder="cth. 123"
              value={orderForm.student_id}
              onChange={(e) => setOrderForm({ ...orderForm, student_id: e.target.value })}
            />
          </Field>
          <Field label="Layanan" required>
            <Select value={orderForm.laundry_service_id} onChange={(e) => setOrderForm({ ...orderForm, laundry_service_id: e.target.value })}>
              <option value="">— pilih layanan —</option>
              {(services.data ?? []).map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.pricing_model})</option>
              ))}
            </Select>
          </Field>
          <Field label="Diserahkan oleh" hint="Nama penyerah cucian: santri / wali / petugas.">
            <Input
              placeholder="santri / wali / petugas"
              value={orderForm.submitted_by}
              onChange={(e) => setOrderForm({ ...orderForm, submitted_by: e.target.value })}
            />
          </Field>
          <Field label="Catatan">
            <Input value={orderForm.notes} onChange={(e) => setOrderForm({ ...orderForm, notes: e.target.value })} placeholder="cth. ada seragam upacara" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: timbang order ───────────────────────────────────── */}
      <SideModal
        open={weighModalOpen}
        onClose={() => setWeighModalOpen(false)}
        title={weighTarget ? `Timbang ${weighTarget.order_number}` : 'Timbang Order'}
        description="Netto = berat bruto − berat tas (§30). Simpan untuk melanjutkan ke kalkulasi split gratis/bayar."
        icon={<Scale className="h-5 w-5" />}
        tone="slate"
        footer={
          <>
            <Button variant="ghost" onClick={() => setWeighModalOpen(false)}>Batal</Button>
            <Button onClick={() => weighOrder.mutate()} loading={weighOrder.isPending} disabled={!weighForm.order_id}>
              Simpan Timbangan
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          {weighTarget ? (
            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
              <p className="font-medium text-slate-900">{weighTarget.student_name ?? `Santri #${weighTarget.student_id}`}</p>
              <p className="text-xs text-slate-500">{weighTarget.service ?? '—'} · status <StatusPill status={weighTarget.status} /></p>
            </div>
          ) : null}
          <Field label="ID order" required>
            <Input value={weighForm.order_id} onChange={(e) => setWeighForm({ ...weighForm, order_id: e.target.value })} placeholder="cth. 45" />
          </Field>
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Berat bruto (kg)" required>
              <Input type="number" min="0" step="0.001" value={weighForm.gross_weight} onChange={(e) => setWeighForm({ ...weighForm, gross_weight: e.target.value })} placeholder="0.000" />
            </Field>
            <Field label="Berat tas (kg)">
              <Input type="number" min="0" step="0.001" value={weighForm.bag_weight} onChange={(e) => setWeighForm({ ...weighForm, bag_weight: e.target.value })} placeholder="0.5" />
            </Field>
            <Field label="Jumlah item">
              <Input type="number" min="0" value={weighForm.item_count} onChange={(e) => setWeighForm({ ...weighForm, item_count: e.target.value })} placeholder="cth. 7" />
            </Field>
          </div>
          {weighForm.gross_weight !== '' ? (
            <div className="rounded-xl border border-brand-200 bg-brand-50/60 px-4 py-3 text-sm">
              <span className="text-slate-600">Netto terhitung: </span>
              <span className="font-semibold text-brand-700">
                <Weight value={Number(weighForm.gross_weight || 0) - Number(weighForm.bag_weight || 0)} />
              </span>
            </div>
          ) : null}
          <p className="flex items-start gap-2 text-xs leading-relaxed text-slate-500">
            <CircleDollarSign className="mt-0.5 h-3.5 w-3.5 shrink-0 text-brand-600" />
            Setelah timbang, jalankan <span className="font-medium text-slate-700">Kalkulasi</span> di tabel untuk membagi jatah gratis vs porsi berbayar, lalu bayar via wallet bila ada tagihan.
          </p>
        </div>
      </SideModal>
    </div>
  );
}
