import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { BadgePercent, ShieldAlert, TriangleAlert } from 'lucide-react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SideModal } from '@/components/ui/SideModal';
import { useAuthStore } from '@/stores/authStore';
import { laundryApi } from '../api/laundryApi';
import { DataTable, DateOnly, Field, Money, PageHeader, Panel, StatusPill, readError } from '../components/LaundryUi';
import type { LaundryIncident } from '../types';

const TYPES = ['', 'MISSING', 'DAMAGED', 'STAIN', 'COLOR_TRANSFER', 'OTHER'];
const TYPE_LABELS: Record<string, string> = {
  MISSING: 'Item Hilang', DAMAGED: 'Rusak', STAIN: 'Terkena Noda', COLOR_TRANSFER: 'Luntur', OTHER: 'Lainnya',
};
const STATUSES = ['', 'OPEN', 'INVESTIGATING', 'RESOLVED', 'CLOSED'];
const COMPENSATIONS = ['REPLACEMENT', 'REFUND', 'CREDIT', 'FREE_QUOTA', 'WALLET_REFUND', 'MANUAL'];

/**
 * Insiden & kompensasi (§39–§41, Rule 18).
 *
 * Item hilang/rusak wajib jadi insiden; order ditandai LOST/DAMAGED.
 * Kompensasi finansial/jatah hanya dieksekusi setelah approval.
 * Laporan & kompensasi diisi lewat drawer samping per insiden.
 */
export default function LaundryIncidentsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [type, setType] = useState('');
  const [status, setStatus] = useState('');
  const [reportForm, setReportForm] = useState({ order_id: '', incident_type: 'MISSING', description: '' });
  const [compForm, setCompForm] = useState({ incident_id: '', compensation_type: 'WALLET_REFUND', compensation_amount: '', note: '' });
  const [reportModalOpen, setReportModalOpen] = useState(false);
  const [compModalOpen, setCompModalOpen] = useState(false);

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
      setReportModalOpen(false);
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
    onSuccess: () => { toast.success('Kompensasi diusulkan — menunggu approval'); setCompModalOpen(false); refresh(); },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengusulkan kompensasi')),
  });

  const approve = useMutation({
    mutationFn: () => laundryApi.approveCompensation(Number(compForm.incident_id), { note: compForm.note || undefined }),
    onSuccess: (incident) => {
      toast.success(`Kompensasi dieksekusi (ref ${incident.compensation_reference ?? '—'})`);
      setCompForm({ incident_id: '', compensation_type: 'WALLET_REFUND', compensation_amount: '', note: '' });
      setCompModalOpen(false);
      refresh();
    },
    onError: (error: unknown) => toast.error(readError(error, 'Gagal mengeksekusi kompensasi')),
  });

  const openCompensation = (incident: LaundryIncident) => {
    setCompForm({
      incident_id: String(incident.id),
      compensation_type: incident.compensation_type ?? 'WALLET_REFUND',
      compensation_amount: incident.compensation_amount != null ? String(incident.compensation_amount) : '',
      note: '',
    });
    setCompModalOpen(true);
  };

  return (
    <div className="space-y-5">
      <PageHeader
        icon={<TriangleAlert className="h-6 w-6" />}
        title="Insiden Laundry"
        description="Hilang, rusak, noda, luntur — dengan kompensasi ber-approval."
        actions={can('laundry.incident.manage') ? (
          <Button variant="danger" onClick={() => setReportModalOpen(true)}>
            <ShieldAlert className="h-4 w-4" /> Laporkan Insiden
          </Button>
        ) : undefined}
      />

      <Panel title="Filter">
        <div className="mb-4 flex flex-wrap items-end gap-2">
          <Select value={type} onChange={(e) => setType(e.target.value)} aria-label="Jenis" className="w-48">
            {TYPES.map((t) => <option key={t} value={t}>{t === '' ? 'Semua jenis' : TYPE_LABELS[t] ?? t}</option>)}
          </Select>
          <Select value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status" className="w-48">
            {STATUSES.map((s) => <option key={s} value={s}>{s === '' ? 'Semua status' : s}</option>)}
          </Select>
        </div>
        <DataTable<LaundryIncident>
          loading={incidents.isLoading}
          rows={incidents.data?.rows ?? []}
          empty="Belum ada insiden."
          columns={[
            { key: 'number', header: 'Nomor', render: (r) => (
              <div>
                <p className="font-mono text-xs font-semibold text-slate-900">{r.incident_number}</p>
                <p className="text-xs text-slate-500">{r.order?.order_number ?? `order #${r.laundry_order_id}`}</p>
              </div>
            ) },
            { key: 'type', header: 'Jenis', render: (r) => <StatusPill status={r.incident_type} /> },
            { key: 'desc', header: 'Deskripsi', render: (r) => <span className="line-clamp-2 max-w-xs text-slate-600">{r.description}</span> },
            { key: 'comp', header: 'Kompensasi', render: (r) => (
              r.compensation_type ? (
                <span className="text-xs">{r.compensation_type}{r.compensation_amount != null ? <> · <Money value={r.compensation_amount} /></> : ''}</span>
              ) : <span className="text-slate-400">—</span>
            ) },
            { key: 'status', header: 'Status', render: (r) => <StatusPill status={r.status} /> },
            { key: 'at', header: 'Dilaporkan', render: (r) => <DateOnly value={r.created_at} /> },
            { key: 'aksi', header: 'Aksi', render: (r) => (
              can('laundry.incident.manage') && (r.status === 'OPEN' || r.status === 'INVESTIGATING') ? (
                <div className="flex gap-1.5">
                  {r.status === 'OPEN' ? (
                    <Button size="sm" variant="outline" onClick={() => updateStatus.mutate({ id: r.id, next: 'INVESTIGATING' })}>Selidiki</Button>
                  ) : null}
                  <Button size="sm" variant="outline" onClick={() => openCompensation(r)}>
                    <BadgePercent className="h-3.5 w-3.5" /> Kompensasi
                  </Button>
                </div>
              ) : null
            ) },
          ]}
        />
      </Panel>

      {/* ── Drawer: laporkan insiden ────────────────────────────────── */}
      <SideModal
        open={reportModalOpen}
        onClose={() => setReportModalOpen(false)}
        title="Laporkan Insiden"
        description="Jenis MISSING/DAMAGED otomatis menandai order sebagai LOST/DAMAGED (Rule 18)."
        icon={<ShieldAlert className="h-5 w-5" />}
        tone="rose"
        footer={
          <>
            <Button variant="ghost" onClick={() => setReportModalOpen(false)}>Batal</Button>
            <Button variant="danger" onClick={() => report.mutate()} loading={report.isPending} disabled={!reportForm.order_id || !reportForm.description.trim()}>
              Simpan Insiden
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <Field label="ID order" required>
            <Input value={reportForm.order_id} onChange={(e) => setReportForm({ ...reportForm, order_id: e.target.value })} placeholder="cth. 45" className="font-mono" />
          </Field>
          <Field label="Jenis insiden" required>
            <Select value={reportForm.incident_type} onChange={(e) => setReportForm({ ...reportForm, incident_type: e.target.value })}>
              {TYPES.filter(Boolean).map((t) => <option key={t} value={t}>{TYPE_LABELS[t] ?? t}</option>)}
            </Select>
          </Field>
          <Field label="Deskripsi" required hint="Jelaskan item & kronologi sespesifik mungkin untuk memudahkan investigasi.">
            <Textarea value={reportForm.description} onChange={(e) => setReportForm({ ...reportForm, description: e.target.value })} rows={4} placeholder="cth. Satu jaket tidak ditemukan setelah pencucian…" />
          </Field>
        </div>
      </SideModal>

      {/* ── Drawer: kompensasi ber-approval ─────────────────────────── */}
      <SideModal
        open={compModalOpen}
        onClose={() => setCompModalOpen(false)}
        title="Kompensasi ber-Approval"
        description="Usulkan dulu — eksekusi hanya setelah disetujui (§41)."
        icon={<BadgePercent className="h-5 w-5" />}
        tone="amber"
        footer={
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <Button variant="outline" onClick={() => propose.mutate()} loading={propose.isPending} disabled={!compForm.incident_id}>
              Usulkan
            </Button>
            {can('laundry.payment.override') ? (
              <Button onClick={() => approve.mutate()} loading={approve.isPending} disabled={!compForm.incident_id}>
                Setujui & Eksekusi
              </Button>
            ) : null}
          </div>
        }
      >
        <div className="space-y-4">
          <Field label="ID insiden" required>
            <Input value={compForm.incident_id} onChange={(e) => setCompForm({ ...compForm, incident_id: e.target.value })} className="font-mono" />
          </Field>
          <Field label="Jenis kompensasi" required>
            <Select value={compForm.compensation_type} onChange={(e) => setCompForm({ ...compForm, compensation_type: e.target.value })}>
              {COMPENSATIONS.map((c) => <option key={c} value={c}>{c}</option>)}
            </Select>
          </Field>
          <Field label="Nominal (untuk REFUND / WALLET_REFUND / FREE_QUOTA)" hint="Kosongkan untuk kompensasi non-finansial.">
            <Input type="number" min="0" value={compForm.compensation_amount} onChange={(e) => setCompForm({ ...compForm, compensation_amount: e.target.value })} placeholder="cth. 15000" />
          </Field>
          <Field label="Catatan approval">
            <Input value={compForm.note} onChange={(e) => setCompForm({ ...compForm, note: e.target.value })} placeholder="opsional — tercatat di audit" />
          </Field>
        </div>
      </SideModal>
    </div>
  );
}
