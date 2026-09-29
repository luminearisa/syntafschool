import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Plus, Radio } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { useAuthStore } from '@/stores/authStore';
import { phase4Api, type LiveSessionPayload } from '../api/phase4Api';

/**
 * Kelas langsung: jadwal, gabung, dan (untuk guru) pembuatan jadwal (§13–§17).
 */
export default function LmsLiveSessionsPage() {
  const queryClient = useQueryClient();
  const canManage = useAuthStore((state) => state.can)('lms.live.manage');
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ course_id: '', title: '', start_at: '', duration_minutes: '60', join_url: '' });

  const sessions = useQuery({
    queryKey: ['lms', 'live-sessions'],
    queryFn: () => phase4Api.myLiveSessions(),
  });

  const join = useMutation({
    mutationFn: (sessionId: number) => phase4Api.joinLiveSession(sessionId),
    onSuccess: (result) => {
      const url = (result as { join_url?: string | null }).join_url ?? null;
      toast.success('Kehadiran dicatat');
      if (url) window.open(url, '_blank', 'noopener');
      queryClient.invalidateQueries({ queryKey: ['lms', 'live-sessions'] });
    },
    onError: () => toast.error('Kelas belum dapat digabung'),
  });

  const create = useMutation({
    mutationFn: () =>
      phase4Api.createLiveSession(Number(form.course_id), {
        title: form.title,
        start_at: form.start_at,
        duration_minutes: Number(form.duration_minutes) || 60,
        join_url: form.join_url || undefined,
      }),
    onSuccess: () => {
      toast.success('Jadwal kelas dibuat');
      setShowForm(false);
      setForm({ course_id: '', title: '', start_at: '', duration_minutes: '60', join_url: '' });
      queryClient.invalidateQueries({ queryKey: ['lms', 'live-sessions'] });
    },
    onError: () => toast.error('Gagal membuat jadwal'),
  });

  const liveNow = sessions.data?.live_now ?? [];
  const upcoming = sessions.data?.sessions ?? [];

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <Radio className="h-5 w-5 text-rose-600" aria-hidden="true" /> Kelas langsung
          </h1>
          <p className="text-sm text-slate-500">Kelas, konsultasi, dan webinar beserta rekamannya.</p>
        </div>
        {canManage && (
          <Button size="sm" onClick={() => setShowForm((value) => !value)}>
            <Plus className="mr-2 h-4 w-4" aria-hidden="true" /> Jadwalkan kelas
          </Button>
        )}
      </header>

      {showForm && canManage && (
        <Card>
          <CardHeader>
            <CardTitle>Jadwal baru</CardTitle>
          </CardHeader>
          <CardBody className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">ID course</span>
              <input
                value={form.course_id}
                onChange={(event) => setForm({ ...form, course_id: event.target.value })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2"
                inputMode="numeric"
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Judul</span>
              <input
                value={form.title}
                onChange={(event) => setForm({ ...form, title: event.target.value })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Mulai</span>
              <input
                type="datetime-local"
                value={form.start_at}
                onChange={(event) => setForm({ ...form, start_at: event.target.value })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Durasi (menit)</span>
              <input
                value={form.duration_minutes}
                onChange={(event) => setForm({ ...form, duration_minutes: event.target.value })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2"
                inputMode="numeric"
              />
            </label>
            <label className="text-sm sm:col-span-2">
              <span className="mb-1 block text-xs text-slate-500">Tautan kelas (Zoom/Meet)</span>
              <input
                value={form.join_url}
                onChange={(event) => setForm({ ...form, join_url: event.target.value })}
                placeholder="https://"
                className="w-full rounded-lg border border-slate-300 px-3 py-2"
              />
            </label>
            <div className="sm:col-span-2">
              <Button
                onClick={() => create.mutate()}
                disabled={create.isPending || !form.course_id || !form.title || !form.start_at}
              >
                {create.isPending ? 'Menyimpan…' : 'Simpan jadwal'}
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      {sessions.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : liveNow.length === 0 && upcoming.length === 0 ? (
        <EmptyState
          title="Belum ada jadwal kelas"
          description="Jadwal kelas langsung dari guru Anda akan tampil di sini."
        />
      ) : (
        <div className="space-y-3">
          {liveNow.length > 0 && (
            <div className="rounded-lg border border-rose-200 bg-rose-50 p-3">
              <p className="flex items-center gap-2 text-sm font-medium text-rose-700">
                <Radio className="h-4 w-4" aria-hidden="true" /> Sedang berlangsung
              </p>
            </div>
          )}
          {[...liveNow, ...upcoming].map((session) => (
            <SessionRow key={session.id} session={session} onJoin={() => join.mutate(session.id)} joining={join.isPending} />
          ))}
        </div>
      )}
    </div>
  );
}

function SessionRow({
  session,
  onJoin,
  joining,
}: {
  session: LiveSessionPayload;
  onJoin: () => void;
  joining: boolean;
}) {
  const start = session.start_at ? new Date(session.start_at) : null;

  return (
    <Card>
      <CardBody className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0">
          <p className="truncate text-sm font-medium text-slate-800">{session.title}</p>
          <p className="mt-1 flex items-center gap-2 text-xs text-slate-500">
            <CalendarClock className="h-3.5 w-3.5" aria-hidden="true" />
            {start ? start.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-'}
            {session.duration_minutes ? ` · ${session.duration_minutes} menit` : ''}
            {session.recordings_count ? ` · ${session.recordings_count} rekaman` : ''}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={(session.tone ?? 'blue') as never}>{session.status_label ?? session.status}</Badge>
          <Button size="sm" onClick={onJoin} disabled={joining || session.has_ended === true}>
            {session.status === 'LIVE' ? 'Gabung sekarang' : 'Gabung'}
          </Button>
        </div>
      </CardBody>
    </Card>
  );
}
