import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Handshake, Package, PackagePlus, ShoppingBag, Wrench } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SideModal } from '@/components/ui/SideModal';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, PageHeader, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { LaundryBag } from '../types';

const BAG_STATUSES = ['', 'AVAILABLE', 'ASSIGNED', 'IN_USE', 'REPAIR', 'RETIRED'];

type BagForm = {
  bag_code: string;
  rfid_uid: string;
  qr_code: string;
  bag_type: string;
  capacity_kg: string;
  tare_weight: string;
  notes: string;
  status: string;
};

const EMPTY_BAG: BagForm = { bag_code: '', rfid_uid: '', qr_code: '', bag_type: 'STANDARD', capacity_kg: '', tare_weight: '0.5', notes: '', status: 'AVAILABLE' };

/**
 * Laundry bag (§16): siklus Student → Bag → Order → Processing → Return.
 * Tas punya identitas sendiri (kode/RFID/QR) dan tidak pernah dihapus.
 * Buat, edit, dan penautan tas dikerjakan lewat drawer samping.
 */
export default function LaundryBagsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [form, setForm] = useState<BagForm>(EMPTY_BAG);
  const [editingBag, setEditingBag] = useState<LaundryBag | null>(null);
  const [bagModalOpen, setBagModalOpen] = useState(false);
  const [assignForm, setAssignForm] = useState({ bag_id: '', student_id: '', notes: '' });
  const [assignModalOpen, setAssignModalOpen] = useState(false);

  const bags = useQuery({
    queryKey: ['laundry', 'bags', { status }],
    queryFn: () => laundryApi.bags({ status: status || undefined, per_page: 25 }),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['laundry', 'bags'] });

  const openCreate = () => {
    setEditingBag(null);
    setForm(EMPTY_BAG);
    setBagModalOpen(true);
  };

  const openEdit = (bag: LaundryBag) => {
    setEditingBag(bag);
    setForm({
      bag_code: bag.bag_code,
      rfid_uid: bag.rfid_uid ?? '',
      qr_code: bag.qr_code ?? '',
      bag_type: bag.bag_type ?? 'STANDARD',
      capacity_kg: bag.capacity_kg != null ? String(bag.capacity_kg) : '',
      tare_weight: bag.tare_weight != null ? String(bag.tare_weight) : '',
      notes: bag.notes ?? '',
      status: bag.status,
    });
    setBagModalOpen(true);
  };

  const submitBag = useMutation({
    mutationFn: () => {
      const payload = {
        bag_code: form.bag_code.trim(),
        rfid_uid: form.rfid_uid.trim() || undefined,
        qr_code: form.qr_code.trim() || undefined,
        bag_type: form.bag_type,
        capacity_kg: form.capacity_kg ? Number(form.capacity_kg) : undefined,
        tare_weight: form.tare_weight ? Number(form.tare_weight) : undefined,
        notes: form.notes.trim() || undefined,
      };
      if (editingBag) return laundryApi.updateBag(editingBag.id, { ...payload, status: form.status });
      return laundryApi.createBag(payload);
    },
    onSuccess: (bag) => {
      toast.success(editingBag ? `Tas ${bag.bag_code} diperbarui` : `Tas ${bag.bag_code} dibuat`);
      setBagModalOpen(false);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal menyimpan tas')),
  });

  const assign = useMutation({
    mutationFn: () => laundryApi.assignBag(Number(assignForm.bag_id), {
      student_id: Number(assignForm.student_id),
      notes: assignForm.notes || undefined,
    }),
    onSuccess: () => { toast.success('Tas ditautkan ke santri'); setAssignModalOpen(false); setAssignForm({ bag_id: '', student_id: '', notes: '' }); refresh(); },
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
    <div className="space-y-5">
      <PageHeader
        icon={<ShoppingBag className="h-6 w-6" />}
        title="Laundry Bag"
        description="Identitas fisik tas (RFID/QR) yang mengikuti siklus order. Berat kosong (tare) jadi pengurang timbangan."
        actions={can('laundry.bag.manage') ? (
          <Button onClick={openCreate}>
            <PackagePlus className="h-4 w-4" /> Tas Baru
          </Button>
        ) : undefined}
      />

      <Panel title="Daftar tas" description="Filter berdasarkan status siklus hidup tas.">
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <Select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status" className="max-w-56">
            {BAG_STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : s}</option>)}
          </Select>
        </div>
        <DataTable<LaundryBag>
          loading={bags.isLoading}
          rows={bags.data?.rows ?? []}
          empty="Belum ada tas terdaftar."
          columns={[
            { key: 'code', header: 'Kode', render: (r) => (
              <div>
                <p className="font-mono font-semibold text-slate-900">{r.bag_code}</p>
                <p className="text-xs text-slate-500">{r.bag_type ?? 'STANDARD'} · tare <Weight value={r.tare_weight} /></p>
              </div>
            ) },
            { key: 'ids', header: 'RFID / QR', render: (r) => (
              <div className="font-mono text-xs">
                <p>{r.rfid_uid ?? '—'}</p>
                <p className="text-slate-400">{r.qr_code ?? ''}</p>
              </div>
            ) },
            { key: 'holder', header: 'Pemegang', render: (r) => (
              r.assignments && r.assignments.length > 0 ? (
                <span className="text-sm">Santri #{r.assignments[0].student_id}</span>
              ) : <span className="text-slate-400">—</span>
            ) },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.bag.manage') ? (
                <div className="flex flex-wrap gap-1.5">
                  <Button size="sm" variant="outline" onClick={() => openEdit(r)}>
                    <Wrench className="h-3.5 w-3.5" /> Edit
                  </Button>
                  {r.status === 'AVAILABLE' || r.status === 'ASSIGNED' ? (
                    <Button size="sm" variant="outline" onClick={() => { setAssignForm({ bag_id: String(r.id), student_id: '', notes: '' }); setAssignModalOpen(true); }}>
                      <Handshake className="h-3.5 w-3.5" /> Tautkan
                    </Button>
                  ) : null}
                  {r.status === 'ASSIGNED' || r.status === 'IN_USE' ? (
                    <Button size="sm" variant="outline" onClick={() => release.mutate(r.id)} loading={release.isPending}>Lepas</Button>
                  ) : null}
                  <Button size="sm" variant="ghost" onClick={() => setRepair.mutate(r)} title={r.status === 'REPAIR' ? 'Tandai tersedia' : 'Tandai perlu perbaikan'}>
                    {r.status === 'REPAIR' ? 'Aktifkan' : 'Rusak'}
                  </Button>
                </div>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {/* ── Drawer: buat / edit tas ─────────────────────────────────── */}
      <SideModal
        open={bagModalOpen}
        onClose={() => setBagModalOpen(false)}
        title={editingBag ? `Edit Tas ${editingBag.bag_code}` : 'Tas Baru'}
        description={editingBag ? 'Perubahan identitas tas tercatat — tas tidak pernah dihapus, hanya dipensiunkan.' : 'Daftarkan identitas fisik tas baru beserta berat kosong (tare).'}
        icon={<Package className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setBagModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitBag.mutate()} loading={submitBag.isPending} disabled={!form.bag_code.trim()}>
              {editingBag ? 'Simpan Perubahan' : 'Buat Tas'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Kode tas" required>
            <Input value={form.bag_code} onChange={(e) => setForm({ ...form, bag_code: e.target.value })} placeholder="cth. BAG-001" className="font-mono" />
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Tipe">
              <Select value={form.bag_type} onChange={(e) => setForm({ ...form, bag_type: e.target.value })}>
                <option value="STANDARD">STANDARD</option>
                <option value="LARGE">LARGE</option>
                <option value="DELICATE">DELICATE</option>
              </Select>
            </Field>
            {editingBag ? (
              <Field label="Status">
                <Select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value })}>
                  {BAG_STATUSES.filter(Boolean).map((s) => <option key={s} value={s}>{s}</option>)}
                </Select>
              </Field>
            ) : null}
            <Field label="RFID UID">
              <Input value={form.rfid_uid} onChange={(e) => setForm({ ...form, rfid_uid: e.target.value })} placeholder="opsional" className="font-mono" />
            </Field>
            <Field label="Kode QR">
              <Input value={form.qr_code} onChange={(e) => setForm({ ...form, qr_code: e.target.value })} placeholder="opsional" className="font-mono" />
            </Field>
            <Field label="Kapasitas (kg)">
              <Input type="number" min="0" value={form.capacity_kg} onChange={(e) => setForm({ ...form, capacity_kg: e.target.value })} placeholder="cth. 8" />
            </Field>
            <Field label="Berat kosong / tare (kg)" hint="Dikurangkan otomatis saat penimbangan.">
              <Input type="number" min="0" step="0.001" value={form.tare_weight} onChange={(e) => setForm({ ...form, tare_weight: e.target.value })} />
            </Field>
          </div>
          <Field label="Catatan">
            <Input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} placeholder="cth. label menyulam di sisi kiri" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: tautkan tas ke santri ───────────────────────────── */}
      <SideModal
        open={assignModalOpen}
        onClose={() => setAssignModalOpen(false)}
        title="Tautkan Tas ke Santri"
        description="Satu tas aktif per penautan — tas lama otomatis dilepas bila santri sudah punya."
        icon={<Handshake className="h-5 w-5" />}
        tone="slate"
        footer={
          <>
            <Button variant="ghost" onClick={() => setAssignModalOpen(false)}>Batal</Button>
            <Button onClick={() => assign.mutate()} loading={assign.isPending} disabled={!assignForm.bag_id || !assignForm.student_id}>
              Tautkan Tas
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID tas" required>
            <Input value={assignForm.bag_id} onChange={(e) => setAssignForm({ ...assignForm, bag_id: e.target.value })} className="font-mono" />
          </Field>
          <Field label="ID santri" required>
            <Input value={assignForm.student_id} onChange={(e) => setAssignForm({ ...assignForm, student_id: e.target.value })} placeholder="cth. 123" className="font-mono" />
          </Field>
          <Field label="Catatan">
            <Input value={assignForm.notes} onChange={(e) => setAssignForm({ ...assignForm, notes: e.target.value })} placeholder="opsional" />
          </Field>
        </div>
      </SideModal>
    </div>
  );
}
