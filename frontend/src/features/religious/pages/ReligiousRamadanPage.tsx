import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, MoonStar, Plus, RefreshCw, Sparkles } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage } from '@/lib/axios';
import type { ReligiousRamadanProgram } from '@/types/religious';
import { religiousApi } from '../api/religiousApi';
import { displayStatus, ErrorCard, FormField, localDate, LoadingCard, MetricCard, ReligiousPageHeader, statusTone } from '../components/ReligiousUi';

interface RamadanForm {
  name: string;
  hijri_year: string;
  gregorian_start: string;
  gregorian_end: string;
  mosque_id: string;
  expected_participants: string;
  budget: string;
  program_types: string[];
  notes: string;
}
const blankRamadan = (): RamadanForm => ({
  name: '', hijri_year: '', gregorian_start: '', gregorian_end: '', mosque_id: '',
  expected_participants: '', budget: '', program_types: ['TARAWIH', 'IFTAR', 'SUHOOR', 'TADARUS'], notes: '',
});
const RAMADAN_TYPES = ['TARAWIH', 'TAHAJJUD', 'TADARUS', 'IFTAR', 'SUHOOR', 'KAJIAN', 'QURAN_PROGRAM', 'CHARITY', 'ITIKAF', 'NIGHT_ACTIVITY'];

function scalarEntries(value: Record<string, unknown> | undefined) {
  return Object.entries(value ?? {}).filter(([, entry]) => entry === null || ['string', 'number', 'boolean'].includes(typeof entry));
}

export function ReligiousRamadanPage() {
  const queryClient = useQueryClient();
  const { can } = usePermission();
  const [date, setDate] = useState(localDate());
  const [formOpen, setFormOpen] = useState(false);
  const [form, setForm] = useState<RamadanForm>(blankRamadan);
  const [reportId, setReportId] = useState<number | null>(null);

  const mosqueQuery = useQuery({
    queryKey: ['religious', 'mosques', 'ramadan-options'],
    queryFn: () => religiousApi.listMosques({}),
    enabled: can('religious.mosque.view'),
  });
  const ramadanQuery = useQuery({
    queryKey: ['religious', 'ramadan', date],
    queryFn: () => religiousApi.ramadan(date),
  });
  const reportQuery = useQuery({
    queryKey: ['religious', 'ramadan-report', reportId],
    queryFn: () => religiousApi.ramadanReport(reportId!),
    enabled: reportId !== null && can('religious.report.view'),
  });

  const programs = ramadanQuery.data?.programs ?? [];
  const dashboardRows = useMemo(() => scalarEntries(ramadanQuery.data?.dashboard), [ramadanQuery.data?.dashboard]);

  const createMutation = useMutation({
    mutationFn: () => religiousApi.createRamadanProgram({
      name: form.name.trim() || undefined,
      hijri_year: form.hijri_year.trim() || undefined,
      gregorian_start: form.gregorian_start || undefined,
      gregorian_end: form.gregorian_end || undefined,
      mosque_id: form.mosque_id ? Number(form.mosque_id) : undefined,
      expected_participants: form.expected_participants ? Number(form.expected_participants) : undefined,
      budget: form.budget ? Number(form.budget) : undefined,
      program_types: form.program_types,
      notes: form.notes.trim() || undefined,
    }),
    onSuccess: () => {
      toast.success('Program Ramadan berhasil dibuat');
      setFormOpen(false);
      setForm(blankRamadan());
      queryClient.invalidateQueries({ queryKey: ['religious', 'ramadan'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });
  const generateMutation = useMutation({
    mutationFn: (id: number) => religiousApi.generateRamadanDays(id, 30),
    onSuccess: (_result, id) => {
      toast.success('Kalender Ramadan berhasil dibangkitkan');
      queryClient.invalidateQueries({ queryKey: ['religious', 'ramadan'] });
      if (can('religious.report.view')) setReportId(id);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const submitProgram = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (form.gregorian_start && form.gregorian_end && form.gregorian_end < form.gregorian_start) {
      toast.error('Tanggal akhir Ramadan tidak boleh sebelum tanggal mulai.');
      return;
    }
    createMutation.mutate();
  };

  const toggleType = (type: string) => {
    setForm((current) => ({
      ...current,
      program_types: current.program_types.includes(type)
        ? current.program_types.filter((item) => item !== type)
        : [...current.program_types, type],
    }));
  };

  return (
    <div className="space-y-5">
      <ReligiousPageHeader
        title="Program Ramadan"
        description="Rencanakan program Ramadan, kalender harian, dan pelaporan kegiatan. Praktik dan pengaturan ibadah mengikuti konfigurasi institusi."
        actions={
          <>
            <label className="sr-only" htmlFor="ramadan-dashboard-date">Tanggal dashboard Ramadan</label>
            <Input id="ramadan-dashboard-date" type="date" className="w-40" value={date} onChange={(event) => setDate(event.target.value)} />
            <Button variant="outline" size="icon" title="Muat ulang program" aria-label="Muat ulang program" onClick={() => ramadanQuery.refetch()}>
              <RefreshCw className={ramadanQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="religious.ramadan.manage"><Button onClick={() => { setForm(blankRamadan()); setFormOpen(true); }}><Plus className="h-4 w-4" /> Program baru</Button></Can>
          </>
        }
      />

      {ramadanQuery.isLoading ? (
        <LoadingCard label="Memuat program Ramadan..." />
      ) : ramadanQuery.isError ? (
        <ErrorCard message={getErrorMessage(ramadanQuery.error)} onRetry={() => ramadanQuery.refetch()} />
      ) : (
        <>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard label="Program terdaftar" value={programs.length} icon={<MoonStar className="h-5 w-5" />} />
            <MetricCard label="Tarawih" value={Number(ramadanQuery.data?.dashboard?.tarawih_sessions ?? 0)} detail="Sesi terjadwal pada tanggal yang dipilih" icon={<Sparkles className="h-5 w-5" />} tone="blue" />
            <MetricCard label="Iftar" value={Number(ramadanQuery.data?.dashboard?.iftar_events ?? 0)} detail="Kegiatan berbuka" icon={<CalendarDays className="h-5 w-5" />} tone="amber" />
            <MetricCard label="Peserta i'tikaf" value={Number(ramadanQuery.data?.dashboard?.itikaf_participants ?? 0)} detail="Data agregat" icon={<MoonStar className="h-5 w-5" />} tone="green" />
          </div>

          {dashboardRows.length > 0 && (
            <Card>
              <CardHeader><CardTitle className="text-sm">Ringkasan aktivitas</CardTitle></CardHeader>
              <CardBody className="flex flex-wrap gap-2">
                {dashboardRows.map(([key, value]) => <Badge key={key} tone="info">{key.replace(/_/g, ' ')}: {String(value ?? '—')}</Badge>)}
              </CardBody>
            </Card>
          )}

          {programs.length === 0 ? (
            <Card><EmptyState title="Belum ada program Ramadan" description="Buat program untuk mengatur kalender Ramadan dan kegiatan pendukung." /></Card>
          ) : (
            <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
              {programs.map((program) => (
                <RamadanProgramCard
                  key={program.id}
                  program={program}
                  canManage={can('religious.ramadan.manage')}
                  canReport={can('religious.report.view')}
                  generating={generateMutation.isPending}
                  onGenerate={() => generateMutation.mutate(program.id)}
                  onReport={() => setReportId(program.id)}
                />
              ))}
            </div>
          )}
        </>
      )}

      <Modal open={formOpen} onClose={() => setFormOpen(false)} title="Buat program Ramadan" size="lg" footer={<><Button variant="outline" onClick={() => setFormOpen(false)}>Batal</Button><Button type="submit" form="religious-ramadan-form" loading={createMutation.isPending}>Simpan program</Button></>}>
        <form id="religious-ramadan-form" onSubmit={submitProgram} className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <FormField label="Nama program"><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Program Ramadan sekolah" /></FormField>
          <FormField label="Tahun Hijriah"><Input value={form.hijri_year} onChange={(event) => setForm({ ...form, hijri_year: event.target.value })} placeholder="1447 H" /></FormField>
          <FormField label="Tanggal mulai"><Input type="date" value={form.gregorian_start} onChange={(event) => setForm({ ...form, gregorian_start: event.target.value })} /></FormField>
          <FormField label="Tanggal selesai"><Input type="date" value={form.gregorian_end} onChange={(event) => setForm({ ...form, gregorian_end: event.target.value })} /></FormField>
          {can('religious.mosque.view') && (
            <FormField label="Masjid (opsional)">
              <Select value={form.mosque_id} onChange={(event) => setForm({ ...form, mosque_id: event.target.value })}>
                <option value="">Tidak ditentukan</option>
                {(mosqueQuery.data ?? []).map((mosque) => <option key={mosque.id} value={mosque.id}>{mosque.name}</option>)}
              </Select>
            </FormField>
          )}
          <FormField label="Perkiraan peserta"><Input type="number" min="0" value={form.expected_participants} onChange={(event) => setForm({ ...form, expected_participants: event.target.value })} /></FormField>
          <FormField label="Anggaran (opsional)"><Input type="number" min="0" value={form.budget} onChange={(event) => setForm({ ...form, budget: event.target.value })} /></FormField>
          <div className="sm:col-span-2">
            <p className="mb-2 text-xs font-medium text-slate-600">Jenis program</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
              {RAMADAN_TYPES.map((type) => (
                <label key={type} className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-xs text-slate-700">
                  <input type="checkbox" checked={form.program_types.includes(type)} onChange={() => toggleType(type)} className="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600" />
                  {type.replace(/_/g, ' ')}
                </label>
              ))}
            </div>
          </div>
          <FormField label="Catatan" className="sm:col-span-2"><Textarea value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></FormField>
        </form>
      </Modal>

      <Modal open={reportId !== null} onClose={() => setReportId(null)} title="Laporan program Ramadan" size="lg">
        {reportQuery.isLoading ? <LoadingCard label="Memuat laporan..." /> : reportQuery.isError ? <p className="text-sm text-rose-700">{getErrorMessage(reportQuery.error)}</p> : reportQuery.data ? <ReportSummary report={reportQuery.data} /> : <p className="text-sm text-slate-500">Pilih program untuk melihat laporan.</p>}
      </Modal>
    </div>
  );
}

function RamadanProgramCard({
  program,
  canManage,
  canReport,
  generating,
  onGenerate,
  onReport,
}: {
  program: ReligiousRamadanProgram;
  canManage: boolean;
  canReport: boolean;
  generating: boolean;
  onGenerate: () => void;
  onReport: () => void;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <CardTitle>{program.name ?? `Program Ramadan ${program.hijri_year ?? ''}`}</CardTitle>
          <p className="mt-1 text-xs text-slate-500">{program.code ?? `ID ${program.id}`} · {program.hijri_year ?? 'Tahun Hijriah belum diisi'}</p>
        </div>
        <Badge tone={statusTone(program.status)}>{displayStatus(program.status)}</Badge>
      </CardHeader>
      <CardBody className="space-y-4">
        <div className="grid grid-cols-2 gap-3 text-sm">
          <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Mulai</p><p className="mt-1 font-medium text-slate-800">{program.gregorian_start ?? '—'}</p></div>
          <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Selesai</p><p className="mt-1 font-medium text-slate-800">{program.gregorian_end ?? '—'}</p></div>
          <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Perkiraan peserta</p><p className="mt-1 font-medium text-slate-800">{program.expected_participants ?? '—'}</p></div>
          <div className="rounded-lg bg-slate-50 p-3"><p className="text-xs text-slate-500">Masjid</p><p className="mt-1 font-medium text-slate-800">{program.mosque_id ?? '—'}</p></div>
        </div>
        <div className="flex flex-wrap gap-2">
          {canManage && <Button size="sm" variant="outline" onClick={onGenerate} loading={generating}><CalendarDays className="h-4 w-4" /> Bangkitkan 30 hari</Button>}
          {canReport && <Button size="sm" variant="ghost" onClick={onReport}>Lihat laporan</Button>}
        </div>
      </CardBody>
    </Card>
  );
}

function ReportSummary({ report }: { report: Record<string, unknown> }) {
  const entries = Object.entries(report);
  return (
    <div className="space-y-3">
      <p className="text-xs leading-5 text-slate-500">Laporan merangkum kegiatan program; nilai yang tidak tersedia ditampilkan apa adanya.</p>
      {entries.length === 0 ? <p className="text-sm text-slate-500">Belum ada data laporan.</p> : (
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
          {entries.map(([key, value]) => (
            <div key={key} className="rounded-lg border border-slate-200 p-3">
              <p className="text-xs capitalize text-slate-500">{key.replace(/_/g, ' ')}</p>
              <p className="mt-1 break-words text-sm font-semibold text-slate-800">{value && typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—')}</p>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
