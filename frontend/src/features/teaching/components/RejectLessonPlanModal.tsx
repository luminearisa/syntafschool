import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { LessonPlan } from '@/types/teaching';

interface Props {
  open: boolean;
  plan: LessonPlan | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}

/**
 * Penolakan RPP.
 *
 * Alasan wajib diisi supaya guru tahu bagian mana yang harus direvisi; setelah
 * ditolak, RPP kembali bisa diedit.
 */
export function RejectLessonPlanModal({
  open,
  plan,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!open) return;
    setReason('');
    setError('');
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();

    if (reason.trim().length < 3) {
      setError('Alasan penolakan wajib diisi (minimal 3 karakter)');
      return;
    }

    onSubmit(reason);
  };

  const err = error || serverErrors?.rejection_reason?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tolak RPP / Modul Ajar"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="reject-plan-form" variant="danger" loading={submitting}>
            Tolak RPP
          </Button>
        </>
      }
    >
      <form id="reject-plan-form" onSubmit={handleSubmit} className="space-y-4">
        {plan && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <div className="font-medium text-slate-700">
              {plan.code} — {plan.title}
            </div>
            <div className="mt-1">
              {plan.subject?.name ?? '-'} · {plan.duration_jp} JP · {plan.semester?.name ?? '-'}
            </div>
          </div>
        )}

        <div>
          <Label required>Alasan Penolakan</Label>
          <Textarea
            value={reason}
            error={err}
            rows={4}
            placeholder="mis. tujuan pembelajaran belum terukur, kegiatan inti belum memuat penilaian formatif"
            onChange={(e) => {
              setReason(e.target.value);
              setError('');
            }}
          />
          {err && <p className="mt-1 text-xs text-red-500">{err}</p>}
          <p className="mt-1 text-xs text-slate-500">
            Setelah ditolak, RPP kembali dapat diedit oleh guru penyusun untuk direvisi.
          </p>
        </div>
      </form>
    </Modal>
  );
}
