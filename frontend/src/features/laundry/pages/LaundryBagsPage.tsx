import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, Money, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { LaundryBag } from '../types';

const BAG_STATUSES = ['', 'AVAILABLE', 'ASSIGNED', 'IN_USE', 'REPAIR', 'RETIRED'];

/**
 * Laundry bag (§16): siklus Student → Bag → Order → Processing → Return.
 * Tas punya identitas sendiri (kode/RFID/QR) dan tidak pernah dihapus.
 */
export default function LaundryBagsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [form, setForm] = useState({ bag_code: '', rfid_uid: '', qr_code: '', bag_type: 'STANDARD', capacity_kg: '', tare_weight: '0.5' });
  const [assignForm, setAssignForm] = useState({ bag_id: '', student_id: '' });

  const bags = useQuery({
    queryKey: ['laundry', 'bags', { status }],
    queryFn: () => laundryApi.bags({ status: status || undefined, per_page: 25 }),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['laundry', 'bags'] });

  const create = useMutation({
    mutationFn: () => laundryApi.createBag({
      bag_code: form.bag_code.trim(),
      rfid_uid: form.rfid_uid.trim() || undefined,
      qr_code: form.qr_code.trim() || undefined,
      bag_type: form.bag_type,
      capacity_kg: form.capacity_kg ? Number(form.capacity_kg) : undefined,
      tare_weight: form.tare_weight ? Number(form.tare_weight) : undefined,
    }),
    onSuccess: (bag) => {
      toast.success(`Tas ${bag.bag_code} dibuat`);
      setForm({ bag_code: '', rfid_uid: '', qr_code: '', bag_type: 'STANDARD', capacity_kg: '', tare_weight: '0.5' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal membuat tas')),
  });

  const assign = useMutation({
    mutationFn: () => laundryApi.assignBag(Number(assignForm.bag_id), { student_id: Number(assignForm.student_id) }),
    onSuccess: () => { toast.success('Tas ditautkan ke santri'); setAssignForm({ bag_id: '', student_id: '' }); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menautkan tas')),
  });

  const release = useMutation({
    mutationFn: (id: number) => laundryApi.releaseBag(id),
    onSuccess: () => { toast.success('Tas dikembalikan — tersedia lagi'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal melepas tas')),
  });

  const setRepair = useMutation({
    mutationFn: (bag: LaundryBag) => laundryApi.updateBag(bag.id, { status: bag.status === 'REPAIR' ? 'AVAILABLE' : 'REPAIR' }),
    onSuccess: () => { toast.success('Status tas diperbarui'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal memperbarui tas')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Laundry Bag</h1>
        <p className="text-sm text-slate-500">Identitas fisik tas (RFID/QR) yang mengikuti siklus order. Berat kosong (tare) jadi pengurang timbangan.</p>
      </div>

      <Panel title="Filter">
        <Select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status" className="max-w-xs">
          {BAG_STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : s}</option>)}
        </Select>
      </Panel>

      <Panel title={`Tas (${bags.data?.meta.total ?? 0})`}>
        <DataTable<LaundryBag>
          loading={bags.isLoading}
          rows={bags.data?.rows ?? []}
          empty="Belum ada tas terdaftar."
          columns={[
            { key: 'code', header: 'Kode', render: (r) => (
              <div>
                <p className="font-medium font-mono">{r.bag_code}</p>
                <p className="text-xs text-slate-500">{r.bag_type ?? 'STANDARD'} · tare <Weight value={r.tare_weight} /></p>
              </div>
            ) },
            { key: 'ids', header: 'RFID / QR', render: (r) => (
              <div className="font-mono text-xs">
                <p>{r.rfid_uid ?? '—'}</p>
                <p className="text-slate-500">{r.qr_code ?? ''}</p>
              </div>
            ) },
            { key: 'holder', header: 'Pemegang', render: (r) => (
              r.assignments && r.assignments.length > 0 ? `Santri #${r.assignments[0].student_id}` : '—'
            ) },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.bag.manage') ? (
                <div className="flex gap-1">
                  {r.status === 'AVAILABLE' || r.status === 'ASSIGNED' ? (
                    <Button size="sm" variant="outline" onClick={() => setAssignForm({ ...assignForm, bag_id: String(r.id) })}>Tautkan</Button>
                  ) : null}
                  {r.status === 'ASSIGNED' || r.status === 'IN_USE' ? (
                    <Button size="sm" variant="outline" onClick={() => release.mutate(r.id)} loading={release.isPending}>Lepas</Button>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => setRepair.mutate(r)}>
                    {r.status === 'REPAIR' ? 'Aktifkan' : 'Rusak'}
                  </Button>
                </div>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {can('laundry.bag.manage') ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Tas baru">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="Kode tas" required>
                <Input value={form.bag_code} onChange={(e) => setForm({ ...form, bag_code: e.target.value })} placeholder="cth. BAG-001" />
              </Field>
              <Field label="Tipe">
                <Select value={form.bag_type} onChange={(e) => setForm({ ...form, bag_type: e.target.value })}>
                  <option value="STANDARD">STANDARD</option>
                  <option value="LARGE">LARGE</option>
                  <option value="DELICATE">DELICATE</option>
                </Select>
              </Field>
              <Field label="RFID UID">
                <Input value={form.rfid_uid} onChange={(e) => setForm({ ...form, rfid_uid: e.target.value })} placeholder="opsional" />
              </Field>
              <Field label="Kode QR">
                <Input value={form.qr_code} onChange={(e) => setForm({ ...form, qr_code: e.target.value })} placeholder="opsional" />
              </Field>
              <Field label="Kapasitas (kg)">
                <Input type="number" min="0" value={form.capacity_kg} onChange={(e) => setForm({ ...form, capacity_kg: e.target.value })} />
              </Field>
              <Field label="Berat kosong / tare (kg)">
                <Input type="number" min="0" step="0.001" value={form.tare_weight} onChange={(e) => setForm({ ...form, tare_weight: e.target.value })} />
              </Field>
            </div>
            <div className="mt-3">
              <Button onClick={() => create.mutate()} loading={create.isPending} disabled={!form.bag_code.trim()}>
                Buat tas
              </Button>
            </div>
          </Panel>

          <Panel title="Tautkan ke santri" description="Satu tas aktif per penautan">
            <div className="grid gap-3 md:grid-cols-2">
              <Field label="ID tas" required>
                <Input value={assignForm.bag_id} onChange={(e) => setAssignForm({ ...assignForm, bag_id: e.target.value })} />
              </Field>
              <Field label="ID santri" required>
                <Input value={assignForm.student_id} onChange={(e) => setAssignForm({ ...assignForm, student_id: e.target.value })} />
              </Field>
            </div>
            <div className="mt-3">
              <Button
                variant="secondary"
                onClick={() => assign.mutate()}
                loading={assign.isPending}
                disabled={!assignForm.bag_id || !assignForm.student_id}
              >
                Tautkan
              </Button>
            </div>
            <p className="mt-2 text-xs text-slate-500">Contoh nominal: tas <Money value={0} /> tidak dipungut biaya — biaya hanya dari layanan.</p>
          </Panel>
        </div>
      ) : null}
    </div>
  );
}
