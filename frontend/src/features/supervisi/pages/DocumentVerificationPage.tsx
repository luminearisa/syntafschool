import { useQuery } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { BadgeCheck, FileWarning, Loader2, ShieldCheck } from 'lucide-react';
import { supervisiInsightApi } from '@/features/supervisi/api/supervisiInsightApi';

/**
 * Halaman verifikasi dokumen supervisi (publik).
 *
 * Alamat ini yang disematkan sebagai QR pada berkas PDF: siapa pun yang
 * memegang salinan cetak dapat memastikan dokumen tersebut benar dihasilkan
 * sistem dan isinya tidak diubah — tanpa perlu masuk aplikasi.
 */
export default function DocumentVerificationPage() {
  const { code = '' } = useParams<{ code: string }>();

  const query = useQuery({
    queryKey: ['supervisi', 'verify-document', code],
    queryFn: () => supervisiInsightApi.verifyDocument(code),
    retry: false,
    enabled: code.length > 0,
  });

  const data = query.data;

  return (
    <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6">
      <div className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-8 shadow-sm">
        <div className="mb-6 flex items-center gap-3">
          <ShieldCheck className="h-6 w-6 text-brand-600" />
          <div>
            <h1 className="text-lg font-semibold text-slate-900">Verifikasi Dokumen Supervisi</h1>
            <p className="text-sm text-slate-500">Kode: {code.toUpperCase()}</p>
          </div>
        </div>

        {query.isLoading && (
          <div className="flex items-center gap-2 py-8 text-slate-500">
            <Loader2 className="h-4 w-4 animate-spin" /> Memeriksa dokumen...
          </div>
        )}

        {!query.isLoading && (
          <div className="space-y-4">
            <div
              className={`flex items-start gap-3 rounded-xl border p-4 ${
                data?.valid ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50'
              }`}
            >
              {data?.valid ? (
                <BadgeCheck className="mt-0.5 h-5 w-5 text-green-600" />
              ) : (
                <FileWarning className="mt-0.5 h-5 w-5 text-red-600" />
              )}
              <div>
                <div className={`font-medium ${data?.valid ? 'text-green-800' : 'text-red-800'}`}>
                  {data?.valid ? 'Dokumen sah' : 'Dokumen tidak dapat diverifikasi'}
                </div>
                <p className={`text-sm ${data?.valid ? 'text-green-700' : 'text-red-700'}`}>{data?.message}</p>
              </div>
            </div>

            {data?.title && (
              <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                <div>
                  <dt className="text-slate-500">Jenis dokumen</dt>
                  <dd className="text-slate-900">{data.title}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">Format berkas</dt>
                  <dd className="text-slate-900">{data.format}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">Jumlah baris</dt>
                  <dd className="text-slate-900">{data.rows_count ?? '—'}</dd>
                </div>
                <div>
                  <dt className="text-slate-500">Dihasilkan</dt>
                  <dd className="text-slate-900">
                    {data.generated_at ? new Date(data.generated_at).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' }) : '—'}
                  </dd>
                </div>
                <div className="sm:col-span-2">
                  <dt className="text-slate-500">Sidik digital (SHA-256)</dt>
                  <dd className="break-all font-mono text-xs text-slate-700">{data.checksum ?? '—'}</dd>
                </div>
              </dl>
            )}

            <p className="text-xs text-slate-500">
              Kode ini hanya mengungkap keterangan dokumen — isi laporan tidak dapat dibaca dari halaman ini.
              Bila sidik digital tidak cocok, minta salinan resmi terbaru dari sekolah.
            </p>
          </div>
        )}
      </div>
    </div>
  );
}
