import { Link } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { ArrowRight, CalendarClock, MapPin } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { competitionApi } from '../api/competitionApi';

interface Assignment {
  id: number;
  assignment_role: string;
  is_head_judge: boolean;
  status: string;
  competition_judge?: { display_name: string; expertise?: string };
  competition_match?: { id: number; match_number: number; scheduled_at?: string | null; venue_name?: string | null; status: string } | null;
  competition_session?: { id: number; name: string; starts_at: string; venue_name?: string | null; status: string } | null;
}

export default function JudgeAssignmentsPage() {
  const query = useQuery({ queryKey: ['competition', 'judge', 'assignments'], queryFn: competitionApi.judgeAssignments });
  const rows = query.data?.items as Assignment[] | undefined;
  if (query.isLoading) return <div className="flex justify-center py-16"><Spinner /></div>;

  return (
    <div className="mx-auto max-w-4xl space-y-5">
      <header><p className="text-xs font-semibold uppercase tracking-[0.16em] text-brand-700">Judge Portal</p><h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Penugasan juri</h1><p className="mt-1 text-sm text-slate-500">Pilih pertandingan atau sesi untuk melihat rubric dan memasukkan penilaian.</p></header>
      {!rows?.length ? <Card><EmptyState title="Belum ada penugasan" description="Ketika koordinator menugaskan Anda, sesi penilaian akan tampil di sini." /></Card> : <div className="space-y-3">{rows.map((row) => {
        const match = row.competition_match;
        const session = row.competition_session;
        const title = match ? `Pertandingan ${match.match_number}` : session?.name ?? `Penugasan #${row.id}`;
        const date = match?.scheduled_at ?? session?.starts_at;
        const href = match ? `/judge/matches/${match.id}` : `/judge/assignments`;
        return <Card key={row.id}><CardBody className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h2 className="font-semibold text-slate-900">{title}</h2><Badge tone={row.is_head_judge ? 'brand' : 'info'}>{row.is_head_judge ? 'Head judge' : row.assignment_role.replace(/_/g, ' ')}</Badge><Badge tone={row.status === 'ASSIGNED' ? 'warning' : 'success'}>{row.status}</Badge></div><div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">{date && <span className="inline-flex items-center gap-1"><CalendarClock className="h-3.5 w-3.5" />{new Date(date).toLocaleString('id-ID')}</span>}{(match?.venue_name || session?.venue_name) && <span className="inline-flex items-center gap-1"><MapPin className="h-3.5 w-3.5" />{match?.venue_name ?? session?.venue_name}</span>}</div></div>{match && <Link to={href} className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-brand-600 px-4 text-sm font-medium text-white hover:bg-brand-700">Buka penilaian <ArrowRight className="h-4 w-4" /></Link>}</CardBody></Card>;
      })}</div>}
    </div>
  );
}
