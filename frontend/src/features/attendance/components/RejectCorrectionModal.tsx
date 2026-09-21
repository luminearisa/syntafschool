import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { AttendanceCorrection } from '@/types/attendance';

interface Props {
  open: boolean;
  correction: AttendanceCorrection | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}

/** Penolakan koreksi presensi — presensi tidak ikut berubah. */
export function RejectCorrectionModal({
  open,
  correction,
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

    if (!reason.trim()) {
      setError('Alasan penolakan wajib diisi');
      return;
    }

    onSubmit(reason);
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Tolak Koreksi Presensi"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            type="submit"
            form="reject-correction-form"
            variant="danger"
            loading={submitting}
          >
            Tolak Koreksi
          </Button>
        </>
      }
    >
      <form id="reject-correction-form" onSubmit={handleSubmit} className="space-y-4">
        {correction && (
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-600">
            <div className="font-medium text-slate-700">
              {correction.attendance?.student?.full_name ?? 'Siswa'} ·{' '}
              {correction.attendance?.date ?? '-'}
            </div>
            <div className="mt-1">
              {correction.old_status_name ?? '-'} → {correction.new_status_name ?? '-'}
            </div>
            <div className="mt-1 italic">"{correction.reason}"</div>
          </div>
        )}

        <div>
          <Label required>Alasan Penolakan</Label>
          <Textarea
            value={reason}
            error={error || serverErrors?.reason?.[0]}
            placeholder="mis. bukti surat izin tidak dilampirkan"
            onChange={(e) => {
              setReason(e.target.value);
              setError('');
            }}
          />
          {(error || serverErrors?.reason?.[0]) && (
            <p className="mt-1 text-xs text-red-500">{error || serverErrors?.reason?.[0]}</p>
          )}
        </div>
      </form>
    </Modal>
  );
}
