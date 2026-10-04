import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, Field, Money, Panel, StatusPill, Weight, readError } from '../components/LaundryUi';

/**
 * Master laundry (§3–§5, §34): layanan, aturan harga (Rule 23: nonaktifkan,
 * bukan hapus), mesin, dan policy jatah berversi (Rule 22).
 */
export default function LaundryMasterPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [serviceForm, setServiceForm] = useState({ code: '', name: '', pricing_model: 'WEIGHT' });
  const [priceForm, setPriceForm] = useState({ laundry_service_id: '', unit_price: '10000', effective_from: new Date().toISOString().slice(0, 10) });
  const [machineForm, setMachineForm] = useState({ code: '', name: '', machine_type: 'WASHING' });
  const [policyForm, setPolicyForm] = useState({ code: '', name: '' });
  const [versionForm, setVersionForm] = useState({ policy_id: '', quota_amount: '10', period_type: 'MONTHLY', publish: true });

  const services = useQuery({ queryKey: ['laundry', 'services', 'all'], queryFn: () => laundryApi.services({ active_only: false }) });
  const pricing = useQuery({ queryKey: ['laundry', 'pricing'], queryFn: () => laundryApi.pricingRules({ active_only: false }) });
  const machines = useQuery({ queryKey: ['laundry', 'machines'], queryFn: () => laundryApi.machines() });
  const policies = useQuery({ queryKey: ['laundry', 'policies'], queryFn: () => laundryApi.policies({ active_only: false }) });

  const refresh = (key: string) => queryClient.invalidateQueries({ queryKey: ['laundry', key] });

  const createService = useMutation({
    mutationFn: () => laundryApi.createService({ code: serviceForm.code.trim().toUpperCase(), name: serviceForm.name.trim(), pricing_model: serviceForm.pricing_model }),
    onSuccess: () => { toast.success('Layanan dibuat'); setServiceForm({ code: '', name: '', pricing_model: 'WEIGHT' }); refresh('services'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat layanan')),
  });

  const createPrice = useMutation({
    mutationFn: () => laundryApi.createPricingRule({
      laundry_service_id: priceForm.laundry_service_id ? Number(priceForm.laundry_service_id) : undefined,
      pricing_model: 'WEIGHT',
      unit_price: Number(priceForm.unit_price),
      effective_from: priceForm.effective_from,
    }),
    onSuccess: () => { toast.success('Aturan harga dibuat'); refresh('pricing'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat harga')),
  });

  const deactivatePrice = useMutation({
    mutationFn: (id: number) => laundryApi.deactivatePricingRule(id),
    onSuccess: () => { toast.success('Harga lama dinonaktifkan — histori order aman'); refresh('pricing'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal menonaktifkan')),
  });

  const createMachine = useMutation({
    mutationFn: () => laundryApi.createMachine({ code: machineForm.code.trim().toUpperCase(), name: machineForm.name.trim(), machine_type: machineForm.machine_type }),
    onSuccess: () => { toast.success('Mesin didaftarkan'); setMachineForm({ code: '', name: '', machine_type: 'WASHING' }); refresh('machines'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal mendaftar mesin')),
  });

  const createPolicy = useMutation({
    mutationFn: () => laundryApi.createPolicy({ code: policyForm.code.trim().toUpperCase(), name: policyForm.name.trim() }),
    onSuccess: () => { toast.success('Policy dibuat — tambah versi lalu publikasikan'); setPolicyForm({ code: '', name: '' }); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat policy')),
  });

  const createVersion = useMutation({
    mutationFn: () => laundryApi.createPolicyVersion(Number(versionForm.policy_id), {
      quota_type: 'WEIGHT',
      quota_amount: Number(versionForm.quota_amount),
      period_type: versionForm.period_type,
      carry_over_mode: 'NO_CARRY_OVER',
      effective_from: new Date().toISOString().slice(0, 10),
      publish: versionForm.publish,
    }),
    onSuccess: () => { toast.success('Versi policy tersimpan'); refresh('policies'); },
    onError: (e: unknown) => toast.error(readError(e, 'Gagal membuat versi')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Master Laundry</h1>
        <p className="text-sm text-slate-500">Layanan, harga, mesin, dan kebijakan jatah. Perubahan harga/policy tidak menyentuh histori.</p>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Layanan" description="REGULAR / EXPRESS / BEDDING / … (§3)">
          <DataTable
            loading={services.isLoading}
            rows={services.data ?? []}
            empty="Belum ada layanan."
            columns={[
              { key: 'code', header: 'Kode', render: (r) => <span className="font-mono">{r.code}</span> },
              { key: 'name', header: 'Nama', render: (r) => r.name },
              { key: 'model', header: 'Model', render: (r) => <StatusPill status={r.pricing_model} /> },
              { key: 'active', header: 'Aktif', render: (r) => (r.is_active ? 'Ya' : 'Tidak') },
            ]}
          />
          {can('laundry.service.manage') ? (
            <div className="mt-3 grid gap-2 md:grid-cols-4">
              <Field label="Kode"><Input value={serviceForm.code} onChange={(e) => setServiceForm({ ...serviceForm, code: e.target.value })} placeholder="REGULAR" /></Field>
              <Field label="Nama"><Input value={serviceForm.name} onChange={(e) => setServiceForm({ ...serviceForm, name: e.target.value })} /></Field>
              <Field label="Model">
                <Select value={serviceForm.pricing_model} onChange={(e) => setServiceForm({ ...serviceForm, pricing_model: e.target.value })}>
                  <option value="WEIGHT">WEIGHT</option><option value="PIECE">PIECE</option><option value="SERVICE">SERVICE</option>
                </Select>
              </Field>
              <div className="flex items-end"><Button onClick={() => createService.mutate()} loading={createService.isPending} disabled={!serviceForm.code || !serviceForm.name}>Tambah</Button></div>
            </div>
          ) : null}
        </Panel>

        <Panel title="Mesin" description="Status OPERATIONAL / MAINTENANCE / OUT_OF_SERVICE (§34)">
          <DataTable
            loading={machines.isLoading}
            rows={machines.data ?? []}
            empty="Belum ada mesin."
            columns={[
              { key: 'code', header: 'Kode', render: (r) => <span className="font-mono">{r.code}</span> },
              { key: 'name', header: 'Nama', render: (r) => r.name },
              { key: 'type', header: 'Tipe', render: (r) => r.machine_type },
              { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
            ]}
          />
          {can('laundry.machine.manage') ? (
            <div className="mt-3 grid gap-2 md:grid-cols-4">
              <Field label="Kode"><Input value={machineForm.code} onChange={(e) => setMachineForm({ ...machineForm, code: e.target.value })} /></Field>
              <Field label="Nama"><Input value={machineForm.name} onChange={(e) => setMachineForm({ ...machineForm, name: e.target.value })} /></Field>
              <Field label="Tipe">
                <Select value={machineForm.machine_type} onChange={(e) => setMachineForm({ ...machineForm, machine_type: e.target.value })}>
                  <option value="WASHING">WASHING</option><option value="DRYING">DRYING</option><option value="IRONING">IRONING</option>
                </Select>
              </Field>
              <div className="flex items-end"><Button onClick={() => createMachine.mutate()} loading={createMachine.isPending} disabled={!machineForm.code || !machineForm.name}>Tambah</Button></div>
            </div>
          ) : null}
        </Panel>
      </div>

      <Panel title="Aturan harga" description="Efektif-berjangka. Harga lama dinonaktifkan, bukan dihapus (Rule 23).">
        <DataTable
          loading={pricing.isLoading}
          rows={pricing.data ?? []}
          empty="Belum ada aturan harga."
          columns={[
            { key: 'service', header: 'Layanan', render: (r) => r.service?.name ?? (r.laundry_service_id ? `#${r.laundry_service_id}` : 'semua') },
            { key: 'price', header: 'Harga', render: (r) => <Money value={r.unit_price} /> },
            { key: 'min', header: 'Min qty/charge', render: (r) => `${r.minimum_quantity ?? '—'} / ${r.minimum_charge ?? '—'}` },
            { key: 'fee', header: 'Fee', render: (r) => r.fee_type ?? '—' },
            { key: 'eff', header: 'Berlaku', render: (r) => `${r.effective_from ?? '—'} → ${r.effective_until ?? '…'}` },
            { key: 'active', header: 'Aktif', render: (r) => <StatusPill status={r.is_active ? 'ACTIVE' : 'ARCHIVED'} label={r.is_active ? 'Aktif' : 'Nonaktif'} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.pricing.manage') && r.is_active ? (
                <Button size="sm" variant="outline" onClick={() => deactivatePrice.mutate(r.id)}>Nonaktifkan</Button>
              ) : null
            ) },
          ]}
        />
        {can('laundry.pricing.manage') ? (
          <div className="mt-3 grid gap-2 md:grid-cols-4">
            <Field label="Layanan">
              <Select value={priceForm.laundry_service_id} onChange={(e) => setPriceForm({ ...priceForm, laundry_service_id: e.target.value })}>
                <option value="">— semua —</option>
                {(services.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </Select>
            </Field>
            <Field label="Harga/kg (Rp)"><Input type="number" min="0" value={priceForm.unit_price} onChange={(e) => setPriceForm({ ...priceForm, unit_price: e.target.value })} /></Field>
            <Field label="Berlaku dari"><Input type="date" value={priceForm.effective_from} onChange={(e) => setPriceForm({ ...priceForm, effective_from: e.target.value })} /></Field>
            <div className="flex items-end"><Button onClick={() => createPrice.mutate()} loading={createPrice.isPending}>Tambah harga</Button></div>
          </div>
        ) : null}
      </Panel>

      <Panel title="Policy jatah gratis" description="Berversi: terbit tidak dapat diubah — perubahan = versi baru (Rule 22)">
        <DataTable
          loading={policies.isLoading}
          rows={policies.data ?? []}
          empty="Belum ada policy."
          columns={[
            { key: 'code', header: 'Kode', render: (r) => <span className="font-mono">{r.code}</span> },
            { key: 'name', header: 'Nama', render: (r) => r.name },
            { key: 'versions', header: 'Versi', render: (r) => (
              <ul className="space-y-1">
                {(r.versions ?? []).map((v) => (
                  <li key={v.id} className="text-xs">
                    v{v.version} · <Weight value={v.quota_amount} /> {v.quota_type}/{v.period_type} · <StatusPill status={v.status} />
                  </li>
                ))}
                {(r.versions ?? []).length === 0 ? <li className="text-xs text-slate-400">belum ada versi</li> : null}
              </ul>
            ) },
            { key: 'active', header: 'Aktif', render: (r) => (r.is_active ? 'Ya' : 'Tidak') },
          ]}
        />
        {can('laundry.policy.manage') ? (
          <div className="mt-3 grid gap-2 md:grid-cols-5">
            <Field label="Kode policy"><Input value={policyForm.code} onChange={(e) => setPolicyForm({ ...policyForm, code: e.target.value })} placeholder="POL-BRD" /></Field>
            <Field label="Nama"><Input value={policyForm.name} onChange={(e) => setPolicyForm({ ...policyForm, name: e.target.value })} /></Field>
            <div className="flex items-end"><Button onClick={() => createPolicy.mutate()} loading={createPolicy.isPending} disabled={!policyForm.code || !policyForm.name}>Buat policy</Button></div>
            <Field label="ID policy (versi)"><Input value={versionForm.policy_id} onChange={(e) => setVersionForm({ ...versionForm, policy_id: e.target.value })} /></Field>
            <Field label="Jatah (kg)">
              <div className="flex gap-2">
                <Input type="number" min="0" value={versionForm.quota_amount} onChange={(e) => setVersionForm({ ...versionForm, quota_amount: e.target.value })} />
                <Button onClick={() => createVersion.mutate()} loading={createVersion.isPending} disabled={!versionForm.policy_id}>Terbit</Button>
              </div>
            </Field>
          </div>
        ) : null}
      </Panel>
    </div>
  );
}
