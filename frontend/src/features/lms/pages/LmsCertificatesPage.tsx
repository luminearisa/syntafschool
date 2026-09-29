import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Award, Download, ShieldCheck } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { phase4Api } from '../api/phase4Api';

/**
 * Sertifikat siswa + verifikasi publik (§21–§25).
 *
 * Verifikasi memakai token/nomor sertifikat dan hanya menampilkan informasi yang
 * memang boleh publik (status, nama pada sertifikat, judul course, waktu terbit).
 */
export default function LmsCertificatesPage() {
  const [query, setQuery] = useState('');
  const [verification, setVerification] = useState<Record<string, unknown> | null>(null);
  const [verifying, setVerifying] = useState(false);

  const certificates = useQuery({
    queryKey: ['lms', 'certificates'],
    queryFn: () => phase4Api.myCertificates(),
  });

  const rows = certificates.data?.certificates ?? [];

  async function verify() {
    if (!query.trim()) return;
    setVerifying(true);
    try {
      const result = await phase4Api.verifyCertificate(query.trim());
      setVerification(result);
    } finally {
      setVerifying(false);
    }
  }

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <Award className="h-5 w-5 text-brand-600" aria-hidden="true" /> Sertifikat saya
        </h1>
        <p className="text-sm text-slate-500">
          Sertifikat terbit otomatis setelah seluruh syarat course terpenuhi.
        </p>
      </header>

      {certificates.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          title="Belum ada sertifikat"
          description="Selesaikan course beserta syaratnya (progres, nilai, atau kompetensi) untuk memperoleh sertifikat."
        />
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {rows.map((certificate) => (
            <Card key={certificate.id}>
              <CardHeader className="flex items-start justify-between gap-3">
                <div>
                  <CardTitle>{certificate.course_name ?? 'Course'}</CardTitle>
                  <p className="mt-1 font-mono text-xs text-slate-500">{certificate.certificate_number}</p>
                </div>
                <Badge tone={(certificate.tone ?? 'green') as never}>{certificate.status_label ?? certificate.status}</Badge>
              </CardHeader>
              <CardBody className="space-y-3 text-sm text-slate-600">
                <dl className="grid grid-cols-2 gap-2">
                  <div>
                    <dt className="text-xs text-slate-500">Terbit</dt>
                    <dd>{certificate.issued_at ? new Date(certificate.issued_at).toLocaleDateString('id-ID') : '-'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-slate-500">Nilai</dt>
                    <dd>{certificate.grade_value ?? '-'}</dd>
                  </div>
                  <div>
                    <dt className="text-xs text-slate-500">Ketuntasan</dt>
                    <dd>{certificate.completion_percent !== null && certificate.completion_percent !== undefined ? `${certificate.completion_percent}%` : '-'}</dd>
                  </div>
                </dl>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    void phase4Api.registerCertificateDownload(certificate.id);
                    window.print();
                  }}
                >
                  <Download className="mr-2 h-4 w-4" aria-hidden="true" /> Unduh / cetak
                </Button>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="h-4 w-4 text-emerald-600" aria-hidden="true" /> Verifikasi keaslian sertifikat
          </CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <p className="text-sm text-slate-600">
            Masukkan nomor sertifikat atau token verifikasi yang tertera pada dokumen.
          </p>
          <div className="flex flex-col gap-2 sm:flex-row">
            <label className="flex-1">
              <span className="sr-only">Nomor sertifikat atau token</span>
              <input
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="CERT-2026-000001 atau token"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-brand-500 focus:outline-none"
              />
            </label>
            <Button onClick={verify} disabled={verifying || !query.trim()}>
              {verifying ? 'Memeriksa…' : 'Verifikasi'}
            </Button>
          </div>

          {verification && (
            <div className="rounded-lg bg-slate-50 p-3 text-sm">
              <pre className="whitespace-pre-wrap break-words font-mono text-xs text-slate-700">
                {JSON.stringify(verification, null, 2)}
              </pre>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
