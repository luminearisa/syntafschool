import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  Banknote, BookOpenText, Cog, PackagePlus, Plus, ScrollText, WashingMachine, Wrench,
} from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { SideModal } from '@/components/ui/SideModal';
import { usePermission } from '@/hooks/usePermission';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Field, Money, PageHeader, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';
import type { LaundryMachine, LaundryService } from '../types';

/**
 * Master laundry (§3–§5, §34): layanan, aturan harga (Rule 23: nonaktifkan,
 * bukan hapus), mesin, dan policy jatah berversi (Rule 22).
 * Semua create/edit dikerjakan di drawer samping per entitas.
 */

type ServiceForm = { code: string; name: string; description: string; pricing_model: string; processing_hours: string; is_active: boolean };
type MachineForm = { code: string; name: string; machine_type: string; capacity_kg: string; location: string; status: string; notes: string };
type PriceForm = { laundry_service_id: string; pricing_model: string; unit_price: string; minimum_quantity: string; minimum_charge: string; effective_from: string; effective_until: string };
type PolicyForm = { code: string; name: string; description: string };
type VersionForm = { policy_id: string; quota_type: string; quota_amount: string; period_type: string; carry_over_mode: string; effective_from: string; publish: boolean };

const EMPTY_SERVICE: ServiceForm = { code: '', name: '', description: '', pricing_model: 'WEIGHT', processing_hours: '', is_active: true };
const EMPTY_MACHINE: MachineForm = { code: '', name: '', machine_type: 'WASHING', capacity_kg: '', location: '', status: 'OPERATIONAL', notes: '' };
const EMPTY_PRICE: PriceForm = {
  laundry_service_id: '', pricing_model: 'WEIGHT', unit_price: '', minimum_quantity: '', minimum_charge: '',
  effective_from: new Date().toISOString().slice(0, 10), effective_until: '',
};
const EMPTY_POLICY: PolicyForm = { code: '', name: '', description: '' };
const EMPTY_VERSION: VersionForm = {
  policy_id: '', quota_type: 'WEIGHT', quota_amount: '10', period_type: 'MONTHLY', carry_over_mode: 'NO_CARRY_OVER',
  effective_from: new Date().toISOString().slice(0, 10), publish: true,
};

export default function LaundryMasterPage() {
  const { can } = usePermission();
  const queryClient = useQueryClient();

  const [serviceForm, setServiceForm] = useState<ServiceForm>(EMPTY_SERVICE);
  const [editingService, setEditingService] = useState<LaundryService | null>(null);
  const [serviceModalOpen, setServiceModalOpen] = useState(false);

  const [machineForm, setMachineForm] = useState<MachineForm>(EMPTY_MACHINE);
  const [editingMachine, setEditingMachine] = useState<LaundryMachine | null>(null);
  const [machineModalOpen, setMachineModalOpen] = useState(false);

  const [priceForm, setPriceForm] = useState<PriceForm>(EMPTY_PRICE);
  const [priceModalOpen, setPriceModalOpen] = useState(false);

  const [policyForm, setPolicyForm] = useState<PolicyForm>(EMPTY_POLICY);
  const [policyModalOpen, setPolicyModalOpen] = useState(false);
  const [versionForm, setVersionForm] = useState<VersionForm>(EMPTY_VERSION);
  const [versionModalOpen, setVersionModalOpen] = useState(false);

  const services = useQuery({ queryKey: ['laundry', 'services', 'all'], queryFn: () => laundryApi.services({ active_only: false }) });
  const pricing = useQuery({ queryKey: ['laundry', 'pricing'], queryFn: () => laundryApi.pricingRules({ active_only: false }) });
  const machines = useQuery({ queryKey: ['laundry', 'machines'], queryFn: () => laundryApi.machines() });
  const policies = useQuery({ queryKey: ['laundry', 'policies'], queryFn: () => laundryApi.policies({ active_only: false }) });

  const refresh = (key: string) => queryClient.invalidateQueries({ queryKey: ['laundry', key] });

  /* ── Layanan ──────────────────────────────────────────────────────── */

  const openCreateService = () => {
    setEditingService(null);
    setServiceForm(EMPTY_SERVICE);
    setServiceModalOpen(true);
  };

  const openEditService = (svc: LaundryService) => {
    setEditingService(svc);
    setServiceForm({
      code: svc.code,
      name: svc.name,
      description: svc.description ?? '',
      pricing_model: svc.pricing_model,
      processing_hours: svc.processing_hours != null ? String(svc.processing_hours) : '',
      is_active: svc.is_active,
    });
    setServiceModalOpen(true);
  };

  const submitService = useMutation({
    mutationFn: () => {
      const payload = {
        code: serviceForm.code.trim().toUpperCase(),
        name: serviceForm.name.trim(),
        description: serviceForm.description.trim() || undefined,
        pricing_model: serviceForm.pricing_model,
        processing_hours: serviceForm.processing_hours ? Number(serviceForm.processing_hours) : undefined,
        is_active: serviceForm.is_active,
      };
      if (editingService) return laundryApi.updateService(editingService.id, payload);
      return laundryApi.createService(payload);
    },
    onSuccess: () => {
      toast.success(editingService ? 'Layanan diperbarui' : 'Layanan dibuat');
      setServiceModalOpen(false);
      refresh('services');
    },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal menyimpan layanan')),
  });

  /* ── Mesin ────────────────────────────────────────────────────────── */

  const openCreateMachine = () => {
    setEditingMachine(null);
    setMachineForm(EMPTY_MACHINE);
    setMachineModalOpen(true);
  };

  const openEditMachine = (machine: LaundryMachine) => {
    setEditingMachine(machine);
    setMachineForm({
      code: machine.code,
      name: machine.name,
      machine_type: machine.machine_type,
      capacity_kg: machine.capacity_kg != null ? String(machine.capacity_kg) : '',
      location: machine.location ?? '',
      status: machine.status,
      notes: machine.notes ?? '',
    });
    setMachineModalOpen(true);
  };

  const submitMachine = useMutation({
    mutationFn: () => {
      const payload = {
        code: machineForm.code.trim().toUpperCase(),
        name: machineForm.name.trim(),
        machine_type: machineForm.machine_type,
        capacity_kg: machineForm.capacity_kg ? Number(machineForm.capacity_kg) : undefined,
        location: machineForm.location.trim() || undefined,
        status: machineForm.status,
        notes: machineForm.notes.trim() || undefined,
      };
      if (editingMachine) return laundryApi.updateMachine(editingMachine.id, payload);
      return laundryApi.createMachine(payload);
    },
    onSuccess: () => {
      toast.success(editingMachine ? 'Mesin diperbarui' : 'Mesin didaftarkan');
      setMachineModalOpen(false);
      refresh('machines');
    },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal menyimpan mesin')),
  });

  /* ── Harga ────────────────────────────────────────────────────────── */

  const submitPrice = useMutation({
    mutationFn: () => laundryApi.createPricingRule({
      laundry_service_id: priceForm.laundry_service_id ? Number(priceForm.laundry_service_id) : undefined,
      pricing_model: priceForm.pricing_model,
      unit_price: Number(priceForm.unit_price),
      minimum_quantity: priceForm.minimum_quantity ? Number(priceForm.minimum_quantity) : undefined,
      minimum_charge: priceForm.minimum_charge ? Number(priceForm.minimum_charge) : undefined,
      effective_from: priceForm.effective_from,
      effective_until: priceForm.effective_until || undefined,
    }),
    onSuccess: () => { toast.success('Aturan harga dibuat'); setPriceModalOpen(false); setPriceForm({ ...EMPTY_PRICE, effective_from: priceForm.effective_from }); refresh('pricing'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat harga')),
  });

  const deactivatePrice = useMutation({
    mutationFn: (id: number) => laundryApi.deactivatePricingRule(id),
    onSuccess: () => { toast.success('Harga lama dinonaktifkan — histori order aman'); refresh('pricing'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal menonaktifkan')),
  });

  /* ── Policy ───────────────────────────────────────────────────────── */

  const submitPolicy = useMutation({
    mutationFn: () => laundryApi.createPolicy({
      code: policyForm.code.trim().toUpperCase(),
      name: policyForm.name.trim(),
      description: policyForm.description.trim() || undefined,
    }),
    onSuccess: () => { toast.success('Policy dibuat — tambah versi lalu publikasikan'); setPolicyModalOpen(false); setPolicyForm(EMPTY_POLICY); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat policy')),
  });

  const submitVersion = useMutation({
    mutationFn: () => laundryApi.createPolicyVersion(Number(versionForm.policy_id), {
      quota_type: versionForm.quota_type,
      quota_amount: Number(versionForm.quota_amount),
      period_type: versionForm.period_type,
      carry_over_mode: versionForm.carry_over_mode,
      effective_from: versionForm.effective_from,
      publish: versionForm.publish,
    }),
    onSuccess: () => { toast.success('Versi policy tersimpan'); setVersionModalOpen(false); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat versi')),
  });

  const publishVersion = useMutation({
    mutationFn: (versionId: number) => laundryApi.publishPolicyVersion(versionId),
    onSuccess: () => { toast.success('Versi policy terbit'); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal menerbitkan versi')),
  });

  const archiveVersion = useMutation({
    mutationFn: (versionId: number) => laundryApi.archivePolicyVersion(versionId),
    onSuccess: () => { toast.success('Versi policy diarsipkan'); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal mengarsipkan versi')),
  });

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<Cog className="h-6 w-6" />}
        title="Master Laundry"
        description="Layanan, harga, mesin, dan kebijakan jatah. Perubahan harga/policy tidak menyentuh histori."
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel
          title="Layanan"
          description="REGULAR / EXPRESS / BEDDING / … (§3)"
          action={can('laundry.service.manage') ? (
            <Button size="sm" variant="outline" onClick={openCreateService}>
              <Plus className="h-3.5 w-3.5" /> Layanan
            </Button>
          ) : undefined}
        >
          <DataTable
            loading={services.isLoading}
            rows={services.data ?? []}
            empty="Belum ada layanan."
            columns={[
              { key: 'code', header: 'Kode', render: (r) => <span className="font-mono font-semibold">{r.code}</span> },
              { key: 'name', header: 'Nama', render: (r) => (
                <div>
                  <p>{r.name}</p>
                  {r.processing_hours ? <p className="text-xs text-slate-400">est. {r.processing_hours} jam</p> : null}
                </div>
              ) },
              { key: 'model', header: 'Model', render: (r) => <StatusPill status={r.pricing_model} /> },
              { key: 'active', header: 'Aktif', render: (r) => (
                <StatusPill status={r.is_active ? 'ACTIVE' : 'ARCHIVED'} label={r.is_active ? 'Ya' : 'Tidak'} />
              ) },
              { key: 'aksi', header: 'Aksi', render: (r) => (
                can('laundry.service.manage') ? (
                  <Button size="sm" variant="ghost" onClick={() => openEditService(r)}>
                    <Wrench className="h-3.5 w-3.5" /> Edit
                  </Button>
                ) : null
              ) },
            ]}
          />
        </Panel>

        <Panel
          title="Mesin"
          description="Status OPERATIONAL / MAINTENANCE / OUT_OF_SERVICE (§34)"
          action={can('laundry.machine.manage') ? (
            <Button size="sm" variant="outline" onClick={openCreateMachine}>
              <Plus className="h-3.5 w-3.5" /> Mesin
            </Button>
          ) : undefined}
        >
          <DataTable
            loading={machines.isLoading}
            rows={machines.data ?? []}
            empty="Belum ada mesin."
            columns={[
              { key: 'code', header: 'Kode', render: (r) => <span className="font-mono font-semibold">{r.code}</span> },
              { key: 'name', header: 'Nama', render: (r) => (
                <div>
                  <p>{r.name}</p>
                  {r.capacity_kg ? <p className="text-xs text-slate-400">kapasitas {r.capacity_kg} kg</p> : null}
                </div>
              ) },
              { key: 'type', header: 'Tipe', render: (r) => <span className="text-xs text-slate-600">{r.machine_type}</span> },
              { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
              { key: 'aksi', header: 'Aksi', render: (r) => (
                can('laundry.machine.manage') ? (
                  <Button size="sm" variant="ghost" onClick={() => openEditMachine(r)}>
                    <Wrench className="h-3.5 w-3.5" /> Edit
                  </Button>
                ) : null
              ) },
            ]}
          />
        </Panel>
      </div>

      <Panel
        title="Aturan harga"
        description="Efektif-berjangka. Harga lama dinonaktifkan, bukan dihapus (Rule 23)."
        action={can('laundry.pricing.manage') ? (
          <Button size="sm" variant="outline" onClick={() => setPriceModalOpen(true)}>
            <Banknote className="h-3.5 w-3.5" /> Harga baru
          </Button>
        ) : undefined}
      >
        <DataTable
          loading={pricing.isLoading}
          rows={pricing.data ?? []}
          empty="Belum ada aturan harga."
          columns={[
            { key: 'service', header: 'Layanan', render: (r) => r.service?.name ?? (r.laundry_service_id ? `#${r.laundry_service_id}` : 'semua') },
            { key: 'price', header: 'Harga', render: (r) => <span className="font-semibold"><Money value={r.unit_price} /></span> },
            { key: 'min', header: 'Min qty / charge', render: (r) => <span className="text-xs tabular-nums">{r.minimum_quantity ?? '—'} / {r.minimum_charge ?? '—'}</span> },
            { key: 'fee', header: 'Fee', render: (r) => <span className="text-xs">{r.fee_type ?? '—'}</span> },
            { key: 'eff', header: 'Berlaku', render: (r) => <span className="text-xs tabular-nums">{r.effective_from ?? '—'} → {r.effective_until ?? '…'}</span> },
            { key: 'active', header: 'Aktif', render: (r) => <StatusPill status={r.is_active ? 'ACTIVE' : 'ARCHIVED'} label={r.is_active ? 'Aktif' : 'Nonaktif'} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.pricing.manage') && r.is_active ? (
                <Button size="sm" variant="outline" onClick={() => deactivatePrice.mutate(r.id)}>Nonaktifkan</Button>
              ) : null
            ) },
          ]}
        />
      </Panel>

      <Panel
        title="Policy jatah gratis"
        description="Berversi: terbit tidak dapat diubah — perubahan = versi baru (Rule 22)"
        action={can('laundry.policy.manage') ? (
          <div className="flex gap-2">
            <Button size="sm" variant="outline" onClick={() => setVersionModalOpen(true)}>
              <ScrollText className="h-3.5 w-3.5" /> Versi baru
            </Button>
            <Button size="sm" variant="outline" onClick={() => setPolicyModalOpen(true)}>
              <Plus className="h-3.5 w-3.5" /> Policy
            </Button>
          </div>
        ) : undefined}
      >
        <DataTable
          loading={policies.isLoading}
          rows={policies.data ?? []}
          empty="Belum ada policy."
          columns={[
            { key: 'code', header: 'Kode', render: (r) => <span className="font-mono font-semibold">{r.code}</span> },
            { key: 'name', header: 'Nama', render: (r) => r.name },
            { key: 'versions', header: 'Versi', render: (r) => (
              <ul className="space-y-1.5">
                {(r.versions ?? []).map((v) => (
                  <li key={v.id} className="flex flex-wrap items-center gap-1.5 text-xs">
                    <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono font-semibold text-slate-600">v{v.version}</span>
                    <Weight value={v.quota_amount} /> {v.quota_type}/{v.period_type}
                    <StatusPill status={v.status} />
                    {can('laundry.policy.manage') && v.status === 'DRAFT' ? (
                      <button type="button" className="text-brand-700 underline hover:no-underline" onClick={() => publishVersion.mutate(v.id)}>terbitkan</button>
                    ) : null}
                    {can('laundry.policy.manage') && v.status === 'PUBLISHED' ? (
                      <button type="button" className="text-slate-500 underline hover:no-underline" onClick={() => archiveVersion.mutate(v.id)}>arsipkan</button>
                    ) : null}
                  </li>
                ))}
                {(r.versions ?? []).length === 0 ? <li className="text-xs text-slate-400">belum ada versi</li> : null}
              </ul>
            ) },
            { key: 'active', header: 'Aktif', render: (r) => (
              <StatusPill status={r.is_active ? 'ACTIVE' : 'ARCHIVED'} label={r.is_active ? 'Ya' : 'Tidak'} />
            ) },
          ]}
        />
      </Panel>

      {/* ── Drawer: layanan ─────────────────────────────────────────── */}
      <SideModal
        open={serviceModalOpen}
        onClose={() => setServiceModalOpen(false)}
        title={editingService ? `Edit Layanan ${editingService.code}` : 'Layanan Baru'}
        description="Katalog layanan cuci (§3) — model harga menentukan cara hitung tagihan."
        icon={<BookOpenText className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setServiceModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitService.mutate()} loading={submitService.isPending} disabled={!serviceForm.code || !serviceForm.name}>
              {editingService ? 'Simpan Perubahan' : 'Tambah Layanan'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Kode" required>
              <Input value={serviceForm.code} onChange={(e) => setServiceForm({ ...serviceForm, code: e.target.value })} placeholder="REGULAR" className="font-mono uppercase" />
            </Field>
            <Field label="Nama" required>
              <Input value={serviceForm.name} onChange={(e) => setServiceForm({ ...serviceForm, name: e.target.value })} placeholder="cth. Cuci Reguler" />
            </Field>
            <Field label="Model harga" required>
              <Select value={serviceForm.pricing_model} onChange={(e) => setServiceForm({ ...serviceForm, pricing_model: e.target.value })}>
                <option value="WEIGHT">WEIGHT — per kg</option>
                <option value="PIECE">PIECE — per potong</option>
                <option value="SERVICE">SERVICE — per layanan</option>
              </Select>
            </Field>
            <Field label="Estimasi proses (jam)">
              <Input type="number" min="0" step="0.5" value={serviceForm.processing_hours} onChange={(e) => setServiceForm({ ...serviceForm, processing_hours: e.target.value })} placeholder="cth. 24" />
            </Field>
          </div>
          <Field label="Deskripsi">
            <Input value={serviceForm.description} onChange={(e) => setServiceForm({ ...serviceForm, description: e.target.value })} placeholder="opsional" />
          </Field>
          <label className="flex items-center gap-2.5 rounded-xl border border-slate-200 bg-slate-50/60 px-4 py-3">
            <input
              type="checkbox"
              checked={serviceForm.is_active}
              onChange={(e) => setServiceForm({ ...serviceForm, is_active: e.target.checked })}
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            <span className="text-sm font-medium text-slate-700">Layanan aktif</span>
            <span className="text-xs text-slate-400">— nonaktif tidak muncul di meja penerimaan</span>
          </label>
        </div>
      </SideModal>

      {/* ── Drawer: mesin ───────────────────────────────────────────── */}
      <SideModal
        open={machineModalOpen}
        onClose={() => setMachineModalOpen(false)}
        title={editingMachine ? `Edit Mesin ${editingMachine.code}` : 'Mesin Baru'}
        description="Registrasi mesin cuci/kering/setrika beserta kapasitasnya (§34)."
        icon={<WashingMachine className="h-5 w-5" />}
        tone="slate"
        footer={
          <>
            <Button variant="ghost" onClick={() => setMachineModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitMachine.mutate()} loading={submitMachine.isPending} disabled={!machineForm.code || !machineForm.name}>
              {editingMachine ? 'Simpan Perubahan' : 'Daftarkan Mesin'}
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Kode" required>
              <Input value={machineForm.code} onChange={(e) => setMachineForm({ ...machineForm, code: e.target.value })} placeholder="cth. W01" className="font-mono uppercase" />
            </Field>
            <Field label="Nama" required>
              <Input value={machineForm.name} onChange={(e) => setMachineForm({ ...machineForm, name: e.target.value })} placeholder="cth. Mesin Cuci A" />
            </Field>
            <Field label="Tipe" required>
              <Select value={machineForm.machine_type} onChange={(e) => setMachineForm({ ...machineForm, machine_type: e.target.value })}>
                <option value="WASHING">WASHING</option>
                <option value="DRYING">DRYING</option>
                <option value="IRONING">IRONING</option>
              </Select>
            </Field>
            {editingMachine ? (
              <Field label="Status">
                <Select value={machineForm.status} onChange={(e) => setMachineForm({ ...machineForm, status: e.target.value })}>
                  <option value="OPERATIONAL">OPERATIONAL</option>
                  <option value="MAINTENANCE">MAINTENANCE</option>
                  <option value="OUT_OF_SERVICE">OUT_OF_SERVICE</option>
                </Select>
              </Field>
            ) : null}
            <Field label="Kapasitas (kg)">
              <Input type="number" min="0" value={machineForm.capacity_kg} onChange={(e) => setMachineForm({ ...machineForm, capacity_kg: e.target.value })} placeholder="cth. 12" />
            </Field>
            <Field label="Lokasi">
              <Input value={machineForm.location} onChange={(e) => setMachineForm({ ...machineForm, location: e.target.value })} placeholder="cth. Ruang Cuci Utama" />
            </Field>
          </div>
          <Field label="Catatan">
            <Input value={machineForm.notes} onChange={(e) => setMachineForm({ ...machineForm, notes: e.target.value })} placeholder="opsional" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: aturan harga ────────────────────────────────────── */}
      <SideModal
        open={priceModalOpen}
        onClose={() => setPriceModalOpen(false)}
        title="Aturan Harga Baru"
        description="Efektif berjangka — harga lama otomatis ter-nonaktif sesuai prioritas berlaku (Rule 23)."
        icon={<Banknote className="h-5 w-5" />}
        tone="amber"
        footer={
          <>
            <Button variant="ghost" onClick={() => setPriceModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitPrice.mutate()} loading={submitPrice.isPending} disabled={!priceForm.unit_price}>
              Tambah Harga
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Layanan">
              <Select value={priceForm.laundry_service_id} onChange={(e) => setPriceForm({ ...priceForm, laundry_service_id: e.target.value })}>
                <option value="">— semua layanan —</option>
                {(services.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <Field label="Model harga" required>
              <Select value={priceForm.pricing_model} onChange={(e) => setPriceForm({ ...priceForm, pricing_model: e.target.value })}>
                <option value="WEIGHT">WEIGHT — per kg</option>
                <option value="PIECE">PIECE — per potong</option>
                <option value="SERVICE">SERVICE — per layanan</option>
              </Select>
            </Field>
            <Field label="Harga satuan (Rp)" required>
              <Input type="number" min="0" value={priceForm.unit_price} onChange={(e) => setPriceForm({ ...priceForm, unit_price: e.target.value })} placeholder="cth. 10000" />
            </Field>
            <Field label="Kuantitas minimum">
              <Input type="number" min="0" step="0.001" value={priceForm.minimum_quantity} onChange={(e) => setPriceForm({ ...priceForm, minimum_quantity: e.target.value })} placeholder="opsional" />
            </Field>
            <Field label="Tagihan minimum (Rp)">
              <Input type="number" min="0" value={priceForm.minimum_charge} onChange={(e) => setPriceForm({ ...priceForm, minimum_charge: e.target.value })} placeholder="opsional" />
            </Field>
            <Field label="Berlaku dari" required>
              <Input type="date" value={priceForm.effective_from} onChange={(e) => setPriceForm({ ...priceForm, effective_from: e.target.value })} />
            </Field>
            <Field label="Berlaku sampai">
              <Input type="date" value={priceForm.effective_until} onChange={(e) => setPriceForm({ ...priceForm, effective_until: e.target.value })} placeholder="kosong = tanpa batas" />
            </Field>
          </div>
          <DateOnly value={priceForm.effective_from} />
        </div>
      </SideModal>

      {/* ── Drawer: policy baru ─────────────────────────────────────── */}
      <SideModal
        open={policyModalOpen}
        onClose={() => setPolicyModalOpen(false)}
        title="Policy Baru"
        description="Kerangka kebijakan jatah — lanjutkan dengan menambah versi (Rule 22)."
        icon={<ScrollText className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setPolicyModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitPolicy.mutate()} loading={submitPolicy.isPending} disabled={!policyForm.code || !policyForm.name}>
              Buat Policy
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Kode policy" required>
              <Input value={policyForm.code} onChange={(e) => setPolicyForm({ ...policyForm, code: e.target.value })} placeholder="POL-BRD" className="font-mono uppercase" />
            </Field>
            <Field label="Nama" required>
              <Input value={policyForm.name} onChange={(e) => setPolicyForm({ ...policyForm, name: e.target.value })} placeholder="cth. Jatah Asrama Putra" />
            </Field>
          </div>
          <Field label="Deskripsi">
            <Input value={policyForm.description} onChange={(e) => setPolicyForm({ ...policyForm, description: e.target.value })} placeholder="opsional" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: versi policy ────────────────────────────────────── */}
      <SideModal
        open={versionModalOpen}
        onClose={() => setVersionModalOpen(false)}
        title="Versi Policy Baru"
        description="Terbitkan langsung atau simpan sebagai DRAFT. Versi yang sudah terbit tidak dapat diubah (Rule 22)."
        icon={<PackagePlus className="h-5 w-5" />}
        footer={
          <>
            <Button variant="ghost" onClick={() => setVersionModalOpen(false)}>Batal</Button>
            <Button onClick={() => submitVersion.mutate()} loading={submitVersion.isPending} disabled={!versionForm.policy_id || !versionForm.quota_amount}>
              Simpan Versi
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="Policy" required>
            <Select value={versionForm.policy_id} onChange={(e) => setVersionForm({ ...versionForm, policy_id: e.target.value })}>
              <option value="">— pilih policy —</option>
              {(policies.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} — {p.name}</option>)}
            </Select>
          </Field>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Tipe jatah" required>
              <Select value={versionForm.quota_type} onChange={(e) => setVersionForm({ ...versionForm, quota_type: e.target.value })}>
                <option value="WEIGHT">WEIGHT — kg</option>
                <option value="PIECE">PIECE — potong</option>
              </Select>
            </Field>
            <Field label="Jumlah jatah" required>
              <Input type="number" min="0" step="0.001" value={versionForm.quota_amount} onChange={(e) => setVersionForm({ ...versionForm, quota_amount: e.target.value })} placeholder="cth. 10" />
            </Field>
            <Field label="Tipe periode" required>
              <Select value={versionForm.period_type} onChange={(e) => setVersionForm({ ...versionForm, period_type: e.target.value })}>
                <option value="MONTHLY">MONTHLY — bulanan</option>
                <option value="WEEKLY">WEEKLY — mingguan</option>
                <option value="DAILY">DAILY — harian</option>
                <option value="CUSTOM">CUSTOM — kustom</option>
              </Select>
            </Field>
            <Field label="Mode carry-over">
              <Select value={versionForm.carry_over_mode} onChange={(e) => setVersionForm({ ...versionForm, carry_over_mode: e.target.value })}>
                <option value="NO_CARRY_OVER">NO_CARRY_OVER — hangus</option>
                <option value="FULL_CARRY_OVER">FULL_CARRY_OVER — dibawa penuh</option>
              </Select>
            </Field>
            <Field label="Efektif dari" required>
              <Input type="date" value={versionForm.effective_from} onChange={(e) => setVersionForm({ ...versionForm, effective_from: e.target.value })} />
            </Field>
          </div>
          <label className="flex items-center gap-2.5 rounded-xl border border-brand-200 bg-brand-50/50 px-4 py-3">
            <input
              type="checkbox"
              checked={versionForm.publish}
              onChange={(e) => setVersionForm({ ...versionForm, publish: e.target.checked })}
              className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
            />
            <span className="text-sm font-medium text-slate-700">Terbitkan segera</span>
            <span className="text-xs text-slate-500">— jika tidak, versi tersimpan sebagai DRAFT</span>
          </label>
        </div>
      </SideModal>
    </div>
  );
}
