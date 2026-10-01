import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Activity, FileText, MessageSquare, RefreshCw, ShieldCheck, Stethoscope } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { workflowApi } from '../api/workflowApi';
import { DecisionDialog } from '../components/DecisionDialog';
import { ProgressBar, formatAmount, formatDateTime, instanceTone, priorityTone } from '../components/SlaBadge';
import { StepTimeline } from '../components/StepTimeline';
import { WorkflowVisualizer } from '../components/WorkflowVisualizer';
import type { WorkflowApprovalRow, WorkflowStepRow } from '../types';

/**
 * Ruang kerja satu request (§46, §47, §49, §65).
 *
 * Satu halaman memuat yang dibutuhkan untuk memutuskan dengan sadar:
 *   visualizer jalur + timeline immutable + snapshot konteks + dokumen rujukan,
 *   dengan tombol keputusan hanya muncul bagi approver yang benar-benar berhak.
 */
export default function WorkflowInstancePage() {
  const { instanceId } = useParams<{ instanceId: string }>();
  const id = Number(instanceId);
  const queryClient = useQueryClient();
  const [dialog, setDialog] = useState<{ approvalId: number; decision: 'approve' | 'reject' | 'return' | 'request-changes' } | null>(null);
  const [comment, setComment] = useState('');

  const currentUser = useAuthStore((state) => state.user);
  const canOverride = useAuthStore((state) => state.can('workflow.approval.override'));

  const instance = useQuery({ queryKey: ['workflow', 'instance', id], queryFn: () => workflowApi.instance(id), enabled: Number.isFinite(id) });
  const context = useQuery({ queryKey: ['workflow', 'instance', id, 'context'], queryFn: () => workflowApi.contextSnapshots(id), enabled: Number.isFinite(id) });
  const audit = useQuery({ queryKey: ['workflow', 'instance', id, 'audit'], queryFn: () => workflowApi.instanceAudit(id), enabled: Number.isFinite(id), retry: false });
  const dryRun = useMutation({ mutationFn: () => workflowApi.dryRun(id) });

  const addComment = useMutation({
    mutationFn: () => workflowApi.comment(id, comment),
    onSuccess: () => {
      toast.success('Komentar ditambahkan.');
      setComment('');
      queryClient.invalidateQueries({ queryKey: ['workflow', 'instance', id] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (instance.isLoading) return <Spinner />;

  if (!instance.data) {
    return <p className="text-sm text-slate-500">Request tidak ditemukan atau Anda tidak berhak melihatnya.</p>;
  }

  const data = instance.data;

  /*
   * Tombol keputusan hanya muncul bila penugasan ini benar-benar untuk pengguna
   * (atau bila ia memegang izin override). Backend tetap penjaga terakhir, tetapi
   * menawarkan tombol yang pasti ditolak hanya membingungkan approver.
   */
  const myApprovals = data.approvals.filter(
    (approval) => approval.status === 'PENDING' && (approval.approver_id === currentUser?.id || canOverride),
  );

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-900">{data.title}</h1>
            <Badge tone={instanceTone(data.status)}>{data.status_label}</Badge>
            <Badge tone={priorityTone(data.priority)}>{data.priority}</Badge>
            {data.submission_number > 1 ? <Badge tone="neutral">submit ke-{data.submission_number}</Badge> : null}
          </div>
          <p className="mt-1 text-sm text-slate-500">
            {data.code} · {data.definition_name} (v{data.version}) · {data.module}
            {data.subject_reference ? ` · ${data.subject_reference}` : ''}
          </p>
          <p className="text-sm text-slate-500">
            Pemohon {data.requester}
            {data.department ? ` (${data.department})` : ''}
            {formatAmount(data.amount, data.currency) ? ` · ${formatAmount(data.amount, data.currency)}` : ''}
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {myApprovals.map((approval) => (
            <div key={approval.id} className="flex items-center gap-2 rounded-lg border border-brand-200 bg-brand-50/60 px-3 py-2">
              <span className="text-xs text-brand-800">Anda berhak memutuskan</span>
              <Button size="sm" onClick={() => setDialog({ approvalId: approval.id, decision: 'approve' })}>
                Setujui
              </Button>
              <Button size="sm" variant="outline" onClick={() => setDialog({ approvalId: approval.id, decision: 'return' })}>
                Kembalikan
              </Button>
              <Button size="sm" variant="danger" onClick={() => setDialog({ approvalId: approval.id, decision: 'reject' })}>
                Tolak
              </Button>
            </div>
          ))}

          <Can perm="workflow.approval.reassign">
            <Button variant="outline" size="sm" loading={dryRun.isPending} onClick={() => dryRun.mutate()}>
              <Stethoscope className="mr-1 h-4 w-4" /> Dry run
            </Button>
          </Can>
        </div>
      </div>

      {data.failure_reason ? (
        <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
          Workflow gagal: {data.failure_reason}
        </div>
      ) : null}

      {dryRun.data ? (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <Stethoscope className="h-4 w-4" /> Hasil dry run
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            <p className={dryRun.data.healthy ? 'text-green-700' : 'text-amber-700'}>
              {dryRun.data.healthy ? 'Alur sehat: approver ditemukan dan aksi terdaftar.' : 'Perlu perhatian:'}
            </p>
            <ul className="list-disc space-y-1 pl-5 text-slate-600">
              {dryRun.data.blockers.map((blocker) => (
                <li key={blocker}>{blocker}</li>
              ))}
            </ul>
          </CardBody>
        </Card>
      ) : null}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <ShieldCheck className="h-4 w-4" /> Jalur persetujuan
            </CardTitle>
          </CardHeader>
          <CardBody>
            <ProgressBar percent={data.progress?.percent ?? 0} className="mb-4" />
            <WorkflowVisualizer nodes={data.graph.nodes} currentKeys={data.current_nodes} />
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <FileText className="h-4 w-4" /> Konteks beku
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            <p className="text-xs text-slate-500">
              Snapshot inilah yang dibaca kondisi & approver — bukan data hidup yang bisa berubah setelah keputusan.
            </p>
            <dl className="space-y-1">
              {Object.entries(context.data?.current ?? data.context_summary ?? {}).map(([key, value]) => (
                <div key={key} className="flex items-start justify-between gap-3 border-b border-slate-100 py-1 last:border-0">
                  <dt className="text-xs uppercase tracking-wide text-slate-500">{key}</dt>
                  <dd className="text-right text-sm text-slate-800">
                    {typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—')}
                  </dd>
                </div>
              ))}
            </dl>
            {context.data?.age_days !== null && context.data?.age_days !== undefined ? (
              <p className="pt-1 text-xs text-slate-500">Snapshot berumur {context.data.age_days} hari.</p>
            ) : null}
            {data.attachments.length > 0 ? (
              <div className="pt-2">
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Dokumen pendukung</p>
                <ul className="mt-1 space-y-1 text-sm">
                  {data.attachments.map((attachment) => (
                    <li key={attachment.id} className="text-slate-700">
                      {attachment.label ?? attachment.original_name ?? 'Dokumen'}
                      <span className="ml-1 text-xs text-slate-500">({attachment.source})</span>
                    </li>
                  ))}
                </ul>
              </div>
            ) : null}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <Activity className="h-4 w-4" /> Langkah & penugasan
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-3 p-0">
            {data.steps.length === 0 ? <p className="p-4 text-sm text-slate-500">Belum ada langkah yang aktif.</p> : null}
            <ul className="divide-y divide-slate-100">
              {data.steps.map((step) => (
                <StepRow key={step.id} step={step} />
              ))}
            </ul>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-sm">
              <RefreshCw className="h-4 w-4" /> Timeline (tidak dapat diubah)
            </CardTitle>
          </CardHeader>
          <CardBody>
            <StepTimeline entries={data.timeline} />
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-sm">
            <MessageSquare className="h-4 w-4" /> Diskusi
          </CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <ul className="space-y-2">
            {data.comments.map((entry) => (
              <li key={entry.id} className="rounded-lg bg-slate-50 px-3 py-2">
                <p className="text-xs text-slate-500">
                  {entry.author} · {formatDateTime(entry.created_at)}
                  {entry.is_internal ? ' · catatan internal' : ''}
                </p>
                <p className="text-sm text-slate-700">{entry.body}</p>
              </li>
            ))}
          </ul>

          <div>
            <Textarea value={comment} onChange={(event) => setComment(event.target.value)} rows={2} placeholder="Tulis komentar atau pertanyaan untuk pemohon…" />
            <div className="mt-2 flex justify-end">
              <Button size="sm" loading={addComment.isPending} disabled={comment.trim() === ''} onClick={() => addComment.mutate()}>
                Kirim komentar
              </Button>
            </div>
          </div>
        </CardBody>
      </Card>

      {audit.data ? (
        <Card>
          <CardHeader className="flex items-center justify-between">
            <CardTitle className="text-sm">Jejak audit</CardTitle>
            <Badge tone={audit.data.chain.valid ? 'success' : 'danger'}>
              {audit.data.chain.valid ? `Rantai utuh (${audit.data.chain.checked} baris)` : `Rantai terputus di #${audit.data.chain.broken_at}`}
            </Badge>
          </CardHeader>
          <CardBody className="p-0">
            <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto">
              {audit.data.logs.map((log) => (
                <li key={log.id} className="px-4 py-2 text-xs">
                  <p className="text-slate-600">
                    <span className="font-medium text-slate-800">{log.action}</span> · {formatDateTime(log.at)} · {log.actor ?? 'sistem'}
                  </p>
                  {log.reason ? <p className="text-slate-500">Alasan: {log.reason}</p> : null}
                  <p className="truncate text-slate-500">hash {log.hash.slice(0, 16)}…</p>
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      ) : null}

      <DecisionDialog
        approval={myApprovals.find((approval) => approval.id === dialog?.approvalId) as unknown as WorkflowApprovalRow ?? null}
        decision={dialog?.decision ?? 'approve'}
        onClose={() => setDialog(null)}
        onDecided={() => queryClient.invalidateQueries({ queryKey: ['workflow'] })}
      />
    </div>
  );
}

function StepRow({ step }: { step: WorkflowStepRow }) {
  const tone =
    step.status === 'COMPLETED' ? 'success' : step.status === 'REJECTED' ? 'danger' : step.status === 'RETURNED' ? 'warning' : 'info';

  return (
    <li className="px-4 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="text-sm font-medium text-slate-800">
            {step.sequence}. {step.name}
            {step.round > 1 ? <span className="ml-2 text-xs text-amber-600">ronde {step.round}</span> : null}
          </p>
          <p className="text-xs text-slate-500">
            {step.approval_mode} · kuorum {step.quorum.policy}
            {step.quorum.policy === 'MINIMUM_N' || step.quorum.policy === 'PERCENTAGE' ? ` (${step.quorum.value})` : ''} ·{' '}
            {step.quorum.approved}/{step.quorum.total} setuju
            {step.sla_minutes ? ` · SLA ${step.sla_minutes} menit` : ''}
          </p>
        </div>
        <Badge tone={tone}>{step.status_label}</Badge>
      </div>

      {step.approvers.length > 0 ? (
        <ul className="mt-2 space-y-1">
          {step.approvers.map((approver) => (
            <li key={approver.approval_id} className="flex flex-wrap items-center gap-2 text-xs text-slate-600">
              <Badge tone={approver.status === 'APPROVED' ? 'success' : approver.status === 'PENDING' ? 'info' : 'neutral'}>
                {approver.status}
              </Badge>
              <span>{approver.name ?? `User #${approver.user_id}`}</span>
              <span className="text-slate-500">via {approver.source.toLowerCase()}</span>
              {approver.is_delegate && approver.original_approver ? (
                <span className="text-amber-600">delegasi dari {approver.original_approver}</span>
              ) : null}
              {approver.responded_at ? <span className="text-slate-500">{formatDateTime(approver.responded_at)}</span> : null}
            </li>
          ))}
        </ul>
      ) : null}

      {step.warnings.length > 0 ? (
        <ul className="mt-2 list-disc pl-5 text-xs text-amber-600">
          {step.warnings.map((warning) => (
            <li key={warning}>{warning}</li>
          ))}
        </ul>
      ) : null}
    </li>
  );
}
