import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Handshake, PackageCheck, Send } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SideModal } from '@/components/ui/SideModal';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, PageHeader, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

const METHODS = ['RFID', 'QR', 'PIN', 'STUDENT_ID', 'AUTHORIZED_PERSON'];

/**
 * Serah terima (§43, §44, Rule 19/26).
 *
 * READY → DELIVERED → COMPLETED. Setiap serah terima wajib verifikasi
 * penerima + nama penerima tercatat. Klik order membuka drawer yang sudah
 * terisi ID order-nya.
 */
export default function LaundryDeliveryPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ order_id: '', mode: 'pickup' as 'pickup' | 'deliver', verification_method: 'RFID', verification_value: '', receiver_name: '', dormitory_id: '', notes: '' });
  const [modalOpen, setModalOpen] = useState(false);

  const ready = useQuery({
    queryKey: ['laundry', 'orders', 'ready'],
    queryFn: () => laundryApi.orders({ status: 'READY', per_page: 25 }),
  });
  const packaged = useQuery({
    queryKey: ['laundry', 'orders', 'packaged'],
    queryFn: () => laundryApi.orders({ status: 'PACKAGED', per_page: 25 }),
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['laundry', 'orders'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'kanban'] });
    queryClient.invalidateQueries({ queryKey: ['laundry', 'dashboard'] });
  };

  const markReady = useMutation({
    mutationFn: (id: number) => laundryApi.markReady(id),
    onSuccess: () => { toast.success('Order siap diambil — wali diberi tahu'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menandai siap')),
  });

  const handover = useMutation({
    mutationFn: async () => {
      const id = Number(form.order_id);
      const payload = {
        verification_method: form.verification_method,
        verification_value: form.verification_value || undefined,
        receiver_name: form.receiver_name.trim(),
        notes: form.notes || undefined,
        ...(form.mode === 'deliver' && form.dormitory_id ? { dormitory_id: Number(form.dormitory_id) } : {}),
      };
      if (form.mode === 'pickup') {
        return laundryApi.pickupOrder(id, payload);
      }
      const result = await laundryApi.deliverOrder(id, payload);
      return { order: result.order, pickup: result.delivery };
    },
    onSuccess: () => {
      toast.success(form.mode === 'pickup' ? 'Diambil — order COMPLETED' : 'Diantar ke asrama — order COMPLETED');
      setModalOpen(false);
      setForm({ ...form, order_id: '', verification_value: '', receiver_name: '', notes: '' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Verifikasi penerima gagal')),
  });

  const readyRows: OrderSummary[] = [...(packaged.data?.rows ?? []), ...(ready.data?.rows ?? [])];

  const openHandover = (row: OrderSummary) => {
    setForm({ ...form, order_id: String(row.id), receiver_name: '', verification_value: '' });
    setModalOpen(true);
  };

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<Handshake className="h-6 w-6" />}
        title="Serah Terima"
        description="Siap diambil → verifikasi penerima → selesai. Tas dilepas kembali otomatis."
        actions={can('laundry.deliver') ? (
          <Button onClick={() => { setForm({ ...form, order_id: '' }); setModalOpen(true); }}>
            <Send className="h-4 w-4" /> Catat Serah Terima
          </Button>
        ) : undefined}
      />

      <Panel title="Siap diserahterimakan" description="Status PACKAGED & READY — klik tombol Serah terima untuk membuka form terisi otomatis.">
        <DataTable<OrderSummary>
          loading={ready.isLoading || packaged.isLoading}
          rows={readyRows}
          empty="Tidak ada order menunggu serah terima."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <div>
                <p className="font-medium text-slate-900">{row.order_number}</p>
                <p className="text-xs text-slate-500">{row.student_name ?? `santri #${row.student_id}`} · {row.service ?? '—'}</p>
              </div>
            ) },
            { key: 'weight', header: 'Berat', render: (row) => <Weight value={row.net_weight} /> },
            { key: 'payment', header: 'Bayar', render: (row) => <StatusPill status={row.payment_status} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'aksi', header: 'Aksi', render: (row) => (
              <div className="flex flex-wrap gap-1.5">
                {can('laundry.deliver') && row.status === 'PACKAGED' ? (
                  <Button size="sm" variant="outline" onClick={() => markReady.mutate(row.id)} loading={markReady.isPending}>
                    <PackageCheck className="h-3.5 w-3.5" /> Tandai siap
                  </Button>
                ) : null}
                {can('laundry.deliver') && row.status === 'READY' ? (
                  <Button size="sm" onClick={() => openHandover(row)}>
                    <Send className="h-3.5 w-3.5" /> Serah terima
                  </Button>
                ) : null}
              </div>
            ) },
          ]}
        />
      </Panel>

      {/* ── Drawer: catat serah terima ──────────────────────────────── */}
      <SideModal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Catat Serah Terima"
        description="Nama penerima wajib tercatat (Rule 19). Order menjadi COMPLETED setelah tersimpan."
        icon={<Handshake className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => handover.mutate()}
              loading={handover.isPending}
              disabled={!form.order_id || !form.receiver_name.trim()}
            >
              {form.mode === 'pickup' ? 'Catat Pengambilan' : 'Catat Pengantaran'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID order" required hint="Klik tombol serah terima pada tabel untuk mengisi otomatis.">
            <Input value={form.order_id} onChange={(e) => setForm({ ...form, order_id: e.target.value })} placeholder="cth. 45" className="font-mono" />
          </Field>
          <Field label="Cara serah terima" required>
            <Select value={form.mode} onChange={(e) => setForm({ ...form, mode: e.target.value as 'pickup' | 'deliver' })}>
              <option value="pickup">PICKUP — ambil di counter</option>
              <option value="deliver">DORMITORY_DELIVERY — antar ke asrama</option>
            </Select>
          </Field>
          <Field label="Metode verifikasi" required>
            <Select value={form.verification_method} onChange={(e) => setForm({ ...form, verification_method: e.target.value })}>
              {METHODS.map((m) => <option key={m} value={m}>{m}</option>)}
            </Select>
          </Field>
          <Field label="Nilai verifikasi (UID / QR / PIN / NIS)">
            <Input value={form.verification_value} onChange={(e) => setForm({ ...form, verification_value: e.target.value })} className="font-mono" placeholder="tunjukkan kartu / sebutkan PIN" />
          </Field>
          <Field label="Nama penerima" required>
            <Input value={form.receiver_name} onChange={(e) => setForm({ ...form, receiver_name: e.target.value })} placeholder="cth. Ahmad / Musyrif Asrama" />
          </Field>
          {form.mode === 'deliver' ? (
            <Field label="ID asrama (opsional)">
              <Input type="number" value={form.dormitory_id} onChange={(e) => setForm({ ...form, dormitory_id: e.target.value })} className="font-mono" />
            </Field>
          ) : null}
          <Field label="Catatan">
            <Textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} rows={2} placeholder="opsional" />
          </Field>
          <p className="rounded-xl bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
            PIN = 4 digit terakhir NIS · QR = nomor order/label · RFID = kartu santri pemilik order.
          </p>
        </div>
      </SideModal>
    </div>
  );
}
