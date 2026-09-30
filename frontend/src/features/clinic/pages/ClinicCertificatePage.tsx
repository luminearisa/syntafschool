import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { FileCheck2, FileUp, QrCode } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';
import { downloadBlob } from '../utils/download';

/**
 * Surat keterangan kesehatan & dokumen pasien.
 *
 * Alur surat bersifat eksplisit: diminta → ditinjau → diputuskan → diterbitkan.
 * Surat tidak "terbit" hanya karena ada permintaan, karena penerbitan adalah
 * pernyataan klinis yang harus disetujui tenaga berwenang. Setiap surat punya
 * kode verifikasi yang dapat dicek pihak luar tanpa membuka rekam medis.
 */
export default function ClinicCertificatePage() {
  const queryClient = useQueryClient();
  const [patientId, setPatientId] = useState('');
  const [requestForm, setRequestForm] = useState({ type: 'RETURN_TO_SCHOOL', purpose: '' });
  const [reviewForm, setReviewForm] = useState({
    clinical_summary: '',
    recommendation: '',
    restrictions: '',
    valid_until: '',
  });
  const [term, setTerm] = useState('');
  const [docType, setDocType] = useState('MEDICAL_CERTIFICATE');
  const [docTitle, setDocTitle] = useState('');
  const [docVisibility, setDocVisibility] = useState('CLINICAL');

  const search = useQuery({
    queryKey: ['clinic', 'patients', term],
    queryFn: () => clinicApi.patients({ search: term, limit: 20 }),
  });

  const certificates = useQuery({
    queryKey: ['clinic', 'certificates'],
    queryFn: () => clinicApi.certificates({}),
  });

  const documents = useQuery({
    queryKey: ['clinic', 'documents', patientId],
    queryFn: () => clinicApi.documents(Number(patientId)),
    enabled: patientId !== '',
  });

  const requestCertificate = useMutation({
    mutationFn: () => clinicApi.requestCertificate({ patient_id: Number(patientId), ...requestForm }),
    onSuccess: (created) => {
      toast.success(`Permintaan surat ${created.certificate_number} dibuat.`);
      setRequestForm({ type: 'RETURN_TO_SCHOOL', purpose: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'certificates'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const review = useMutation({
    mutationFn: ({ id, approve }: { id: number; approve: boolean }) =>
      clinicApi.reviewCertificate(id, {
        approve,
        clinical_summary: reviewForm.clinical_summary || null,
        recommendation: reviewForm.recommendation || null,
        restrictions: reviewForm.restrictions || null,
        valid_until: reviewForm.valid_until || null,
        reason: approve ? null : 'Belum memenuhi syarat klinis',
      }),
    onSuccess: () => {
      toast.success('Keputusan surat disimpan.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'certificates'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const issue = useMutation({
    mutationFn: (id: number) => clinicApi.issueCertificate(id),
    onSuccess: () => {
      toast.success('Surat diterbitkan — orang tua diberi kabar.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'certificates'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const upload = useMutation({
    mutationFn: (file: File) =>
      clinicApi.uploadDocument(Number(patientId), file, {
        type: docType,
        title: docTitle || file.name,
        visibility: docVisibility,
      }),
    onSuccess: () => {
      toast.success('Dokumen diunggah (disimpan pada penyimpanan privat).');
      setDocTitle('');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'documents', patientId] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = certificates.data ?? [];
  const patients = search.data?.rows ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Surat Keterangan & Dokumen</h1>
        <p className="text-sm text-slate-500">
          Surat sehat, izin sakit, clearance kegiatan — dengan alur peninjauan dan kode verifikasi publik.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Pasien</CardTitle>
        </CardHeader>
        <CardBody className="space-y-2">
          <Input
            placeholder="Cari nama / NIS / nomor rekam medis"
            value={term}
            onChange={(event) => setTerm(event.target.value)}
          />
          {patients.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              {patients.map((row) => (
                <button
                  key={row.patient_id}
                  type="button"
                  onClick={() => setPatientId(String(row.patient_id))}
                  className={`rounded-md border p-2 text-left text-sm hover:bg-slate-50 ${
                    patientId === String(row.patient_id) ? 'border-sky-400 bg-sky-50' : ''
                  }`}
                >
                  <span className="font-medium text-slate-800">{row.student?.name ?? 'Pasien'}</span>
                  <span className="ml-2 text-xs text-slate-500">{row.medical_record_number}</span>
                </button>
              ))}
            </div>
          ) : null}
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileCheck2 className="h-4 w-4" /> Ajukan surat
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div>
              <Label>Jenis surat</Label>
              <Select value={requestForm.type} onChange={(event) => setRequestForm({ ...requestForm, type: event.target.value })}>
                <option value="SCHOOL_ACTIVITY">Kegiatan sekolah</option>
                <option value="SPORTS">Olahraga</option>
                <option value="BOARDING">Asrama</option>
                <option value="EVENT">Acara</option>
                <option value="RETURN_TO_SCHOOL">Kembali ke sekolah</option>
                <option value="MEDICAL_LEAVE">Izin sakit</option>
                <option value="OTHER">Lainnya</option>
              </Select>
            </div>
            <Textarea
              placeholder="Keperluan surat"
              value={requestForm.purpose}
              onChange={(event) => setRequestForm({ ...requestForm, purpose: event.target.value })}
            />
            <Button
              className="w-full"
              onClick={() => requestCertificate.mutate()}
              disabled={patientId === '' || requestCertificate.isPending}
            >
              Ajukan permintaan
            </Button>
            <p className="text-xs text-slate-500">
              Permintaan tidak langsung terbit: surat harus ditinjau dan disetujui tenaga berwenang.
            </p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Formulir peninjauan</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <Textarea
              placeholder="Ringkasan klinis (menjadi isi surat)"
              value={reviewForm.clinical_summary}
              onChange={(event) => setReviewForm({ ...reviewForm, clinical_summary: event.target.value })}
            />
            <Textarea
              placeholder="Rekomendasi"
              value={reviewForm.recommendation}
              onChange={(event) => setReviewForm({ ...reviewForm, recommendation: event.target.value })}
            />
            <Textarea
              placeholder="Pembatasan (mis. tidak boleh olahraga berat 2 minggu)"
              value={reviewForm.restrictions}
              onChange={(event) => setReviewForm({ ...reviewForm, restrictions: event.target.value })}
            />
            <div>
              <Label>Berlaku sampai</Label>
              <Input
                type="date"
                value={reviewForm.valid_until}
                onChange={(event) => setReviewForm({ ...reviewForm, valid_until: event.target.value })}
              />
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <FileUp className="h-4 w-4" /> Unggah dokumen pasien
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div>
              <Label>Jenis dokumen</Label>
              <Select value={docType} onChange={(event) => setDocType(event.target.value)}>
                <option value="MEDICAL_CERTIFICATE">Surat keterangan medis</option>
                <option value="REFERRAL_LETTER">Surat rujukan</option>
                <option value="TREATMENT_NOTE">Catatan tindakan</option>
                <option value="HEALTH_CLEARANCE">Surat sehat</option>
                <option value="MEDICATION_INSTRUCTION">Instruksi obat</option>
                <option value="OTHER">Lainnya</option>
              </Select>
            </div>
            <Input
              placeholder="Judul dokumen"
              value={docTitle}
              onChange={(event) => setDocTitle(event.target.value)}
            />
            <div>
              <Label>Visibilitas</Label>
              <Select value={docVisibility} onChange={(event) => setDocVisibility(event.target.value)}>
                <option value="CLINICAL">Klinis (petugas saja)</option>
                <option value="PARENT">Orang tua boleh lihat</option>
                <option value="STAFF">Pegawai sekolah</option>
              </Select>
            </div>
            <input
              type="file"
              accept="application/pdf,image/jpeg,image/png,image/webp"
              onChange={(event) => {
                const file = event.target.files?.[0];
                if (file && patientId !== '') upload.mutate(file);
                else if (file) toast.error('Pilih pasien lebih dahulu.');
              }}
              className="w-full text-sm"
            />
            <p className="text-xs text-slate-500">
              Hanya PDF/gambar maksimal 8 MB. Berkas disimpan pada penyimpanan privat dan hanya dapat diunduh lewat
              endpoint ber-otorisasi.
            </p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Daftar surat keterangan</CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          {rows.length === 0 ? (
            <div className="p-4">
              <EmptyState title="Belum ada surat" description="Permintaan surat baru akan tampil di sini." />
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {rows.map((row) => (
                <div key={row.id} className="space-y-2 p-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-900">{row.certificate_number}</span>
                    <Badge
                      tone={
                        row.status === 'APPROVED'
                          ? 'success'
                          : row.status === 'REJECTED'
                            ? 'danger'
                            : row.status === 'REQUESTED'
                              ? 'warning'
                              : 'info'
                      }
                    >
                      {row.status}
                    </Badge>
                    <Badge tone="neutral">{row.type}</Badge>
                    <span className="text-sm text-slate-700">{row.patient?.student?.full_name ?? 'Pasien'}</span>
                  </div>
                  {row.purpose ? <p className="text-sm text-slate-600">{row.purpose}</p> : null}
                  {row.clinical_summary ? (
                    <p className="text-xs text-slate-600">Ringkasan: {row.clinical_summary}</p>
                  ) : null}
                  {row.rejection_reason ? (
                    <p className="text-xs text-rose-600">Ditolak: {row.rejection_reason}</p>
                  ) : null}
                  {row.issued_at ? (
                    <p className="text-xs text-slate-500">
                      Diterbitkan {new Date(row.issued_at).toLocaleString('id-ID')}
                      {row.valid_until ? ` · berlaku sampai ${row.valid_until}` : ''}
                    </p>
                  ) : null}

                  <div className="flex flex-wrap gap-2">
                    {row.status === 'REQUESTED' ? (
                      <>
                        <Button
                          size="sm"
                          onClick={() => review.mutate({ id: row.id, approve: true })}
                          disabled={review.isPending}
                        >
                          Setujui
                        </Button>
                        <Button
                          size="sm"
                          variant="danger"
                          onClick={() => review.mutate({ id: row.id, approve: false })}
                          disabled={review.isPending}
                        >
                          Tolak
                        </Button>
                      </>
                    ) : null}

                    {row.status === 'APPROVED' && row.issued_at === null ? (
                      <Button size="sm" variant="secondary" onClick={() => issue.mutate(row.id)} disabled={issue.isPending}>
                        Terbitkan surat
                      </Button>
                    ) : null}

                    <span className="flex items-center gap-1 text-xs text-slate-500">
                      <QrCode className="h-3 w-3" /> kode verifikasi: {row.verification_code}
                    </span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      {patientId !== '' ? (
        <Card>
          <CardHeader>
            <CardTitle>Dokumen pasien terpilih</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            {(documents.data ?? []).length === 0 ? (
              <div className="p-4">
                <EmptyState title="Belum ada dokumen" description="Unggahan dokumen pasien akan tampil di sini." />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {(documents.data ?? []).map((document) => (
                  <div key={document.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                    <div>
                      <p className="font-medium text-slate-800">{document.title}</p>
                      <p className="text-xs text-slate-500">
                        {document.document_number} · {document.type} · v{document.version} · {document.visibility}
                        {document.is_current ? '' : ' · versi lama'}
                      </p>
                    </div>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() =>
                        clinicApi
                          .downloadDocument(document.id)
                          .then(({ blob, filename }) => downloadBlob(blob, filename))
                          .catch((error) => toast.error(getErrorMessage(error)))
                      }
                    >
                      Unduh
                    </Button>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      ) : null}

      <p className="text-xs text-slate-500">
        Riwayat lengkap per pasien ada di{' '}
        <Link className="text-sky-600 underline" to="/clinic/patients">
          Pasien & Rekam Medis
        </Link>
        .
      </p>
    </div>
  );
}
