import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Download, FileCheck2, RefreshCw, Search } from 'lucide-react';
import { toast } from 'sonner';
import { reportCardsApi } from '../api/reportCardsApi';
import { reportStatusTone } from '../lib/labels';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';

const REPORT_TYPES = [
  { value: 'tengah_semester', label: 'Tengah Semester' },
  { value: 'semester', label: 'Semester' },
  { value: 'akhir', label: 'Akhir Tahun' },
];
const REPORT_STATUSES = [
  { value: '', label: 'Semua status' },
  { value: 'draft', label: 'Draft' },
  { value: 'in_progress', label: 'Sedang berjalan' },
  { value: 'ready_for_review', label: 'Menunggu review' },
  { value: 'approved', label: 'Disetujui' },
  { value: 'published', label: 'Diterbitkan' },
];

function formatScore(score: number | null) {
  return score === null ? '—' : new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(score);
}

export function ReportCardsPage() {
  const queryClient = useQueryClient();
  const [classId, setClassId] = useState<number | ''>('');
  const [semesterId, setSemesterId] = useState<number | ''>('');
  const [reportType, setReportType] = useState('semester');
  const [generateType, setGenerateType] = useState('semester');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const lookupsQuery = useQuery({
    queryKey: ['report-cards', 'lookups'],
    staleTime: 5 * 60_000,
    queryFn: () => reportCardsApi.lookups(),
  });
  const reportsQuery = useQuery({
    queryKey: ['report-cards', page, classId, semesterId, reportType, status, search],
    queryFn: () => reportCardsApi.list({
      page, per_page: 15,
      class_id: classId,
      semester_id: semesterId,
      type: reportType || undefined,
      status: status || undefined,
      search: search || undefined,
    }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['report-cards'] });
    if (selectedId) queryClient.invalidateQueries({ queryKey: ['report-card', selectedId] });
  };
  const generateMutation = useMutation({
    mutationFn: () => reportCardsApi.generate({ class_id: Number(classId), semester_id: Number(semesterId), type: generateType }),
    onSuccess: (result) => { toast.success(`${result.generated} rapor berhasil digenerate sebagai draft`); setPage(1); invalidate(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const applySearch = (event: FormEvent) => { event.preventDefault(); setSearch(searchInput.trim()); setPage(1); };
  const classes = lookupsQuery.data?.classes ?? [];
  const semesters = lookupsQuery.data?.semesters ?? [];
  const reports = reportsQuery.data?.data ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Rapor</h1>
          <p className="text-sm text-slate-500">Buat snapshot nilai dan presensi, lakukan review, lalu terbitkan rapor siswa.</p>
        </div>
        <Button size="icon" variant="ghost" title="Muat ulang" onClick={() => reportsQuery.refetch()}><RefreshCw className={reportsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} /></Button>
      </div>

      <Card className="space-y-3 p-4">
        <div className="flex items-center gap-2"><FileCheck2 className="h-4 w-4 text-brand-600" /><h2 className="text-sm font-semibold text-slate-800">Generate Rapor Kelas</h2></div>
        <p className="text-xs text-slate-500">Nilai hanya diambil setelah semua penilaian terkait difinalisasi. Generate ulang akan memperbarui draft yang belum diterbitkan.</p>
        {lookupsQuery.isLoading ? <div className="flex items-center gap-2 py-3 text-sm text-slate-400"><Spinner /> Memuat kelas dan semester...</div> : lookupsQuery.isError ? <p className="text-sm text-red-600">{getErrorMessage(lookupsQuery.error)}</p> : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1fr_1fr_auto]">
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Kelas</label><Select value={classId} onChange={(event) => { setClassId(event.target.value ? Number(event.target.value) : ''); const current = classes.find((row) => row.id === Number(event.target.value)); if (current) { const suggested = semesters.find((semester) => semester.academic_year_id === current.academic_year_id); if (suggested) setSemesterId(suggested.id); } }}><option value="">Pilih kelas</option>{classes.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.code}</option>)}</Select></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Semester</label><Select value={semesterId} onChange={(event) => setSemesterId(event.target.value ? Number(event.target.value) : '')}><option value="">Pilih semester</option>{semesters.map((row) => <option key={row.id} value={row.id}>{row.name} · {row.academic_year?.name ?? ''}</option>)}</Select></div>
            <div><label className="mb-1 block text-xs font-medium text-slate-600">Jenis Rapor</label><Select value={generateType} onChange={(event) => setGenerateType(event.target.value)}>{REPORT_TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select></div>
            <div className="flex items-end"><Can perm="report-card.generate"><Button className="w-full" onClick={() => { if (!classId || !semesterId) { toast.error('Pilih kelas dan semester terlebih dahulu.'); return; } generateMutation.mutate(); }} loading={generateMutation.isPending}>Generate Draft</Button></Can></div>
          </div>
        )}
      </Card>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <form onSubmit={applySearch} className="flex items-center gap-2 lg:col-span-2">
            <div className="relative w-full"><Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" /><Input value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Cari nama atau NIS siswa..." className="pl-9" /></div>
            <Button type="submit" variant="outline">Cari</Button>
          </form>
          <Select value={status} onChange={(event) => { setStatus(event.target.value); setPage(1); }}>{REPORT_STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select>
          <Select value={reportType} onChange={(event) => { setReportType(event.target.value); setPage(1); }}>{REPORT_TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</Select>
        </div>
      </Card>

      <Card>
        {reportsQuery.isLoading ? <div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat rapor...</div>
          : reportsQuery.isError ? <EmptyState title="Gagal memuat rapor" description={getErrorMessage(reportsQuery.error)} action={<Button size="sm" variant="outline" className="mt-2" onClick={() => reportsQuery.refetch()}>Coba lagi</Button>} />
            : reports.length === 0 ? <EmptyState title="Belum ada rapor" description="Pilih kelas dan semester di atas, lalu generate rapor draft." />
              : <div className="overflow-x-auto"><table className="w-full text-sm">
                <thead><tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500"><th className="px-4 py-3">Siswa</th><th className="px-4 py-3">Kelas / Periode</th><th className="px-4 py-3">Jenis</th><th className="px-4 py-3 text-right">Rata-rata</th><th className="px-4 py-3 text-center">Peringkat</th><th className="px-4 py-3">Status</th><th className="px-4 py-3 text-right">Aksi</th></tr></thead>
                <tbody>{reports.map((report) => <tr key={report.id} className="border-b border-slate-50 hover:bg-slate-50/60"><td className="px-4 py-3"><div className="font-medium text-slate-800">{report.student?.full_name ?? '—'}</div><div className="font-mono text-xs text-slate-400">{report.student?.nis ?? '—'}</div></td><td className="px-4 py-3"><div className="font-medium text-slate-700">{report.school_class?.name ?? '—'}</div><div className="text-xs text-slate-400">{report.semester?.name ?? '—'} · {report.academic_year?.name ?? ''}</div></td><td className="px-4 py-3 text-slate-600">{report.type_label}</td><td className="px-4 py-3 text-right font-semibold">{formatScore(report.average_score)}</td><td className="px-4 py-3 text-center">{report.rank ?? '—'}</td><td className="px-4 py-3"><Badge tone={reportStatusTone(report.status)}>{report.status_label}</Badge></td><td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={() => setSelectedId(report.id)}>Detail</Button></td></tr>)}</tbody>
              </table></div>}
        {reportsQuery.data?.meta && <Pagination meta={reportsQuery.data.meta} onPageChange={setPage} />}
      </Card>

      <ReportCardDetailModal
        id={selectedId}
        open={selectedId !== null}
        onClose={() => setSelectedId(null)}
        onChanged={invalidate}
      />
    </div>
  );
}

function ReportCardDetailModal({ id, open, onClose, onChanged }: { id: number | null; open: boolean; onClose: () => void; onChanged: () => void }) {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const detail = useQuery({
    queryKey: ['report-card', id],
    enabled: open && id !== null,
    queryFn: () => reportCardsApi.show(id!),
  });
  const [notes, setNotes] = useState('');
  const [homeroomNote, setHomeroomNote] = useState('');
  const [busyAction, setBusyAction] = useState('');

  useEffect(() => {
    if (!detail.data) return;
    setNotes(detail.data.notes ?? '');
    setHomeroomNote(detail.data.homeroom_note ?? '');
  }, [detail.data]);

  const run = async (action: 'save' | 'ready' | 'approve' | 'publish' | 'pdf') => {
    if (id === null) return;
    setBusyAction(action);
    try {
      if (action === 'save') await reportCardsApi.update(id, { notes, homeroom_note: homeroomNote });
      if (action === 'ready') await reportCardsApi.ready(id);
      if (action === 'approve') await reportCardsApi.approve(id);
      if (action === 'publish') await reportCardsApi.publish(id);
      if (action === 'pdf') {
        const blob = await reportCardsApi.pdf(id);
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = `rapor-${detail.data?.student?.nis ?? id}.pdf`;
        anchor.click();
        URL.revokeObjectURL(url);
      }
      if (action !== 'pdf') {
        toast.success(action === 'save' ? 'Catatan rapor disimpan' : action === 'ready' ? 'Rapor diajukan untuk review' : action === 'approve' ? 'Rapor disetujui' : 'Rapor diterbitkan');
        await queryClient.invalidateQueries({ queryKey: ['report-card', id] });
        onChanged();
      }
    } catch (error) {
      toast.error(getErrorMessage(error));
    } finally {
      setBusyAction('');
    }
  };

  const report = detail.data;
  const canEdit = can('report-card.update') && !!report && !['approved', 'published'].includes(report.status);

  return (
    <Modal open={open} onClose={onClose} title={report ? `Rapor · ${report.student?.full_name ?? ''}` : 'Detail Rapor'} size="xl">
      {detail.isLoading ? <div className="flex justify-center py-16"><Spinner /></div> : detail.isError ? <EmptyState title="Gagal memuat detail rapor" description={getErrorMessage(detail.error)} /> : report ? (
        <div className="space-y-4">
          <div className="flex flex-col gap-3 rounded-lg bg-slate-50 p-4 sm:flex-row sm:items-center sm:justify-between">
            <div><p className="font-semibold text-slate-800">{report.student?.full_name} · {report.student?.nis}</p><p className="text-xs text-slate-500">{report.school_class?.name} · {report.semester?.name} {report.academic_year?.name}</p></div>
            <div className="flex items-center gap-2"><Badge tone={reportStatusTone(report.status)}>{report.status_label}</Badge><Button size="sm" variant="outline" onClick={() => void run('pdf')} loading={busyAction === 'pdf'}><Download className="h-4 w-4" /> PDF</Button></div>
          </div>
          <div className="overflow-x-auto rounded-lg border border-slate-200"><table className="w-full text-sm"><thead><tr className="bg-slate-50 text-left text-xs uppercase text-slate-500"><th className="px-3 py-2">Mata Pelajaran</th><th className="px-3 py-2 text-right">Nilai</th><th className="px-3 py-2">Huruf</th><th className="px-3 py-2">Predikat</th><th className="px-3 py-2">Catatan Guru</th></tr></thead><tbody>{(report.items ?? []).map((item) => <tr key={item.id} className="border-t border-slate-100"><td className="px-3 py-2 font-medium">{item.subject?.name ?? '—'}</td><td className="px-3 py-2 text-right">{formatScore(item.score)}</td><td className="px-3 py-2">{item.grade ?? '—'}</td><td className="px-3 py-2">{item.predicate ?? '—'}</td><td className="px-3 py-2">{item.teacher_notes ?? '—'}</td></tr>)}</tbody><tfoot><tr className="border-t bg-slate-50 font-semibold"><td className="px-3 py-2">Rata-rata kelas</td><td className="px-3 py-2 text-right">{formatScore(report.average_score)}</td><td colSpan={3} className="px-3 py-2">Peringkat: {report.rank ?? '—'}</td></tr></tfoot></table></div>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">{[
            ['Hadir', report.attendance_summary?.present ?? 0], ['Terlambat', report.attendance_summary?.late ?? 0], ['Sakit', report.attendance_summary?.sick ?? 0], ['Izin', report.attendance_summary?.permitted ?? 0], ['Alpa', report.attendance_summary?.absent ?? 0],
          ].map(([label, value]) => <div key={label} className="rounded-lg border border-slate-200 p-3"><p className="text-xs text-slate-500">{label}</p><p className="mt-1 font-semibold text-slate-800">{value}</p></div>)}</div>
          <div className="text-xs text-slate-500">Persentase kehadiran: {formatScore(report.attendance_summary?.percentage ?? 0)}% · Basis perhitungan: {report.attendance_summary?.basis === 'effective_days' ? 'hari efektif' : 'hari tercatat'}</div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2"><div><label className="mb-1 block text-xs font-medium text-slate-600">Catatan Sekolah</label><Textarea value={notes} disabled={!canEdit} onChange={(event) => setNotes(event.target.value)} /></div><div><label className="mb-1 block text-xs font-medium text-slate-600">Catatan Wali Kelas</label><Textarea value={homeroomNote} disabled={!canEdit} onChange={(event) => setHomeroomNote(event.target.value)} /></div></div>
          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-100 pt-3">
            {canEdit && <Button variant="outline" onClick={() => void run('save')} loading={busyAction === 'save'}>Simpan Catatan</Button>}
            {report.status === 'draft' && <Can perm="report-card.update"><Button onClick={() => void run('ready')} loading={busyAction === 'ready'}>Ajukan Review</Button></Can>}
            {report.status === 'ready_for_review' && <Can perm="report-card.approve"><Button onClick={() => void run('approve')} loading={busyAction === 'approve'}>Setujui Rapor</Button></Can>}
            {report.status === 'approved' && <Can perm="report-card.publish"><Button onClick={() => void run('publish')} loading={busyAction === 'publish'}>Terbitkan Rapor</Button></Can>}
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
