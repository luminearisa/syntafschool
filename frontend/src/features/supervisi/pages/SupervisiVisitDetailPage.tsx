import { useEffect, useMemo, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { ArrowLeft, Camera, CheckCircle2, ClipboardList, RefreshCw, Save, Send, Trash2, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { isAxiosError } from 'axios';
import { supervisiVisitApi } from '../api/supervisiVisitApi';
import { supervisiGrowthApi } from '../api/supervisiGrowthApi';
import type {
  DraftAnswer,
  VisitConferenceRow,
  VisitDetail,
  VisitFindingRow,
  VisitIndicatorRow,
} from '../types';
import { VisitResultPanel } from '../components/VisitResultPanel';
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
import { Can } from '@/components/common/Can';

const TONE_CLASS: Record<string, string> = {
  GREEN: 'border-green-300 bg-green-50 text-green-800',
  AMBER: 'border-amber-300 bg-amber-50 text-amber-800',
  RED: 'border-red-300 bg-red-50 text-red-800',
  GRAY: 'border-slate-300 bg-slate-50 text-slate-700',
};

const COMPONENTS = [
  { value: 'PERENCANAAN', label: 'Perencanaan' },
  { value: 'PELAKSANAAN', label: 'Pelaksanaan' },
  { value: 'PENILAIAN', label: 'Penilaian' },
  { value: 'PENGELOLAAN_KELAS', label: 'Pengelolaan Kelas' },
  { value: 'SIKAP_PROFESIONAL', label: 'Sikap Profesional' },
  { value: 'ADMINISTRASI', label: 'Administrasi' },
  { value: 'MANAJERIAL', label: 'Manajerial' },
  { value: 'SARANA_PRASARANA', label: 'Sarana & Prasarana' },
  { value: 'LAINNYA', label: 'Lainnya' },
];

const PRIORITIES = [
  { value: 'LOW', label: 'Rendah' },
  { value: 'MEDIUM', label: 'Sedang' },
  { value: 'HIGH', label: 'Tinggi' },
  { value: 'URGENT', label: 'Mendesak' },
];

/** Ambil daftar kesalahan validasi (mis. checklist finalisasi) dari respons backend. */
function validationList(error: unknown, key = 'checklist'): string[] {
  if (isAxiosError(error)) {
    const errors = error.response?.data?.errors as Record<string, string[]> | undefined;
    if (errors) {
      const first = errors[key];

      return Array.isArray(first) ? first : Object.values(errors).flat();
    }
  }

  return [getErrorMessage(error)];
}

/** Halaman pelaksanaan satu kegiatan supervisi (pra-observasi → observasi → analisis → hasil). */
export default function SupervisiVisitDetailPage() {
  const { visitId } = useParams<{ visitId: string }>();
  const id = Number(visitId);
  const queryClient = useQueryClient();
  const navigate = useNavigate();

  const [tab, setTab] = useState<'OBSERVE' | 'ANALYZE' | 'RESULT'>('OBSERVE');
  const [drafts, setDrafts] = useState<Record<number, DraftAnswer>>({});
  const [dirty, setDirty] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<Record<number, Record<string, string>>>({});
  const [findings, setFindings] = useState<VisitFindingRow[]>([]);
  const [finalNotes, setFinalNotes] = useState({ summary: '', strengths: '', improvements: '' });
  const [checklistIssues, setChecklistIssues] = useState<string[]>([]);
  const [conference, setConference] = useState<{ kind: 'PRE' | 'POST'; held_at: string; reflection: string; next_focus: string; agreements: string; questions: string }>({
    kind: 'PRE',
    held_at: '',
    reflection: '',
    next_focus: '',
    agreements: '',
    questions: '',
  });
  const [evidenceFile, setEvidenceFile] = useState<File | null>(null);
  const [evidenceLink, setEvidenceLink] = useState('');

  const initialized = useRef<string | null>(null);

  const detailQuery = useQuery({
    queryKey: ['supervisi', 'visit', id],
    queryFn: () => supervisiVisitApi.detail(id),
    enabled: Number.isFinite(id),
  });

  const detail: VisitDetail | undefined = detailQuery.data;

  // Muat ulang draf hanya ketika kegiatan/revisi berubah, bukan setiap kali
  // data diambil ulang (agar isian yang belum disimpan tidak hilang).
  useEffect(() => {
    if (!detail) return;

    const key = `${detail.visit.id}:${detail.visit.revision}:${detail.visit.status}`;
    if (initialized.current === key) return;
    initialized.current = key;

    const next: Record<number, DraftAnswer> = {};
    for (const answer of detail.answers) {
      next[answer.indicator_id] = {
        indicator_id: answer.indicator_id,
        option_id: answer.option_id ?? null,
        option_ids: answer.value_json?.option_ids ?? [],
        value_number: answer.value_number ?? null,
        value_text: answer.value_text ?? null,
        value_long_text: answer.value_long_text ?? null,
        value_date: answer.value_date ?? null,
        is_na: answer.is_na,
        comment: answer.comment ?? '',
        evidence_note: answer.evidence_note ?? '',
      };
    }
    setDrafts(next);
    setFindings(detail.findings.map((finding) => ({ ...finding })));
    setDirty(false);
    setFieldErrors({});
    setFinalNotes({
      summary: detail.visit.summary ?? '',
      strengths: detail.visit.strengths ?? '',
      improvements: detail.visit.improvements ?? '',
    });
    setChecklistIssues(detail.checklist);
    setTab(detail.visit.is_finalized ? 'RESULT' : detail.answers.length > 0 ? 'OBSERVE' : 'OBSERVE');
  }, [detail]);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['supervisi', 'visit', id] });

  const saveMutation = useMutation({
    mutationFn: (answers: DraftAnswer[]) => supervisiVisitApi.saveAnswers(id, answers),
    onSuccess: (result) => {
      toast.success('Jawaban tersimpan');
      setDirty(false);
      setFieldErrors({});
      setChecklistIssues(result.checklist);
      invalidate();
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'visits'] });
    },
    onError: (error) => {
      if (isAxiosError(error)) {
        const errors = (error.response?.data?.errors ?? {}) as Record<string, string[]>;
        const mapped: Record<number, Record<string, string>> = {};
        let order = 0;

        for (const key of Object.keys(errors)) {
          const match = /^answers\.(\d+)\.(.+)$/.exec(key);
          if (!match) {
            setChecklistIssues(Object.values(errors).flat());
            continue;
          }
          const indicatorId = payloadRef.current[Number(match[1])]?.indicator_id;
          if (indicatorId) {
            mapped[indicatorId] = { ...(mapped[indicatorId] ?? {}), [match[2]]: errors[key][0] };
          }
          order += 1;
        }

        if (order === 0) {
          toast.error(getErrorMessage(error));
        } else {
          setFieldErrors(mapped);
          toast.error('Ada jawaban yang belum lengkap.');
        }
      } else {
        toast.error(getErrorMessage(error));
      }
    },
  });

  const payloadRef = useRef<DraftAnswer[]>([]);

  const submitMutation = useMutation({
    mutationFn: () => supervisiVisitApi.submit(id),
    onSuccess: () => {
      toast.success('Kegiatan dikirim untuk dianalisis');
      invalidate();
      setTab('ANALYZE');
    },
    onError: (error) => {
      setChecklistIssues(validationList(error, 'answers'));
      toast.error(getErrorMessage(error));
    },
  });

  const findingsMutation = useMutation({
    mutationFn: (rows: VisitFindingRow[]) => supervisiVisitApi.saveFindings(id, rows),
    onSuccess: () => {
      toast.success('Temuan tersimpan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const conferenceMutation = useMutation({
    mutationFn: () =>
      supervisiVisitApi.recordConference(id, conference.kind, {
        held_at: conference.held_at || null,
        agreements: conference.agreements
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean),
        answers: conference.questions
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean)
          .map((line) => {
            const [question, ...rest] = line.split('|');

            return { question: question.trim(), answer: rest.join('|').trim() };
          }),
        reflection: conference.reflection || null,
        next_focus: conference.next_focus || null,
      }),
    onSuccess: () => {
      toast.success('Wawancara tersimpan');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const finalizeMutation = useMutation({
    mutationFn: () => supervisiVisitApi.finalize(id, finalNotes),
    onSuccess: () => {
      toast.success('Hasil difinalkan');
      setChecklistIssues([]);
      invalidate();
      setTab('RESULT');
    },
    onError: (error) => {
      setChecklistIssues(validationList(error));
      toast.error(getErrorMessage(error));
    },
  });

  const reopenMutation = useMutation({
    mutationFn: () => supervisiVisitApi.reopen(id, 'Koreksi setelah peninjauan ulang'),
    onSuccess: () => {
      toast.success('Hasil dibuka ulang untuk koreksi');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const actionPlanMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.createActionPlanFromVisit(id),
    onSuccess: () => {
      toast.success('RTL disusun dari temuan kegiatan');
      navigate('/supervisi/action-plans');
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const evidenceMutation = useMutation({
    mutationFn: () => supervisiVisitApi.uploadEvidence(id, { file: evidenceFile, url: evidenceLink || null }),
    onSuccess: () => {
      toast.success('Bukti tersimpan');
      setEvidenceFile(null);
      setEvidenceLink('');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const removeEvidenceMutation = useMutation({
    mutationFn: (evidenceId: number) => supervisiVisitApi.deleteEvidence(id, evidenceId),
    onSuccess: () => {
      toast.success('Bukti dihapus');
      invalidate();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const computedScores = useMemo(() => {
    const map = new Map<number, number | null>();
    for (const answer of detail?.answers ?? []) {
      map.set(answer.indicator_id, answer.computed_score ?? null);
    }

    return map;
  }, [detail]);

  const indicators = useMemo(
    () => (detail?.sections ?? []).flatMap((section) => section.indicators),
    [detail],
  );

  /** Perkiraan nilai sementara dari isian yang belum/telah disimpan. */
  const localTotals = useMemo(() => {
    let raw = 0;
    let max = 0;
    let answered = 0;
    let missing = 0;

    for (const indicator of indicators) {
      const draft = drafts[indicator.id];
      const filled = draft !== undefined && (draft.is_na
        || draft.option_id != null
        || (draft.option_ids ?? []).length > 0
        || (draft.value_number !== null && draft.value_number !== undefined)
        || (draft.value_text ?? '') !== ''
        || (draft.value_long_text ?? '') !== ''
        || (draft.value_date ?? '') !== '');

      if (filled) {
        answered += 1;
      } else if (indicator.is_required && indicator.is_scored) {
        missing += 1;
      }

      if (!indicator.is_scored || draft === undefined) continue;

      let score: number | null = null;
      if (draft.is_na && indicator.allow_na) {
        continue;
      }
      if (indicator.input_type === 'SCALE') {
        score = indicator.options.find((option) => option.id === draft.option_id)?.score ?? null;
      } else if (indicator.input_type === 'NUMBER') {
        const value = draft.value_number;
        const min = typeof indicator.score_min === 'number' ? indicator.score_min : null;
        const max = typeof indicator.score_max === 'number' ? indicator.score_max : null;

        if (typeof value !== 'number') {
          score = null;
        } else if (indicator.direction === 'LOWER_BETTER' && min !== null && max !== null) {
          // Arah "makin kecil makin baik" dibalik seperti di mesin skor server.
          score = min + max - value;
        } else {
          score = value;
        }
      } else if (indicator.input_type === 'BOOLEAN') {
        score = draft.value_text === 'YES'
          ? indicator.score_yes ?? 1
          : draft.value_text === 'PARTIAL'
            ? indicator.score_partial ?? null
            : draft.value_text === 'NO'
              ? indicator.score_no ?? 0
              : null;
      } else if (indicator.input_type === 'MULTI_CHOICE') {
        score = (draft.option_ids ?? []).reduce(
          (total, optionId) => total + (indicator.options.find((option) => option.id === optionId)?.score ?? 0),
          0,
        );
      }

      if (score === null) continue;
      const weight = indicator.weight || 1;
      raw += score * weight;
      max += indicator.max_score * weight;
    }

    return {
      raw: Math.round(raw * 100) / 100,
      max: Math.round(max * 100) / 100,
      percent: max > 0 ? Math.round((raw / max) * 10000) / 100 : null,
      answered,
      missing,
    };
  }, [drafts, indicators]);

  const updateDraft = (indicatorId: number, patch: Partial<DraftAnswer>) => {
    setDrafts((previous) => ({
      ...previous,
      [indicatorId]: {
        ...(previous[indicatorId] ?? { is_na: false }),
        ...patch,
        indicator_id: indicatorId,
      },
    }));
    setDirty(true);
  };

  const save = () => {
    const payload: DraftAnswer[] = [];

    for (const indicator of indicators) {
      const draft = drafts[indicator.id];
      if (!draft) continue;
      payload.push(draft);
    }

    if (payload.length === 0) {
      toast.error('Belum ada jawaban untuk disimpan.');
      return;
    }

    payloadRef.current = payload;
    saveMutation.mutate(payload);
  };

  if (detailQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat kegiatan...
        </div>
      </Card>
    );
  }

  if (detailQuery.isError || !detail) {
    return (
      <Card>
        <CardBody>
          <EmptyState title="Kegiatan tidak ditemukan" description="Periksa tautan atau pilih kegiatan dari daftar." />
        </CardBody>
      </Card>
    );
  }

  const canExecute = detail.abilities.execute;
  const editable = canExecute && !detail.visit.is_finalized;
  const isSupervisee = detail.abilities.acknowledge;

  return (
    <div className="space-y-4 pb-36">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <Link to="/supervisi/visits" className="inline-flex items-center gap-1 text-sm text-brand-700 hover:underline">
            <ArrowLeft className="h-4 w-4" /> Daftar kegiatan
          </Link>
          <h1 className="mt-1 text-lg font-semibold text-slate-900">
            {detail.visit.activity_kind_label} — {detail.visit.supervisee?.full_name ?? '-'}
          </h1>
          <div className="mt-1 flex flex-wrap items-center gap-2 text-sm text-slate-500">
            <Badge tone={detail.visit.is_finalized ? 'green' : 'blue'}>{detail.visit.status_label}</Badge>
            <span>{detail.visit.code}</span>
            {detail.instrument && <span>• {detail.instrument.name} (v{detail.instrument.version})</span>}
            {detail.visit.observed_at && (
              <span>• {new Date(detail.visit.observed_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' })}</span>
            )}
            {detail.visit.is_disputed && <Badge tone="red">Keberatan</Badge>}
          </div>
        </div>
        <div className="flex items-center gap-2">
          {detail.visit.is_finalized && (
            <Can perm="supervisi.action_plan.create">
              <Button variant="outline" onClick={() => actionPlanMutation.mutate()} disabled={actionPlanMutation.isPending}>
                {actionPlanMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <ClipboardList className="mr-2 h-4 w-4" />}
                Susun RTL dari temuan
              </Button>
            </Can>
          )}
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => detailQuery.refetch()}>
            <RefreshCw className={detailQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
        </div>
      </div>

      <div className="inline-flex flex-wrap rounded-lg border border-slate-200 p-0.5">
        {[
          { key: 'OBSERVE' as const, label: 'Observasi & Instrumen' },
          { key: 'ANALYZE' as const, label: 'Wawancara & Temuan' },
          { key: 'RESULT' as const, label: 'Hasil & Tanda Tangan' },
        ].map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            className={`rounded-md px-3 py-1.5 text-sm ${
              tab === item.key ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {detail.checklist.length > 0 && editable && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          <div className="font-medium">Belum lengkap</div>
          <ul className="mt-1 list-disc space-y-0.5 pl-5">
            {detail.checklist.map((issue) => <li key={issue}>{issue}</li>)}
          </ul>
        </div>
      )}

      {tab === 'OBSERVE' && (
        <div className="space-y-3">
          {detail.sections.length === 0 && (
            <Card><CardBody><EmptyState title="Instrumen belum memiliki indikator" /></CardBody></Card>
          )}

          {detail.sections.map((section) => {
            const sectionScore = detail.summary?.sections.find((row) => row.section_id === section.id);

            return (
              <Card key={section.id}>
                <CardHeader>
                  <div className="flex items-center justify-between gap-2">
                    <CardTitle>{section.title}</CardTitle>
                    <div className="text-sm text-slate-500">
                      {sectionScore?.percent !== null && sectionScore?.percent !== undefined
                        ? `${sectionScore.percent}% (${sectionScore.raw}/${sectionScore.max})`
                        : 'belum dinilai'}
                    </div>
                  </div>
                </CardHeader>
                <CardBody className="space-y-4">
                  {section.indicators.map((indicator, index) => (
                    <IndicatorField
                      key={indicator.id}
                      index={index + 1}
                      indicator={indicator}
                      draft={drafts[indicator.id]}
                      computed={computedScores.get(indicator.id) ?? null}
                      disabled={!editable}
                      errors={fieldErrors[indicator.id] ?? {}}
                      onChange={(patch) => updateDraft(indicator.id, patch)}
                      evidence={(detail.evidence ?? []).filter((item) => item.visit_answer_id === null)}
                      onRemoveEvidence={(evidenceId) => removeEvidenceMutation.mutate(evidenceId)}
                    />
                  ))}
                </CardBody>
              </Card>
            );
          })}

          {editable && (
            <Card>
              <CardHeader><CardTitle>Bukti kegiatan</CardTitle></CardHeader>
              <CardBody className="space-y-3">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                  <div className="sm:col-span-2">
                    <Label>Unggah foto/dokumen</Label>
                    <input
                      type="file"
                      className="w-full rounded-lg border border-slate-300 p-1.5 text-sm"
                      onChange={(event) => setEvidenceFile(event.target.files?.[0] ?? null)}
                    />
                  </div>
                  <div>
                    <Label>Atau tautan</Label>
                    <Input value={evidenceLink} onChange={(event) => setEvidenceLink(event.target.value)} placeholder="https://..." />
                  </div>
                </div>
                <Button
                  variant="outline"
                  onClick={() => evidenceMutation.mutate()}
                  disabled={(!evidenceFile && !evidenceLink) || evidenceMutation.isPending}
                >
                  {evidenceMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Upload className="mr-2 h-4 w-4" />} Tambah bukti
                </Button>

                {(detail.evidence ?? []).length > 0 && (
                  <ul className="divide-y divide-slate-100 text-sm">
                    {detail.evidence.map((item) => (
                      <li key={item.id} className="flex items-center justify-between gap-2 py-2">
                        <span className="flex items-center gap-2 text-slate-700">
                          <Camera className="h-4 w-4 text-slate-400" />
                          {item.caption || item.kind_label}
                          {item.url && (
                            <a href={item.url} target="_blank" rel="noreferrer" className="text-brand-700 hover:underline">tautan</a>
                          )}
                        </span>
                        <Button size="sm" variant="ghost" onClick={() => removeEvidenceMutation.mutate(item.id)}>
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {tab === 'ANALYZE' && (
        <div className="space-y-3">
          <Card>
            <CardHeader><CardTitle>Wawancara Pra / Pasca-Observasi</CardTitle></CardHeader>
            <CardBody className="space-y-3">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                  <Label>Jenis</Label>
                  <Select
                    value={conference.kind}
                    onChange={(event) => setConference((previous) => ({ ...previous, kind: event.target.value as 'PRE' | 'POST' }))}
                    disabled={!editable}
                  >
                    <option value="PRE">Pra-observasi</option>
                    <option value="POST">Pasca-observasi</option>
                  </Select>
                </div>
                <div>
                  <Label>Waktu</Label>
                  <Input
                    type="datetime-local"
                    value={conference.held_at}
                    disabled={!editable}
                    onChange={(event) => setConference((previous) => ({ ...previous, held_at: event.target.value }))}
                  />
                </div>
                <div>
                  <Label>Fokus berikutnya</Label>
                  <Input
                    value={conference.next_focus}
                    disabled={!editable}
                    onChange={(event) => setConference((previous) => ({ ...previous, next_focus: event.target.value }))}
                    placeholder="mis. pertanyaan pemantik HOTS"
                  />
                </div>
              </div>
              <div>
                <Label>Pertanyaan & jawaban (satu per baris, pisahkan dengan “|”)</Label>
                <Textarea
                  rows={4}
                  value={conference.questions}
                  disabled={!editable}
                  placeholder="Apa tujuan pembelajaran hari ini?|Peserta didik memahami sistem ekskresi"
                  onChange={(event) => setConference((previous) => ({ ...previous, questions: event.target.value }))}
                />
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <Label>Kesepakatan (satu per baris)</Label>
                  <Textarea
                    rows={3}
                    value={conference.agreements}
                    disabled={!editable}
                    onChange={(event) => setConference((previous) => ({ ...previous, agreements: event.target.value }))}
                  />
                </div>
                <div>
                  <Label>Refleksi guru</Label>
                  <Textarea
                    rows={3}
                    value={conference.reflection}
                    disabled={!editable}
                    onChange={(event) => setConference((previous) => ({ ...previous, reflection: event.target.value }))}
                  />
                </div>
              </div>
              <Button onClick={() => conferenceMutation.mutate()} disabled={!editable || conferenceMutation.isPending}>
                {conferenceMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan wawancara
              </Button>

              <div className="space-y-2 border-t border-slate-100 pt-3 text-sm">
                {(Object.values(detail.conferences) as VisitConferenceRow[]).map((row) => (
                  <div key={row.kind} className="rounded-lg bg-slate-50 p-3">
                    <div className="font-medium text-slate-700">
                      {row.kind === 'PRE' ? 'Pra-observasi' : 'Pasca-observasi'} •{' '}
                      {row.held_at ? new Date(row.held_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-'}
                    </div>
                    {row.reflection && <p className="mt-1 text-slate-600">Refleksi: {row.reflection}</p>}
                    {row.agreements && row.agreements.length > 0 && (
                      <ul className="mt-1 list-disc pl-5 text-slate-600">
                        {row.agreements.map((agreement) => <li key={agreement}>{agreement}</li>)}
                      </ul>
                    )}
                  </div>
                ))}
              </div>
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <div className="flex items-center justify-between">
                <CardTitle>Temuan & Rekomendasi</CardTitle>
                {editable && (
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setFindings((previous) => [...previous, { component: 'PELAKSANAAN', priority: 'MEDIUM' }])}
                  >
                    Tambah temuan
                  </Button>
                )}
              </div>
            </CardHeader>
            <CardBody className="space-y-3">
              {findings.length === 0 && <p className="text-sm text-slate-500">Belum ada temuan.</p>}
              {findings.map((finding, index) => (
                <div key={index} className="space-y-3 rounded-lg border border-slate-200 p-3">
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <Label>Komponen</Label>
                      <Select
                        value={finding.component}
                        disabled={!editable}
                        onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, component: event.target.value } : row)))}
                      >
                        {COMPONENTS.map((component) => <option key={component.value} value={component.value}>{component.label}</option>)}
                      </Select>
                    </div>
                    <div>
                      <Label>Prioritas</Label>
                      <Select
                        value={finding.priority}
                        disabled={!editable}
                        onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, priority: event.target.value } : row)))}
                      >
                        {PRIORITIES.map((priority) => <option key={priority.value} value={priority.value}>{priority.label}</option>)}
                      </Select>
                    </div>
                  </div>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                      <Label>Kelebihan</Label>
                      <Textarea rows={2} value={finding.strength ?? ''} disabled={!editable} onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, strength: event.target.value } : row)))} />
                    </div>
                    <div>
                      <Label>Kelemahan</Label>
                      <Textarea rows={2} value={finding.weakness ?? ''} disabled={!editable} onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, weakness: event.target.value } : row)))} />
                    </div>
                    <div>
                      <Label>Masalah</Label>
                      <Textarea rows={2} value={finding.problem ?? ''} disabled={!editable} onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, problem: event.target.value } : row)))} />
                    </div>
                    <div>
                      <Label>Faktor penyebab</Label>
                      <Textarea rows={2} value={finding.cause ?? ''} disabled={!editable} onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, cause: event.target.value } : row)))} />
                    </div>
                  </div>
                  <div>
                    <Label required>Rekomendasi</Label>
                    <Textarea rows={2} value={finding.recommendation ?? ''} disabled={!editable} onChange={(event) => setFindings((previous) => previous.map((row, i) => (i === index ? { ...row, recommendation: event.target.value } : row)))} />
                  </div>
                  {editable && (
                    <div className="flex justify-end">
                      <Button size="sm" variant="ghost" onClick={() => setFindings((previous) => previous.filter((_, i) => i !== index))}>
                        <Trash2 className="h-4 w-4" />
                      </Button>
                    </div>
                  )}
                </div>
              ))}
              {editable && (
                <Button variant="outline" onClick={() => findingsMutation.mutate(findings)} disabled={findingsMutation.isPending}>
                  {findingsMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan temuan
                </Button>
              )}
            </CardBody>
          </Card>

          {detail.abilities.finalize && (
            <Card>
              <CardHeader><CardTitle>Finalisasi Hasil</CardTitle></CardHeader>
              <CardBody className="space-y-3">
                <div>
                  <Label>Ringkasan</Label>
                  <Textarea rows={2} value={finalNotes.summary} onChange={(event) => setFinalNotes((previous) => ({ ...previous, summary: event.target.value }))} />
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div>
                    <Label>Kekuatan</Label>
                    <Textarea rows={2} value={finalNotes.strengths} onChange={(event) => setFinalNotes((previous) => ({ ...previous, strengths: event.target.value }))} />
                  </div>
                  <div>
                    <Label>Perlu ditingkatkan</Label>
                    <Textarea rows={2} value={finalNotes.improvements} onChange={(event) => setFinalNotes((previous) => ({ ...previous, improvements: event.target.value }))} />
                  </div>
                </div>

                {checklistIssues.length > 0 && (
                  <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    <div className="font-medium">Sebelum dapat difinalkan</div>
                    <ul className="mt-1 list-disc pl-5">
                      {checklistIssues.map((issue) => <li key={issue}>{issue}</li>)}
                    </ul>
                  </div>
                )}

                <Button onClick={() => finalizeMutation.mutate()} disabled={finalizeMutation.isPending || dirty}>
                  {finalizeMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <CheckCircle2 className="mr-2 h-4 w-4" />} Finalkan hasil
                </Button>
                {dirty && <p className="text-xs text-amber-700">Simpan jawaban terlebih dahulu sebelum finalisasi.</p>}
              </CardBody>
            </Card>
          )}
        </div>
      )}

      {tab === 'RESULT' && (
        <VisitResultPanel
          detail={detail}
          canReopen={detail.abilities.reopen}
          onReopen={() => reopenMutation.mutate()}
          isSupervisee={isSupervisee}
          onChanged={invalidate}
        />
      )}

      {editable && (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur">
          <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <span className="text-slate-600">
                Terjawab <strong>{localTotals.answered}</strong>/{indicators.length}
              </span>
              <span className="text-slate-600">Skor sementara <strong>{localTotals.raw}</strong>/{localTotals.max}</span>
              {localTotals.percent !== null && <Badge tone="brand">{localTotals.percent}%</Badge>}
              {localTotals.missing > 0 && <Badge tone="amber">{localTotals.missing} wajib belum diisi</Badge>}
              {dirty && <Badge tone="amber">belum disimpan</Badge>}
            </div>
            <div className="flex items-center gap-2">
              <Button variant="outline" onClick={save} disabled={saveMutation.isPending || !dirty}>
                {saveMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Save className="mr-2 h-4 w-4" />} Simpan
              </Button>
              <Button
                onClick={() => {
                  if (dirty) {
                    toast.error('Simpan jawaban terlebih dahulu.');
                    return;
                  }
                  if (localTotals.missing > 0) {
                    toast.error('Masih ada indikator wajib yang belum diisi.');
                    return;
                  }
                  submitMutation.mutate();
                }}
                disabled={submitMutation.isPending || detail.visit.is_finalized}
              >
                {submitMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Send className="mr-2 h-4 w-4" />} Kirim untuk dianalisis
              </Button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

/** Satu indikator dengan kendali sesuai tipe inputnya. */
function IndicatorField({
  index,
  indicator,
  draft,
  computed,
  disabled,
  errors,
  onChange,
  evidence,
  onRemoveEvidence,
}: {
  index: number;
  indicator: VisitIndicatorRow;
  draft?: DraftAnswer;
  computed: number | null;
  disabled: boolean;
  errors: Record<string, string>;
  onChange: (patch: Partial<DraftAnswer>) => void;
  evidence: Array<{ id: number; caption?: string | null; kind_label: string }>;
  onRemoveEvidence: (evidenceId: number) => void;
}) {
  const current = draft ?? { indicator_id: indicator.id, is_na: false };
  const requiresComment = indicator.min_comment_below !== null
    && computed !== null
    && computed < (indicator.min_comment_below ?? 0);

  return (
    <div className="rounded-lg border border-slate-200 p-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div className="flex-1">
          <div className="text-sm font-medium text-slate-800">
            {index}. {indicator.statement}
          </div>
          {indicator.guidance && <p className="mt-1 text-xs text-slate-500">{indicator.guidance}</p>}
          {indicator.evidence_hint && <p className="mt-1 text-xs text-slate-400">Bukti disarankan: {indicator.evidence_hint}</p>}
        </div>
        <div className="flex items-center gap-2">
          {indicator.is_critical && <Badge tone="red">kritis</Badge>}
          {indicator.is_required ? <Badge tone="gray">wajib</Badge> : <Badge tone="gray">opsional</Badge>}
          {computed !== null && <Badge tone="brand">{computed} / {indicator.max_score}</Badge>}
        </div>
      </div>

      <div className="mt-3 space-y-3">
        {indicator.input_type === 'SCALE' && (
          <div className="flex flex-wrap gap-2">
            {indicator.options.map((option) => (
              <button
                key={option.id}
                type="button"
                disabled={disabled || current.is_na}
                onClick={() => onChange({ option_id: option.id })}
                className={`rounded-lg border px-3 py-2 text-sm transition ${
                  current.option_id === option.id ? TONE_CLASS[option.tone] ?? TONE_CLASS.GRAY : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                } ${disabled || current.is_na ? 'opacity-60' : ''}`}
              >
                <span className="font-medium">{option.label}</span>
                <span className="ml-2 text-xs text-slate-500">{option.score}</span>
              </button>
            ))}
          </div>
        )}

        {indicator.input_type === 'NUMBER' && (
          <div className="flex flex-wrap items-end gap-3">
            <div className="w-40">
              <Input
                type="number"
                step={indicator.score_step ?? 1}
                min={indicator.score_min ?? undefined}
                max={indicator.score_max ?? undefined}
                value={current.value_number ?? ''}
                disabled={disabled || current.is_na}
                onChange={(event) => onChange({ value_number: event.target.value === '' ? null : Number(event.target.value) })}
              />
            </div>
            <div className="text-sm text-slate-500">
              {indicator.score_unit && <span>satuan: {indicator.score_unit} • </span>}
              rentang {indicator.score_min ?? 0}–{indicator.score_max ?? '∞'}
              {indicator.direction === 'LOWER_BETTER' ? ' • semakin kecil semakin baik' : ' • semakin besar semakin baik'}
            </div>
          </div>
        )}

        {indicator.input_type === 'BOOLEAN' && (
          <div className="flex gap-2">
            {[
              { value: 'YES', label: 'Ya', score: indicator.score_yes },
              { value: 'PARTIAL', label: 'Sebagian', score: indicator.score_partial },
              { value: 'NO', label: 'Tidak', score: indicator.score_no },
            ]
              .filter((option) => option.score !== null && option.score !== undefined)
              .map((option) => (
                <button
                  key={option.value}
                  type="button"
                  disabled={disabled || current.is_na}
                  onClick={() => onChange({ value_text: option.value })}
                  className={`rounded-lg border px-4 py-2 text-sm ${
                    current.value_text === option.value ? 'border-brand-300 bg-brand-50 font-medium text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                  }`}
                >
                  {option.label} <span className="ml-1 text-xs text-slate-500">{option.score}</span>
                </button>
              ))}
          </div>
        )}

        {indicator.input_type === 'MULTI_CHOICE' && (
          <div className="flex flex-wrap gap-2">
            {indicator.options.map((option) => {
              const selected = (current.option_ids ?? []).includes(option.id);

              return (
                <button
                  key={option.id}
                  type="button"
                  disabled={disabled || current.is_na}
                  onClick={() => onChange({
                    option_ids: selected
                      ? (current.option_ids ?? []).filter((id) => id !== option.id)
                      : [...(current.option_ids ?? []), option.id],
                  })}
                  className={`rounded-lg border px-3 py-2 text-sm ${
                    selected ? 'border-brand-300 bg-brand-50 text-brand-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                  }`}
                >
                  {option.label} <span className="ml-1 text-xs text-slate-500">{option.score}</span>
                </button>
              );
            })}
          </div>
        )}

        {(indicator.input_type === 'TEXT' || indicator.input_type === 'FILE') && (
          <Input
            value={current.value_text ?? ''}
            disabled={disabled || current.is_na}
            placeholder={indicator.input_type === 'FILE' ? 'Tulis nama berkas/tautan bukti' : 'Tulis jawaban singkat'}
            onChange={(event) => onChange({ value_text: event.target.value })}
          />
        )}

        {indicator.input_type === 'LONG_TEXT' && (
          <Textarea
            rows={3}
            value={current.value_long_text ?? ''}
            disabled={disabled || current.is_na}
            placeholder="Catatan fakta observasi..."
            onChange={(event) => onChange({ value_long_text: event.target.value })}
          />
        )}

        {indicator.input_type === 'DATE' && (
          <Input
            type="date"
            className="w-48"
            value={current.value_date ?? ''}
            disabled={disabled || current.is_na}
            onChange={(event) => onChange({ value_date: event.target.value })}
          />
        )}

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <div>
            <Label>
              Komentar{requiresComment && <span className="ml-1 text-red-500">wajib — skor di bawah {indicator.min_comment_below}</span>}
            </Label>
            <Textarea
              rows={2}
              value={current.comment ?? ''}
              disabled={disabled}
              error={errors.comment}
              placeholder="Umpan balik spesifik dan dapat ditindaklanjuti"
              onChange={(event) => onChange({ comment: event.target.value })}
            />
            {errors.comment && <p className="mt-1 text-xs text-red-600">{errors.comment}</p>}
          </div>
          <div>
            <Label>Catatan bukti</Label>
            <Input
              value={current.evidence_note ?? ''}
              disabled={disabled}
              placeholder="mis. foto papan tulis, lembar kerja kelompok 3"
              onChange={(event) => onChange({ evidence_note: event.target.value })}
            />
            {errors.value_number && <p className="mt-1 text-xs text-red-600">{errors.value_number}</p>}
            {errors.option_id && <p className="mt-1 text-xs text-red-600">{errors.option_id}</p>}
            {errors.value_text && <p className="mt-1 text-xs text-red-600">{errors.value_text}</p>}
            {errors.value_long_text && <p className="mt-1 text-xs text-red-600">{errors.value_long_text}</p>}
            {evidence.length > 0 && (
              <ul className="mt-2 space-y-1 text-xs text-slate-500">
                {evidence.map((item) => (
                  <li key={item.id} className="flex items-center justify-between gap-2">
                    <span>{item.caption || item.kind_label}</span>
                    <button type="button" className="text-red-600" onClick={() => onRemoveEvidence(item.id)}>hapus</button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>

        {indicator.allow_na && (
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={current.is_na}
              disabled={disabled}
              onChange={(event) => onChange({ is_na: event.target.checked })}
            />
            Tidak relevan pada kegiatan ini (dikeluarkan dari perhitungan)
          </label>
        )}
      </div>
    </div>
  );
}
