import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { ArrowLeft, Calculator, CheckCircle2, RotateCcw, Save, Users } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiGrowthApi, type AppraisalIndicatorRow } from '../api/supervisiGrowthApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';

const RATINGS = [
  { value: 'DI_ATAS_EKSPEKTASI', label: 'Di atas ekspektasi' },
  { value: 'SESUAI_EKSPEKTASI', label: 'Sesuai ekspektasi' },
  { value: 'DI_BAWAH_EKSPEKTASI', label: 'Di bawah ekspektasi' },
];

const SOURCE_LABELS: Record<string, string> = {
  SUPERVISOR: 'Atasan',
  PEER: 'Rekan sejawat',
  SELF: 'Diri sendiri',
  SUBORDINATE: 'Bawahan',
  STUDENT: 'Peserta didik',
  PARENT: 'Orang tua',
};

/** Lembar kerja satu dokumen penilaian kinerja: skor, hitung, sumber, terbit. */
export default function SupervisiAppraisalDetailPage() {
  const { appraisalId } = useParams<{ appraisalId: string }>();
  const id = Number(appraisalId);
  const queryClient = useQueryClient();

  const [scores, setScores] = useState<Record<number, number | null>>({});
  const [comments, setComments] = useState<Record<number, string>>({});
  const [attendance, setAttendance] = useState('');
  const [ratings, setRatings] = useState({ result: '', behavior: '' });
  const [percents, setPercents] = useState({ result: '', behavior: '' });
  const [sources, setSources] = useState<Array<{ source: string; weight: number; score: number | null; comment?: string }>>([]);
  const [loaded, setLoaded] = useState(false);

  const detailQuery = useQuery({
    queryKey: ['supervisi', 'appraisal', id],
    queryFn: () => supervisiGrowthApi.appraisalDetail(id),
    enabled: Number.isFinite(id),
  });

  const detail = detailQuery.data;

  useEffect(() => {
    if (!detail || loaded) return;

    const next: Record<number, number | null> = {};
    const nextComments: Record<number, string> = {};
    for (const score of detail.appraisal.scores) {
      next[score.indicator_id] = score.score ?? null;
      if (score.comment) nextComments[score.indicator_id] = score.comment;
    }

    setScores(next);
    setComments(nextComments);
    setAttendance(detail.appraisal.attendance_absence_days === null || detail.appraisal.attendance_absence_days === undefined
      ? ''
      : String(detail.appraisal.attendance_absence_days));
    setRatings({
      result: detail.appraisal.result_rating ?? '',
      behavior: detail.appraisal.behavior_rating ?? '',
    });

    setSources(
      detail.sources.length > 0
        ? detail.sources.map((source) => ({
            source: source.source,
            weight: source.weight,
            score: source.score ?? null,
            comment: source.comment ?? '',
          }))
        : Object.entries(detail.source_weights).map(([key, weight]) => ({ source: key, weight: Number(weight), score: null })),
    );
    setLoaded(true);
  }, [detail, loaded]);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['supervisi', 'appraisal', id] });

  const saveMutation = useMutation({
    mutationFn: () =>
      supervisiGrowthApi.saveAppraisalScores(
        id,
        Object.entries(scores)
          .filter(([, value]) => value !== null && value !== undefined)
          .map(([indicatorId, value]) => ({
            indicator_id: Number(indicatorId),
            score: Number(value),
            comment: comments[Number(indicatorId)] || null,
          })),
      ),
    onSuccess: () => {
      toast.success('Skor tersimpan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const calculateMutation = useMutation({
    mutationFn: () =>
      supervisiGrowthApi.calculateAppraisal(id, {
        attendance_absence_days: attendance === '' ? null : Number(attendance),
        result_rating: ratings.result || null,
        behavior_rating: ratings.behavior || null,
        result_percent: percents.result === '' ? null : Number(percents.result),
        behavior_percent: percents.behavior === '' ? null : Number(percents.behavior),
      }),
    onSuccess: () => {
      toast.success('Nilai dihitung & langkah perhitungan disimpan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const sourcesMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.saveAppraisalSources(id, sources),
    onSuccess: () => {
      toast.success('Penilaian multi-sumber tersimpan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const publishMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.publishAppraisal(id),
    onSuccess: () => {
      toast.success('Hasil diterbitkan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const reopenMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.reopenAppraisal(id, 'Koreksi setelah peninjauan ulang'),
    onSuccess: () => {
      toast.success('Dokumen dibuka ulang');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const totalWeight = useMemo(() => sources.reduce((sum, row) => sum + Number(row.weight || 0), 0), [sources]);
  const answered = useMemo(() => Object.values(scores).filter((value) => value !== null && value !== undefined).length, [scores]);

  if (detailQuery.isLoading) {
    return <Card><div className="flex items-center justify-center gap-2 py-16 text-slate-400"><Spinner /> Memuat dokumen...</div></Card>;
  }

  if (detailQuery.isError || !detail) {
    return <Card><CardBody><EmptyState title="Dokumen tidak ditemukan" /></CardBody></Card>;
  }

  const appraisal = detail.appraisal;
  const steps = (appraisal.snapshot?.steps ?? {}) as Record<string, any>;
  const editable = detail.abilities.score;

  return (
    <div className="space-y-4 pb-16">
      <div className="flex flex-col gap-2">
        <Link to="/supervisi/appraisals" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
          <ArrowLeft className="h-4 w-4" /> Daftar penilaian
        </Link>
        <h1 className="text-lg font-semibold text-slate-900">
          {appraisal.appraisal_type} — {appraisal.supervisee?.full_name ?? '-'}
        </h1>
        <div className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
          <Badge tone={appraisal.status === 'PUBLISHED' ? 'green' : 'blue'}>{appraisal.status}</Badge>
          {detail.instrument && <span>{detail.instrument.name} (v{detail.instrument.version})</span>}
          <span>
            {appraisal.period_start ? new Date(appraisal.period_start).toLocaleDateString('id-ID') : '-'} –{' '}
            {appraisal.period_end ? new Date(appraisal.period_end).toLocaleDateString('id-ID') : '-'}
          </span>
          {appraisal.published_at && <span>• terbit {new Date(appraisal.published_at).toLocaleDateString('id-ID')}</span>}
        </div>
      </div>

      <Card>
        <CardHeader><CardTitle>Hasil Perhitungan</CardTitle></CardHeader>
        <CardBody className="space-y-3">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Skor mentah</div>
              <div className="text-lg font-semibold text-slate-900">{appraisal.raw_score ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Maksimum</div>
              <div className="text-lg font-semibold text-slate-900">{appraisal.max_score ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Nilai akhir</div>
              <div className="text-lg font-semibold text-slate-900">{appraisal.final_score ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Predikat</div>
              <div className="text-sm font-semibold text-slate-900">{appraisal.grade_label ?? appraisal.final_predicate ?? '—'}</div>
            </div>
            <div className="rounded-lg bg-slate-50 p-3">
              <div className="text-xs text-slate-500">Angka kredit</div>
              <div className="text-sm font-semibold text-slate-900">
                {appraisal.credit_percent === null || appraisal.credit_percent === undefined ? '—' : `${appraisal.credit_percent}%`}
              </div>
            </div>
          </div>

          <ul className="space-y-1 text-xs text-slate-500">
            {steps.converted !== null && steps.converted !== undefined && (
              <li>Konversi 100: {String(steps.converted)}</li>
            )}
            {steps.attendance && (
              <li>
                Koreksi kehadiran: {String(steps.attendance.absence_days)} hari tanpa keterangan dari {String(steps.attendance.working_days)} hari
                kerja → {String(steps.attendance.percent)}% (nilai {String(steps.after_attendance)})
              </li>
            )}
            {steps.supervision?.average && (
              <li>
                Kontribusi supervisi {String(steps.supervision.weight)}% dari rata-rata {String(steps.supervision.average)} ={' '}
                {String(steps.supervision.contribution)} ({String(steps.supervision.visits)} kegiatan terfinalkan)
              </li>
            )}
            {steps.multi_source?.score && (
              <li>
                Multi-sumber: total bobot {String(steps.multi_source.total_weight)}% → {String(steps.multi_source.score)}
              </li>
            )}
            {steps.skp && <li>Kuadran SKP: {String(steps.skp.result_rating)} × {String(steps.skp.behavior_rating)} → {String(steps.skp.label)}</li>}
          </ul>

          <div className="flex flex-wrap items-center gap-2">
            {editable && (
              <>
                <Button variant="outline" onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
                  {saveMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Save className="mr-2 h-4 w-4" />} Simpan skor
                </Button>
                <Button onClick={() => calculateMutation.mutate()} disabled={calculateMutation.isPending}>
                  {calculateMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Calculator className="mr-2 h-4 w-4" />} Hitung nilai
                </Button>
              </>
            )}
            {detail.abilities.publish && appraisal.status !== 'PUBLISHED' && (
              <Button variant="outline" onClick={() => publishMutation.mutate()} disabled={publishMutation.isPending}>
                <CheckCircle2 className="mr-2 h-4 w-4" /> Terbitkan hasil
              </Button>
            )}
            {detail.abilities.reopen && (
              <Button variant="outline" onClick={() => reopenMutation.mutate()} disabled={reopenMutation.isPending}>
                <RotateCcw className="mr-2 h-4 w-4" /> Buka ulang untuk koreksi
              </Button>
            )}
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <CardTitle>Penilaian Per Kompetensi</CardTitle>
            <span className="text-sm text-slate-500">{answered}/{detail.indicators.length} terisi</span>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          {detail.indicators.length === 0 && <p className="text-sm text-slate-500">Dokumen ini tidak memakai instrumen (jenis SKP).</p>}
          {detail.indicators.map((indicator: AppraisalIndicatorRow, index) => (
            <div key={indicator.id} className="rounded-lg border border-slate-200 p-3">
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="flex-1">
                  <div className="text-sm font-medium text-slate-800">{index + 1}. {indicator.statement}</div>
                  <div className="text-xs text-slate-500">{indicator.section} • maks {indicator.max_score}</div>
                  {indicator.guidance && <p className="mt-1 text-xs text-slate-400">{indicator.guidance}</p>}
                </div>
                <Badge tone="brand">
                  {scores[indicator.id] ?? '—'} / {indicator.max_score}
                </Badge>
              </div>

              <div className="mt-2 flex flex-wrap gap-2">
                {indicator.options.map((option) => (
                  <button
                    key={option.id}
                    type="button"
                    disabled={!editable}
                    onClick={() => setScores((previous) => ({ ...previous, [indicator.id]: option.score }))}
                    className={`rounded-lg border px-3 py-1.5 text-sm ${
                      scores[indicator.id] === option.score
                        ? 'border-brand-300 bg-brand-50 font-medium text-brand-700'
                        : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                    } ${editable ? '' : 'opacity-60'}`}
                  >
                    {option.label} <span className="ml-1 text-xs text-slate-500">{option.score}</span>
                  </button>
                ))}

                {indicator.options.length === 0 && (
                  <div className="w-32">
                    <Input
                      type="number"
                      disabled={!editable}
                      value={scores[indicator.id] ?? ''}
                      onChange={(event) => setScores((previous) => ({
                        ...previous,
                        [indicator.id]: event.target.value === '' ? null : Number(event.target.value),
                      }))}
                    />
                  </div>
                )}
              </div>

              <div className="mt-2">
                <Textarea
                  rows={2}
                  disabled={!editable}
                  value={comments[indicator.id] ?? ''}
                  placeholder="Catatan bukti/umpan balik (opsional)"
                  onChange={(event) => setComments((previous) => ({ ...previous, [indicator.id]: event.target.value }))}
                />
              </div>
            </div>
          ))}
        </CardBody>
      </Card>

      {editable && (
        <Card>
          <CardHeader><CardTitle>Koreksi &amp; Kuadran</CardTitle></CardHeader>
          <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <Label>Hari tanpa keterangan (alfa)</Label>
              <Input type="number" min={0} value={attendance} onChange={(event) => setAttendance(event.target.value)} />
              <p className="mt-1 text-xs text-slate-500">Dipakai pada koreksi kehadiran PKG/NKKS bila fitur aktif.</p>
            </div>
            <div>
              <Label>Rating hasil kerja</Label>
              <Select value={ratings.result} onChange={(event) => setRatings((previous) => ({ ...previous, result: event.target.value }))}>
                <option value="">— tidak diisi —</option>
                {RATINGS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </Select>
              <div className="mt-2">
                <Input
                  placeholder="atau persen capaian, mis. 105"
                  value={percents.result}
                  onChange={(event) => setPercents((previous) => ({ ...previous, result: event.target.value }))}
                />
              </div>
            </div>
            <div>
              <Label>Rating perilaku kerja</Label>
              <Select value={ratings.behavior} onChange={(event) => setRatings((previous) => ({ ...previous, behavior: event.target.value }))}>
                <option value="">— tidak diisi —</option>
                {RATINGS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </Select>
              <div className="mt-2">
                <Input
                  placeholder="atau persen, mis. 95"
                  value={percents.behavior}
                  onChange={(event) => setPercents((previous) => ({ ...previous, behavior: event.target.value }))}
                />
              </div>
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <Users className="h-4 w-4 text-brand-600" />
              <CardTitle>Penilaian Multi-Sumber (360°)</CardTitle>
            </div>
            <span className={`text-sm ${Math.abs(totalWeight - 100) > 0.01 ? 'text-amber-600' : 'text-slate-500'}`}>
              total bobot {totalWeight}%
            </span>
          </div>
        </CardHeader>
        <CardBody className="space-y-3">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-medium">Sumber</th>
                  <th className="px-3 py-2 font-medium">Bobot (%)</th>
                  <th className="px-3 py-2 font-medium">Skor (0–100)</th>
                  <th className="px-3 py-2 font-medium">Catatan</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {sources.map((row, index) => (
                  <tr key={row.source}>
                    <td className="px-3 py-2">{SOURCE_LABELS[row.source] ?? row.source}</td>
                    <td className="px-3 py-2 w-24">
                      <Input
                        type="number"
                        disabled={!editable}
                        value={row.weight}
                        onChange={(event) => setSources((previous) => previous.map((item, i) => (
                          i === index ? { ...item, weight: Number(event.target.value) } : item
                        )))}
                      />
                    </td>
                    <td className="px-3 py-2 w-28">
                      <Input
                        type="number"
                        disabled={!editable}
                        value={row.score ?? ''}
                        onChange={(event) => setSources((previous) => previous.map((item, i) => (
                          i === index
                            ? { ...item, score: event.target.value === '' ? null : Number(event.target.value) }
                            : item
                        )))}
                      />
                    </td>
                    <td className="px-3 py-2">
                      <Input
                        disabled={!editable}
                        value={row.comment ?? ''}
                        onChange={(event) => setSources((previous) => previous.map((item, i) => (
                          i === index ? { ...item, comment: event.target.value } : item
                        )))}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {editable && (
            <Button variant="outline" onClick={() => sourcesMutation.mutate()} disabled={sourcesMutation.isPending}>
              {sourcesMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan multi-sumber
            </Button>
          )}
          <p className="text-xs text-slate-500">
            Bila normalisasi otomatis dimatikan sekolah, jumlah bobot wajib tepat 100%. Sumber tanpa skor diabaikan dari perhitungan.
          </p>
        </CardBody>
      </Card>
    </div>
  );
}
