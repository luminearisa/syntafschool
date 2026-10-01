import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { getErrorMessage } from '@/lib/axios';
import { activityApi } from '../api/activityApi';
import type { ActivityCertificateVerification } from '../types';

/**
 * Verifikasi Sertifikat Aktivitas.
 *
 * Endpoint verifikasi sengaja publik dan hanya mengembalikan fakta sertifikat
 * (nomor, jenis, judul, program, tanggal, status) — **tanpa** nama atau NIS
 * siswa, karena nomor sertifikat dapat beredar luas dan pemegangnya tidak
 * otomatis berhak atas data anak.
 */
export default function ActivityCertificateVerifyPage() {
  const [number, setNumber] = useState('');
  const [result, setResult] = useState<ActivityCertificateVerification | null>(null);
  const [error, setError] = useState<string | null>(null);

  const verify = useMutation({
    mutationFn: () => activityApi.verifyCertificate(number.trim()).then((r) => r.data.data),
    onSuccess: (data) => {
      setResult(data);
      setError(null);
    },
    onError: (mutationError) => {
      setResult(null);
      setError(getErrorMessage(mutationError));
    },
  });

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Verifikasi Sertifikat Aktivitas</h1>
        <p className="mt-1 text-sm text-slate-500">
          Masukkan nomor sertifikat (mis. CERT-ACT-2026-000001) atau kode verifikasinya. Hasil verifikasi tidak memuat data pribadi siswa.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Periksa keaslian</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <form
            className="flex flex-wrap gap-2"
            onSubmit={(event) => {
              event.preventDefault();
              if (number.trim().length >= 6) verify.mutate();
            }}
          >
            <div className="w-80">
              <Input placeholder="Nomor / kode sertifikat" value={number} onChange={(event) => setNumber(event.target.value)} />
            </div>
            <Button type="submit" loading={verify.isPending} disabled={number.trim().length < 6}>
              Verifikasi
            </Button>
          </form>

          {error && <p className="text-sm text-red-700">{error}</p>}

          {result && (
            <div
              className={`rounded-lg border px-4 py-3 text-sm ${
                result.valid ? 'border-green-200 bg-green-50 text-green-900' : 'border-amber-200 bg-amber-50 text-amber-900'
              }`}
            >
              <div className="flex items-center gap-2">
                <Badge tone={result.valid ? 'success' : 'warning'}>{result.result_label}</Badge>
                <span>{result.valid ? 'Sertifikat sah dan terbit.' : 'Sertifikat tidak dapat dinyatakan sah.'}</span>
              </div>

              {result.certificate && (
                <dl className="mt-3 grid gap-1 text-sm sm:grid-cols-2">
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-600">Nomor</dt>
                    <dd>{result.certificate.number}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-600">Jenis</dt>
                    <dd>{result.certificate.type_label ?? result.certificate.type}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-600">Judul</dt>
                    <dd>{result.certificate.title}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-600">Program</dt>
                    <dd>{result.certificate.program ?? '-'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs uppercase tracking-wide text-slate-600">Tanggal terbit</dt>
                    <dd>{result.certificate.issued_date ?? '-'}</dd>
                  </div>
                </dl>
              )}

              <p className="mt-3 text-xs text-slate-600">Diperiksa pada {result.verified_at.slice(0, 19).replace('T', ' ')}</p>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
