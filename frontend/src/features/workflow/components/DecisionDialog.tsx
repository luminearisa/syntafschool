import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';
import type { WorkflowApprovalRow } from '../types';

type Decision = 'approve' | 'reject' | 'return' | 'request-changes';

const labels: Record<Decision, string> = {
  approve: 'Setujui',
  reject: 'Tolak',
  return: 'Kembalikan untuk Revisi',
  'request-changes': 'Minta Perubahan',
};

const hints: Record<Decision, string> = {
  approve: 'Komentar opsional. Keputusan tercatat atas nama Anda.',
  reject: 'Alasan penolakan wajib diisi — requester akan menerimanya sebagai keputusan final.',
  return: 'Sebutkan apa yang harus diperbaiki. Request kembali ke requester dan dapat disubmit ulang.',
  'request-changes': 'Sebutkan perubahan yang diminta agar requester dapat memperbaiki dokumennya.',
};

/**
 * Dialog keputusan approver (§32, §33).
 *
 * `Idempotency-Key` dibuat sekali per pembukaan dialog: bila jaringan putus dan
 * pengguna menekan tombol dua kali, engine mengenali permintaan yang sama dan
 * tidak mencatat keputusan kedua (§64).
 */
export function DecisionDialog({
  approval,
  decision,
  onClose,
  onDecided,
}: {
  approval: WorkflowApprovalRow | null;
  decision: Decision;
  onClose: () => void;
  onDecided: () => void;
}) {
  const [comment, setComment] = useState('');
  const [overrideReason, setOverrideReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [idempotencyKey] = useState(() => `wf-${Date.now()}-${Math.random().toString(36).slice(2, 10)}`);

  const requiresComment = decision !== 'approve';

  const submit = async () => {
    if (!approval) return;

    if (requiresComment && comment.trim() === '') {
      toast.error('Komentar/alasan wajib diisi untuk keputusan ini.');

      return;
    }

    setBusy(true);

    try {
      await workflowApi.decide(approval.id, decision, {
        comment: comment.trim() || undefined,
        override_reason: overrideReason.trim() || undefined,
        idempotencyKey,
      });

      toast.success(`Keputusan "${labels[decision]}" tercatat.`);
      onDecided();
      onClose();
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={approval !== null}
      onClose={onClose}
      title={`${labels[decision]} — ${approval?.title ?? ''}`}
      size="md"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="outline" onClick={onClose} disabled={busy}>
            Batal
          </Button>
          <Button variant={decision === 'approve' ? 'primary' : 'danger'} onClick={submit} loading={busy}>
            {labels[decision]}
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        <div className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
          <p>
            <span className="font-medium">{approval?.instance_code}</span> · {approval?.step} · {approval?.module}
          </p>
          <p className="mt-0.5">{hints[decision]}</p>
        </div>

        <div>
          <Label htmlFor="decision-comment">Komentar / alasan {requiresComment ? <span className="text-red-500">*</span> : null}</Label>
          <Textarea
            id="decision-comment"
            value={comment}
            onChange={(event) => setComment(event.target.value)}
            rows={4}
            placeholder={requiresComment ? 'Tuliskan alasan yang dapat dipertanggungjawabkan…' : 'Catatan tambahan (opsional)'}
          />
        </div>

        <div>
          <Label htmlFor="decision-override">Alasan override (khusus pengelola workflow)</Label>
          <Textarea
            id="decision-override"
            value={overrideReason}
            onChange={(event) => setOverrideReason(event.target.value)}
            rows={2}
            placeholder="Diisi hanya bila Anda bertindak di luar penugasan normal (self-approval/SoD)."
          />
        </div>
      </div>
    </Modal>
  );
}
