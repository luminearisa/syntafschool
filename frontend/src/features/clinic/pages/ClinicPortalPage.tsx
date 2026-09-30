import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ShieldCheck, Stethoscope } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';

/**
 * Portal kesehatan orang tua & siswa.
 *
 * Halaman ini baca-saja dan isinya mengikuti kebijakan privasi sekolah: yang
 * ditampilkan adalah layanan yang diterima anak, bukan salinan rekam medis.
 * Catatan internal petugas, asesmen rinci, dan diagnosis hanya muncul bila
 * administrator sekolah memang mengizinkannya.
 */
export default function ClinicPortalPage() {
  const [selected, setSelected] = useState<number | null>(null);

  const children = useQuery({ queryKey: ['clinic', 'portal', 'children'], queryFn: () => clinicApi.portalChildren() });

  const visits = useQuery({
    queryKey: ['clinic', 'portal', 'visits', selected],
    queryFn: () => clinicApi.portalVisits(selected as number),
    enabled: selected !== null,
  });

  const certificates = useQuery({
    queryKey: ['clinic', 'portal', 'certificates', selected],
    queryFn: () => clinicApi.portalCertificates(selected as number),
    enabled: selected !== null,
  });

  if (children.isLoading) {
    return (
      <div className="flex justify-center py-20">
        <Spinner />
      </div>
    );
  }

  if (children.isError) {
    return <EmptyState title="Portal kesehatan tidak dapat dibuka" description={getErrorMessage(children.error)} />;
  }

  const registered = children.data?.registered ?? [];
  const unregistered = children.data?.unregistered ?? [];
  const privacy = children.data?.privacy ?? {};

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Kesehatan Anak</h1>
        <p className="text-sm text-slate-500">
          Riwayat layanan kesehatan anak Anda di sekolah. Halaman ini hanya menampilkan data anak yang terhubung dengan
          akun Anda.
        </p>
      </div>

      {registered.length === 0 && unregistered.length === 0 ? (
        <EmptyState
          title="Belum ada anak terhubung"
          description="Akun Anda belum tertaut ke data siswa. Hubungi tata usaha sekolah bila ini keliru."
        />
      ) : null}

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {registered.map((child) => (
          <button
            key={child.patient_id}
            type="button"
            onClick={() => setSelected(child.patient_id)}
            className={`rounded-lg border p-3 text-left hover:bg-slate-50 ${
              selected === child.patient_id ? 'border-sky-400 bg-sky-50' : ''
            }`}
          >
            <p className="font-medium text-slate-900">{child.name ?? 'Anak'}</p>
            <p className="text-xs text-slate-500">
              {child.nis ?? '—'} · No. rekam medis {child.medical_record_number}
            </p>
            <div className="mt-1 flex flex-wrap gap-1">
              {child.alerts.allergy ? <Badge tone="danger">Ada alergi</Badge> : null}
              {child.alerts.special_care ? <Badge tone="warning">Kondisi khusus</Badge> : null}
            </div>
            <p className="mt-1 text-xs text-slate-500">
              Kunjungan terakhir:{' '}
              {child.last_visit_at ? new Date(child.last_visit_at).toLocaleDateString('id-ID') : 'belum ada'}
            </p>
          </button>
        ))}

        {unregistered.map((student) => (
          <div key={student.id} className="rounded-lg border border-dashed p-3">
            <p className="font-medium text-slate-700">{student.name}</p>
            <p className="text-xs text-slate-500">
              {student.nis ?? '—'} · {student.class_name ?? 'tanpa kelas'}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              Belum pernah berobat ke UKS/klinik, jadi belum ada rekam medis. Data akan dibuat otomatis saat kunjungan
              pertama.
            </p>
          </div>
        ))}
      </div>

      {selected !== null ? (
        <>
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Stethoscope className="h-4 w-4" /> Riwayat layanan
              </CardTitle>
            </CardHeader>
            <CardBody className="p-0">
              {visits.isLoading ? (
                <div className="flex justify-center p-6">
                  <Spinner />
                </div>
              ) : (visits.data ?? []).length === 0 ? (
                <div className="p-4">
                  <EmptyState title="Belum ada kunjungan" description="Anak Anda belum pernah dilayani klinik sekolah." />
                </div>
              ) : (
                <div className="divide-y divide-slate-100">
                  {(visits.data ?? []).map((visit) => (
                    <div key={visit.visit_number} className="space-y-2 p-3">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-slate-900">
                          {new Date(visit.visit_at).toLocaleDateString('id-ID', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric',
                          })}
                        </span>
                        <Badge tone="neutral">{visit.visit_type_label}</Badge>
                        <Badge tone={visit.status === 'DISCHARGED' ? 'success' : 'info'}>{visit.status_label}</Badge>
                      </div>
                      <p className="text-sm text-slate-700">Keluhan: {visit.chief_complaint ?? '—'}</p>
                      {visit.diagnosis ? <p className="text-sm text-slate-700">Diagnosis: {visit.diagnosis}</p> : null}
                      {visit.medications.length > 0 ? (
                        <p className="text-xs text-slate-600">
                          Obat: {visit.medications.map((med) => `${med.medication_name} (${med.status})`).join(', ')}
                        </p>
                      ) : null}
                      {visit.discharge ? (
                        <div className="rounded-md bg-slate-50 p-2 text-xs text-slate-700">
                          <p>
                            Status pulang: {visit.discharge.status_label} · kondisi {visit.discharge.condition}
                          </p>
                          {visit.discharge.instructions ? <p>Instruksi: {visit.discharge.instructions}</p> : null}
                          {visit.discharge.follow_up_required ? (
                            <p>Kontrol ulang: {visit.discharge.follow_up_date}</p>
                          ) : null}
                        </div>
                      ) : null}
                      {visit.referrals.length > 0 ? (
                        <div className="rounded-md bg-orange-50 p-2 text-xs text-slate-700">
                          {visit.referrals.map((referral) => (
                            <p key={referral.referral_number}>
                              Rujukan {referral.referral_number} ke {referral.facility} ({referral.status})
                            </p>
                          ))}
                        </div>
                      ) : null}
                    </div>
                  ))}
                </div>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Surat keterangan</CardTitle>
            </CardHeader>
            <CardBody>
              {(certificates.data ?? []).length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada surat keterangan kesehatan.</p>
              ) : (
                <ul className="space-y-1 text-sm">
                  {(certificates.data ?? []).map((certificate) => (
                    <li key={certificate.certificate_number} className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-800">{certificate.certificate_number}</span>
                      <Badge tone={certificate.is_valid ? 'success' : 'neutral'}>{certificate.status}</Badge>
                      <span className="text-xs text-slate-500">
                        {certificate.type}
                        {certificate.valid_until ? ` · berlaku sampai ${certificate.valid_until}` : ''}
                      </span>
                    </li>
                  ))}
                </ul>
              )}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ShieldCheck className="h-4 w-4" /> Yang dapat Anda lihat
              </CardTitle>
            </CardHeader>
            <CardBody className="text-sm text-slate-600">
              <ul className="list-inside list-disc space-y-1">
                <li>Tanggal & jenis layanan: selalu ditampilkan</li>
                <li>Obat yang diberikan: {privacy.medication_visible ? 'ditampilkan' : 'disembunyikan kebijakan'}</li>
                <li>Diagnosis: {privacy.diagnosis_visible ? 'ditampilkan' : 'disembunyikan kebijakan'}</li>
                <li>Catatan internal petugas: selalu disembunyikan</li>
                <li>Asesmen klinis rinci: {privacy.assessment_visible ? 'ditampilkan' : 'disembunyikan kebijakan'}</li>
              </ul>
              <p className="mt-2 text-xs text-slate-500">
                Halaman ini baca-saja. Perubahan catatan kesehatan hanya dapat dilakukan petugas klinik.
              </p>
            </CardBody>
          </Card>
        </>
      ) : null}
    </div>
  );
}
