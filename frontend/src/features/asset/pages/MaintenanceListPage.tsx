import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, ClipboardList, PlayCircle, ShieldCheck, Wrench } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { formatRupiah } from '@/lib/utils';
import { maintenanceApi } from '../api/assetOpsApi';
import { metaApi } from '../api/assetApi';
import { FormField, Money } from '../components/AssetUi';
import { fmtDateTime, fmtDuration, isOverdue } from '../lib/format';
import { maintenanceRequestStatus, priority as priorityLabels, workOrderStatus, workOrderType } from '../lib/labels';
import type { MaintenanceRequest, MaintenanceWorkOrder } from '../types';

/**
 * Pemeliharaan: permintaan dan perintah kerja pada satu layar, karena di
 * lapangan keduanya memang satu urusan yang belum selesai.
 */
export default function MaintenanceListPage() {
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  const [tab, setTab] = useState<'requests' | 'orders'>('requests');
  const [assign, setAssign] = useState<MaintenanceRequest | null>(null);
  const [selesai, setSelesai] = useState<MaintenanceWorkOrder | null>(null);
  const [sukuCadang, setSukuCadang] = useState<MaintenanceWorkOrder | null>(null);

  const page = Number(params.get('page') ?? 1);
  const status = params.get('status') ?? '';

  const { data: requests, isLoading: memuatPermintaan } = useQuery({
    queryKey: ['asset', 'maintenance', 'requests', page, status],
    queryFn: () => maintenanceApi.requests({ page, per_page: 20, status: status || undefined }),
    enabled: tab === 'requests',
  });

  const { data: workOrders, isLoading: memuatWo } = useQuery({
    queryKey: ['asset', 'maintenance', 'work-orders', page, status],
    queryFn: () => maintenanceApi.workOrders({ page, per_page: 20, status: status || undefined }),
    enabled: tab === 'orders',
  });

  const aksi = useMutation({
    mutationFn: ({ id, langkah }: { id: number; langkah: 'start' | 'verify' }) =>
      langkah === 'start' ? maintenanceApi.start(id, {}) : maintenanceApi.verify(id, {}),
    onSuccess: () => {
      toast.success('Langkah pekerjaan dicatat');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const daftarPermintaan = requests?.data ?? [];
  const daftarWo = workOrders?.data ?? [];

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Pemeliharaan</h1>
        <p className="text-xs text-slate-500">
          Perbaikan tidak dianggap selesai sebelum diverifikasi — dan pekerjaan yang menunggu suku cadang ditandai, bukan ditutup.
        </p>
      </header>

      <div className="flex flex-wrap gap-1 border-b border-slate-200">
        {[
          { key: 'requests' as const, label: 'Permintaan', icon: <ClipboardList className="h-3.5 w-3.5" /> },
          { key: 'orders' as const, label: 'Perintah Kerja', icon: <Wrench className="h-3.5 w-3.5" /> },
        ].map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`flex items-center gap-1.5 border-b-2 px-3 py-2 text-xs font-medium ${
              tab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'
            }`}
          >
            {t.icon}
            {t.label}
          </button>
        ))}
        <div className="ml-auto pb-1">
          <Select
            value={status}
            onChange={(e) => {
              const next = new URLSearchParams(params);
              if (e.target.value) next.set('status', e.target.value);
              else next.delete('status');
              next.delete('page');
              setParams(next);
            }}
            className="max-w-[12rem]"
          >
            <option value="">Semua status</option>
            {(tab === 'requests' ? maintenanceRequestStatus.options() : workOrderStatus.options()).map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
      </div>

      <Card>
        {tab === 'requests' ? (
          memuatPermintaan ? (
            <div className="flex justify-center py-16">
              <Spinner />
            </div>
          ) : daftarPermintaan.length === 0 ? (
            <EmptyState title="Tidak ada permintaan" description="Permintaan dari guru, siswa, atau hasil inspeksi akan muncul di sini." />
          ) : (
            <ul className="divide-y divide-slate-100">
              {daftarPermintaan.map((request) => (
                <li key={request.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-mono text-xs text-slate-500">{request.request_number}</span>
                      <Badge tone={maintenanceRequestStatus.tone(request.status)}>{maintenanceRequestStatus.label(request.status)}</Badge>
                      <Badge tone={priorityLabels.tone(request.priority)}>{priorityLabels.label(request.priority)}</Badge>
                      {isOverdue(request.sla_due_at, Boolean(request.completed_at)) && (
                        <Badge tone="red">
                          <AlertTriangle className="mr-1 h-3 w-3" /> Lewat SLA
                        </Badge>
                      )}
                    </div>
                    <p className="mt-1 text-sm text-slate-800">{request.title}</p>
                    <p className="text-[11px] text-slate-500">
                      {request.asset?.name ?? request.facility?.name ?? '-'} · dilaporkan {fmtDateTime(request.reported_at)}
                      {request.sla_due_at && <> · target {fmtDateTime(request.sla_due_at)}</>}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Money value={request.cost_estimate} />
                    {['NEW', 'TRIAGED'].includes(request.status) && (
                      <Can perm="asset.maintenance.manage">
                        <Button size="sm" variant="outline" onClick={() => setAssign(request)}>
                          Tugaskan teknisi
                        </Button>
                      </Can>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )
        ) : memuatWo ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : daftarWo.length === 0 ? (
          <EmptyState title="Tidak ada perintah kerja" />
        ) : (
          <ul className="divide-y divide-slate-100">
            {daftarWo.map((wo) => (
              <li key={wo.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{wo.work_order_number}</span>
                    <Badge tone={workOrderStatus.tone(wo.status)}>{workOrderStatus.label(wo.status)}</Badge>
                    <Badge tone="gray">{workOrderType.label(wo.type)}</Badge>
                    {wo.priority && <Badge tone={priorityLabels.tone(wo.priority)}>{priorityLabels.label(wo.priority)}</Badge>}
                    {isOverdue(wo.due_at, Boolean(wo.completed_at)) && <Badge tone="red">Lewat tenggat</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{wo.title ?? wo.request?.title ?? '-'}</p>
                  <p className="text-[11px] text-slate-500">
                    {wo.asset?.name ?? '-'}
                    {wo.assigned_to && <> · teknisi {wo.assigned_to.name}</>}
                    {wo.downtime_minutes ? <> · henti {fmtDuration(wo.downtime_minutes)}</> : null}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <span className="text-xs text-slate-600">
                    {wo.total_cost ? formatRupiah(wo.total_cost) : '—'}
                    {wo.finance_status && wo.finance_status !== 'POSTED' && (
                      <span className="ml-1 text-[10px] text-amber-700">({wo.finance_status})</span>
                    )}
                  </span>
                  {['ASSIGNED', 'ON_HOLD', 'WAITING_PARTS'].includes(wo.status) && (
                    <Can perm="asset.maintenance.manage">
                      <Button size="sm" variant="outline" onClick={() => aksi.mutate({ id: wo.id, langkah: 'start' })}>
                        <PlayCircle className="mr-1 h-3.5 w-3.5" /> Mulai
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setSukuCadang(wo)}>
                        Suku cadang
                      </Button>
                    </Can>
                  )}
                  {wo.status === 'IN_PROGRESS' && (
                    <Can perm="asset.maintenance.execute">
                      <Button size="sm" variant="outline" onClick={() => setSukuCadang(wo)}>
                        Suku cadang
                      </Button>
                      <Button size="sm" onClick={() => setSelesai(wo)}>
                        Selesaikan
                      </Button>
                    </Can>
                  )}
                  {wo.status === 'COMPLETED' && (
                    <Can perm="asset.maintenance.verify">
                      <Button size="sm" onClick={() => aksi.mutate({ id: wo.id, langkah: 'verify' })}>
                        <ShieldCheck className="mr-1 h-3.5 w-3.5" /> Verifikasi
                      </Button>
                    </Can>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}

        {(tab === 'requests' ? requests?.meta : workOrders?.meta) && (
          <Pagination
            meta={(tab === 'requests' ? requests?.meta : workOrders?.meta)!}
            onPageChange={(p) => {
              const next = new URLSearchParams(params);
              next.set('page', String(p));
              setParams(next);
            }}
          />
        )}
      </Card>

      {assign && <AssignModal request={assign} onClose={() => setAssign(null)} />}
      {selesai && <CompleteModal workOrder={selesai} onClose={() => setSelesai(null)} />}
      {sukuCadang && <PartsModal workOrder={sukuCadang} onClose={() => setSukuCadang(null)} />}
    </div>
  );
}

function AssignModal({ request, onClose }: { request: MaintenanceRequest; onClose: () => void }) {
  const qc = useQueryClient();
  const [teknisi, setTeknisi] = useState<number | ''>('');
  const [form, setForm] = useState({ priority: request.priority, type: 'CORRECTIVE', title: request.title, description: request.description ?? '' });
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  const { data: orang } = useQuery({
    queryKey: ['asset', 'technicians'],
    queryFn: () => metaApi.custodians({ per_page: 300 }),
  });

  const simpan = useMutation({
    mutationFn: () => maintenanceApi.assign(request.id, { technician_id: Number(teknisi), ...form }),
    onSuccess: () => {
      toast.success('Perintah kerja dibuat dan teknisi ditugaskan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Tugaskan teknisi — ${request.request_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || teknisi === ''}>
            Buat perintah kerja
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Teknisi" required error={errors.technician_id?.[0]}>
          <SearchSelect options={(orang ?? []).map((c) => ({ value: c.id, label: c.name }))} value={teknisi} onChange={(v) => setTeknisi(v === '' ? '' : Number(v))} />
        </FormField>
        <FormField label="Prioritas">
          <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
            {priorityLabels.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Jenis pekerjaan">
          <Select value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            {workOrderType.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Judul pekerjaan">
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} />
        </FormField>
        <FormField label="Keterangan">
          <Textarea rows={2} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
        </FormField>
      </div>
    </Modal>
  );
}

function PartsModal({ workOrder, onClose }: { workOrder: MaintenanceWorkOrder; onClose: () => void }) {
  const qc = useQueryClient();
  const [baris, setBaris] = useState([{ code: '', quantity: 1 }]);
  const [pesan, setPesan] = useState<string | null>(null);

  const simpan = useMutation({
    mutationFn: () => maintenanceApi.parts(workOrder.id, baris.filter((b) => b.code.trim())),
    onSuccess: (hasil) => {
      if (hasil?.shortage) {
        setPesan(hasil.message ?? 'Stok tidak mencukupi — pekerjaan ditahan sampai barang tersedia.');
        toast.warning('Stok kurang: pekerjaan ditahan, bukan dianggap selesai');
      } else {
        toast.success('Suku cadang tercatat pada perintah kerja');
        onClose();
      }
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title={`Suku cadang — ${workOrder.work_order_number}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Tutup
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || baris.every((b) => !b.code.trim())}>
            Keluarkan dari persediaan
          </Button>
        </div>
      }
    >
      <p className="text-xs text-slate-600">
        Suku cadang diambil dari modul Persediaan. Bila stok kurang, pekerjaan ditahan dan permintaan berubah menjadi “Menunggu Suku Cadang”
        — sekolah melihat kenyataannya, bukan pekerjaan yang diam-diam gagal.
      </p>
      <div className="mt-3 space-y-2">
        {baris.map((b, index) => (
          <div key={index} className="flex items-center gap-2">
            <Input
              placeholder="Kode barang (SKU)"
              value={b.code}
              onChange={(e) => setBaris((prev) => prev.map((row, i) => (i === index ? { ...row, code: e.target.value } : row)))}
            />
            <Input
              type="number"
              min={1}
              className="w-24"
              value={b.quantity}
              onChange={(e) => setBaris((prev) => prev.map((row, i) => (i === index ? { ...row, quantity: Number(e.target.value) } : row)))}
            />
            <Button variant="ghost" size="sm" onClick={() => setBaris((prev) => prev.filter((_, i) => i !== index))}>
              Hapus
            </Button>
          </div>
        ))}
      </div>
      <Button variant="outline" size="sm" className="mt-2" onClick={() => setBaris((prev) => [...prev, { code: '', quantity: 1 }])}>
        Tambah baris
      </Button>
      {pesan && <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">{pesan}</p>}
    </Modal>
  );
}

function CompleteModal({ workOrder, onClose }: { workOrder: MaintenanceWorkOrder; onClose: () => void }) {
  const qc = useQueryClient();
  const [form, setForm] = useState({
    labor_cost: '',
    external_cost: '',
    transport_cost: '',
    other_cost: '',
    diagnosis: workOrder.diagnosis ?? '',
    repair_plan: workOrder.repair_plan ?? '',
    notes: '',
  });

  const simpan = useMutation({
    mutationFn: () => maintenanceApi.complete(workOrder.id, form),
    onSuccess: (wo) => {
      toast.success(`Pekerjaan selesai — total ${formatRupiah(wo.total_cost ?? 0)}`);
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const total = ['labor_cost', 'external_cost', 'transport_cost', 'other_cost'].reduce(
    (acc, key) => acc + Number((form as Record<string, string>)[key] || 0),
    0,
  );

  return (
    <Modal
      open
      onClose={onClose}
      title={`Selesaikan — ${workOrder.work_order_number}`}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Tandai selesai
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Diagnosa" className="sm:col-span-2">
          <Textarea rows={2} value={form.diagnosis} onChange={(e) => setForm({ ...form, diagnosis: e.target.value })} />
        </FormField>
        <FormField label="Tindakan perbaikan" className="sm:col-span-2">
          <Textarea rows={2} value={form.repair_plan} onChange={(e) => setForm({ ...form, repair_plan: e.target.value })} />
        </FormField>
        <FormField label="Biaya tenaga (Rp)">
          <Input type="number" value={form.labor_cost} onChange={(e) => setForm({ ...form, labor_cost: e.target.value })} />
        </FormField>
        <FormField label="Biaya pihak ketiga (Rp)">
          <Input type="number" value={form.external_cost} onChange={(e) => setForm({ ...form, external_cost: e.target.value })} />
        </FormField>
        <FormField label="Transport (Rp)">
          <Input type="number" value={form.transport_cost} onChange={(e) => setForm({ ...form, transport_cost: e.target.value })} />
        </FormField>
        <FormField label="Biaya lain (Rp)">
          <Input type="number" value={form.other_cost} onChange={(e) => setForm({ ...form, other_cost: e.target.value })} />
        </FormField>
        <FormField label="Catatan" className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>
      <p className="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
        Total biaya yang akan dicatat (termasuk suku cadang yang sudah dikeluarkan):{' '}
        <span className="font-semibold">{formatRupiah(total + Number(workOrder.parts_cost ?? 0))}</span>
      </p>
    </Modal>
  );
}
