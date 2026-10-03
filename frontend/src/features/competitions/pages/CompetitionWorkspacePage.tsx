import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Award, CalendarDays, Check, ClipboardCheck, Layers3, Plus, Trophy, Users, WandSparkles } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { toast } from 'sonner';
import { getErrorMessage } from '@/lib/axios';
import { usePermission } from '@/hooks/usePermission';
import { competitionApi } from '../api/competitionApi';
import type { CompetitionCategory, CompetitionMatch, CompetitionRegistration, CompetitionResult, CompetitionRound } from '../types';

type Tab = 'overview' | 'categories' | 'registrations' | 'participants' | 'operations' | 'results';
const tabs: { id: Tab; label: string; icon: ReactNode }[] = [
  { id: 'overview', label: 'Ringkasan', icon: <Layers3 className="h-4 w-4" /> },
  { id: 'categories', label: 'Kategori', icon: <Trophy className="h-4 w-4" /> },
  { id: 'registrations', label: 'Pendaftaran', icon: <ClipboardCheck className="h-4 w-4" /> },
  { id: 'participants', label: 'Peserta & tim', icon: <Users className="h-4 w-4" /> },
  { id: 'operations', label: 'Babak & jadwal', icon: <CalendarDays className="h-4 w-4" /> },
  { id: 'results', label: 'Hasil & award', icon: <Award className="h-4 w-4" /> },
];

function tone(status: string): 'neutral' | 'success' | 'warning' | 'info' | 'danger' {
  if (['APPROVED', 'ELIGIBLE', 'COMPLETED', 'PUBLISHED', 'LOCKED', 'ADVANCED', 'FINALIZED'].includes(status)) return 'success';
  if (['REJECTED', 'ELIGIBILITY_FAILED', 'DISQUALIFIED', 'CANCELLED'].includes(status)) return 'danger';
  if (['SUBMITTED', 'UNDER_REVIEW', 'WAITLISTED', 'DRAFT', 'SCHEDULED', 'RUNNING'].includes(status)) return 'warning';
  return 'neutral';
}

function Pill({ value }: { value?: string | null }) {
  const label = value ? value.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, (x: string) => x.toUpperCase()) : '—';
  return <Badge tone={value ? tone(value) : 'neutral'}>{label}</Badge>;
}

function SectionTitle({ title, description, action }: { title: string; description?: string; action?: ReactNode }) {
  return <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div><h2 className="text-base font-semibold text-slate-900">{title}</h2>{description && <p className="mt-1 text-xs text-slate-500">{description}</p>}</div>{action}</div>;
}

export default function CompetitionWorkspacePage({ competitionId: providedCompetitionId }: { competitionId?: string } = {}) {
  const { competitionId: routeCompetitionId } = useParams();
  const competitionId = Number(providedCompetitionId ?? routeCompetitionId);
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [searchParams, setSearchParams] = useSearchParams();
  const activeTab = (tabs.some((tab) => tab.id === searchParams.get('tab')) ? searchParams.get('tab') : 'overview') as Tab;
  const requestedEdition = Number(searchParams.get('edition')) || undefined;
  const [showEditionForm, setShowEditionForm] = useState(false);
  const [showCategoryForm, setShowCategoryForm] = useState(false);
  const [showRoundForm, setShowRoundForm] = useState(false);
  const [showAwardForm, setShowAwardForm] = useState(false);
  const [selectedCategoryId, setSelectedCategoryId] = useState<number | undefined>();
  const [selectedRoundId, setSelectedRoundId] = useState<number | undefined>();
  const [editionForm, setEditionForm] = useState({ year: String(new Date().getFullYear()), name: '', theme: '', starts_on: '', ends_on: '' });
  const [categoryForm, setCategoryForm] = useState({ code: '', name: '', participant_mode: 'INDIVIDUAL', capacity: '', min_team_size: '', max_team_size: '', requires_parent_consent: false });
  const [roundForm, setRoundForm] = useState({ category_id: '', name: '', round_type: 'QUALIFICATION', format: 'KNOCKOUT', qualification_count: '', is_final: false });
  const [awardForm, setAwardForm] = useState({ category_id: '', name: '', position_from: '1', position_to: '1', award_type: 'PLACEMENT' });
  const detailQuery = useQuery({ queryKey: ['competitions', competitionId, 'detail'], queryFn: () => competitionApi.detail(competitionId), enabled: Number.isFinite(competitionId) && competitionId > 0 });
  const detail = detailQuery.data;
  const editions = detail?.editions ?? [];
  const edition = editions.find((row) => row.id === requestedEdition) ?? editions[0];
  const editionId = edition?.id;

  useEffect(() => {
    if (!requestedEdition && edition?.id) setSearchParams((current) => { const next = new URLSearchParams(current); next.set('edition', String(edition.id)); return next; }, { replace: true });
  }, [requestedEdition, edition?.id, setSearchParams]);

  const categoriesQuery = useQuery({ queryKey: ['competition', editionId, 'categories'], queryFn: () => competitionApi.categories(editionId as number), enabled: Boolean(editionId) });
  const categories = categoriesQuery.data ?? [];
  const registrationsQuery = useQuery({ queryKey: ['competition', editionId, 'registrations'], queryFn: () => competitionApi.registrations(editionId as number, { per_page: 100 }), enabled: Boolean(editionId) && activeTab === 'registrations' });
  const participantsQuery = useQuery({ queryKey: ['competition', editionId, 'participants'], queryFn: () => competitionApi.participants(editionId as number, { per_page: 100 }), enabled: Boolean(editionId) && activeTab === 'participants' });
  const roundsQuery = useQuery({ queryKey: ['competition', editionId, 'rounds', selectedCategoryId], queryFn: () => competitionApi.rounds(editionId as number, selectedCategoryId), enabled: Boolean(editionId) && ['operations', 'results', 'overview'].includes(activeTab) });
  const rounds = roundsQuery.data ?? [];
  const effectiveRoundId = selectedRoundId ?? rounds.find((round) => round.is_final)?.id ?? rounds[rounds.length - 1]?.id;
  const matchesQuery = useQuery({ queryKey: ['competition', editionId, 'matches', selectedCategoryId], queryFn: () => competitionApi.matches(editionId as number, { category_id: selectedCategoryId, per_page: 100 }), enabled: Boolean(editionId) && activeTab === 'operations' });
  const bracketQuery = useQuery({ queryKey: ['competition', 'bracket', effectiveRoundId], queryFn: () => competitionApi.bracket(effectiveRoundId as number), enabled: Boolean(effectiveRoundId) && activeTab === 'operations' });
  const resultsQuery = useQuery({ queryKey: ['competition', editionId, 'results', selectedCategoryId], queryFn: () => competitionApi.results(editionId as number, { category_id: selectedCategoryId, per_page: 100 }), enabled: Boolean(editionId) && activeTab === 'results' });
  const awardsQuery = useQuery({ queryKey: ['competition', editionId, 'awards'], queryFn: () => competitionApi.awards(editionId as number), enabled: Boolean(editionId) && activeTab === 'results' });

  function invalidate() {
    void queryClient.invalidateQueries({ queryKey: ['competitions'] });
    if (editionId) void queryClient.invalidateQueries({ queryKey: ['competition', editionId] });
  }
  function mutation<TData>(fn: () => Promise<TData>, message: string) {
    return {
      mutationFn: fn,
      onSuccess: () => { toast.success(message); invalidate(); },
      onError: (error: unknown) => toast.error(getErrorMessage(error)),
    };
  }

  const createEditionMutation = useMutation(mutation(() => competitionApi.createEdition(competitionId, {
    year: Number(editionForm.year), name: editionForm.name || undefined, theme: editionForm.theme || undefined,
    starts_on: editionForm.starts_on || undefined, ends_on: editionForm.ends_on || undefined,
  }), 'Edition dibuat.'));
  const createCategoryMutation = useMutation(mutation(() => competitionApi.createCategory(editionId as number, {
    code: categoryForm.code, name: categoryForm.name, participant_mode: categoryForm.participant_mode,
    capacity: categoryForm.capacity ? Number(categoryForm.capacity) : undefined,
    min_team_size: categoryForm.min_team_size ? Number(categoryForm.min_team_size) : undefined,
    max_team_size: categoryForm.max_team_size ? Number(categoryForm.max_team_size) : undefined,
    requires_parent_consent: categoryForm.requires_parent_consent,
  }), 'Kategori dibuat.'));
  const createRoundMutation = useMutation(mutation(() => competitionApi.createRound(editionId as number, {
    category_id: Number(roundForm.category_id), name: roundForm.name, round_type: roundForm.round_type,
    format: roundForm.format, qualification_count: roundForm.qualification_count ? Number(roundForm.qualification_count) : undefined,
    is_final: roundForm.is_final,
  }), 'Babak dibuat.'));
  const generateFixturesMutation = useMutation({
    mutationFn: (roundId: number) => competitionApi.generateFixtures(roundId, {}),
    onSuccess: () => { toast.success('Bracket dan fixture berhasil dibuat.'); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const openRegistrationMutation = useMutation(mutation(() => competitionApi.openRegistration(editionId as number), 'Pendaftaran dibuka.'));
  const publishEditionMutation = useMutation(mutation(() => competitionApi.publishEdition(editionId as number), 'Edition dipublikasikan.'));
  const finalizeResultsMutation = useMutation(mutation(() => competitionApi.finalizeResults(editionId as number, selectedCategoryId as number, effectiveRoundId), 'Hasil kategori difinalisasi.'));
  const publishResultsMutation = useMutation(mutation(() => competitionApi.publishResults(editionId as number, selectedCategoryId), 'Hasil dipublikasikan.'));
  const createAwardMutation = useMutation(mutation(() => competitionApi.createAward(editionId as number, {
    category_id: Number(awardForm.category_id), name: awardForm.name, criteria_type: 'RANKING', award_type: awardForm.award_type,
    position_from: Number(awardForm.position_from), position_to: Number(awardForm.position_to),
  }), 'Award dikonfigurasi.'));
  const decisionMutation = useMutation({
    mutationFn: ({ registrationId, decision }: { registrationId: number; decision: 'APPROVED' | 'REJECTED' }) => competitionApi.decideRegistration(registrationId, decision, decision === 'REJECTED' ? 'Tidak memenuhi verifikasi kompetisi.' : undefined),
    onSuccess: () => { toast.success('Keputusan pendaftaran diperbarui.'); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  function changeTab(tab: Tab) {
    setSearchParams((current) => { const next = new URLSearchParams(current); next.set('tab', tab); return next; });
  }
  function onEditionChange(id: string) {
    setSearchParams((current) => { const next = new URLSearchParams(current); if (id) next.set('edition', id); else next.delete('edition'); return next; });
  }
  function submitEdition(event: FormEvent<HTMLFormElement>) { event.preventDefault(); createEditionMutation.mutate(); setShowEditionForm(false); }
  function submitCategory(event: FormEvent<HTMLFormElement>) { event.preventDefault(); createCategoryMutation.mutate(); setShowCategoryForm(false); }
  function submitRound(event: FormEvent<HTMLFormElement>) { event.preventDefault(); createRoundMutation.mutate(); setShowRoundForm(false); }
  function submitAward(event: FormEvent<HTMLFormElement>) { event.preventDefault(); createAwardMutation.mutate(); setShowAwardForm(false); }

  if (detailQuery.isLoading) return <div className="flex justify-center py-16"><Spinner /></div>;
  if (detailQuery.isError || !detail) return <EmptyState title="Kompetisi tidak ditemukan" description="Pastikan kompetisi masih tersedia dalam cakupan akses Anda." action={<Link to="/competitions"><Button variant="outline">Kembali ke daftar</Button></Link>} />;

  const competition = detail.competition;
  const registrations = registrationsQuery.data?.items ?? [];
  const participants = participantsQuery.data?.items ?? [];
  const matches = matchesQuery.data?.items ?? [];
  const results = resultsQuery.data?.items ?? [];
  const awards = awardsQuery.data ?? [];
  const bracketRender = (bracketQuery.data?.bracket as { render_data?: Array<{ round: number; name: string; slots: Array<{ position: number; participant_id?: number | null; team_id?: number | null; seed?: number | null; status: string; bye: boolean }> }> } | null)?.render_data ?? [];

  return (
    <div className="space-y-5">
      <Link to="/competitions" className="inline-flex items-center gap-1.5 text-sm font-medium text-slate-500 hover:text-brand-700"><ArrowLeft className="h-4 w-4" /> Semua kompetisi</Link>
      <header className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2"><h1 className="text-xl font-semibold text-slate-900 sm:text-2xl">{competition.name}</h1><Pill value={competition.status} /></div>
          <p className="mt-1 text-sm text-slate-500">{competition.code} {detail.type?.name ? `· ${detail.type.name}` : ''} {detail.domain?.name ? `· ${detail.domain.name}` : ''}</p>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          {editions.length > 0 && <Select aria-label="Pilih edition" className="min-w-[180px]" value={String(edition?.id ?? '')} onChange={(e) => onEditionChange(e.target.value)}><option value="">Pilih edition</option>{editions.map((row) => <option key={row.id} value={row.id}>{row.year} · {row.name}</option>)}</Select>}
          {can('competition.update') && <Button variant="outline" onClick={() => setShowEditionForm((value) => !value)}><Plus className="h-4 w-4" /> Edition</Button>}
        </div>
      </header>

      {showEditionForm && (
        <Card><CardHeader><CardTitle>Buat edition</CardTitle><p className="mt-1 text-xs text-slate-500">Gunakan edition per tahun/periode. Riwayat lama tetap utuh.</p></CardHeader><CardBody>
          <form onSubmit={submitEdition} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label className="space-y-1 text-xs font-medium text-slate-600">Tahun<input required type="number" min="2000" max="2200" className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={editionForm.year} onChange={(e) => setEditionForm({ ...editionForm, year: e.target.value })} /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Nama edition<input className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" placeholder={`${competition.name} ${editionForm.year}`} value={editionForm.name} onChange={(e) => setEditionForm({ ...editionForm, name: e.target.value })} /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Tema<input className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={editionForm.theme} onChange={(e) => setEditionForm({ ...editionForm, theme: e.target.value })} /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Tanggal mulai<input type="date" className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={editionForm.starts_on} onChange={(e) => setEditionForm({ ...editionForm, starts_on: e.target.value })} /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Tanggal selesai<input type="date" className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={editionForm.ends_on} onChange={(e) => setEditionForm({ ...editionForm, ends_on: e.target.value })} /></label>
            <div className="flex items-end gap-2 sm:col-span-2 xl:col-span-3"><Button type="submit" loading={createEditionMutation.isPending}>Simpan edition</Button><Button type="button" variant="outline" onClick={() => setShowEditionForm(false)}>Batal</Button></div>
          </form>
        </CardBody></Card>
      )}

      {edition && (
        <Card className="border-brand-100 bg-gradient-to-r from-brand-50/70 to-white">
          <CardBody className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <div><p className="text-xs font-semibold uppercase tracking-wide text-brand-700">{edition.year} edition</p><h2 className="mt-1 text-base font-semibold text-slate-900">{edition.name}</h2><p className="mt-1 text-xs text-slate-500">{edition.starts_on ?? 'Tanggal belum ditentukan'}{edition.ends_on ? ` – ${edition.ends_on}` : ''} · {categories.length} kategori</p></div>
            <div className="flex flex-wrap items-center gap-2"><Pill value={edition.status} />{can('competition.publish') && edition.status === 'DRAFT' && <Button size="sm" variant="outline" onClick={() => publishEditionMutation.mutate()} loading={publishEditionMutation.isPending}>Publikasikan</Button>}{can('competition.publish') && ['DRAFT','PLANNING'].includes(edition.status) && <Button size="sm" onClick={() => openRegistrationMutation.mutate()} loading={openRegistrationMutation.isPending}>Buka pendaftaran</Button>}</div>
          </CardBody>
        </Card>
      )}

      {!edition && !showEditionForm && <Card><EmptyState title="Belum ada edition" description="Buat edition untuk mengelola kategori, pendaftaran, babak, dan hasil." action={can('competition.update') ? <Button size="sm" onClick={() => setShowEditionForm(true)}><Plus className="h-4 w-4" /> Buat edition</Button> : undefined} /></Card>}

      <div className="overflow-x-auto">
        <nav role="tablist" aria-label="Bagian kompetisi" className="flex min-w-max gap-1 border-b border-slate-200">
          {tabs.map((tab) => <button key={tab.id} id={`competition-tab-${tab.id}`} role="tab" aria-selected={activeTab === tab.id} type="button" onClick={() => changeTab(tab.id)} className={`inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium transition ${activeTab === tab.id ? 'border-brand-600 text-brand-700' : 'border-transparent text-slate-500 hover:text-slate-800'}`}>{tab.icon}{tab.label}</button>)}
        </nav>
      </div>

      {activeTab === 'overview' && (
        <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
          <Card><CardHeader><SectionTitle title="Siklus kerja edition" description="Status edition mengatur pintu pendaftaran dan pelaksanaan." /></CardHeader><CardBody className="grid gap-3 sm:grid-cols-2">
            {[['Kategori', categories.length, 'Konfigurasi jalur/kelas lomba'], ['Pendaftaran', edition?.status === 'REGISTRATION_OPEN' ? 'Dibuka' : 'Belum dibuka', 'Eligibility, consent, dan dokumen'], ['Babak', rounds.length, 'Bracket, round robin, atau Swiss'], ['Hasil', edition?.status === 'FINAL' ? 'Siap terbit' : 'Belum final', 'Ranking berasal dari skor final']].map(([label, value, hint]) => <div key={String(label)} className="rounded-xl border border-slate-200 bg-white p-4"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 text-lg font-semibold text-slate-900">{value}</p><p className="mt-1 text-xs text-slate-500">{hint}</p></div>)}
          </CardBody></Card>
          <Card><CardHeader><CardTitle className="text-sm">Tindakan berikutnya</CardTitle></CardHeader><CardBody className="space-y-2 text-sm text-slate-600">
            {!edition && <p>Buat edition untuk memulai.</p>}
            {edition && categories.length === 0 && <button className="flex w-full items-center justify-between rounded-lg border border-slate-200 p-3 text-left hover:border-brand-300" onClick={() => { changeTab('categories'); setShowCategoryForm(true); }}>Tambahkan kategori <ArrowLeft className="h-4 w-4 rotate-180" /></button>}
            {edition && categories.length > 0 && edition.status !== 'REGISTRATION_OPEN' && <button className="flex w-full items-center justify-between rounded-lg border border-slate-200 p-3 text-left hover:border-brand-300" onClick={() => openRegistrationMutation.mutate()}>Buka pendaftaran <ArrowLeft className="h-4 w-4 rotate-180" /></button>}
            {edition && <button className="flex w-full items-center justify-between rounded-lg border border-slate-200 p-3 text-left hover:border-brand-300" onClick={() => changeTab('registrations')}>Tinjau pendaftaran <ArrowLeft className="h-4 w-4 rotate-180" /></button>}
            {edition && <p className="rounded-lg bg-slate-50 p-3 text-xs leading-5">Data master siswa, wali, juri pegawai, fasilitas, keuangan, sertifikat, dan achievement tetap dikelola oleh modul ERP masing-masing.</p>}
          </CardBody></Card>
        </div>
      )}

      {activeTab === 'categories' && (
        <div className="space-y-4">
          <SectionTitle title="Kategori & division" description="Atur kapasitas, mode peserta, eligibility, fee, rubric, dan award per kategori." action={can('competition.update') && edition ? <Button onClick={() => setShowCategoryForm((value) => !value)}><Plus className="h-4 w-4" /> Tambah kategori</Button> : undefined} />
          {showCategoryForm && <Card><CardBody><form onSubmit={submitCategory} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <label className="space-y-1 text-xs font-medium text-slate-600">Kode<input required maxLength={60} className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={categoryForm.code} onChange={(e) => setCategoryForm({ ...categoryForm, code: e.target.value.toUpperCase() })} placeholder="SMP-PUTRA" /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Nama kategori<input required maxLength={200} className="mt-1 h-9 w-full rounded-lg border border-slate-300 px-3 text-sm" value={categoryForm.name} onChange={(e) => setCategoryForm({ ...categoryForm, name: e.target.value })} placeholder="SMP Putra" /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Peserta<Select value={categoryForm.participant_mode} onChange={(e) => setCategoryForm({ ...categoryForm, participant_mode: e.target.value })}><option value="INDIVIDUAL">Individu</option><option value="TEAM">Beregu</option><option value="MIXED">Campuran</option></Select></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Kapasitas<Input type="number" min="1" value={categoryForm.capacity} onChange={(e) => setCategoryForm({ ...categoryForm, capacity: e.target.value })} placeholder="Tanpa batas" /></label>
            {categoryForm.participant_mode !== 'INDIVIDUAL' && <><label className="space-y-1 text-xs font-medium text-slate-600">Anggota minimum<Input type="number" min="1" value={categoryForm.min_team_size} onChange={(e) => setCategoryForm({ ...categoryForm, min_team_size: e.target.value })} /></label><label className="space-y-1 text-xs font-medium text-slate-600">Anggota maksimum<Input type="number" min="1" value={categoryForm.max_team_size} onChange={(e) => setCategoryForm({ ...categoryForm, max_team_size: e.target.value })} /></label></>}
            <label className="flex items-center gap-2 self-end rounded-lg border border-slate-200 p-2.5 text-sm text-slate-700"><input type="checkbox" checked={categoryForm.requires_parent_consent} onChange={(e) => setCategoryForm({ ...categoryForm, requires_parent_consent: e.target.checked })} /> Wajib izin orang tua</label>
            <div className="flex items-end gap-2 sm:col-span-2 xl:col-span-4"><Button type="submit" loading={createCategoryMutation.isPending}>Simpan kategori</Button><Button type="button" variant="outline" onClick={() => setShowCategoryForm(false)}>Batal</Button></div>
          </form></CardBody></Card>}
          {categoriesQuery.isLoading ? <div className="flex justify-center py-10"><Spinner /></div> : categories.length === 0 ? <Card><EmptyState title="Kategori belum dibuat" description="Buat kategori untuk membagi peserta menurut jenjang, mode, atau aturan lomba." /></Card> : <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">{categories.map((category: CompetitionCategory) => <Card key={category.id}><CardBody className="p-4"><div className="flex items-start justify-between gap-2"><div><p className="font-semibold text-slate-900">{category.name}</p><p className="mt-0.5 text-xs text-slate-500">{category.code}</p></div><Pill value={category.participant_mode} /></div><div className="mt-4 grid grid-cols-2 gap-2 text-xs"><div className="rounded-lg bg-slate-50 p-2"><span className="text-slate-500">Kapasitas</span><p className="mt-0.5 font-semibold text-slate-800">{category.capacity ?? 'Tanpa batas'}</p></div><div className="rounded-lg bg-slate-50 p-2"><span className="text-slate-500">Skor</span><p className="mt-0.5 font-semibold text-slate-800">{category.scoring_model ?? 'WEIGHTED'}</p></div></div><div className="mt-3 flex flex-wrap gap-1.5">{category.requires_judge && <Badge tone="info">Juri</Badge>}{category.requires_submission && <Badge tone="warning">Karya</Badge>}{category.requires_parent_consent && <Badge tone="warning">Izin wali</Badge>}{category.requires_documents && <Badge tone="neutral">Dokumen</Badge>}</div></CardBody></Card>)}</div>}
        </div>
      )}

      {activeTab === 'registrations' && (
        <div className="space-y-4"><SectionTitle title="Verifikasi pendaftaran" description="Approval akan mengecek eligibility, dokumen wajib, persetujuan orang tua, pembayaran, dan kapasitas sebelum membuat peserta." />
          {registrationsQuery.isLoading ? <div className="flex justify-center py-10"><Spinner /></div> : registrations.length === 0 ? <Card><EmptyState title="Belum ada pendaftaran" description="Pendaftaran yang masuk akan tampil di sini untuk diverifikasi." /></Card> : <Card><div className="overflow-x-auto"><table className="w-full min-w-[820px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Nomor</th><th className="px-4 py-3">Peserta</th><th className="px-4 py-3">Eligibility</th><th className="px-4 py-3">Dokumen / izin</th><th className="px-4 py-3">Status</th><th className="px-5 py-3 text-right">Tindakan</th></tr></thead><tbody className="divide-y divide-slate-100">{registrations.map((reg: CompetitionRegistration) => { const category = categories.find((row) => row.id === reg.category_id); return <tr key={reg.id}><td className="px-5 py-3.5 font-medium text-slate-800">{reg.registration_number}<p className="mt-0.5 text-xs text-slate-500">{category?.name ?? `Kategori #${reg.category_id}`}</p></td><td className="px-4 py-3.5 text-slate-700">{reg.external_participant_name ?? (reg.student_id ? `Siswa #${reg.student_id}` : reg.team_id ? `Tim #${reg.team_id}` : reg.institution_name ?? 'Peserta')}<p className="mt-0.5 text-xs text-slate-500">{reg.participant_type}</p></td><td className="px-4 py-3.5"><Pill value={reg.eligibility_status} /></td><td className="px-4 py-3.5"><div className="flex flex-wrap gap-1"><Pill value={reg.document_status} /><Pill value={reg.consent_status} /></div></td><td className="px-4 py-3.5"><Pill value={reg.status} /></td><td className="px-5 py-3.5"><div className="flex justify-end gap-2">{can('registration.approve') && ['SUBMITTED','UNDER_REVIEW','NEEDS_REVISION'].includes(reg.status) && <Button size="sm" onClick={() => decisionMutation.mutate({ registrationId: reg.id, decision: 'APPROVED' })} loading={decisionMutation.isPending}><Check className="h-3.5 w-3.5" /> Setujui</Button>}{can('registration.reject') && ['SUBMITTED','UNDER_REVIEW','NEEDS_REVISION'].includes(reg.status) && <Button size="sm" variant="outline" onClick={() => decisionMutation.mutate({ registrationId: reg.id, decision: 'REJECTED' })}>Tolak</Button>}</div></td></tr>; })}</tbody></table></div></Card>}
        </div>
      )}

      {activeTab === 'participants' && (
        <div className="space-y-4"><SectionTitle title="Peserta & tim" description="Peserta dibuat secara transaksional setelah pendaftaran disetujui." />
          {participantsQuery.isLoading ? <div className="flex justify-center py-10"><Spinner /></div> : participants.length === 0 ? <Card><EmptyState title="Belum ada peserta terverifikasi" description="Setujui pendaftaran untuk membentuk roster peserta resmi." /></Card> : <Card><div className="overflow-x-auto"><table className="w-full min-w-[650px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Nama</th><th className="px-4 py-3">Kategori</th><th className="px-4 py-3">Jenis</th><th className="px-4 py-3">Kualifikasi</th><th className="px-5 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{participants.map((person) => <tr key={person.id}><td className="px-5 py-3.5 font-medium text-slate-800">{person.display_name ?? `Peserta #${person.id}`}</td><td className="px-4 py-3.5 text-slate-600">{categories.find((category) => category.id === person.category_id)?.name ?? '—'}</td><td className="px-4 py-3.5 text-slate-600">{person.participant_type}</td><td className="px-4 py-3.5"><Pill value={person.qualification_status} /></td><td className="px-5 py-3.5"><Pill value={person.status} /></td></tr>)}</tbody></table></div></Card>}
        </div>
      )}

      {activeTab === 'operations' && (
        <div className="space-y-4"><SectionTitle title="Babak, bracket & jadwal" description="Generator mendukung knockout, round robin, dan Swiss pairing awal. Schedule conflict diperiksa sebelum menyimpan waktu/venue." action={can('schedule.manage') && edition ? <Button onClick={() => setShowRoundForm((value) => !value)}><Plus className="h-4 w-4" /> Buat babak</Button> : undefined} />
          {showRoundForm && <Card><CardBody><form onSubmit={submitRound} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label className="space-y-1 text-xs font-medium text-slate-600">Kategori<Select required value={roundForm.category_id} onChange={(e) => setRoundForm({ ...roundForm, category_id: e.target.value })}><option value="">Pilih kategori</option>{categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}</Select></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Nama babak<Input required value={roundForm.name} onChange={(e) => setRoundForm({ ...roundForm, name: e.target.value })} placeholder="Penyisihan / Final" /></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Format<Select value={roundForm.format} onChange={(e) => setRoundForm({ ...roundForm, format: e.target.value })}><option value="KNOCKOUT">Knockout</option><option value="ROUND_ROBIN">Round robin</option><option value="SWISS">Swiss</option><option value="SINGLE">Manual / single</option></Select></label>
            <label className="space-y-1 text-xs font-medium text-slate-600">Tahap<Select value={roundForm.round_type} onChange={(e) => setRoundForm({ ...roundForm, round_type: e.target.value })}><option value="QUALIFICATION">Kualifikasi</option><option value="PRELIMINARY">Penyisihan</option><option value="SEMIFINAL">Semifinal</option><option value="FINAL">Final</option></Select></label>
            <label className="flex items-center gap-2 self-end rounded-lg border border-slate-200 p-2.5 text-sm text-slate-700"><input type="checkbox" checked={roundForm.is_final} onChange={(e) => setRoundForm({ ...roundForm, is_final: e.target.checked })} /> Babak final</label>
            <div className="flex items-end gap-2 sm:col-span-2 xl:col-span-5"><Button type="submit" loading={createRoundMutation.isPending}>Simpan babak</Button><Button type="button" variant="outline" onClick={() => setShowRoundForm(false)}>Batal</Button></div>
          </form></CardBody></Card>}
          <div className="grid gap-4 xl:grid-cols-[0.9fr_1.1fr]">
            <Card><CardHeader><CardTitle className="text-sm">Daftar babak</CardTitle></CardHeader><div className="divide-y divide-slate-100">{roundsQuery.isLoading ? <div className="flex justify-center p-8"><Spinner /></div> : rounds.length === 0 ? <EmptyState title="Belum ada babak" /> : rounds.map((round: CompetitionRound) => <div key={round.id} className={`flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between ${selectedRoundId === round.id ? 'bg-brand-50/60' : ''}`}><button className="min-w-0 text-left" onClick={() => { setSelectedRoundId(round.id); setSelectedCategoryId(round.category_id); }}><p className="truncate text-sm font-semibold text-slate-900">{round.sequence}. {round.name}</p><p className="mt-1 text-xs text-slate-500">{categories.find((category) => category.id === round.category_id)?.name ?? 'Kategori'} · {round.format}</p></button><div className="flex items-center gap-2"><Pill value={round.status} />{can('schedule.manage') && ['DRAFT','SCHEDULED'].includes(round.status) && <Button size="sm" variant="outline" onClick={() => generateFixturesMutation.mutate(round.id)} loading={generateFixturesMutation.isPending}><WandSparkles className="h-3.5 w-3.5" /> Buat fixture</Button>}</div></div>)}</div></Card>
            <Card><CardHeader><CardTitle className="text-sm">Pertandingan & jadwal</CardTitle></CardHeader>{matchesQuery.isLoading ? <div className="flex justify-center p-8"><Spinner /></div> : matches.length === 0 ? <EmptyState title="Fixture belum dibuat" description="Pilih babak dan buat bracket/fixture otomatis." /> : <div className="divide-y divide-slate-100">{matches.map((match: CompetitionMatch) => <div key={match.id} className="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-semibold text-slate-900">Match {match.match_number}</p><p className="mt-0.5 text-xs text-slate-500">{match.scheduled_at ? new Date(match.scheduled_at).toLocaleString('id-ID') : 'Belum dijadwalkan'}{match.venue_name ? ` · ${match.venue_name}` : ''}</p></div><div className="flex items-center gap-2"><Pill value={match.status} />{match.status === 'SCHEDULED' && can('schedule.manage') && <Button size="sm" variant="outline" onClick={() => toast.info('Pilih waktu dan venue melalui dialog penjadwalan yang akan datang.')}>Jadwalkan</Button>}</div></div>)}</div>}</Card>
          </div>
          {bracketQuery.isLoading ? <div className="flex justify-center py-6"><Spinner /></div> : bracketRender.length > 0 && <Card><CardHeader><CardTitle className="text-sm">Bracket</CardTitle></CardHeader><CardBody className="overflow-x-auto pt-0"><div className="flex min-w-max items-stretch gap-4 pb-2">{bracketRender.map((stage) => <section key={stage.round} className="w-48 space-y-2"><h3 className="text-xs font-semibold uppercase tracking-wide text-slate-500">{stage.name}</h3>{stage.slots.map((slot) => <div key={`${stage.round}-${slot.position}`} className="rounded-lg border border-slate-200 bg-slate-50 p-3"><p className="text-[11px] text-slate-400">Seed {slot.seed ?? '—'} · {slot.status}</p><p className="mt-1 text-sm font-medium text-slate-800">{slot.bye ? 'BYE' : slot.participant_id ? `Peserta #${slot.participant_id}` : slot.team_id ? `Tim #${slot.team_id}` : 'Menunggu pemenang'}</p></div>)}</section>)}</div></CardBody></Card>}
        </div>
      )}

      {activeTab === 'results' && (
        <div className="space-y-4"><SectionTitle title="Ranking, hasil & penghargaan" description="Hasil dirakit hanya dari scorecard final, agregasi juri, tie-break, dan penalti." />
          <Card><CardBody className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between"><div className="grid gap-3 sm:grid-cols-2"><label className="space-y-1 text-xs font-medium text-slate-600">Kategori<Select value={selectedCategoryId ? String(selectedCategoryId) : ''} onChange={(e) => setSelectedCategoryId(e.target.value ? Number(e.target.value) : undefined)}><option value="">Pilih kategori</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</Select></label><label className="space-y-1 text-xs font-medium text-slate-600">Babak<Select value={effectiveRoundId ? String(effectiveRoundId) : ''} onChange={(e) => setSelectedRoundId(e.target.value ? Number(e.target.value) : undefined)}><option value="">Babak terakhir</option>{rounds.map((round) => <option key={round.id} value={round.id}>{round.name}</option>)}</Select></label></div><div className="flex flex-wrap gap-2">{can('award.manage') && <Button variant="outline" onClick={() => setShowAwardForm((value) => !value)}><Plus className="h-4 w-4" /> Award</Button>}{can('result.finalize') && <Button disabled={!selectedCategoryId} onClick={() => finalizeResultsMutation.mutate()} loading={finalizeResultsMutation.isPending}>Finalisasi ranking</Button>}{can('result.publish') && <Button variant="secondary" onClick={() => publishResultsMutation.mutate()} loading={publishResultsMutation.isPending}>Publikasikan hasil</Button>}</div></CardBody></Card>
          {showAwardForm && <Card><CardBody><form onSubmit={submitAward} className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5"><label className="space-y-1 text-xs font-medium text-slate-600">Kategori<Select required value={awardForm.category_id} onChange={(e) => setAwardForm({ ...awardForm, category_id: e.target.value })}><option value="">Pilih kategori</option>{categories.map((cat) => <option key={cat.id} value={cat.id}>{cat.name}</option>)}</Select></label><label className="space-y-1 text-xs font-medium text-slate-600">Nama award<Input required value={awardForm.name} onChange={(e) => setAwardForm({ ...awardForm, name: e.target.value })} placeholder="Juara 1" /></label><label className="space-y-1 text-xs font-medium text-slate-600">Posisi dari<Input type="number" min="1" value={awardForm.position_from} onChange={(e) => setAwardForm({ ...awardForm, position_from: e.target.value })} /></label><label className="space-y-1 text-xs font-medium text-slate-600">Posisi sampai<Input type="number" min="1" value={awardForm.position_to} onChange={(e) => setAwardForm({ ...awardForm, position_to: e.target.value })} /></label><div className="flex items-end"><Button type="submit" loading={createAwardMutation.isPending}>Simpan award</Button></div></form></CardBody></Card>}
          {resultsQuery.isLoading ? <div className="flex justify-center py-10"><Spinner /></div> : results.length === 0 ? <Card><EmptyState title="Hasil belum difinalisasi" description="Pilih kategori dan babak, kemudian finalisasi ranking setelah pertandingan serta scorecard terkunci." /></Card> : <Card><div className="overflow-x-auto"><table className="w-full min-w-[700px] text-left text-sm"><thead className="border-b border-slate-100 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th className="px-5 py-3">Posisi</th><th className="px-4 py-3">Peserta</th><th className="px-4 py-3">Skor final</th><th className="px-4 py-3">Kualifikasi</th><th className="px-5 py-3">Status</th></tr></thead><tbody className="divide-y divide-slate-100">{results.map((result: CompetitionResult) => <tr key={result.id}><td className="px-5 py-3.5 font-semibold text-slate-900">{result.position ? `#${result.position}` : '—'}</td><td className="px-4 py-3.5 text-slate-700">Peserta #{result.participant_id ?? result.team_id}</td><td className="px-4 py-3.5 tabular-nums font-medium text-slate-900">{result.final_score ?? '—'}</td><td className="px-4 py-3.5"><Pill value={result.qualification_status} /></td><td className="px-5 py-3.5"><Pill value={result.status} /></td></tr>)}</tbody></table></div></Card>}
          {awards.length > 0 && <Card><CardHeader><CardTitle className="text-sm">Award yang dikonfigurasi</CardTitle></CardHeader><CardBody className="flex flex-wrap gap-2">{awards.map((award) => <Badge key={award.id} tone="info">{award.name} · posisi {award.position_from ?? '—'}{award.position_to && award.position_to !== award.position_from ? `–${award.position_to}` : ''}</Badge>)}</CardBody></Card>}
        </div>
      )}

      {!edition && <p className="rounded-xl border border-dashed border-slate-300 bg-white p-5 text-sm text-slate-500">Buat atau pilih edition agar pengelolaan kategori dan operasi dapat digunakan.</p>}
    </div>
  );
}
