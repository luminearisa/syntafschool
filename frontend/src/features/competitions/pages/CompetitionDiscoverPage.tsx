import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, Search, Sparkles, Users } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { toast } from 'sonner';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { usePermission } from '@/hooks/usePermission';
import { competitionApi } from '../api/competitionApi';

export default function CompetitionDiscoverPage() {
  const queryClient = useQueryClient();
  const user = useAuthStore((state) => state.user);
  const { can } = usePermission();
  const isParent = can('competition.portal.parent') && !can('competition.portal.student');
  const [search, setSearch] = useState('');
  const [selectedStudent, setSelectedStudent] = useState(user?.userable_id ? String(user.userable_id) : '');
  const listQuery = useQuery({ queryKey: ['competition', 'discover', search], queryFn: () => competitionApi.discover({ search, per_page: 50 }) });
  const childrenQuery = useQuery({ queryKey: ['competition', 'my-children'], queryFn: competitionApi.myChildren, enabled: isParent });
  const children = childrenQuery.data ?? [];
  const createMutation = useMutation({
    mutationFn: async ({ editionId, categoryId }: { editionId: number; categoryId: number }) => {
      const registration = await competitionApi.createRegistration(editionId, {
        category_id: categoryId,
        participant_type: 'STUDENT',
        student_id: Number(selectedStudent),
        idempotency_key: globalThis.crypto?.randomUUID?.() ?? `registration-${Date.now()}-${categoryId}`,
      });
      if (registration.status === 'DRAFT') return competitionApi.submitRegistration(registration.id);
      return registration;
    },
    onSuccess: async () => {
      toast.success('Pendaftaran tersimpan dan dikirim untuk evaluasi eligibility.');
      await queryClient.invalidateQueries({ queryKey: ['competition'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const items = listQuery.data?.items ?? [];

  if (listQuery.isLoading || (isParent && childrenQuery.isLoading)) return <div className="flex justify-center py-16"><Spinner /></div>;

  return (
    <div className="mx-auto max-w-5xl space-y-5">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div><p className="text-xs font-semibold uppercase tracking-[0.16em] text-brand-700">Portal Peserta</p><h1 className="mt-1 text-xl font-semibold text-slate-900 sm:text-2xl">Jelajahi kompetisi</h1><p className="mt-1 max-w-2xl text-sm text-slate-500">Cari kompetisi yang sedang membuka pendaftaran. Sistem akan mengevaluasi eligibility dan menampilkan status pendaftaran Anda.</p></div>
        <div className="w-full sm:max-w-xs"><label className="mb-1 block text-xs font-medium text-slate-600">Cari kompetisi</label><div className="relative"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input className="pl-9" placeholder="Nama kompetisi" value={search} onChange={(event) => setSearch(event.target.value)} /></div></div>
      </header>
      {isParent && <Card><CardBody className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-semibold text-slate-800">Daftarkan anak</p><p className="mt-0.5 text-xs text-slate-500">Daftar anak berasal dari relasi wali di modul Student/Parent.</p></div><Select aria-label="Pilih anak" className="sm:max-w-xs" value={selectedStudent} onChange={(event) => setSelectedStudent(event.target.value)}><option value="">Pilih anak</option>{children.map((child) => <option key={child.id} value={child.id}>{child.name ?? child.full_name ?? `Siswa #${child.id}`}{child.class_name ? ` · ${child.class_name}` : ''}</option>)}</Select></CardBody></Card>}
      {items.length === 0 ? <Card><EmptyState title="Belum ada kompetisi terbuka" description="Kompetisi yang membuka pendaftaran akan muncul di sini." /></Card> : <div className="grid gap-4 md:grid-cols-2">{items.map((edition) => <Card key={edition.id} className="flex h-full flex-col"><CardHeader><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="text-xs font-semibold uppercase tracking-wide text-brand-700">{edition.year} edition</p><CardTitle className="mt-1">{edition.competition?.name ?? edition.name}</CardTitle><p className="mt-1 text-xs text-slate-500">{edition.competition?.organizer_name ?? 'Penyelenggara sekolah'} · {edition.name}</p></div><Badge tone="success">Pendaftaran dibuka</Badge></div></CardHeader><CardBody className="flex flex-1 flex-col gap-4 pt-0"><p className="line-clamp-3 text-sm leading-6 text-slate-600">{edition.description ?? 'Kompetisi sekolah. Baca persyaratan kategori dan lengkapi pendaftaran sebelum batas waktu.'}</p><div className="flex flex-wrap gap-3 text-xs text-slate-500"><span className="inline-flex items-center gap-1"><CalendarDays className="h-3.5 w-3.5" />{edition.starts_on ?? 'Jadwal menyusul'}{edition.ends_on ? ` – ${edition.ends_on}` : ''}</span><span className="inline-flex items-center gap-1"><Users className="h-3.5 w-3.5" />{edition.categories.length} kategori</span></div><div className="space-y-2">{edition.categories.map((category) => <div key={category.id} className="flex flex-col gap-2 rounded-lg border border-slate-200 p-3 sm:flex-row sm:items-center sm:justify-between"><div className="min-w-0"><p className="text-sm font-medium text-slate-800">{category.name}</p><div className="mt-1 flex flex-wrap gap-1.5"><Badge tone="neutral">{category.participant_mode === 'TEAM' ? 'Beregu' : 'Individu'}</Badge>{category.capacity && <Badge tone="info">Kapasitas {category.capacity}</Badge>}{(edition.requires_parent_consent || category.requires_parent_consent) && <Badge tone="warning">Perlu izin wali</Badge>}{Number(category.registration_fee ?? 0) > 0 && <Badge tone="neutral">Biaya {new Intl.NumberFormat('id-ID').format(Number(category.registration_fee))}</Badge>}</div></div><Button size="sm" className="shrink-0" disabled={!selectedStudent || createMutation.isPending || category.participant_mode === 'TEAM'} loading={createMutation.isPending} onClick={() => createMutation.mutate({ editionId: edition.id, categoryId: category.id })}><Sparkles className="h-3.5 w-3.5" /> Daftar</Button></div>)}</div></CardBody></Card>)}</div>}
      <p className="text-center text-xs text-slate-600">Tim beregu diajukan melalui manajer/pembina tim. Eligibility, persetujuan wali, dan dokumen wajib diperiksa server sebelum approval.</p>
    </div>
  );
}
