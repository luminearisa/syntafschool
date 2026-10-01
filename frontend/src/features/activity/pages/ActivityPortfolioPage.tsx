import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';

/**
 * Portofolio & Transkrip Aktivitas.
 *
 * Portofolio selalu **dirakit ulang** dari data asli (keanggotaan, kehadiran,
 * penilaian, prestasi, sertifikat) — bukan catatan yang diketik terpisah,
 * sehingga tidak mungkin bertentangan dengan riwayat. Yang disimpan hanyalah
 * cuplikan (snapshot) saat sekolah ingin membagikannya keluar.
 *
 * Transkrip di sini adalah transkrip **aktivitas**, berbeda dari transkrip
 * akademik: nilai rapor tetap milik SIAKAD.
 */
export default function ActivityPortfolioPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const metaQuery = useQuery({ queryKey: ['activity', 'me'], queryFn: () => activityApi.me().then((r) => r.data.data) });

  const [studentId, setStudentId] = useState('');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [saved, setSaved] = useState<{ id: number; shareUrl: string | null } | null>(null);

  const meta = metaQuery.data;
  const defaultStudentId = meta?.student_id ?? meta?.child_student_ids[0] ?? 0;
  const activeStudentId = Number(studentId || defaultStudentId || 0);

  const portfolioQuery = useQuery({
    queryKey: ['activity', 'portfolio-detail', activeStudentId],
    queryFn: () => activityApi.portfolio(activeStudentId).then((r) => r.data.data),
    enabled: activeStudentId > 0,
  });

  const transcriptQuery = useQuery({
    queryKey: ['activity', 'transcript', activeStudentId],
    queryFn: () => activityApi.transcript(activeStudentId).then((r) => r.data.data),
    enabled: activeStudentId > 0,
  });

  const savePortfolio = useMutation({
    mutationFn: () => activityApi.storePortfolio(activeStudentId).then((r) => r.data.data),
    onSuccess: (portfolio) => {
      setSaved({ id: portfolio.id, shareUrl: portfolio.public_token ? `${window.location.origin}/aktivitas/portofolio/${portfolio.public_token}` : null });
      setNotice({ tone: 'success', text: `Portofolio tersimpan sebagai cuplikan #${portfolio.id}.` });
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const sharePortfolio = useMutation({
    mutationFn: (enabled: boolean) => activityApi.sharePortfolio(saved?.id as number, enabled).then((r) => r.data.data as { public_token?: string | null }),
    onSuccess: (portfolio, enabled) => {
      setSaved((current) =>
        current
          ? {
              ...current,
              shareUrl: enabled && portfolio.public_token ? `${window.location.origin}/aktivitas/portofolio/${portfolio.public_token}` : null,
            }
          : current,
      );
      setNotice({ tone: 'success', text: enabled ? 'Tautan berbagi diaktifkan.' : 'Tautan berbagi dimatikan.' });
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

  const portfolio = portfolioQuery.data;
  const transcript = transcriptQuery.data;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Portofolio & Transkrip Aktivitas</h1>
          <p className="mt-1 text-sm text-slate-500">
            Capaian non-akademik siswa: keanggotaan, keterampilan terbukti, kepemimpinan, prestasi, dan sertifikat.
          </p>
        </div>
        {can('activity.portfolio.share') && (
          <div className="w-44">
            <Input placeholder="ID siswa lain" value={studentId} onChange={(event) => setStudentId(event.target.value)} aria-label="ID siswa" />
          </div>
        )}
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

      {activeStudentId === 0 ? (
        <EmptyState title="Belum ada siswa terpilih" description="Akun ini belum tertaut ke data siswa, dan Anda tidak mengisi ID siswa." />
      ) : portfolioQuery.isLoading ? (
        <Spinner />
      ) : !portfolio ? (
        <EmptyState title="Portofolio belum dapat dirakit" description="Belum ada data aktivitas untuk siswa ini." />
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-sm font-medium text-slate-800">{portfolio.student?.name ?? `Siswa #${activeStudentId}`}</p>
              <p className="text-xs text-slate-500">
                {portfolio.student?.class_name ?? '-'} · dirakit {portfolio.generated_at.slice(0, 19).replace('T', ' ')}
              </p>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" loading={savePortfolio.isPending} onClick={() => savePortfolio.mutate()}>
                Simpan cuplikan portofolio
              </Button>
              {saved && can('activity.portfolio.share') && (
                <>
                  <Button variant="outline" onClick={() => sharePortfolio.mutate(!saved.shareUrl)} loading={sharePortfolio.isPending}>
                    {saved.shareUrl ? 'Matikan tautan berbagi' : 'Aktifkan tautan berbagi'}
                  </Button>
                  {saved.shareUrl && (
                    <Button
                      variant="secondary"
                      onClick={() => {
                        void navigator.clipboard?.writeText(saved.shareUrl as string);
                        setNotice({ tone: 'success', text: 'Tautan disalin. Nama siswa disamarkan pada halaman publik.' });
                      }}
                    >
                      Salin tautan publik
                    </Button>
                  )}
                </>
              )}
            </div>
          </div>

          {saved?.shareUrl && (
            <p className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-2 text-xs text-slate-600 break-all">
              Tautan publik: {saved.shareUrl} — dapat dibuka tanpa login; identitas siswa disamarkan dan NIS tidak pernah dikirim.
            </p>
          )}

          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            {[
              ['Aktivitas', portfolio.summary.activities],
              ['Aktif', portfolio.summary.active_activities],
              ['Keterampilan', portfolio.summary.skills],
              ['Prestasi', `${portfolio.summary.verified_achievements}/${portfolio.summary.achievements}`],
              ['Sertifikat', portfolio.summary.certificates],
              ['Kepemimpinan', portfolio.summary.leadership_roles],
            ].map(([label, value]) => (
              <Card key={String(label)}>
                <CardBody>
                  <p className="text-xs uppercase tracking-wide text-slate-600">{label}</p>
                  <p className="mt-1 text-2xl font-semibold text-slate-800">{value as string | number}</p>
                </CardBody>
              </Card>
            ))}
          </div>

          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Riwayat aktivitas</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {portfolio.activities.length === 0 ? (
                  <p className="text-slate-500">Belum ada aktivitas.</p>
                ) : (
                  portfolio.activities.map((activity) => (
                    <div key={activity.membership_id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                      <div>
                        <p className="text-slate-800">{activity.program}</p>
                        <p className="text-xs text-slate-500">
                          {activity.role_label ?? '-'} · {activity.period ?? 'lintas periode'} · gabung {activity.joined_at ?? '-'}
                        </p>
                      </div>
                      <div className="flex items-center gap-2 text-xs text-slate-600">
                        <span>hadir {activity.attendance_percentage === null || activity.attendance_percentage === undefined ? '-' : `${Math.round(activity.attendance_percentage)}%`}</span>
                        <span>nilai {activity.average_score === null || activity.average_score === undefined ? '-' : Number(activity.average_score).toFixed(1)}</span>
                        <Badge tone={activity.completed ? 'success' : 'neutral'}>{activity.status_label ?? '-'}</Badge>
                      </div>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Keterampilan, kepemimpinan & pengabdian</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3 text-sm">
                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Keterampilan terbukti</p>
                  <div className="mt-1 flex flex-wrap gap-1">
                    {portfolio.skills.length === 0 ? <span className="text-slate-500">Belum ada bukti keterampilan.</span> : null}
                    {portfolio.skills.map((skill) => (
                      <Badge key={skill.skill} tone="brand">
                        {skill.skill}
                        {skill.level ? ` · ${skill.level}` : ''}
                        {skill.evidence_count > 1 ? ` (${skill.evidence_count} bukti)` : ''}
                      </Badge>
                    ))}
                  </div>
                </div>

                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Peran kepemimpinan</p>
                  <ul className="mt-1 space-y-1">
                    {portfolio.leadership.length === 0 ? <li className="text-slate-500">Belum ada peran kepemimpinan.</li> : null}
                    {portfolio.leadership.map((entry, index) => (
                      <li key={index} className="text-slate-700">
                        {entry.role} {entry.program ? `· ${entry.program}` : ''} {entry.period ? `(${entry.period})` : ''}
                      </li>
                    ))}
                  </ul>
                </div>

                <div>
                  <p className="text-xs font-medium uppercase tracking-wide text-slate-600">Pengabdian masyarakat & proyek</p>
                  <ul className="mt-1 space-y-1">
                    {portfolio.community_service.length === 0 && portfolio.projects.length === 0 ? (
                      <li className="text-slate-500">Belum ada catatan.</li>
                    ) : null}
                    {portfolio.community_service.map((entry, index) => (
                      <li key={`cs-${index}`} className="text-slate-700">
                        {entry.program} {entry.joined_at ? `· ${entry.joined_at}` : ''}
                      </li>
                    ))}
                    {portfolio.projects.map((project) => (
                      <li key={`pr-${project.id}`} className="text-slate-700">
                        {project.title} · {project.status} {project.completed_on ? `· ${project.completed_on}` : ''}
                      </li>
                    ))}
                  </ul>
                </div>
              </CardBody>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-sm">Transkrip aktivitas</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {transcriptQuery.isLoading ? (
                <Spinner />
              ) : (
                <div className="overflow-x-auto">
                  <table className="min-w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                        <th className="px-3 py-2">Periode</th>
                        <th className="px-3 py-2">Aktivitas</th>
                        <th className="px-3 py-2">Peran</th>
                        <th className="px-3 py-2">Hadir</th>
                        <th className="px-3 py-2">Nilai</th>
                        <th className="px-3 py-2">Prestasi</th>
                        <th className="px-3 py-2">Sertifikat</th>
                      </tr>
                    </thead>
                    <tbody>
                      {(transcript?.rows ?? []).map((row, index) => (
                        <tr key={index} className="border-b border-slate-100">
                          <td className="px-3 py-2 text-slate-600">{row.period ?? '-'}</td>
                          <td className="px-3 py-2 text-slate-800">{row.activity}</td>
                          <td className="px-3 py-2 text-slate-600">{row.role ?? '-'}</td>
                          <td className="px-3 py-2 text-slate-600">
                            {row.attendance_percentage === null || row.attendance_percentage === undefined ? '-' : `${Math.round(row.attendance_percentage)}%`}
                          </td>
                          <td className="px-3 py-2 text-slate-600">{row.average_score === null || row.average_score === undefined ? '-' : Number(row.average_score).toFixed(1)}</td>
                          <td className="px-3 py-2 text-slate-600">{row.achievements}</td>
                          <td className="px-3 py-2 font-mono text-xs text-slate-600">{row.certificate ?? '-'}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {(transcript?.rows ?? []).length === 0 && <p className="py-4 text-sm text-slate-500">Belum ada baris transkrip.</p>}
                </div>
              )}

              {transcript && (
                <p className="text-xs text-slate-500">
                  {transcript.totals.activities} aktivitas ({transcript.totals.completed} selesai) · rata-rata kehadiran{' '}
                  {transcript.totals.average_attendance === null ? '-' : `${transcript.totals.average_attendance}%`} · rata-rata nilai{' '}
                  {transcript.totals.average_score === null ? '-' : transcript.totals.average_score}. Nilai akademik tetap berada di rapor SIAKAD.
                </p>
              )}
            </CardBody>
          </Card>
        </>
      )}
    </div>
  );
}
