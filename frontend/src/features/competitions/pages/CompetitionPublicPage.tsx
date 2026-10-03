import { useQuery } from '@tanstack/react-query';
import { CalendarDays, GraduationCap, MapPin, Trophy } from 'lucide-react';
import { Link } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

interface Props { slug: string }
interface PublicPageData {
  competition: { id: number; code: string; name: string; slug: string; short_name?: string | null; description?: string | null; organizer_name?: string | null };
  edition: { id: number; year: number; name: string; theme?: string | null; description?: string | null; terms?: string | null; starts_on?: string | null; ends_on?: string | null; registration_opens?: string | null; registration_closes?: string | null; status: string };
  categories: Array<{ id: number; code: string; name: string; description?: string | null; participant_mode: string; min_grade_level?: string | null; max_grade_level?: string | null; capacity?: number | null }>;
  results: Array<{ category_id: number; participant_name: string; final_score?: number | string | null; position?: number | null; qualification_status?: string | null; status: string }>;
}

export default function CompetitionPublicPage({ slug }: Props) {
  const query = useQuery({
    queryKey: ['competition', 'public', slug],
    queryFn: () => api.get<ApiResponse<PublicPageData>>(`/public/competitions/${encodeURIComponent(slug)}`).then((response) => response.data.data),
    enabled: Boolean(slug),
  });
  if (query.isLoading) return <div className="flex min-h-screen items-center justify-center"><Spinner /></div>;
  if (query.isError || !query.data) return <div className="mx-auto flex min-h-screen max-w-xl items-center px-5"><EmptyState title="Kompetisi tidak ditemukan" description="Kompetisi ini tidak tersedia untuk publik atau belum dipublikasikan." action={<Link to="/login" className="text-sm font-medium text-brand-700">Masuk ke portal sekolah</Link>} /></div>;

  const { competition, edition, categories, results } = query.data;
  return (
    <div className="min-h-screen bg-slate-50 pb-12">
      <header className="bg-gradient-to-br from-slate-950 via-slate-900 to-brand-950 px-5 py-10 text-white sm:py-16">
        <div className="mx-auto max-w-5xl">
          <Link to="/" className="inline-flex items-center gap-2 text-xs font-medium text-slate-300 hover:text-white"><GraduationCap className="h-4 w-4" /> Syntaf School</Link>
          <Badge tone="info" className="mt-7">{edition.year} edition · {edition.status.replace(/_/g, ' ')}</Badge>
          <h1 className="mt-3 text-3xl font-bold tracking-tight sm:text-5xl">{competition.name}</h1>
          <p className="mt-3 max-w-3xl text-sm leading-6 text-slate-300 sm:text-base">{edition.theme ?? competition.description ?? edition.description ?? 'Informasi kompetisi.'}</p>
          <div className="mt-6 flex flex-wrap gap-x-5 gap-y-2 text-xs text-slate-300 sm:text-sm"><span className="inline-flex items-center gap-1.5"><CalendarDays className="h-4 w-4" />{edition.starts_on ?? 'Jadwal menyusul'}{edition.ends_on ? ` – ${edition.ends_on}` : ''}</span>{competition.organizer_name && <span className="inline-flex items-center gap-1.5"><MapPin className="h-4 w-4" />{competition.organizer_name}</span>}</div>
          <div className="mt-7 flex flex-wrap gap-2"><Link to="/login" className="inline-flex h-10 items-center rounded-lg bg-white px-4 text-sm font-semibold text-slate-900 hover:bg-slate-100">Masuk untuk mendaftar</Link><a href="#kategori" className="inline-flex h-10 items-center rounded-lg border border-white/30 px-4 text-sm font-medium text-white hover:bg-white/10">Lihat kategori</a></div>
        </div>
      </header>
      <main className="mx-auto grid max-w-5xl gap-5 px-4 py-6 sm:px-6 sm:py-8 lg:grid-cols-[1.3fr_0.7fr]">
        <div className="space-y-5">
          <Card id="kategori"><CardHeader><CardTitle className="flex items-center gap-2"><Trophy className="h-4 w-4 text-brand-700" /> Kategori</CardTitle></CardHeader><CardBody className="grid gap-3 pt-0 sm:grid-cols-2">{categories.map((category) => <div key={category.id} className="rounded-xl border border-slate-200 p-4"><div className="flex items-start justify-between gap-2"><h2 className="font-semibold text-slate-900">{category.name}</h2><Badge tone="neutral">{category.participant_mode}</Badge></div>{category.description && <p className="mt-2 text-xs leading-5 text-slate-500">{category.description}</p>}<p className="mt-3 text-xs text-slate-500">{category.min_grade_level || category.max_grade_level ? `Jenjang ${category.min_grade_level ?? '—'}–${category.max_grade_level ?? '—'}` : 'Persyaratan jenjang sesuai aturan kompetisi'}{category.capacity ? ` · Kapasitas ${category.capacity}` : ''}</p></div>)}{categories.length === 0 && <p className="text-sm text-slate-500">Kategori akan diumumkan segera.</p>}</CardBody></Card>
          <Card><CardHeader><CardTitle>Deskripsi & ketentuan</CardTitle></CardHeader><CardBody className="space-y-3 pt-0"><p className="whitespace-pre-line text-sm leading-6 text-slate-600">{edition.description ?? competition.description ?? 'Belum ada deskripsi tambahan.'}</p>{edition.terms && <div className="rounded-lg bg-slate-50 p-4"><h3 className="text-xs font-semibold uppercase tracking-wide text-slate-600">Ketentuan</h3><p className="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">{edition.terms}</p></div>}</CardBody></Card>
        </div>
        <aside className="space-y-5">
          <Card><CardHeader><CardTitle>Timeline</CardTitle></CardHeader><CardBody className="space-y-3 pt-0 text-sm">{[['Pendaftaran dibuka', edition.registration_opens], ['Batas pendaftaran', edition.registration_closes], ['Hari kompetisi', edition.starts_on]].map(([label, value]) => <div key={String(label)} className="flex items-start justify-between gap-3 border-b border-slate-100 pb-3 last:border-0 last:pb-0"><span className="text-slate-500">{label}</span><span className="text-right font-medium text-slate-800">{value ? new Date(String(value)).toLocaleDateString('id-ID') : 'Belum ditentukan'}</span></div>)}</CardBody></Card>
          <Card className="border-brand-100 bg-brand-50/50"><CardBody className="p-4"><p className="text-sm font-semibold text-slate-900">Peserta sekolah</p><p className="mt-1 text-xs leading-5 text-slate-600">Pendaftaran dan verifikasi eligibility dilakukan melalui portal sekolah. Data pendaftaran tidak ditampilkan di halaman publik.</p><Link to="/login" className="mt-3 inline-flex text-sm font-semibold text-brand-700">Masuk untuk melanjutkan →</Link></CardBody></Card>
          {results.length > 0 && <Card><CardHeader><CardTitle>Hasil yang dipublikasikan</CardTitle></CardHeader><CardBody className="space-y-2 pt-0">{results.map((result, index) => <div key={`${result.category_id}-${result.participant_name}-${index}`} className="flex items-center justify-between gap-3 rounded-lg bg-slate-50 px-3 py-2 text-sm"><span className="truncate text-slate-600">{categories.find((category) => category.id === result.category_id)?.name ?? 'Kategori'} · {result.participant_name}</span><span className="shrink-0 font-semibold text-slate-900">{result.position ? `#${result.position}` : '—'}</span></div>)}</CardBody></Card>}
        </aside>
      </main>
      <footer className="mx-auto max-w-5xl px-4 text-center text-xs text-slate-600 sm:px-6">Competition Management · Informasi publik hanya menampilkan hasil yang telah difinalisasi dan dipublikasikan.</footer>
    </div>
  );
}
