import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Megaphone, Pin, Plus, RefreshCw, Trash2, UserMinus, UserPlus, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import { ContentEditor } from './ContentEditor';
import { ProgressBar } from './ProgressBar';
import { ENROLLMENT_TONE, formatDate, relativeTime } from '../lib/lmsLabels';
import type { LmsGroup } from '../types';

/** Pengelolaan peserta course (§15–§18). */
export function EnrollmentPanel({ courseId, classId, academicYearId }: { courseId: number; classId: number | null; academicYearId: number | null }) {
  const queryClient = useQueryClient();
  const [addOpen, setAddOpen] = useState(false);
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<number[]>([]);
  const [statusFilter, setStatusFilter] = useState('');

  const enrollments = useQuery({
    queryKey: ['lms', 'enrollments', courseId, statusFilter],
    queryFn: () => lmsApi.enrollments(courseId, { status: statusFilter, per_page: 50 }),
  });

  const candidates = useQuery({
    queryKey: ['lms', 'candidates', courseId, search],
    queryFn: () => lmsApi.enrollmentCandidates(courseId, search),
    enabled: addOpen,
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['lms', 'enrollments', courseId] });
    queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] });
  };

  const bulkEnroll = useMutation({
    mutationFn: () => lmsApi.bulkEnroll(courseId, { student_ids: selected }),
    onSuccess: (result) => {
      toast.success(`${result.created} peserta ditambahkan${result.skipped.length ? `, ${result.skipped.length} dilewati` : ''}`);
      if (result.skipped.length > 0) {
        toast.warning(result.skipped.map((skip) => skip.reason).join('; '));
      }
      setSelected([]);
      setAddOpen(false);
      invalidate();
    },
    onError: () => toast.error('Pendaftaran massal gagal'),
  });

  const sync = useMutation({
    mutationFn: () => lmsApi.syncAcademicEnrollments(courseId),
    onSuccess: (result) => {
      toast.success(`${result.created} peserta disinkronkan dari SIAKAD (${result.learners_found} siswa ditemukan)`);
      if (result.skipped.length > 0) {
        toast.warning(`${result.skipped.length} siswa dilewati: ${result.skipped[0]?.reason ?? ''}`);
      }
      invalidate();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Sinkronisasi gagal'),
  });

  const changeStatus = useMutation<unknown, unknown, { id: number; action: 'suspend' | 'cancel' | 'reactivate' | 'complete' | 'remove' }>({
    mutationFn: ({ id, action }): Promise<unknown> => {
      if (action === 'remove') return lmsApi.removeEnrollment(id);
      if (action === 'suspend') return lmsApi.suspendEnrollment(id, 'Ditangguhkan oleh pengelola course');
      if (action === 'cancel') return lmsApi.cancelEnrollment(id, 'Dibatalkan oleh pengelola course');
      if (action === 'reactivate') return lmsApi.reactivateEnrollment(id);
      return lmsApi.completeEnrollment(id);
    },
    onSuccess: () => {
      toast.success('Status peserta diperbarui');
      invalidate();
    },
  });

  const summary = enrollments.data?.data.summary as Record<string, number> | undefined;
  const rows = enrollments.data?.data.enrollments.data ?? [];

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center gap-2">
        <Users className="h-4 w-4 text-brand-600" aria-hidden="true" />
        <CardTitle>Peserta</CardTitle>
        {summary && (
          <div className="ml-auto flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
            <Badge tone="green">{summary.active ?? 0} aktif</Badge>
            <Badge tone="amber">{summary.pending ?? 0} menunggu</Badge>
            <Badge tone="red">{summary.suspended ?? 0} ditangguhkan</Badge>
            <Badge tone="blue">{summary.completed ?? 0} selesai</Badge>
          </div>
        )}
      </CardHeader>
      <CardBody className="space-y-3">
        <div className="flex flex-wrap items-end gap-2">
          <Button size="sm" onClick={() => setAddOpen(true)}>
            <UserPlus className="h-4 w-4" aria-hidden="true" /> Tambah peserta
          </Button>
          {classId !== null && (
            <Button size="sm" variant="outline" loading={sync.isPending} onClick={() => sync.mutate()}>
              <RefreshCw className="h-4 w-4" aria-hidden="true" /> Sinkron dari SIAKAD
            </Button>
          )}
          <div className="ml-auto w-40">
            <Select value={statusFilter} onChange={(event) => setStatusFilter(event.target.value)} aria-label="Filter status">
              <option value="">Semua status</option>
              <option value="ACTIVE">Aktif</option>
              <option value="PENDING">Menunggu</option>
              <option value="SUSPENDED">Ditangguhkan</option>
              <option value="COMPLETED">Selesai</option>
              <option value="CANCELLED">Dibatalkan</option>
            </Select>
          </div>
        </div>

        {academicYearId !== null && classId !== null && (
          <p className="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-[11px] text-sky-800">
            Course ini tertaut ke rombel SIAKAD. Sinkronisasi menambahkan siswa aktif rombel tersebut dan menangguhkan
            peserta yang sudah tidak terdaftar — riwayat belajar tidak dihapus.
          </p>
        )}

        {enrollments.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada peserta" description="Tambahkan siswa secara manual atau sinkronkan dari SIAKAD." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[640px] text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-2 py-2">Nama</th>
                  <th className="px-2 py-2">Peran</th>
                  <th className="px-2 py-2">Asal</th>
                  <th className="px-2 py-2">Status</th>
                  <th className="px-2 py-2">Progress</th>
                  <th className="px-2 py-2 text-right">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((enrollment) => (
                  <tr key={enrollment.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-2 py-2">
                      <p className="font-medium text-slate-800">{enrollment.user?.name ?? `User #${enrollment.user_id}`}</p>
                      <p className="text-[11px] text-slate-500">
                        {enrollment.last_accessed_at ? `Terakhir aktif ${relativeTime(enrollment.last_accessed_at)}` : 'Belum pernah membuka'}
                      </p>
                    </td>
                    <td className="px-2 py-2 text-xs text-slate-600">{enrollment.role_label ?? enrollment.role}</td>
                    <td className="px-2 py-2 text-xs text-slate-600">
                      {enrollment.enrollment_method_label ?? enrollment.enrollment_method}
                    </td>
                    <td className="px-2 py-2">
                      <Badge tone={ENROLLMENT_TONE[enrollment.status]}>{enrollment.status_label ?? enrollment.status}</Badge>
                    </td>
                    <td className="w-32 px-2 py-2">
                      <ProgressBar value={Number(enrollment.progress_percent)} size="sm" showLabel />
                    </td>
                    <td className="px-2 py-2">
                      <div className="flex justify-end gap-1">
                        {enrollment.status === 'ACTIVE' ? (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => changeStatus.mutate({ id: enrollment.id, action: 'suspend' })}
                          >
                            Tangguhkan
                          </Button>
                        ) : (
                          <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => changeStatus.mutate({ id: enrollment.id, action: 'reactivate' })}
                          >
                            Aktifkan
                          </Button>
                        )}
                        <Button
                          size="icon"
                          variant="ghost"
                          title="Keluarkan"
                          onClick={() => {
                            if (confirm(`Keluarkan ${enrollment.user?.name ?? 'peserta'} dari course?`)) {
                              changeStatus.mutate({ id: enrollment.id, action: 'remove' });
                            }
                          }}
                        >
                          <UserMinus className="h-4 w-4 text-red-500" aria-hidden="true" />
                        </Button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardBody>

      <Modal
        open={addOpen}
        onClose={() => setAddOpen(false)}
        size="lg"
        title="Tambah peserta"
        footer={
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-slate-500">{selected.length} siswa dipilih</span>
            <div className="flex gap-2">
              <Button variant="ghost" onClick={() => setAddOpen(false)}>
                Batal
              </Button>
              <Button
                loading={bulkEnroll.isPending}
                disabled={selected.length === 0}
                onClick={() => bulkEnroll.mutate()}
              >
                Daftarkan
              </Button>
            </div>
          </div>
        }
      >
        <div className="space-y-3">
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Cari nama atau NIS siswa…"
            aria-label="Cari siswa"
          />
          {candidates.isLoading ? (
            <div className="flex justify-center py-6">
              <Spinner />
            </div>
          ) : (candidates.data ?? []).length === 0 ? (
            <EmptyState title="Siswa tidak ditemukan" description="Coba kata kunci lain atau sinkronkan dari SIAKAD." />
          ) : (
            <ul className="max-h-72 space-y-1 overflow-y-auto">
              {(candidates.data ?? []).map((candidate) => {
                const checked = selected.includes(candidate.id);
                return (
                  <li key={candidate.id}>
                    <label
                      className={`flex cursor-pointer items-center gap-3 rounded-xl border px-3 py-2 ${
                        checked ? 'border-brand-300 bg-brand-50' : 'border-slate-200'
                      }`}
                    >
                      <input
                        type="checkbox"
                        className="h-4 w-4 rounded border-slate-300"
                        checked={checked}
                        disabled={!candidate.has_account}
                        onChange={(event) =>
                          setSelected((current) =>
                            event.target.checked ? [...current, candidate.id] : current.filter((id) => id !== candidate.id),
                          )
                        }
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-medium text-slate-800">{candidate.name}</span>
                        <span className="block text-[11px] text-slate-500">
                          {candidate.code ?? '-'} • {candidate.class_name ?? 'tanpa kelas'}
                        </span>
                      </span>
                      {!candidate.has_account && <Badge tone="amber">tanpa akun</Badge>}
                    </label>
                  </li>
                );
              })}
            </ul>
          )}
        </div>
      </Modal>
    </Card>
  );
}

/** Pengumuman course (§24). */
export function AnnouncementPanel({ courseId }: { courseId: number }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [pinned, setPinned] = useState(false);

  const announcements = useQuery({
    queryKey: ['lms', 'announcements', courseId],
    queryFn: () => lmsApi.announcements(courseId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['lms', 'announcements', courseId] });

  const create = useMutation({
    mutationFn: () => lmsApi.createAnnouncement(courseId, { title, content, pinned }),
    onSuccess: () => {
      toast.success('Pengumuman dikirim ke peserta');
      setOpen(false);
      setTitle('');
      setContent('');
      setPinned(false);
      invalidate();
    },
    onError: () => toast.error('Gagal membuat pengumuman'),
  });

  const togglePin = useMutation({
    mutationFn: (id: number) => lmsApi.toggleAnnouncementPin(id),
    onSuccess: invalidate,
  });

  const remove = useMutation({
    mutationFn: (id: number) => lmsApi.deleteAnnouncement(id),
    onSuccess: () => {
      toast.success('Pengumuman dihapus');
      invalidate();
    },
  });

  const rows = announcements.data?.data ?? [];

  return (
    <Card>
      <CardHeader className="flex items-center gap-2">
        <Megaphone className="h-4 w-4 text-amber-500" aria-hidden="true" />
        <CardTitle>Pengumuman</CardTitle>
        <Button size="sm" className="ml-auto" onClick={() => setOpen(true)}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Buat
        </Button>
      </CardHeader>
      <CardBody>
        {announcements.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada pengumuman" description="Pengumuman akan tampil di halaman course siswa dan mengirim notifikasi." />
        ) : (
          <ul className="space-y-2">
            {rows.map((announcement) => (
              <li key={announcement.id} className="rounded-xl border border-slate-200 px-3 py-2">
                <div className="flex items-center gap-2">
                  <p className="min-w-0 flex-1 truncate text-sm font-semibold text-slate-800">{announcement.title}</p>
                  {announcement.pinned && <Pin className="h-3.5 w-3.5 text-amber-500" aria-label="Disematkan" />}
                  <Button size="icon" variant="ghost" title="Sematkan" onClick={() => togglePin.mutate(announcement.id)}>
                    <Pin className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button size="icon" variant="ghost" title="Hapus" onClick={() => remove.mutate(announcement.id)}>
                    <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                  </Button>
                </div>
                <p className="mt-1 line-clamp-2 text-xs text-slate-600">{announcement.content.replace(/<[^>]*>/g, ' ')}</p>
                <p className="mt-1 text-[11px] text-slate-400">
                  {announcement.published_at ? formatDate(announcement.published_at, true) : 'Belum terbit'}
                </p>
              </li>
            ))}
          </ul>
        )}
      </CardBody>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        size="lg"
        title="Pengumuman baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button loading={create.isPending} disabled={title.trim().length < 3 || content.trim().length < 3} onClick={() => create.mutate()}>
              Kirim
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="lms-announcement-title">Judul *</Label>
            <Input id="lms-announcement-title" value={title} onChange={(event) => setTitle(event.target.value)} />
          </div>
          <ContentEditor label="Isi pengumuman *" rows={8} value={content} onChange={setContent} />
          <label className="flex items-center gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={pinned}
              onChange={(event) => setPinned(event.target.checked)}
            />
            Sematkan di bagian atas
          </label>
        </div>
      </Modal>
    </Card>
  );
}

/** Kelompok peserta (§19). */
export function GroupPanel({ courseId }: { courseId: number }) {
  const queryClient = useQueryClient();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState('');
  const [capacity, setCapacity] = useState<string>('');

  const groups = useQuery({ queryKey: ['lms', 'groups', courseId], queryFn: () => lmsApi.groups(courseId) });
  const enrollments = useQuery({
    queryKey: ['lms', 'enrollments', courseId, 'students'],
    queryFn: () => lmsApi.enrollments(courseId, { role: 'STUDENT', per_page: 100 }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['lms', 'groups', courseId] });

  const create = useMutation({
    mutationFn: () => lmsApi.createGroup(courseId, { name, capacity: capacity ? Number(capacity) : null }),
    onSuccess: () => {
      toast.success('Kelompok dibuat');
      setOpen(false);
      setName('');
      setCapacity('');
      invalidate();
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => lmsApi.deleteGroup(id),
    onSuccess: () => {
      toast.success('Kelompok dihapus');
      invalidate();
    },
  });

  const addMember = useMutation({
    mutationFn: ({ groupId, enrollmentId }: { groupId: number; enrollmentId: number }) =>
      lmsApi.addGroupMembers(groupId, [enrollmentId]),
    onSuccess: () => {
      toast.success('Anggota ditambahkan');
      invalidate();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Gagal menambah anggota'),
  });

  const removeMember = useMutation({
    mutationFn: ({ groupId, enrollmentId }: { groupId: number; enrollmentId: number }) =>
      lmsApi.removeGroupMembers(groupId, [enrollmentId]),
    onSuccess: () => invalidate(),
  });

  const rows: LmsGroup[] = groups.data ?? [];
  const studentEnrollments = enrollments.data?.data.enrollments.data ?? [];

  return (
    <Card>
      <CardHeader className="flex items-center gap-2">
        <Users className="h-4 w-4 text-violet-500" aria-hidden="true" />
        <CardTitle>Kelompok</CardTitle>
        <Button size="sm" className="ml-auto" onClick={() => setOpen(true)}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Buat kelompok
        </Button>
      </CardHeader>
      <CardBody>
        {groups.isLoading ? (
          <div className="flex justify-center py-6">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada kelompok" description="Kelompok memudahkan pembagian tugas dan diskusi." />
        ) : (
          <div className="space-y-3">
            {rows.map((group) => (
              <div key={group.id} className="rounded-xl border border-slate-200 p-3">
                <div className="flex items-center gap-2">
                  <p className="text-sm font-semibold text-slate-800">{group.name}</p>
                  <Badge tone="gray">
                    {(group.members?.length ?? 0)}
                    {group.capacity ? `/${group.capacity}` : ''} anggota
                  </Badge>
                  <Button
                    size="icon"
                    variant="ghost"
                    className="ml-auto"
                    title="Hapus kelompok"
                    onClick={() => remove.mutate(group.id)}
                  >
                    <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                  </Button>
                </div>
                <ul className="mt-2 flex flex-wrap gap-2">
                  {(group.members ?? []).map((member) => {
                    const enrollment = studentEnrollments.find((row) => row.id === member.enrollment_id);
                    return (
                      <li key={member.id}>
                        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-1 text-[11px] text-slate-700">
                          {enrollment?.user?.name ?? `#${member.user_id}`}
                          <button
                            type="button"
                            aria-label="Keluarkan dari kelompok"
                            onClick={() => removeMember.mutate({ groupId: group.id, enrollmentId: member.enrollment_id })}
                            className="text-slate-400 hover:text-red-500"
                          >
                            ×
                          </button>
                        </span>
                      </li>
                    );
                  })}
                </ul>
                <div className="mt-2 flex items-center gap-2">
                  <Select
                    aria-label="Tambah anggota"
                    value=""
                    onChange={(event) => {
                      const enrollmentId = Number(event.target.value);
                      if (enrollmentId) addMember.mutate({ groupId: group.id, enrollmentId });
                    }}
                    className="h-8 text-xs"
                  >
                    <option value="">+ Tambah anggota…</option>
                    {studentEnrollments
                      .filter((enrollment) => !(group.members ?? []).some((member) => member.enrollment_id === enrollment.id))
                      .map((enrollment) => (
                        <option key={enrollment.id} value={enrollment.id}>
                          {enrollment.user?.name ?? `User #${enrollment.user_id}`}
                        </option>
                      ))}
                  </Select>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardBody>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Kelompok baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setOpen(false)}>
              Batal
            </Button>
            <Button loading={create.isPending} disabled={name.trim().length < 2} onClick={() => create.mutate()}>
              Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="lms-group-name">Nama kelompok *</Label>
            <Input id="lms-group-name" value={name} onChange={(event) => setName(event.target.value)} placeholder="Kelompok A" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-group-capacity">Kapasitas</Label>
            <Input
              id="lms-group-capacity"
              type="number"
              min={1}
              value={capacity}
              onChange={(event) => setCapacity(event.target.value)}
              placeholder="Tanpa batas"
            />
          </div>
        </div>
      </Modal>
    </Card>
  );
}
