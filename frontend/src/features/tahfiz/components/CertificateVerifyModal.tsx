import { useEffect, useState, type FormEvent } from 'react';
import { useMutation } from '@tanstack/react-query';
import { BadgeCheck, ShieldX } from 'lucide-react';
import { toast } from 'sonner';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { certificatesApi } from '../api/tahfizApi';
import { FieldRow } from './TahfizUi';
import { getErrorMessage } from '@/lib/axios';
import type { TahfizCertificate, TahfizCertificateVerifyResult } from '@/types/tahfiz';

interface Props {
  open: boolean;
  certificate?: TahfizCertificate | null;
  onClose: () => void;
}

/**
 * Verifikasi sertifikat lewat token QR.
 *
 * Endpoint verifikasi bersifat publik (tanpa autentikasi), sehingga UI ini juga
 * bekerja untuk pengguna yang tidak memiliki sesi.
 */
export function CertificateVerifyModal({ open, certificate, onClose }: Props) {
  const [token, setToken] = useState('');
  const [result, setResult] = useState<TahfizCertificateVerifyResult | null>(null);

  useEffect(() => {
    if (!open) return;
    setToken(certificate?.qr_token ?? '');
    setResult(null);
  }, [open, certificate]);

  const verifyMutation = useMutation({
    mutationFn: (qrToken: string) => certificatesApi.verify(qrToken),
    onSuccess: (data) => setResult(data),
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!token.trim()) return;
    verifyMutation.mutate(token.trim());
  };

  const cert = result?.certificate ?? null;
  const valid = !!result?.is_valid;

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Verifikasi Sertifikat"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={verifyMutation.isPending}>
            Tutup
          </Button>
          <Button type="submit" form="verify-certificate-form" loading={verifyMutation.isPending}>
            Verifikasi
          </Button>
        </>
      }
    >
      <form id="verify-certificate-form" onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label required>Token QR</Label>
          <Input
            value={token}
            placeholder="Tempel token QR dari sertifikat..."
            onChange={(e) => setToken(e.target.value)}
          />
        </div>

        {result && (
          <div
            className={
              valid
                ? 'rounded-xl border border-green-200 bg-green-50 p-4'
                : 'rounded-xl border border-red-200 bg-red-50 p-4'
            }
          >
            <div className="flex items-center gap-2">
              {valid ? (
                <BadgeCheck className="h-6 w-6 text-green-600" />
              ) : (
                <ShieldX className="h-6 w-6 text-red-600" />
              )}
              <div>
                <p
                  className={
                    valid ? 'text-sm font-semibold text-green-800' : 'text-sm font-semibold text-red-800'
                  }
                >
                  {valid ? 'Sertifikat VALID' : 'Sertifikat TIDAK VALID'}
                </p>
                {result.message && (
                  <p className={valid ? 'text-xs text-green-700' : 'text-xs text-red-700'}>
                    {result.message}
                  </p>
                )}
              </div>
            </div>

            {cert && (
              <div className="mt-3 rounded-lg bg-white/70 p-3">
                <FieldRow label="Nomor">
                  <span className="font-mono">{cert.certificate_number}</span>
                </FieldRow>
                <FieldRow label="Peserta">{cert.participant?.name ?? '—'}</FieldRow>
                <FieldRow label="Pencapaian">{cert.achievement_type_label}</FieldRow>
                <FieldRow label="Tanggal Terbit">{cert.issue_date ?? '—'}</FieldRow>
                <FieldRow label="Status">{cert.status_label}</FieldRow>
                {cert.revoke_reason && (
                  <FieldRow label="Alasan Dicabut">{cert.revoke_reason}</FieldRow>
                )}
              </div>
            )}
          </div>
        )}

        <p className="text-xs text-slate-500">
          Verifikasi berjalan tanpa login (endpoint publik) — hasil yang tampil adalah data langsung
          dari server.
        </p>
      </form>
    </Modal>
  );
}