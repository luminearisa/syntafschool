import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, FileBarChart, RefreshCw, Search, UserRound } from 'lucide-react';
import { toast } from 'sonner';
import { transcriptsApi } from '../api/transcriptsApi';
import { studentsApi } from '@/features/students/api/studentsApi';
import type { Transcript } from '../types';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

function formatNumber(value: number | null | undefined) {
  return value === null || value === undefined ? '—' : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(value);
}

export function TranscriptsPage() {
  const queryClient = useQueryClient();
  const [academicYearId, setAcademicYearId] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const lookupsQuery = useQuery({
    queryKey: ['transcripts', 'lookups'],
    staleTime: 5 * 60_000,
    queryFn: () => transcriptsApi.lookups(),
  });
  const listQuery = useQuery({
    queryKey: ['transcripts', page, academicYearId, search],
    queryFn: () => transcriptsApi.list({
      page,
      per_page: 15,
      academic_year_id: academicYearId,
      search: search || undefined,
    }),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['transcripts'] });
  const applySearch = (event: FormEvent) => { event.preventDefault(); setSearch(searchInput.trim()); setPage(1); };

  const years = lookupsQuery.data?.academic_years ?? [];
  const rows = listQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Transkrip</h1>
          <p className="text-sm text-slate-500">Susun transkrip kumulatif dari rapor yang telah diterbitkan, lalu unduh PDF-nya.</p>
        </div>
        <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => listQuery.refetch()}><RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
      </div>

      <GenerateCard years={years} onGenerated={invalidate} />

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <form onSubmit={applySearch} className="flex items-center gap-2 lg:col-span-3">
            <div className="relative w-full"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nama atau NIS siswa..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <Select value={academicYearId} onChange={(event) => { setAcademicYearId(event.target.value ? Number(event.target.value) : ''); setPage(1); }}>
            <option value="">Semua tahun ajaran</option>
            {years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </Select>
        </div>
      </Card>

      <Card>
        {listQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat transkrip...</div>
          : listQuery.isError ? <EmptyState title="Gagal memuat transkrip" description={getErrorMessage(listQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>Coba lagi</Button>} />
            : rows.length === 0 ? <EmptyState title="Belum ada transkrip" description="Pilih siswa lalu susun transkrip dari rapor yang sudah diterbitkan." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Siswa</th><th className="px-4 py-3">Cakupan</th><th className="px-4 py-3 text-right">Rata-rata</th><th className="px-4 py-3">Dibuat</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{rows.map((row) => <tr key={row.id} className="border-b border-slate-50 hover:bg-slate-50/60"><td className="px-4 py-3"><div className="font-medium text-slate-800">{row.student?.full_name ?? '—'}</div><div className="font-mono text-xs text-slate-400">{row.student?.nis ?? '—'}</div></td><td className="px-4 py-3 text-slate-600">{row.data?.scope?.label ?? '—'}</td><td className="px-4 py-3 text-right font-semibold">{formatNumber(row.data?.overall_average)}</td><td className="px-4 py-3 text-slate-500">{row.generated_at ? new Date(row.generated_at).toLocaleDateString('id-ID') : '—'}</td><td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={() => setSelectedId(row.id)}>Detail</Button></td></tr>)}</tbody>
              </table></div>}
        {listQuery.data?.meta && <Pagination meta={listQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <TranscriptDetailModal id={selectedId} open={selectedId !== null} onClose={() => setSelectedId(null)} />
    </div>
  );
}

function GenerateCard({ years, onGenerated }: { years: { id: number; name: string }[]; onGenerated: () => void }) {
  const [selected, setSelected] = useState<{ id: number; label: string } | null>(null);
  const [yearId, setYearId] = useState<number | ''>('');
  const [term, setTerm] = useState('');
  const [debounced, setDebounced] = useState('');
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebounced(term.trim()), 300);
    return () => window.clearTimeout(handle);
  }, [term]);

  const studentsQuery = useQuery({
    queryKey: ['transcripts', 'student-search', debounced],
    enabled: debounced.length >= 2,
    queryFn: () => studentsApi.list({ search: debounced, per_page: 8 }),
  });

  const generateMutation = useMutation({
    mutationFn: () => transcriptsApi.generate({ student_id: selected?.id ?? 0, academic_year_id: yearId ? Number(yearId) : null }),
    onSuccess: (transcript) => {
      toast.success(`Transkrip ${transcript.student?.full_name ?? ''} berhasil disusun`);
      setSelected(null);
      setTerm('');
      onGenerated();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <Card className="space-y-3 p-4">
      <div className="flex items-center gap-2"><FileBarChart className="h-4 w-4 text-brand-600" /><h2 className="text-sm font-semibold text-slate-800">Susun Transkrip</h2></div>
      <p className="text-xs text-slate-500">Transkrip hanya memuat nilai dari rapor berstatus terbit. Biarkan tahun ajaran kosong untuk transkrip kumulatif seluruh tahun.</p>
      <div className="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_1fr_auto]">
        <div className="relative">
          <label className="mb-1 block text-xs font-medium text-slate-600">Siswa</label>
          <Input value={term || selected?.label || ''} onChange={(event) => { setTerm(event.target.value); setSelected(null); setOpen(true); }} onFocus={() => setOpen(true)} placeholder="Ketik nama atau NIS minimal 2 huruf..." />
          {open && debounced.length >= 2 && (
            <div className="absolute z-20 mt-1 max-h-64 w-full overflow-auto rounded-lg border border-slate-200 bg-white shadow-lg">
              {studentsQuery.isLoading ? <div className="flex items-center gap-2 p-3 text-xs text-slate-400"><Spinner /> Mencari...</div>
                : (studentsQuery.data?.data ?? []).length === 0 ? <p className="p-3 text-xs text-slate-400">Tidak ada siswa cocok.</p>
                  : (studentsQuery.data?.data ?? []).map((student) => (
                    <button key={student.id} type="button" className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50" onClick={() => { setSelected({ id: student.id, label: `${student.full_name} · ${student.nis ?? ''}` }); setTerm(''); setOpen(false); }}>
                      <UserRound className="h-4 w-4 text-slate-400" />
                      <span className="font-medium text-slate-700">{student.full_name}</span>
                      <span className="font-mono text-xs text-slate-400">{student.nis}</span>
                    </button>
                  ))}
            </div>
          )}
        </div>
        <div>
          <label className="mb-1 block text-xs font-medium text-slate-600">Tahun Ajaran</label>
          <Select value={yearId} onChange={(event) => setYearId(event.target.value ? Number(event.target.value) : '')}>
            <option value="">Kumulatif (semua tahun)</option>
            {years.map((year) => <option key={year.id} value={year.id}>{year.name}</option>)}
          </Select>
        </div>
        <div className="flex items-end">
          <Can perm="report-card.generate">
            <Button className="w-full" onClick={() => { if (!selected) { toast.error('Pilih siswa terlebih dahulu.'); return; } generateMutation.mutate(); }} loading={generateMutation.isPending}>Susun Transkrip</Button>
          </Can>
        </div>
      </div>
    </Card>
  );
}

function TranscriptDetailModal({ id, open, onClose }: { id: number | null; open: boolean; onClose: () => void }) {
  const [busy, setBusy] = useState(false);
  const detail = useQuery({
    queryKey: ['transcript', id],
    enabled: open && id !== null,
    queryFn: () => transcriptsApi.show(id!),
  });

  const download = async (transcript: Transcript) => {
    setBusy(true);
    try {
      const blob = await transcriptsApi.pdf(transcript.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = `transkrip-${transcript.student?.nis ?? transcript.id}.pdf`;
      anchor.click();
      URL.revokeObjectURL(url);
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusy(false);
    }
  };

  const transcript = detail.data;

  return (
    <Modal open={open} onClose={onClose} title={transcript ? `Transkrip · ${transcript.student?.full_name ?? ''}` : 'Detail Transkrip'} size="xl">
      {detail.isLoading ? <div className="flex justify-center py-16"><Spinner /></div> : detail.isError ? <EmptyState title="Gagal memuat transkrip" description={getErrorMessage(detail.error)} /> : transcript ? (
        <div className="space-y-4">
          <div className="flex flex-col gap-3 rounded-lg bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-semibold text-slate-800">{transcript.student?.full_name} · {transcript.student?.nis}</p>
              <p className="text-xs text-slate-500">{transcript.data?.scope?.label ?? '—'} · {transcript.data?.report_card_count ?? 0} rapor</p>
            </div>
            <div className="flex items-center gap-2">
              <Badge tone="blue">Rata-rata {formatNumber(transcript.data?.overall_average)}</Badge>
              <Button size="sm" variant="outline" onClick={() => void download(transcript)} loading={busy}><Download className="h-4 w-4" /> PDF</Button>
            </div>
          </div>
          <div className="overflow-x-auto rounded-lg border border-slate-200">
            <table className="w-full text-sm">
              <thead><tr className="bg-slate-50 text-left text-xs uppercase text-slate-500"><th className="px-3 py-2">Mata Pelajaran</th><th className="px-3 py-2">Rincian Nilai</th><th className="px-3 py-2 text-right">Rata-rata</th></tr></thead>
              <tbody>
                {(transcript.data?.subjects ?? []).map((subject) => (
                  <tr key={subject.subject_id} className="border-t border-slate-100">
                    <td className="px-3 py-2 font-medium text-slate-700">{subject.name ?? '—'}</td>
                    <td className="px-3 py-2 text-slate-500">
                      {subject.scores.length === 0 ? '—' : subject.scores.map((score, index) => (
                        <span key={index}>{index > 0 ? '; ' : ''}{score.semester} {score.academic_year}: <strong className="text-slate-700">{formatNumber(score.score)}</strong></span>
                      ))}
                    </td>
                    <td className="px-3 py-2 text-right font-semibold">{formatNumber(subject.average)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
