import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck, Plus, Target } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiInsightApi, type CippRow } from '../api/supervisiInsightApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { api, getErrorMessage } from '@/lib/axios';
import type { ApiResponse } from '@/types';

interface ProgramOption {
  id: number;
  title: string;
  scope?: string | null;
}

const DIMENSIONS = [
  { key: 'context' as const, label: 'Context', hint: 'Kesesuaian tujuan dengan kebutuhan nyata sekolah.' },
  { key: 'input' as const, label: 'Input', hint: 'Kesiapan supervisor, instrumen, jadwal, dan anggaran.' },
  { key: 'process' as const, label: 'Process', hint: 'Cakupan, ketepatan waktu, mutu percakapan pasca-observasi.' },
  { key: 'product' as const, label: 'Product', hint: 'Perubahan nilai, tindak lanjut tuntas, dampak ke peserta didik.' },
];

const emptyForm = {
  period: '',
  context: '',
  input: '',
  process: '',
  product: '',
  context_score: '',
  input_score: '',
  process_score: '',
  product_score: '',
  stakeholders: '',
  finding: '',
  recommendation: '',
};

/** Evaluasi program supervisi dengan model CIPP (context, input, process, product). */
export default function SupervisiCippPage() {
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState<number | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [programId, setProgramId] = useState('');
  const [form, setForm] = useState(emptyForm);

  const listQuery = useQuery({
    queryKey: ['supervisi', 'evaluations'],
    queryFn: () => supervisiInsightApi.evaluations(),
  });

  const detailQuery = useQuery({
    queryKey: ['supervisi', 'evaluation', selected],
    queryFn: () => supervisiInsightApi.evaluationDetail(selected ?? 0),
    enabled: selected !== null,
  });

  const programsQuery = useQuery({
    queryKey: ['supervisi', 'programs', 'options'],
    queryFn: async () => {
      const response = await api.get<ApiResponse<{ rows: ProgramOption[] }>>('/supervisi/programs', { params: { per_page: 100 } });
      const payload = response.data.data;

      return payload.rows ?? [];
    },
    enabled: createOpen,
    staleTime: 5 * 60 * 1000,
  });

  useEffect(() => {
    const evaluation = detailQuery.data?.evaluation;
    if (!evaluation) return;

    setForm({
      period: evaluation.period ?? '',
      context: JSON.stringify(evaluation.context ?? {}, null, 2),
      input: JSON.stringify(evaluation.input ?? {}, null, 2),
      process: JSON.stringify(evaluation.process ?? {}, null, 2),
      product: JSON.stringify(evaluation.product ?? {}, null, 2),
      context_score: evaluation.scores.context?.toString() ?? '',
      input_score: evaluation.scores.input?.toString() ?? '',
      process_score: evaluation.scores.process?.toString() ?? '',
      product_score: evaluation.scores.product?.toString() ?? '',
      stakeholders: (evaluation.stakeholders ?? []).join(', '),
      finding: evaluation.finding ?? '',
      recommendation: evaluation.recommendation ?? '',
    });
  }, [detailQuery.data?.evaluation]);

  const toJson = (value: string): Record<string, unknown> | null => {
    if (value.trim() === '') return null;

    try {
      return JSON.parse(value) as Record<string, unknown>;
    } catch {
      return null;
    }
  };

  const payload = () => ({
    period: form.period || null,
    context: toJson(form.context),
    input: toJson(form.input),
    process: toJson(form.process),
    product: toJson(form.product),
    context_score: form.context_score === '' ? null : Number(form.context_score),
    input_score: form.input_score === '' ? null : Number(form.input_score),
    process_score: form.process_score === '' ? null : Number(form.process_score),
    product_score: form.product_score === '' ? null : Number(form.product_score),
    stakeholders: form.stakeholders === '' ? [] : form.stakeholders.split(',').map((item) => item.trim()).filter(Boolean),
    finding: form.finding || null,
    recommendation: form.recommendation || null,
  });

  const createMutation = useMutation({
    mutationFn: () => supervisiInsightApi.createEvaluation(Number(programId), payload()),
    onSuccess: (data) => {
      toast.success('Evaluasi CIPP dibuat');
      setCreateOpen(false);
      setSelected(data.evaluation.id);
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'evaluations'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveMutation = useMutation({
    mutationFn: () => supervisiInsightApi.saveEvaluation(selected ?? 0, payload()),
    onSuccess: () => {
      toast.success('Evaluasi tersimpan');
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'evaluations'] });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'evaluation', selected] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const finalizeMutation = useMutation({
    mutationFn: () => supervisiInsightApi.finalizeEvaluation(selected ?? 0),
    onSuccess: () => {
      toast.success('Evaluasi difinalkan');
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'evaluations'] });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'evaluation', selected] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows: CippRow[] = listQuery.data?.rows ?? [];
  const detail = detailQuery.data;
  const guide = detail?.guide ?? {};
  const editable = detail?.abilities.update ?? true;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Evaluasi Program (CIPP)</h1>
          <p className="text-sm text-slate-500">
            Menilai program supervisi dari empat sisi: konteks kebutuhan, kesiapan input, proses pelaksanaan, dan hasilnya.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="mr-2 h-4 w-4" /> Evaluasi Baru
        </Button>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card><CardBody>
          <div className="text-xs text-slate-500">Evaluasi</div>
          <div className="text-xl font-semibold text-slate-900">{String(listQuery.data?.summary.evaluations ?? 0)}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Sudah final</div>
          <div className="text-xl font-semibold text-slate-900">{String(listQuery.data?.summary.finalized ?? 0)}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Rata-rata CIPP</div>
          <div className="text-xl font-semibold text-slate-900">{String(listQuery.data?.summary.average ?? '—')}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Dimensi dinilai</div>
          <div className="text-xl font-semibold text-slate-900">4</div>
        </CardBody></Card>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader><CardTitle>Daftar Evaluasi</CardTitle></CardHeader>
          <CardBody className="space-y-2">
            {rows.length === 0 && <EmptyState title="Belum ada evaluasi CIPP" />}
            {rows.map((row) => (
              <button
                key={row.id}
                type="button"
                onClick={() => setSelected(row.id)}
                className={`w-full rounded-lg border p-3 text-left text-sm ${
                  selected === row.id ? 'border-brand-300 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="font-medium text-slate-800">{row.program ?? `Program #${row.program_id}`}</span>
                  <Badge tone={row.finalized_at ? 'green' : 'blue'}>{row.finalized_at ? 'final' : 'draf'}</Badge>
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  {row.period ?? 'tanpa periode'} · rata-rata {row.average ?? '—'}
                </div>
                <div className="mt-1 flex flex-wrap gap-1 text-[10px] text-slate-500">
                  {DIMENSIONS.map((dimension) => (
                    <span key={dimension.key} className="rounded bg-slate-100 px-1.5 py-0.5">
                      {dimension.label[0]}: {row.scores[dimension.key] ?? '—'}
                    </span>
                  ))}
                </div>
              </button>
            ))}
          </CardBody>
        </Card>

        <div className="lg:col-span-2">
          {detail === undefined ? (
            <Card>
              <CardBody>
                <EmptyState title="Pilih evaluasi untuk melihat rinciannya" description="Empat dimensi CIPP dinilai 0–100 dengan bukti dari data supervisi." />
              </CardBody>
            </Card>
          ) : (
            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Target className="h-4 w-4 text-brand-600" />
                    <CardTitle>{detail.evaluation.program ?? 'Evaluasi program'}</CardTitle>
                  </div>
                  <div className="flex items-center gap-2">
                    <Badge tone="brand">rata-rata CIPP {detail.evaluation.average ?? '—'}</Badge>
                    {detail.evaluation.finalized_at && <Badge tone="green">final</Badge>}
                  </div>
                </div>
              </CardHeader>
              <CardBody className="space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {DIMENSIONS.map((dimension) => (
                    <div key={dimension.key}>
                      <Label>{dimension.label} (0–100)</Label>
                      <Input
                        type="number"
                        min={0}
                        max={100}
                        disabled={!editable}
                        value={form[`${dimension.key}_score` as keyof typeof form]}
                        onChange={(event) => setForm((previous) => ({ ...previous, [`${dimension.key}_score`]: event.target.value }))}
                      />
                      <p className="mt-1 text-xs text-slate-500">{guide[dimension.key] ?? dimension.hint}</p>
                    </div>
                  ))}
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Periode</Label>
                    <Input disabled={!editable} value={form.period} onChange={(event) => setForm((previous) => ({ ...previous, period: event.target.value }))} placeholder="2026/2027 - Semester Ganjil" />
                  </div>
                  <div>
                    <Label>Pemangku kepentingan (pisahkan dengan koma)</Label>
                    <Input
                      disabled={!editable}
                      value={form.stakeholders}
                      onChange={(event) => setForm((previous) => ({ ...previous, stakeholders: event.target.value }))}
                      placeholder="kepala_sekolah, guru, pengawas"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {DIMENSIONS.map((dimension) => (
                    <div key={dimension.key}>
                      <Label>Bukti {dimension.label} (JSON)</Label>
                      <Textarea
                        rows={3}
                        disabled={!editable}
                        className="font-mono text-xs"
                        value={form[dimension.key as keyof typeof form]}
                        onChange={(event) => setForm((previous) => ({ ...previous, [dimension.key]: event.target.value }))}
                        placeholder='{"temuan": "..."}'
                      />
                    </div>
                  ))}
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Temuan</Label>
                    <Textarea rows={3} disabled={!editable} value={form.finding} onChange={(event) => setForm((previous) => ({ ...previous, finding: event.target.value }))} />
                  </div>
                  <div>
                    <Label>Rekomendasi (wajib saat finalisasi)</Label>
                    <Textarea rows={3} disabled={!editable} value={form.recommendation} onChange={(event) => setForm((previous) => ({ ...previous, recommendation: event.target.value }))} />
                  </div>
                </div>

                <div className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                  <div className="mb-1 font-medium text-slate-800">Bukti otomatis dari data supervisi</div>
                  <ul className="space-y-0.5">
                    <li>
                      Kegiatan final:{' '}
                      {String((detail.evidence.visits as Record<string, unknown> | undefined)?.finalized ?? 0)} · cakupan:{' '}
                      {String((detail.evidence.coverage as Record<string, unknown> | undefined)?.percent ?? '—')}%
                    </li>
                    <li>
                      Rata-rata nilai:{' '}
                      {String((detail.evidence.scores as Record<string, unknown> | undefined)?.average ?? '—')} · RTL tepat waktu:{' '}
                      {String((detail.evidence.follow_up as Record<string, unknown> | undefined)?.on_time_percent ?? '—')}%
                    </li>
                    <li>
                      Jam pengembangan:{' '}
                      {String((detail.evidence.growth as Record<string, unknown> | undefined)?.total_hours ?? 0)} jam
                    </li>
                  </ul>
                </div>

                {editable && (
                  <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={() => saveMutation.mutate()} disabled={saveMutation.isPending}>
                      {saveMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan
                    </Button>
                    <Button onClick={() => finalizeMutation.mutate()} disabled={finalizeMutation.isPending}>
                      <ClipboardCheck className="mr-2 h-4 w-4" /> Finalkan evaluasi
                    </Button>
                  </div>
                )}
              </CardBody>
            </Card>
          )}
        </div>
      </div>

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Evaluasi CIPP Baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>Batal</Button>
            <Button onClick={() => createMutation.mutate()} disabled={createMutation.isPending || programId === ''}>
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Buat
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <div>
            <Label required>Program yang dievaluasi</Label>
            <Select value={programId} onChange={(event) => setProgramId(event.target.value)}>
              <option value="">Pilih program...</option>
              {(programsQuery.data ?? []).map((program) => (
                <option key={program.id} value={program.id}>{program.title}</option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-slate-500">
              Evaluasi mengikuti periode programnya; angka bukti ditarik otomatis dari data supervisi pada rentang tersebut.
            </p>
          </div>
        </div>
      </Modal>
    </div>
  );
}
