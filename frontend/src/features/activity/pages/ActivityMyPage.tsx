import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { activityApi } from '../api/activityApi';

type Tone = 'neutral' | 'danger' | 'warning' | 'success' | 'info';

const TONES: Record<string, Tone> = {
  ACTIVE: 'success',
  COMPLETED: 'info',
  SUSPENDED: 'warning',
  WITHDRAWN: 'neutral',
  APPROVED: 'success',
  SELECTED: 'success',
  SUBMITTED: 'info',
  UNDER_REVIEW: 'info',
  WAITLISTED: 'warning',
  REJECTED: 'danger',
  CANCELLED: 'neutral',
};

const statusTone = (status?: string): Tone => TONES[status ?? ''] ?? 'neutral';

/**
 * Aktivitas Saya — pengalaman siswa (dan pratinjau orang tua).
 *
 * Menampilkan keanggotaan beserta kehadiran, nilai, dan sertifikat; permohonan
 * berhenti dibuat dari sini dengan alasan yang wajib diisi, karena keputusan
 * berhenti dari aktivitas bukan tombol hapus.
 */
export default function ActivityMyPage() {
  const queryClient = useQueryClient();
  const [reason, setReason] = useState('');
  const [targetMembership, setTargetMembership] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const metaQuery = useQuery({ queryKey: ['activity', 'me'], queryFn: () => activityApi.me().then((r) => r.data.data) });

  const studentId = metaQuery.data?.student_id ?? metaQuery.data?.child_student_ids[0] ?? 0;

  const membershipsQuery = useQuery({
    queryKey: ['activity', 'memberships', studentId],
    queryFn: () => activityApi.membershipForStudent(studentId).then((r) => r.data.data),
    enabled: studentId > 0,
  });

  const registrationsQuery = useQuery({
    queryKey: ['activity', 'my-registrations'],
    queryFn: () => activityApi.myRegistrations({ per_page: 20 }).then((r) => r.data.data),
  });

  const certificatesQuery = useQuery({
    queryKey: ['activity', 'certificates', studentId],
    queryFn: () => activityApi.certificates({ student_id: studentId, per_page: 20 }).then((r) => r.data.data),
    enabled: studentId > 0,
  });

  const portfolioQuery = useQuery({
    queryKey: ['activity', 'portfolio', studentId],
    queryFn: () => activityApi.portfolio(studentId).then((r) => r.data.data),
    enabled: studentId > 0,
  });

  const withdraw = useMutation({
    mutationFn: (membershipId: number) => activityApi.withdraw(membershipId, reason).then((r) => r.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Permohonan berhenti dicatat dan menunggu keputusan pembina/pengelola.' });
      setReason('');
      setTargetMembership(null);
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  if (metaQuery.isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  const memberships = membershipsQuery.data ?? [];
  const registrations = registrationsQuery.data ?? [];
  const certificates = certificatesQuery.data ?? [];
  const portfolio = portfolioQuery.data;

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Aktivitas Saya</h1>
        <p className="mt-1 text-sm text-slate-500">
          Keanggotaan, kehadiran, nilai perkembangan, prestasi, dan sertifikat dalam satu tempat.
        </p>
      </div>

      {notice && (
        <div
          role="status"
          className={
            notice.tone === 'success'
              ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
              : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'
          }
        >
          {notice.text}
        </div>
      )}

      {portfolio && (
        <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {[
            ['Aktivitas', portfolio.summary.activities],
            ['Aktif', portfolio.summary.active_activities],
            ['Prestasi', portfolio.summary.achievements],
            ['Sertifikat', portfolio.summary.certificates],
            ['Kepemimpinan', portfolio.summary.leadership_roles],
            ['Rata-rata hadir', portfolio.summary.average_attendance === null ? '-' : `${Math.round(portfolio.summary.average_attendance)}%`],
          ].map(([label, value]) => (
            <Card key={String(label)}>
              <CardBody>
                <p className="text-xs uppercase tracking-wide text-slate-600">{label}</p>
                <p className="mt-1 text-2xl font-semibold text-slate-800">{value as string}</p>
              </CardBody>
            </Card>
          ))}
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Keanggotaan saya</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          {memberships.length === 0 ? (
            <EmptyState title="Belum menjadi anggota aktivitas" description="Lihat katalog untuk menemukan aktivitas yang cocok." />
          ) : (
            memberships.map((membership) => (
              <div key={membership.id} className="rounded-lg border border-slate-200 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <p className="font-medium text-slate-800">{membership.program}</p>
                    <p className="text-xs text-slate-500">
                      {membership.role_label ?? membership.role} · gabung {membership.joined_at ?? '-'}
                    </p>
                  </div>
                  <div className="flex items-center gap-3">
                    <div className="text-right text-xs text-slate-600">
                      <p>
                        Kehadiran{' '}
                        {membership.attendance_percentage === null || membership.attendance_percentage === undefined
                          ? '-'
                          : `${Math.round(Number(membership.attendance_percentage))}%`}
                      </p>
                      <p>Nilai {membership.average_score === null || membership.average_score === undefined ? '-' : Number(membership.average_score).toFixed(1)}</p>
                    </div>
                    <Badge tone={statusTone(membership.status)}>{membership.status_label ?? membership.status}</Badge>
                    {membership.status !== 'WITHDRAWN' && membership.status !== 'COMPLETED' && (
                      <Button size="sm" variant="outline" onClick={() => setTargetMembership(membership.id)}>
                        Berhenti
                      </Button>
                    )}
                  </div>
                </div>

                {targetMembership === membership.id && (
                  <div className="mt-3 space-y-2 rounded-md bg-slate-50 p-3">
                    <Textarea
                      rows={2}
                      placeholder="Alasan berhenti (wajib) — mis. bentrok jadwal les atau sakit berkepanjangan."
                      value={reason}
                      onChange={(event) => setReason(event.target.value)}
                    />
                    <div className="flex gap-2">
                      <Button size="sm" variant="danger" loading={withdraw.isPending} onClick={() => withdraw.mutate(membership.id)} disabled={reason.trim().length < 3}>
                        Kirim permohonan
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setTargetMembership(null)}>
                        Batal
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Pendaftaran terakhir</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {registrations.length === 0 ? (
              <p className="text-slate-500">Belum ada pendaftaran.</p>
            ) : (
              registrations.map((registration) => (
                <div key={registration.id} className="flex items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                  <div>
                    <p className="font-medium text-slate-800">{registration.program?.name}</p>
                    <p className="text-xs text-slate-500">
                      {registration.code}
                      {registration.waitlist_position ? ` · antrean #${registration.waitlist_position}` : ''}
                    </p>
                  </div>
                  <Badge tone={statusTone(registration.status)}>{registration.status_label ?? registration.status}</Badge>
                </div>
              ))
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Sertifikat</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2 text-sm">
            {certificates.length === 0 ? (
              <p className="text-slate-500">Belum ada sertifikat. Sertifikat terbit setelah aktivitas dinyatakan selesai.</p>
            ) : (
              certificates.map((certificate) => (
                <div key={certificate.id} className="flex items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                  <div>
                    <p className="font-medium text-slate-800">{certificate.title}</p>
                    <p className="text-xs text-slate-500">
                      {certificate.certificate_number} · {certificate.issued_date}
                    </p>
                  </div>
                  <Badge tone={certificate.status === 'ISSUED' ? 'success' : 'danger'}>{certificate.status_label ?? certificate.status}</Badge>
                </div>
              ))
            )}
          </CardBody>
        </Card>
      </div>

      {portfolio && portfolio.skills.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Keterampilan yang terbukti</CardTitle>
          </CardHeader>
          <CardBody className="flex flex-wrap gap-2">
            {portfolio.skills.map((skill) => (
              <Badge key={skill.skill} tone="brand">
                {skill.skill}
                {skill.level ? ` · ${skill.level}` : ''}
                {skill.evidence_count > 1 ? ` (${skill.evidence_count} bukti)` : ''}
              </Badge>
            ))}
          </CardBody>
        </Card>
      )}
    </div>
  );
}
