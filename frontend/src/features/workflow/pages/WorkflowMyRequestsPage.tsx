import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, RotateCcw, Send, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import { ProgressBar, formatAmount, instanceTone } from '../components/SlaBadge';
import type { WorkflowInstanceRow } from '../types';

/**
 * Kotak permintaan milik pengguna (§51).
 *
 * Menampilkan draf, yang sedang menunggu, yang dikembalikan untuk revisi, dan
 * yang sudah selesai — sekaligus menjadi tempat membuat permintaan baru
 * (jalur `MANUAL` bagi modul yang belum punya layar pengajuan sendiri).
 */
export default function WorkflowMyRequestsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('open');
  const [creating, setCreating] = useState(false);
  const [revising, setRevising] = useState<WorkflowInstanceRow | null>(null);
  const [cancelling, setCancelling] = useState<WorkflowInstanceRow | null>(null);

  const requests = useQuery({
    queryKey: ['workflow', 'my-requests', page, status],
    queryFn: () => workflowApi.myRequests({ page, status }),
  });

  const definitions = useQuery({
    queryKey: ['workflow', 'definitions', 'startable'],
    queryFn: () => workflowApi.definitions({ status: 'ACTIVE', per_page: 100 }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['workflow'] });

  const submit = useMutation({
    mutationFn: (id: number) => workflowApi.submitInstance(id),
    onSuccess: () => {
      toast.success('Request dikirim untuk persetujuan.');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const create = useMutation({
    mutationFn: (payload: Record<string, unknown>) => workflowApi.createInstance(payload),
    onSuccess: () => {
      toast.success('Request dibuat.');
      setCreating(false);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const revise = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) => workflowApi.reviseInstance(id, payload),
    onSuccess: () => {
      toast.success('Revisi dikirim ulang.');
      setRevising(null);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const cancel = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) => workflowApi.cancelInstance(id, reason),
    onSuccess: () => {
      toast.success('Request dibatalkan.');
      setCancelling(null);
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Permintaan Saya</h1>
          <p className="text-sm text-slate-500">Pantau status, lengkapi revisi, dan batalkan pengajuan Anda sendiri.</p>
        </div>

        <div className="flex items-center gap-2">
          <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }} className="w-44">
            <option value="open">Masih berjalan</option>
            <option value="DRAFT">Draf</option>
            <option value="WAITING_APPROVAL">Menunggu persetujuan</option>
            <option value="RETURNED">Perlu revisi</option>
            <option value="APPROVED">Disetujui</option>
            <option value="REJECTED">Ditolak</option>
            <option value="CANCELLED">Dibatalkan</option>
            <option value="ALL">Semua</option>
          </Select>

          <Can perm="workflow.request.create">
            <Button onClick={() => setCreating(true)}>
              <Plus className="mr-1 h-4 w-4" /> Ajukan
            </Button>
          </Can>
        </div>
      </div>

      <Card>
        <CardBody className="p-0">
          {requests.isLoading ? <Spinner /> : null}

          {requests.data && requests.data.data.length === 0 ? (
            <EmptyState title="Belum ada permintaan" description="Ajukan permintaan baru untuk memulai alur persetujuan." />
          ) : null}

          <ul className="divide-y divide-slate-100">
            {requests.data?.data.map((request) => (
              <li key={request.id} className="px-4 py-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <Link to={`/workflow/instances/${request.id}`} className="truncate text-sm font-semibold text-brand-700 hover:underline">
                        {request.title}
                      </Link>
                      <Badge tone={instanceTone(request.status)}>{request.status_label}</Badge>
                      {request.submission_number > 1 ? <Badge tone="neutral">submit ke-{request.submission_number}</Badge> : null}
                    </div>
                    <p className="mt-0.5 text-xs text-slate-500">
                      {request.code} · {request.definition_name ?? request.module}
                      {formatAmount(request.amount, request.currency) ? ` · ${formatAmount(request.amount, request.currency)}` : ''}
                    </p>
                    <div className="mt-2 max-w-md">
                      <ProgressBar percent={request.progress?.percent ?? 0} />
                      <p className="mt-1 text-[11px] text-slate-500">
                        {request.progress ? `${request.progress.completed}/${request.progress.total} langkah selesai` : 'Belum ada langkah aktif'}
                        {request.current_nodes.length > 0 ? ` · menunggu: ${request.current_nodes.join(', ')}` : ''}
                      </p>
                    </div>
                  </div>

                  <div className="flex flex-wrap items-center gap-2">
                    {request.status === 'DRAFT' ? (
                      <Button size="sm" onClick={() => submit.mutate(request.id)} loading={submit.isPending}>
                        <Send className="mr-1 h-3.5 w-3.5" /> Kirim
                      </Button>
                    ) : null}

                    {request.can_resubmit && request.status === 'RETURNED' ? (
                      <Button size="sm" onClick={() => setRevising(request)}>
                        <RotateCcw className="mr-1 h-3.5 w-3.5" /> Revisi & kirim ulang
                      </Button>
                    ) : null}

                    {request.can_cancel && request.status !== 'APPROVED' && request.status !== 'REJECTED' ? (
                      <Button size="sm" variant="outline" onClick={() => setCancelling(request)}>
                        <XCircle className="mr-1 h-3.5 w-3.5" /> Batalkan
                      </Button>
                    ) : null}
                  </div>
                </div>
              </li>
            ))}
          </ul>

          {requests.data ? <Pagination meta={requests.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <CreateRequestModal
        open={creating}
        definitions={definitions.data?.data ?? []}
        loading={create.isPending}
        onClose={() => setCreating(false)}
        onSubmit={(payload) => create.mutate(payload)}
      />

      <ReviseModal
        instance={revising}
        loading={revise.isPending}
        onClose={() => setRevising(null)}
        onSubmit={(id, payload) => revise.mutate({ id, payload })}
      />

      <CancelModal
        instance={cancelling}
        loading={cancel.isPending}
        onClose={() => setCancelling(null)}
        onSubmit={(id, reason) => cancel.mutate({ id, reason })}
      />
    </div>
  );
}

function CreateRequestModal({
  open,
  definitions,
  loading,
  onClose,
  onSubmit,
}: {
  open: boolean;
  definitions: Array<{ id: number; code: string; name: string; module: string; required_context: string[] }>;
  loading: boolean;
  onClose: () => void;
  onSubmit: (payload: Record<string, unknown>) => void;
}) {
  const [definitionCode, setDefinitionCode] = useState('');
  const [title, setTitle] = useState('');
  const [reference, setReference] = useState('');
  const [priority, setPriority] = useState('NORMAL');
  const [amount, setAmount] = useState('');
  const [department, setDepartment] = useState('');
  const [contextJson, setContextJson] = useState('{}');

  const selected = definitions.find((definition) => definition.code === definitionCode);

  const submit = () => {
    let context: Record<string, unknown> = {};

    try {
      context = JSON.parse(contextJson || '{}');
    } catch {
      toast.error('Konteks tambahan bukan JSON yang sah.');

      return;
    }

    const merged: Record<string, unknown> = {
      ...context,
      ...(amount ? { amount: Number(amount) } : {}),
      ...(department ? { department } : {}),
    };

    const missing = (selected?.required_context ?? []).filter((key) => merged[key] === undefined || merged[key] === null);

    if (missing.length > 0) {
      toast.error(`Konteks wajib belum lengkap: ${missing.join(', ')}`);

      return;
    }

    onSubmit({
      definition_code: definitionCode,
      title,
      subject_reference: reference || undefined,
      subject_id: reference || undefined,
      subject_type: selected?.module ? `${selected.module}.manual` : undefined,
      priority,
      context: merged,
      submit: true,
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Ajukan permintaan baru"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={loading}>
            Batal
          </Button>
          <Button onClick={submit} loading={loading} disabled={!definitionCode || title.trim() === ''}>
            Buat & kirim
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <div>
          <Label required>Jenis permintaan</Label>
          <Select value={definitionCode} onChange={(event) => setDefinitionCode(event.target.value)}>
            <option value="">— pilih alur —</option>
            {definitions.map((definition) => (
              <option key={definition.id} value={definition.code}>
                {definition.name} ({definition.module})
              </option>
            ))}
          </Select>
          {selected && selected.required_context.length > 0 ? (
            <p className="mt-1 text-xs text-slate-500">Konteks wajib: {selected.required_context.join(', ')}</p>
          ) : null}
        </div>

        <div>
          <Label required>Judul</Label>
          <Input value={title} onChange={(event) => setTitle(event.target.value)} placeholder="mis. Pembelian 2 unit proyektor" />
        </div>

        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label>Nomor dokumen / referensi</Label>
            <Input value={reference} onChange={(event) => setReference(event.target.value)} placeholder="PR-2026-000123" />
          </div>
          <div>
            <Label>Prioritas</Label>
            <Select value={priority} onChange={(event) => setPriority(event.target.value)}>
              <option value="LOW">Rendah</option>
              <option value="NORMAL">Normal</option>
              <option value="HIGH">Tinggi</option>
              <option value="URGENT">Mendesak</option>
            </Select>
          </div>
          <div>
            <Label>Nominal</Label>
            <Input type="number" value={amount} onChange={(event) => setAmount(event.target.value)} placeholder="15000000" />
          </div>
          <div>
            <Label>Unit / departemen</Label>
            <Input value={department} onChange={(event) => setDepartment(event.target.value)} placeholder="SARANA" />
          </div>
        </div>

        <div>
          <Label>Konteks tambahan (JSON)</Label>
          <Textarea
            rows={3}
            value={contextJson}
            onChange={(event) => setContextJson(event.target.value)}
            placeholder='{"cost_center_id": 3, "category": "ATK"}'
          />
          <p className="mt-1 text-xs text-slate-500">
            Dipakai oleh kondisi workflow. Untuk modul yang sudah punya layar pengajuan, data ini datang otomatis dari modul tersebut.
          </p>
        </div>
      </div>
    </Modal>
  );
}

function ReviseModal({
  instance,
  loading,
  onClose,
  onSubmit,
}: {
  instance: WorkflowInstanceRow | null;
  loading: boolean;
  onClose: () => void;
  onSubmit: (id: number, payload: Record<string, unknown>) => void;
}) {
  const [reason, setReason] = useState('');
  const [contextJson, setContextJson] = useState('{}');

  return (
    <Modal
      open={instance !== null}
      onClose={onClose}
      title={`Revisi & kirim ulang — ${instance?.code ?? ''}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={loading}>
            Batal
          </Button>
          <Button
            loading={loading}
            disabled={reason.trim() === ''}
            onClick={() => {
              let context: Record<string, unknown> = {};

              try {
                context = JSON.parse(contextJson || '{}');
              } catch {
                toast.error('Konteks bukan JSON yang sah.');

                return;
              }

              onSubmit(instance!.id, { revision_reason: reason, context });
            }}
          >
            Kirim ulang
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <div>
          <Label required>Catatan revisi</Label>
          <Textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} placeholder="mis. Nota pembelian sudah dilampirkan." />
          <p className="mt-1 text-xs text-slate-500">
            Catatan ini tersimpan permanen pada riwayat submission — approver membaca apa yang berubah.
          </p>
        </div>
        <div>
          <Label>Perubahan konteks (JSON)</Label>
          <Textarea rows={3} value={contextJson} onChange={(event) => setContextJson(event.target.value)} placeholder='{"amount": 1750000}' />
        </div>
      </div>
    </Modal>
  );
}

function CancelModal({
  instance,
  loading,
  onClose,
  onSubmit,
}: {
  instance: WorkflowInstanceRow | null;
  loading: boolean;
  onClose: () => void;
  onSubmit: (id: number, reason: string) => void;
}) {
  const [reason, setReason] = useState('');

  return (
    <Modal
      open={instance !== null}
      onClose={onClose}
      title={`Batalkan ${instance?.code ?? ''}`}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={loading}>
            Tidak jadi
          </Button>
          <Button variant="danger" loading={loading} disabled={reason.trim() === ''} onClick={() => onSubmit(instance!.id, reason)}>
            Batalkan request
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <p className="text-sm text-slate-600">
          Keputusan yang sudah tercatat <span className="font-medium">tidak dihapus</span>. Approver diberi tahu bahwa request dibatalkan.
        </p>
        <div>
          <Label required>Alasan pembatalan</Label>
          <Textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} placeholder="mis. Salah input nominal, akan diajukan ulang." />
        </div>
      </div>
    </Modal>
  );
}
