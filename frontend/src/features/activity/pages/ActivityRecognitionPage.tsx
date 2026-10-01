import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { activityApi } from '../api/activityApi';

const LEVELS = ['INTERNAL', 'SCHOOL', 'DISTRICT', 'CITY', 'PROVINCE', 'NATIONAL', 'INTERNATIONAL'];
const RANKS = [
  { value: 'FIRST', label: 'Juara 1' },
  { value: 'SECOND', label: 'Juara 2' },
  { value: 'THIRD', label: 'Juara 3' },
  { value: 'FOURTH', label: 'Juara Harapan' },
  { value: 'PARTICIPANT', label: 'Peserta' },
  { value: 'SPECIAL', label: 'Penghargaan khusus' },
];
const TYPES = ['ACADEMIC', 'SPORT', 'ART', 'RELIGIOUS', 'LEADERSHIP', 'TECHNOLOGY', 'COMMUNITY_SERVICE', 'OTHER'];

/**
 * Prestasi & Sertifikat.
 *
 * Dua hal yang sengaja dipisah di layar ini, seperti di basis data:
 * **prestasi** adalah fakta yang harus diverifikasi manusia, sedangkan
 * **sertifikat** adalah dokumen yang terbit dari penyelesaian aktivitas atau
 * dari prestasi terverifikasi. Pencabutan sertifikat wajib beralasan dan
 * langsung terlihat pada verifikasi publik.
 */
export default function ActivityRecognitionPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [achievementFilter, setAchievementFilter] = useState({ verification_status: '', level: '' });
  const [form, setForm] = useState({
    student_id: '',
    title: '',
    type: 'SPORT',
    level: 'SCHOOL',
    rank: 'FIRST',
    awarded_date: '',
    organizer: '',
    team_id: '',
    competition_id: '',
  });
  const [verification, setVerification] = useState<{ id: number | null; decision: string; notes: string }>({ id: null, decision: 'VERIFIED', notes: '' });
  const [revocation, setRevocation] = useState<{ id: number | null; reason: string }>({ id: null, reason: '' });

  const achievementsQuery = useQuery({
    queryKey: ['activity', 'achievements', achievementFilter],
    queryFn: () =>
      activityApi
        .achievements({
          verification_status: achievementFilter.verification_status || undefined,
          level: achievementFilter.level || undefined,
          per_page: 30,
        })
        .then((r) => r.data.data),
  });

  const certificatesQuery = useQuery({
    queryKey: ['activity', 'certificates'],
    queryFn: () => activityApi.certificates({ per_page: 30 }).then((r) => r.data.data),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['activity'] });

  const createAchievement = useMutation({
    mutationFn: () =>
      activityApi
        .createAchievement({
          title: form.title,
          type: form.type,
          level: form.level,
          rank: form.rank,
          awarded_date: form.awarded_date || null,
          organizer: form.organizer || null,
          student_id: form.student_id ? Number(form.student_id) : null,
          team_id: form.team_id ? Number(form.team_id) : null,
          competition_id: form.competition_id ? Number(form.competition_id) : null,
          ownership: form.team_id ? 'TEAM' : 'INDIVIDUAL',
        })
        .then((r) => r.data.data),
    onSuccess: (achievement) => {
      setForm({ ...form, title: '', student_id: '', organizer: '' });
      setNotice(`Prestasi "${achievement.title}" dicatat dan menunggu verifikasi.`);
      invalidate();
    },
    onError: (error) => setNotice(getErrorMessage(error), 'danger'),
  });

  const verifyAchievement = useMutation({
    mutationFn: () => activityApi.verifyAchievement(verification.id as number, verification.decision, verification.notes).then((r) => r.data.data),
    onSuccess: (achievement) => {
      setVerification({ id: null, decision: 'VERIFIED', notes: '' });
      setNotice(`Prestasi "${achievement.title}" ditandai ${achievement.verification_label ?? achievement.verification_status}.`);
      invalidate();
    },
    onError: (error) => setNotice(getErrorMessage(error), 'danger'),
  });

  const issueCertificate = useMutation({
    mutationFn: (achievementId: number) => activityApi.issueAchievementCertificate(achievementId).then((r) => r.data.data),
    onSuccess: (certificates) => {
      setNotice(`${certificates.length} sertifikat prestasi diterbitkan.`);
      invalidate();
    },
    onError: (error) => setNotice(getErrorMessage(error), 'danger'),
  });

  const revokeCertificate = useMutation({
    mutationFn: () => activityApi.revokeCertificate(revocation.id as number, revocation.reason).then((r) => r.data.data),
    onSuccess: (certificate) => {
      setRevocation({ id: null, reason: '' });
      setNotice(`Sertifikat ${certificate.certificate_number} dicabut. Verifikasi publik kini menyatakan tidak sah beserta alasan.`);
      invalidate();
    },
    onError: (error) => setNotice(getErrorMessage(error), 'danger'),
  });

  const [notice, setNoticeState] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const setNotice = (text: string, tone: 'success' | 'danger' = 'success') => setNoticeState({ tone, text });

  const achievements = achievementsQuery.data ?? [];
  const certificates = certificatesQuery.data ?? [];

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Prestasi & Sertifikat</h1>
        <p className="mt-1 text-sm text-slate-500">
          Prestasi diverifikasi manusia sebelum menjadi bukti; sertifikat terbit dari penyelesaian aktivitas atau prestasi terverifikasi.
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

      {can('activity.achievement.manage') && (
        <Card>
          <CardHeader>
            <CardTitle className="text-sm">Catat prestasi</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div className="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
              <Input placeholder="Judul prestasi" value={form.title} onChange={(event) => setForm({ ...form, title: event.target.value })} />
              <Input placeholder="ID siswa (individu)" value={form.student_id} onChange={(event) => setForm({ ...form, student_id: event.target.value })} />
              <Input placeholder="ID tim (bila tim)" value={form.team_id} onChange={(event) => setForm({ ...form, team_id: event.target.value })} />
              <Input placeholder="ID kompetisi (bila ada)" value={form.competition_id} onChange={(event) => setForm({ ...form, competition_id: event.target.value })} />
              <Select value={form.type} onChange={(event) => setForm({ ...form, type: event.target.value })} aria-label="Jenis prestasi">
                {TYPES.map((type) => (
                  <option key={type} value={type}>
                    {type}
                  </option>
                ))}
              </Select>
              <Select value={form.level} onChange={(event) => setForm({ ...form, level: event.target.value })} aria-label="Tingkat prestasi">
                {LEVELS.map((level) => (
                  <option key={level} value={level}>
                    {level}
                  </option>
                ))}
              </Select>
              <Select value={form.rank} onChange={(event) => setForm({ ...form, rank: event.target.value })} aria-label="Peringkat">
                {RANKS.map((rank) => (
                  <option key={rank.value} value={rank.value}>
                    {rank.label}
                  </option>
                ))}
              </Select>
              <Input type="date" value={form.awarded_date} onChange={(event) => setForm({ ...form, awarded_date: event.target.value })} aria-label="Tanggal prestasi" />
              <Input placeholder="Penyelenggara" value={form.organizer} onChange={(event) => setForm({ ...form, organizer: event.target.value })} />
            </div>
            <p className="text-xs text-slate-500">
              Mengisi ID tim membuat prestasi menjadi prestasi tim: seluruh anggota yang diberi kredit melihatnya di portofolio masing-masing.
            </p>
            <Button
              loading={createAchievement.isPending}
              disabled={form.title.trim().length < 3 || (!form.student_id && !form.team_id)}
              onClick={() => createAchievement.mutate()}
            >
              Catat prestasi
            </Button>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-end justify-between gap-3">
            <CardTitle className="text-sm">Prestasi siswa</CardTitle>
            <div className="flex gap-2">
              <div className="w-44">
                <Select
                  value={achievementFilter.verification_status}
                  onChange={(event) => setAchievementFilter({ ...achievementFilter, verification_status: event.target.value })}
                  aria-label="Status verifikasi"
                >
                  <option value="">Semua status</option>
                  <option value="PENDING">Menunggu verifikasi</option>
                  <option value="VERIFIED">Terverifikasi</option>
                  <option value="REJECTED">Ditolak</option>
                </Select>
              </div>
              <div className="w-40">
                <Select value={achievementFilter.level} onChange={(event) => setAchievementFilter({ ...achievementFilter, level: event.target.value })} aria-label="Tingkat">
                  <option value="">Semua tingkat</option>
                  {LEVELS.map((level) => (
                    <option key={level} value={level}>
                      {level}
                    </option>
                  ))}
                </Select>
              </div>
            </div>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          {achievementsQuery.isLoading ? (
            <Spinner />
          ) : achievements.length === 0 ? (
            <EmptyState title="Belum ada prestasi" description="Prestasi dari kompetisi yang hasilnya diterbitkan akan muncul di sini." />
          ) : (
            achievements.map((achievement) => (
              <div key={achievement.id} className="rounded-lg border border-slate-200 p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="font-medium text-slate-800">{achievement.title}</p>
                    <p className="text-xs text-slate-500">
                      {achievement.type_label ?? achievement.type} · {achievement.level_label ?? achievement.level} ·{' '}
                      {achievement.rank_label ?? achievement.rank} · {achievement.awarded_date ?? '-'}
                      {achievement.program?.name ? ` · ${achievement.program.name}` : ''}
                      {achievement.ownership === 'TEAM' ? ` · tim (${achievement.team_members ?? 0} anggota)` : ''}
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone={achievement.verification_status === 'VERIFIED' ? 'success' : achievement.verification_status === 'REJECTED' ? 'danger' : 'warning'}>
                      {achievement.verification_label ?? achievement.verification_status}
                    </Badge>
                    {can('activity.achievement.verify') && achievement.verification_status !== 'VERIFIED' && (
                      <Button size="sm" variant="outline" onClick={() => setVerification({ id: achievement.id, decision: 'VERIFIED', notes: '' })}>
                        Verifikasi
                      </Button>
                    )}
                    {can('activity.certificate.issue') && achievement.verification_status === 'VERIFIED' && (
                      <Button size="sm" onClick={() => issueCertificate.mutate(achievement.id)} loading={issueCertificate.isPending}>
                        Terbitkan sertifikat
                      </Button>
                    )}
                  </div>
                </div>

                {verification.id === achievement.id && (
                  <div className="mt-3 space-y-2 rounded-md bg-slate-50 p-3">
                    <Select value={verification.decision} onChange={(event) => setVerification({ ...verification, decision: event.target.value })} aria-label="Keputusan verifikasi">
                      <option value="VERIFIED">Terverifikasi</option>
                      <option value="REJECTED">Ditolak</option>
                      <option value="NEED_REVISION">Perlu perbaikan bukti</option>
                    </Select>
                    <Textarea
                      rows={2}
                      placeholder="Catatan verifikasi (mis. nomor piagam, tautan bukti)"
                      value={verification.notes}
                      onChange={(event) => setVerification({ ...verification, notes: event.target.value })}
                    />
                    <div className="flex gap-2">
                      <Button size="sm" loading={verifyAchievement.isPending} onClick={() => verifyAchievement.mutate()}>
                        Simpan verifikasi
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setVerification({ id: null, decision: 'VERIFIED', notes: '' })}>
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

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Sertifikat aktivitas</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          {certificatesQuery.isLoading ? (
            <Spinner />
          ) : certificates.length === 0 ? (
            <EmptyState title="Belum ada sertifikat" description="Sertifikat terbit setelah aktivitas dinyatakan selesai atau prestasi diverifikasi." />
          ) : (
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                    <th className="px-3 py-2">Nomor</th>
                    <th className="px-3 py-2">Judul</th>
                    <th className="px-3 py-2">Siswa</th>
                    <th className="px-3 py-2">Jenis</th>
                    <th className="px-3 py-2">Terbit</th>
                    <th className="px-3 py-2">Status</th>
                    <th className="px-3 py-2" />
                  </tr>
                </thead>
                <tbody>
                  {certificates.map((certificate) => (
                    <tr key={certificate.id} className="border-b border-slate-100">
                      <td className="px-3 py-2 font-mono text-xs text-slate-700">{certificate.certificate_number}</td>
                      <td className="px-3 py-2 text-slate-700">{certificate.title}</td>
                      <td className="px-3 py-2 text-slate-600">{certificate.student?.full_name ?? '-'}</td>
                      <td className="px-3 py-2 text-slate-600">{certificate.type_label ?? certificate.type}</td>
                      <td className="px-3 py-2 text-slate-600">{certificate.issued_date}</td>
                      <td className="px-3 py-2">
                        <Badge tone={certificate.status === 'ISSUED' ? 'success' : 'danger'}>{certificate.status_label ?? certificate.status}</Badge>
                      </td>
                      <td className="px-3 py-2 text-right">
                        {can('activity.certificate.revoke') && certificate.status === 'ISSUED' && (
                          <Button size="sm" variant="ghost" onClick={() => setRevocation({ id: certificate.id, reason: '' })}>
                            Cabut
                          </Button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {revocation.id !== null && (
            <div className="space-y-2 rounded-md bg-red-50 p-3">
              <p className="text-xs text-red-800">Mencabut sertifikat #{revocation.id}. Alasan wajib diisi dan akan tampil pada verifikasi publik.</p>
              <Textarea
                rows={2}
                placeholder="Alasan pencabutan"
                value={revocation.reason}
                onChange={(event) => setRevocation({ ...revocation, reason: event.target.value })}
              />
              <div className="flex gap-2">
                <Button size="sm" variant="danger" loading={revokeCertificate.isPending} disabled={revocation.reason.trim().length < 3} onClick={() => revokeCertificate.mutate()}>
                  Cabut sertifikat
                </Button>
                <Button size="sm" variant="ghost" onClick={() => setRevocation({ id: null, reason: '' })}>
                  Batal
                </Button>
              </div>
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
