import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { Textarea } from '@/components/ui/Textarea';
import type { TahfizCertificate } from '@/types/tahfiz';

interface Props {
  open: boolean;
  certificate: TahfizCertificate | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (reason: string) => void;
}

/**
 * Cabut sertifikat yang sudah terbit. Alasan pencabutan wajib diisi.
 */
export function RevokeCertificateModal({
  open,
  certificate,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setReason('');
    setErrors({});
  }, [open]);

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!reason.trim()) {
      setErrors({ revoke_reason: 'Alasan pencabutan wajib diisi' });
      return;
    }
    onSubmit(reason);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Cabut Sertifikat"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button variant="danger" type="submit" form="revoke-certificate-form" loading={submitting}>
            Cabut Sertifikat
          </Button>
        </>
      }
    >
      <form id="revoke-certificate-form" onSubmit={handleSubmit} className="space-y-4">
        {certificate && (
          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
            Sertifikat:{' '}
            <span className="font-mono font-medium text-slate-800">
              {certificate.certificate_number}
            </span>
            {' — '}
            {certificate.participant?.name ?? '—'}
          </p>
        )}

        <div>
          <Label required>Alasan Pencabutan</Label>
          <Textarea
            value={reason}
            error={err('revoke_reason')}
            placeholder="mis. kesalahan data pencapaian"
            onChange={(e) => setReason(e.target.value)}
          />
        </div>

        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
          Sertifikat yang dicabut tidak lagi valid saat diverifikasi melalui QR.
        </p>
      </form>
    </Modal>
  );
}