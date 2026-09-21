import { useEffect, useState } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { EXAM_STATUS_LABELS, toOptions } from '../lib/labels';
import type { TahfizExam, TahfizExamStatus } from '@/types/tahfiz';

interface Props {
  exam: TahfizExam | null;
  submitting: boolean;
  onClose: () => void;
  onSubmit: (status: TahfizExamStatus) => void;
}

const STATUS_OPTIONS = toOptions(EXAM_STATUS_LABELS);

/**
 * Ubah status ujian (draf → terjadwal → berlangsung → selesai/dibatalkan).
 * Transisi divalidasi server-side; kombinasi yang tidak sah akan ditolak.
 */
export function ExamTransitionModal({ exam, submitting, onClose, onSubmit }: Props) {
  const [status, setStatus] = useState<TahfizExamStatus>('draft');

  useEffect(() => {
    if (exam) setStatus(exam.status);
  }, [exam]);

  return (
    <Modal
      open={!!exam}
      onClose={onClose}
      title="Transisi Status Ujian"
      size="sm"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            loading={submitting}
            disabled={!exam || status === exam.status}
            onClick={() => onSubmit(status)}
          >
            Ubah Status
          </Button>
        </>
      }
    >
      <div className="space-y-2">
        <p className="text-sm text-slate-600">
          Status saat ini:{' '}
          <span className="font-medium text-slate-800">
            {exam ? EXAM_STATUS_LABELS[exam.status] : '—'}
          </span>
        </p>
        <div>
          <Label required>Status Baru</Label>
          <Select value={status} onChange={(e) => setStatus(e.target.value as TahfizExamStatus)}>
            {STATUS_OPTIONS.map((o) => (
              <option key={String(o.value)} value={String(o.value)}>
                {o.label}
              </option>
            ))}
          </Select>
        </div>
      </div>
    </Modal>
  );
}