import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, FilterBar, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { OrderSummary } from '../types';

const METHODS = ['RFID', 'QR', 'PIN', 'STUDENT_ID', 'AUTHORIZED_PERSON'];

/**
 * Serah terima (§43, §44, Rule 19/26).
 *
 * READY → DELIVERED → COMPLETED. Setiap serah terima wajib verifikasi
 * penerima + nama penerima tercatat.
 */
export default function LaundryDeliveryPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ order_id: '', mode: 'pickup' as 'pickup' | 'deliver', verification_method: 'RFID', verification_value: '', receiver_name: '', dormitory_id: '' });

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
      setForm({ ...form, order_id: '', verification_value: '', receiver_name: '' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Verifikasi penerima gagal')),
  });

  const readyRows: OrderSummary[] = [...(packaged.data?.rows ?? []), ...(ready.data?.rows ?? [])];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Serah Terima</h1>
        <p className="text-sm text-slate-500">Siap diambil → verifikasi penerima → selesai. Tas dilepas kembali otomatis.</p>
      </div>

      <Panel title="Siap diserahterimakan" description="Status PACKAGED & READY">
        <DataTable<OrderSummary>
          loading={ready.isLoading || packaged.isLoading}
          rows={readyRows}
          empty="Tidak ada order menunggu serah terima."
          columns={[
            { key: 'order', header: 'Order', render: (row) => (
              <button type="button" className="font-medium text-slate-800 underline" onClick={() => setForm({ ...form, order_id: String(row.id) })}>
                {row.order_number}
              </button>
            ) },
            { key: 'weight', header: 'Berat', render: (row) => <Weight value={row.net_weight} /> },
            { key: 'payment', header: 'Bayar', render: (row) => <StatusPill status={row.payment_status} /> },
            { key: 'status', header: 'Status', render: (row) => <StatusPill status={row.status} /> },
            { key: 'aksi', header: 'Aksi', render: (row) => (
              can('laundry.deliver') && row.status === 'PACKAGED' ? (
                <Button size="sm" variant="outline" onClick={() => markReady.mutate(row.id)} loading={markReady.isPending}>
                  Tandai siap
                </Button>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {can('laundry.deliver') ? (
        <Panel title="Catat serah terima" description="Nama penerima wajib (Rule 19)">
          <div className="grid gap-3 md:grid-cols-3">
            <Field label="ID order" required>
              <Input value={form.order_id} onChange={(e) => setForm({ ...form, order_id: e.target.value })} placeholder="klik nomor di tabel…" />
            </Field>
            <Field label="Cara serah terima">
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
              <Input value={form.verification_value} onChange={(e) => setForm({ ...form, verification_value: e.target.value })} />
            </Field>
            <Field label="Nama penerima" required>
              <Input value={form.receiver_name} onChange={(e) => setForm({ ...form, receiver_name: e.target.value })} placeholder="cth. Ahmad / Musyrif Asrama" />
            </Field>
            {form.mode === 'deliver' ? (
              <Field label="ID asrama (opsional)">
                <Input value={form.dormitory_id} onChange={(e) => setForm({ ...form, dormitory_id: e.target.value })} />
              </Field>
            ) : null}
          </div>
          <FilterBar>
            <span className="text-xs text-slate-500">PIN = 4 digit terakhir NIS · QR = nomor order/label · RFID = kartu santri pemilik order.</span>
          </FilterBar>
          <Button
            onClick={() => handover.mutate()}
            loading={handover.isPending}
            disabled={!form.order_id || !form.receiver_name.trim()}
          >
            {form.mode === 'pickup' ? 'Catat pengambilan' : 'Catat pengantaran'}
          </Button>
        </Panel>
      ) : null}
    </div>
  );
}
