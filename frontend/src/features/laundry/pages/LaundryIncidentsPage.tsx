import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Field, FilterBar, Panel, StatusPill, readError } from '../components/LaundryUi';
import type { LaundryIncident } from '../types';

const TYPES = ['', 'MISSING', 'DAMAGED', 'STAIN', 'COLOR_TRANSFER', 'OTHER'];
const STATUSES = ['', 'OPEN', 'INVESTIGATING', 'RESOLVED', 'CLOSED'];
const COMPENSATIONS = ['REPLACEMENT', 'REFUND', 'CREDIT', 'FREE_QUOTA', 'WALLET_REFUND', 'MANUAL'];

/**
 * Insiden & kompensasi (§39–§41, Rule 18).
 *
 * Item hilang/rusak wajib jadi insiden; order ditandai LOST/DAMAGED.
 * Kompensasi finansial/jatah hanya dieksekusi setelah approval.
 */
export default function LaundryIncidentsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [reportForm, setReportForm] = useState({ order_id: '', incident_type: 'MISSING', description: '' });
  const [compForm, setCompForm] = useState({ incident_id: '', compensation_type: 'WALLET_REFUND', compensation_amount: '', note: '' });

  const incidents = useQuery({
    queryKey: ['laundry', 'incidents', { type, status }],
    queryFn: () => laundryApi.incidents({
      incident_type: type || undefined,
      status: status || undefined,
      per_page: 25,
    }),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['laundry', 'incidents'] });

  const report = useMutation({
    mutationFn: () => laundryApi.reportIncident(Number(reportForm.order_id), {
      incident_type: reportForm.incident_type,
      description: reportForm.description.trim(),
    }),
    onSuccess: (incident) => {
      toast.success(`Insiden ${incident.incident_number} tercatat`);
      setReportForm({ order_id: '', incident_type: 'MISSING', description: '' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mencatat insiden')),
  });

  const updateStatus = useMutation({
    mutationFn: ({ id, next }: { id: number; next: string }) =>
      laundryApi.updateIncidentStatus(id, { status: next }),
    onSuccess: () => { toast.success('Status insiden diperbarui'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal memperbarui status')),
  });

  const propose = useMutation({
    mutationFn: () => laundryApi.proposeCompensation(Number(compForm.incident_id), {
      compensation_type: compForm.compensation_type,
      compensation_amount: compForm.compensation_amount ? Number(compForm.compensation_amount) : undefined,
    }),
    onSuccess: () => { toast.success('Kompensasi diusulkan — menunggu approval'); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengusulkan kompensasi')),
  });

  const approve = useMutation({
    mutationFn: () => laundryApi.approveCompensation(Number(compForm.incident_id), { note: compForm.note || undefined }),
    onSuccess: (incident) => {
      toast.success(`Kompensasi dieksekusi (ref ${incident.compensation_reference ?? '—'})`);
      setCompForm({ incident_id: '', compensation_type: 'WALLET_REFUND', compensation_amount: '', note: '' });
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengeksekusi kompensasi')),
  });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Insiden Laundry</h1>
        <p className="text-sm text-slate-500">Hilang, rusak, noda, luntur — dengan kompensasi ber-approval.</p>
      </div>

      <Panel title="Filter">
        <FilterBar>
          <Select value={type} onChange={(e) => setType(e.target.value)} aria-label="Jenis">
            {TYPES.map((t) => <option key={t} value={t}>{t === '' ? 'Semua jenis' : t}</option>)}
          </Select>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status">
            {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : s}</option>)}
          </Select>
        </FilterBar>
      </Panel>

      <Panel title={`Insiden (${incidents.data?.meta.total ?? 0})`}>
        <DataTable<LaundryIncident>
          loading={incidents.isLoading}
          rows={incidents.data?.rows ?? []}
          empty="Belum ada insiden."
          columns={[
            { key: 'number', header: 'Nomor', render: (r) => (
              <div>
                <p className="font-medium">{r.incident_number}</p>
                <p className="text-xs text-slate-500">{r.order?.order_number ?? `order #${r.laundry_order_id}`}</p>
              </div>
            ) },
            { key: 'type', header: 'Jenis', render: (r) => <StatusPill status={r.incident_type} /> },
            { key: 'desc', header: 'Deskripsi', render: (r) => <span className="line-clamp-2 max-w-xs">{r.description}</span> },
            { key: 'comp', header: 'Kompensasi', render: (r) => (
              r.compensation_type ? <span>{r.compensation_type}{r.compensation_amount != null ? ` (${r.compensation_amount})` : ''}</span> : '—'
            ) },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
            { key: 'at', header: 'Dilaporkan', render: (r) => <DateOnly value={r.created_at} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.incident.manage') && (r.status === 'OPEN' || r.status === 'INVESTIGATING') ? (
                <div className="flex gap-1">
                  <Button size="sm" variant="outline" onClick={() => updateStatus.mutate({ id: r.id, next: 'INVESTIGATING' })}>Selidiki</Button>
                  <Button size="sm" variant="outline" onClick={() => setCompForm({ ...compForm, incident_id: String(r.id) })}>Kompensasi</Button>
                </div>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {can('laundry.incident.manage') ? (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel title="Laporkan insiden" description="Otomatis menandai order LOST/DAMAGED bila MISSING/DAMAGED">
            <div className="space-y-3">
              <Field label="ID order" required>
                <Input value={reportForm.order_id} onChange={(e) => setReportForm({ ...reportForm, order_id: e.target.value })} />
              </Field>
              <Field label="Jenis">
                <Select value={reportForm.incident_type} onChange={(e) => setReportForm({ ...reportForm, incident_type: e.target.value })}>
                  {TYPES.filter(Boolean).map((t) => <option key={t} value={t}>{t}</option>)}
                </Select>
              </Field>
              <Field label="Deskripsi" required>
                <Textarea value={reportForm.description} onChange={(e) => setReportForm({ ...reportForm, description: e.target.value })} placeholder="cth. Satu jaket tidak ditemukan setelah pencucian…" />
              </Field>
              <Button onClick={() => report.mutate()} loading={report.isPending} disabled={!reportForm.order_id || !reportForm.description.trim()}>
                Simpan insiden
              </Button>
            </div>
          </Panel>

          <Panel title="Kompensasi ber-approval" description="Usulkan dulu, eksekusi hanya setelah disetujui (§41)">
            <div className="space-y-3">
              <Field label="ID insiden" required>
                <Input value={compForm.incident_id} onChange={(e) => setCompForm({ ...compForm, incident_id: e.target.value })} />
              </Field>
              <Field label="Jenis kompensasi">
                <Select value={compForm.compensation_type} onChange={(e) => setCompForm({ ...compForm, compensation_type: e.target.value })}>
                  {COMPENSATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
                </Select>
              </Field>
              <Field label="Nominal (untuk REFUND / WALLET_REFUND / FREE_QUOTA)">
                <Input type="number" min="0" value={compForm.compensation_amount} onChange={(e) => setCompForm({ ...compForm, compensation_amount: e.target.value })} />
              </Field>
              <Field label="Catatan approval">
                <Input value={compForm.note} onChange={(e) => setCompForm({ ...compForm, note: e.target.value })} />
              </Field>
              <div className="flex gap-2">
                <Button variant="outline" onClick={() => propose.mutate()} loading={propose.isPending} disabled={!compForm.incident_id}>
                  Usulkan
                </Button>
                {can('laundry.payment.override') ? (
                  <Button onClick={() => approve.mutate()} loading={approve.isPending} disabled={!compForm.incident_id}>
                    Setujui & eksekusi
                  </Button>
                ) : null}
              </div>
            </div>
          </Panel>
        </div>
      ) : null}
    </div>
  );
}
