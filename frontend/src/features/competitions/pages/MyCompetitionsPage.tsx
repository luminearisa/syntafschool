import { useQuery } from '@tanstack/react-query';
import { CalendarDays, ClipboardCheck, Trophy } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { competitionApi } from '../api/competitionApi';
import type { CompetitionRegistration } from '../types';

function statusTone(status: string): 'neutral' | 'success' | 'warning' | 'info' | 'danger' {
  if (['APPROVED', 'ELIGIBLE'].includes(status)) return 'success';
  if (['REJECTED', 'ELIGIBILITY_FAILED', 'CANCELLED'].includes(status)) return 'danger';
  if (['SUBMITTED', 'UNDER_REVIEW', 'WAITLISTED', 'DRAFT'].includes(status)) return 'warning';
  return 'neutral';
}

export default function MyCompetitionsPage() {
  const query = useQuery({ queryKey: ['competition', 'mine'], queryFn: () => competitionApi.myRegistrations({ per_page: 50 }) });
  const registrations = query.data?.items ?? [];

  if (query.isLoading) return <div className="flex justify-center py-16"><Spinner /></div>;

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <header>
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-brand-700">Portal Peserta</p>
        <h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Kompetisi saya</h1>
        <p className="mt-1 text-sm text-slate-500">Pendaftaran, jadwal, dan status hasil kompetisi yang terkait dengan akun Anda.</p>
      </header>
      {registrations.length === 0 ? (
        <Card><EmptyState title="Belum mengikuti kompetisi" description="Kompetisi yang Anda daftarkan atau yang didaftarkan wali akan muncul di sini." /></Card>
      ) : (
        <div className="space-y-3">
          {registrations.map((registration: CompetitionRegistration) => (
            <Card key={registration.id}>
              <CardBody className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900">{registration.registration_number}</h2><Badge tone={statusTone(registration.status)}>{registration.status.replace(/_/g, ' ')}</Badge></div>
                  <p className="mt-1 text-sm text-slate-600">{registration.external_participant_name ?? (registration.team_id ? `Tim #${registration.team_id}` : `Pendaftaran siswa${registration.student_id ? ` #${registration.student_id}` : ''}`)}</p>
                  <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-500"><span className="inline-flex items-center gap-1"><ClipboardCheck className="h-3.5 w-3.5" /> Eligibility: {registration.eligibility_status ?? 'Menunggu'}</span><span className="inline-flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" /> {registration.submitted_at ? new Date(registration.submitted_at).toLocaleDateString('id-ID') : 'Belum dikirim'}</span></div>
                </div>
                {registration.waitlist_position && <div className="rounded-lg bg-amber-50 px-3 py-2 text-sm font-medium text-amber-800">Waitlist #{registration.waitlist_position}</div>}
              </CardBody>
            </Card>
          ))}
        </div>
      )}
      <Card className="border-slate-200 bg-slate-50"><CardHeader><CardTitle className="text-sm">Catatan akses</CardTitle></CardHeader><CardBody className="flex items-start gap-3 pt-0"><Trophy className="mt-0.5 h-4 w-4 shrink-0 text-brand-700" /><p className="text-xs leading-5 text-slate-600">Data peserta dibatasi oleh backend ke akun siswa, pendaftaran yang dibuat akun Anda, dan relasi wali-anak yang terverifikasi.</p></CardBody></Card>
    </div>
  );
}
