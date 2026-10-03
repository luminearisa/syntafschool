import { useMemo, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ArrowRight, CalendarDays, Plus, Search, Trophy, Users, ClipboardCheck, Clock3 } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { toast } from 'sonner';
import { getErrorMessage } from '@/lib/axios';
import { competitionApi } from '../api/competitionApi';
import type { CompetitionRow } from '../types';

const statusLabel: Record<string, string> = {
  DRAFT: 'Draf', PLANNING: 'Perencanaan', REGISTRATION_OPEN: 'Pendaftaran dibuka',
  REGISTRATION_CLOSED: 'Pendaftaran ditutup', SELECTION: 'Seleksi', RUNNING: 'Berlangsung',
  FINAL: 'Final', COMPLETED: 'Selesai', CANCELLED: 'Dibatalkan', ARCHIVED: 'Arsip',
};

function statusTone(status: string): 'neutral' | 'success' | 'warning' | 'info' | 'danger' {
  if (['RUNNING', 'REGISTRATION_OPEN'].includes(status)) return 'success';
  if (['DRAFT', 'PLANNING', 'SELECTION', 'FINAL'].includes(status)) return 'info';
  if (status === 'CANCELLED') return 'danger';
  if (status === 'COMPLETED' || status === 'ARCHIVED') return 'neutral';
  return 'warning';
}

function Metric({ label, value, hint, icon }: { label: string; value: number; hint: string; icon: React.ReactNode }) {
  return (
    <Card className="h-full">
      <CardBody className="flex items-start justify-between gap-3 p-4 sm:p-5">
        <div className="min-w-0">
          <p className="text-xs font-medium text-slate-500">{label}</p>
          <p className="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{value}</p>
          <p className="mt-1 text-xs text-slate-500">{hint}</p>
        </div>
        <span className="rounded-xl bg-brand-50 p-2.5 text-brand-700">{icon}</span>
      </CardBody>
    </Card>
  );
}

export default function CompetitionOverviewPage() {
  const queryClient = useQueryClient();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [showCreate, setShowCreate] = useState(false);
  const [form, setForm] = useState({ name: '', competition_type_id: '', competition_domain_id: '', organizer_name: '', description: '', default_visibility: 'INTERNAL' });

  const metaQuery = useQuery({ queryKey: ['competitions', 'meta'], queryFn: competitionApi.meta, staleTime: 5 * 60_000 });
  const dashboardQuery = useQuery({ queryKey: ['competitions', 'dashboard'], queryFn: competitionApi.dashboard, staleTime: 30_000 });
  const listQuery = useQuery({
    queryKey: ['competitions', 'list', search, status],
    queryFn: () => competitionApi.list({ search, status, per_page: 30 }),
  });

  const types = metaQuery.data?.types ?? [];
  const domains = metaQuery.data?.domains ?? [];
  const selectedType = useMemo(() => types.find((row) => String(row.id) === form.competition_type_id), [types, form.competition_type_id]);

  const createMutation = useMutation({
    mutationFn: () => competitionApi.create({
      name: form.name.trim(),
      competition_type_id: Number(form.competition_type_id),
      competition_domain_id: form.competition_domain_id ? Number(form.competition_domain_id) : selectedType?.competition_domain_id,
      organizer_name: form.organizer_name || undefined,
      description: form.description || undefined,
      default_visibility: form.default_visibility,
      default_participant_mode: selectedType?.participant_mode ?? 'INDIVIDUAL',
    }),
    onSuccess: async (competition) => {
      toast.success('Kompetisi dibuat. Lanjutkan dengan membuat edition dan kategori.');
      setShowCreate(false);
      setForm({ name: '', competition_type_id: '', competition_domain_id: '', organizer_name: '', description: '', default_visibility: 'INTERNAL' });
      await queryClient.invalidateQueries({ queryKey: ['competitions'] });
      window.location.assign(`/competitions/${competition.id}`);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!form.competition_type_id) {
      toast.error('Pilih jenis kompetisi.');
      return;
    }
    createMutation.mutate();
  }

  const dashboard = dashboardQuery.data;
  const competitions = listQuery.data?.items ?? [];

  if (dashboardQuery.isLoading || metaQuery.isLoading) {
    return <div className="flex justify-center py-16"><Spinner /></div>;
  }

  return (
    <div className="space-y-5">
      <header className="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.16em] text-brand-700">School Operations · Phase 5</p>
          <h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Competition Management</h1>
          <p className="mt-1 max-w-3xl text-sm text-slate-500">Kelola siklus kompetisi dari edition dan eligibility hingga pertandingan, penilaian, hasil, dan penghargaan.</p>
        </div>
        <Button onClick={() => setShowCreate((open) => !open)} className="w-full sm:w-auto">
          <Plus className="h-4 w-4" /> Kompetisi baru
        </Button>
      </header>

      {dashboard && (
        <section aria-label="Ringkasan kompetisi" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Metric label="Kompetisi" value={dashboard.competitions.total} hint={`${dashboard.competitions.active} edition aktif`} icon={<Trophy className="h-5 w-5" />} />
          <Metric label="Pendaftaran" value={dashboard.registrations.total} hint={`${dashboard.registrations.pending} menunggu verifikasi`} icon={<Users className="h-5 w-5" />} />
          <Metric label="Pertandingan mendatang" value={dashboard.operations.upcoming_matches} hint={`${dashboard.operations.running_matches} sedang berjalan`} icon={<CalendarDays className="h-5 w-5" />} />
          <Metric label="Skor tertunda" value={dashboard.operations.pending_scores} hint={`${dashboard.registrations.waitlisted} peserta dalam waitlist`} icon={<ClipboardCheck className="h-5 w-5" />} />
        </section>
      )}

      {showCreate && (
        <Card>
          <CardHeader>
            <CardTitle>Buat competition master</CardTitle>
            <p className="mt-1 text-xs text-slate-500">Master dapat memiliki beberapa edition tahunan; data peserta dan hasil disimpan pada edition.</p>
          </CardHeader>
          <CardBody>
            <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
              <label className="space-y-1.5 text-sm font-medium text-slate-700">
                Nama kompetisi <span className="text-red-600">*</span>
                <Input autoFocus required maxLength={200} placeholder="Contoh: Olimpiade Matematika" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              </label>
              <label className="space-y-1.5 text-sm font-medium text-slate-700">
                Jenis kompetisi <span className="text-red-600">*</span>
                <Select required value={form.competition_type_id} onChange={(e) => setForm({ ...form, competition_type_id: e.target.value })}>
                  <option value="">Pilih jenis</option>
                  {types.map((type) => <option key={type.id} value={type.id}>{type.name}</option>)}
                </Select>
              </label>
              <label className="space-y-1.5 text-sm font-medium text-slate-700">
                Domain
                <Select value={form.competition_domain_id || String(selectedType?.competition_domain_id ?? '')} onChange={(e) => setForm({ ...form, competition_domain_id: e.target.value })}>
                  <option value="">Ikuti jenis kompetisi</option>
                  {domains.map((domain) => <option key={domain.id} value={domain.id}>{domain.name}</option>)}
                </Select>
              </label>
              <label className="space-y-1.5 text-sm font-medium text-slate-700">
                Penyelenggara
                <Input maxLength={200} placeholder="OSIS, unit akademik, pesantren…" value={form.organizer_name} onChange={(e) => setForm({ ...form, organizer_name: e.target.value })} />
              </label>
              <label className="space-y-1.5 text-sm font-medium text-slate-700 md:col-span-2">
                Deskripsi
                <textarea className="min-h-24 w-full rounded-lg border border-slate-300 p-3 text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20" maxLength={20000} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Tujuan dan gambaran kompetisi" />
              </label>
              <div className="flex flex-col-reverse gap-2 border-t border-slate-100 pt-4 sm:col-span-2 sm:flex-row sm:justify-end">
                <Button type="button" variant="outline" onClick={() => setShowCreate(false)}>Batal</Button>
                <Button type="submit" loading={createMutation.isPending}><Plus className="h-4 w-4" /> Buat master</Button>
              </div>
            </form>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <CardTitle>Daftar kompetisi</CardTitle>
            <p className="mt-1 text-xs text-slate-500">Buka workspace untuk edition, kategori, peserta, jadwal, penilaian, dan hasil.</p>
          </div>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(220px,1fr)_190px]">
            <div className="relative">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <Input aria-label="Cari kompetisi" className="pl-9" placeholder="Cari nama atau kode" value={search} onChange={(e) => setSearch(e.target.value)} />
            </div>
            <Select aria-label="Filter status" value={status} onChange={(e) => setStatus(e.target.value)}>
              <option value="">Semua status</option>
              <option value="DRAFT">Draf</option><option value="PLANNING">Perencanaan</option><option value="ACTIVE">Aktif</option><option value="COMPLETED">Selesai</option><option value="CANCELLED">Dibatalkan</option>
            </Select>
          </div>
        </CardHeader>
        {listQuery.isLoading ? <div className="flex justify-center p-10"><Spinner /></div> : competitions.length === 0 ? (
          <EmptyState title="Belum ada kompetisi" description="Buat master kompetisi untuk memulai siklus edition." action={<Button size="sm" onClick={() => setShowCreate(true)}>Buat kompetisi</Button>} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[760px] text-left text-sm">
              <thead className="border-y border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                <tr><th className="px-5 py-3 font-medium">Kompetisi</th><th className="px-4 py-3 font-medium">Jenis / domain</th><th className="px-4 py-3 font-medium">Penyelenggara</th><th className="px-4 py-3 font-medium">Edition</th><th className="px-4 py-3 font-medium">Status</th><th className="px-5 py-3" /></tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {competitions.map((competition: CompetitionRow) => (
                  <tr key={competition.id} className="hover:bg-slate-50/70">
                    <td className="px-5 py-4">
                      <Link to={`/competitions/${competition.id}`} className="font-semibold text-slate-900 hover:text-brand-700">{competition.name}</Link>
                      <p className="mt-0.5 text-xs text-slate-500">{competition.code}</p>
                    </td>
                    <td className="px-4 py-4">
                      <p className="font-medium text-slate-700">{competition.competition_type?.name ?? '—'}</p>
                      <p className="mt-0.5 text-xs text-slate-500">{competition.competition_domain?.name ?? 'Belum diatur'}</p>
                    </td>
                    <td className="px-4 py-4 text-slate-600">{competition.organizer_name ?? '—'}</td>
                    <td className="px-4 py-4"><span className="inline-flex items-center gap-1 text-slate-600"><CalendarDays className="h-3.5 w-3.5" /> {competition.competition_editions_count ?? 0}</span></td>
                    <td className="px-4 py-4"><Badge tone={statusTone(competition.status)}>{statusLabel[competition.status] ?? competition.status}</Badge></td>
                    <td className="px-5 py-4 text-right"><Link to={`/competitions/${competition.id}`} className="inline-flex items-center gap-1 font-medium text-brand-700 hover:text-brand-900">Workspace <ArrowRight className="h-4 w-4" /></Link></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        {!listQuery.isLoading && competitions.length > 0 && <div className="flex items-center justify-between border-t border-slate-100 px-5 py-3 text-xs text-slate-500"><span>{listQuery.data?.total ?? competitions.length} kompetisi</span><span className="inline-flex items-center gap-1"><Clock3 className="h-3.5 w-3.5" /> Data diperbarui langsung dari server</span></div>}
      </Card>
    </div>
  );
}
