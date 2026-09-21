import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { INTERVENTION_STATUS_LABELS, INTERVENTION_TRANSITIONS } from '../lib/labels';
import type { TahfizIntervention, TahfizInterventionStatus } from '@/types/tahfiz';

export interface InterventionTransitionPayload {
  status: TahfizInterventionStatus;
  completed_at?: string;
}

interface Props {
  open: boolean;
  intervention: TahfizIntervention | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: InterventionTransitionPayload) => void;
}

/**
 * Transisi status intervensi.
 *
 * Hanya status berikutnya yang diizinkan oleh `INTERVENTION_TRANSITIONS` yang
 * ditawarkan. Status `done` wajib menyertakan tanggal selesai; `cancelled`
 * tidak pernah mengirimkannya.
 */
export function InterventionTransitionModal({
  open,
  intervention,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [status, setStatus] = useState<TahfizInterventionStatus | ''>('');
  const [completedAt, setCompletedAt] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  const allowed: TahfizInterventionStatus[] = intervention
    ? INTERVENTION_TRANSITIONS[intervention.status]
    : [];

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setCompletedAt('');
    setStatus(allowed[0] ?? '');
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, intervention]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!status) local.status = 'Status tujuan wajib dipilih';
    if (status === 'done' && !completedAt) {
      local.completed_at = 'Tanggal selesai wajib diisi untuk status Selesai';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: InterventionTransitionPayload = {
      status: status as TahfizInterventionStatus,
      ...(status === 'done' ? { completed_at: completedAt } : {}),
    };
    onSubmit(payload);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Transisi Status Intervensi"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="intervention-transition-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="intervention-transition-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
          Status saat ini:{' '}
          <span className="font-medium text-slate-800">
            {intervention ? INTERVENTION_STATUS_LABELS[intervention.status] : '—'}
          </span>
          . Hanya transisi yang diizinkan yang dapat dipilih.
        </div>

        {allowed.length === 0 ? (
          <p className="text-sm text-slate-500">
            Status ini bersifat final dan tidak memiliki transisi lanjutan.
          </p>
        ) : (
          <>
            <div>
              <Label required>Status Tujuan</Label>
              <Select
                value={status}
                error={err('status')}
                onChange={(e) => {
                  const next = e.target.value as TahfizInterventionStatus | '';
                  setStatus(next);
                  if (next !== 'done') setCompletedAt('');
                }}
              >
                <option value="">-- Pilih Status --</option>
                {allowed.map((s) => (
                  <option key={s} value={s}>
                    {INTERVENTION_STATUS_LABELS[s]}
                  </option>
                ))}
              </Select>
            </div>

            {status === 'done' && (
              <div>
                <Label required>Tanggal Selesai</Label>
                <Input
                  type="date"
                  value={completedAt}
                  error={err('completed_at')}
                  onChange={(e) => setCompletedAt(e.target.value)}
                />
              </div>
            )}
          </>
        )}
      </form>
    </Modal>
  );
}