import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Textarea } from '@/components/ui/Textarea';
import { gradebookApi } from '../../api/gradebookApi';
import type { GradeItem, Rubric, RubricAssessment } from '../../types.phase3';

/**
 * Penilaian rubric (§24, §58).
 *
 * Guru memilih level per kriteria, sistem menghitung total dan menormalkannya
 * ke nilai maksimum komponen — nilai akhir tetap dihitung gradebook.
 */
export function RubricAssessmentPanel({
  item,
  studentId,
  studentName,
  onSaved,
}: {
  item: GradeItem;
  studentId: number;
  studentName: string;
  onSaved?: () => void | Promise<void>;
}) {
  const [rubric, setRubric] = useState<Rubric | null>(null);
  const [assessment, setAssessment] = useState<RubricAssessment | null>(null);
  const [selections, setSelections] = useState<Record<number, number>>({});
  const [comments, setComments] = useState<Record<number, string>>({});
  const [feedback, setFeedback] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;

    void (async () => {
      const payload = await gradebookApi.rubricAssessment(item.id, studentId).catch(() => null);

      if (!active || payload === null) {
        return;
      }

      setRubric(payload.rubric);
      setAssessment(payload.assessment);

      const preset: Record<number, number> = {};

      payload.assessment?.criteria.forEach((criterion) => {
        const level = payload.rubric?.criteria
          .find((entry) => entry.id === criterion.criterion_id)
          ?.levels.find((entry) => entry.name === criterion.level);

        if (level !== undefined) {
          preset[criterion.criterion_id] = level.id;
        }
      });

      setSelections(preset);
    })();

    return () => {
      active = false;
    };
  }, [item.id, studentId]);

  const total = useMemo(() => {
    if (rubric === null) {
      return { score: 0, maximum: 0 };
    }

    return rubric.criteria.reduce(
      (accumulator, criterion) => {
        const levelId = selections[criterion.id];
        const level = criterion.levels.find((entry) => entry.id === levelId);
        const weight = rubric.grading_method === 'WEIGHTED' ? Math.max(criterion.weight, 0) || 1 : 1;

        return {
          score: accumulator.score + (level ? level.score * weight : 0),
          maximum: accumulator.maximum + criterion.maximum_score * weight,
        };
      },
      { score: 0, maximum: 0 },
    );
  }, [rubric, selections]);

  const normalized = total.maximum > 0 ? (total.score / total.maximum) * 100 : 0;

  const submit = async (release: boolean) => {
    if (rubric === null) {
      return;
    }

    setBusy(true);
    setError(null);

    try {
      const payload: Record<string, unknown> = {
        rubric_id: rubric.id,
        release,
        feedback: feedback.trim() === '' ? null : feedback.trim(),
        selections: Object.fromEntries(
          Object.entries(selections).map(([criterionId, levelId]) => [
            criterionId,
            { level_id: levelId, comment: comments[Number(criterionId)] ?? null },
          ]),
        ),
      };

      await gradebookApi.assessRubric(item.id, studentId, payload);

      await onSaved?.();
    } catch {
      setError('Penilaian rubric gagal disimpan. Pastikan semua kriteria sudah dipilih.');
    } finally {
      setBusy(false);
    }
  };

  if (rubric === null) {
    return (
      <Card>
        <CardHeader>
          <CardTitle>Rubric</CardTitle>
        </CardHeader>
        <CardBody>
          <p className="text-sm text-slate-500">
            Komponen ini belum memakai rubric. Pilih rubric pada tab <strong>Komponen nilai</strong> untuk menilai dengan kriteria.
          </p>
        </CardBody>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-wrap items-center justify-between gap-2">
        <CardTitle>
          {rubric.name} — {studentName}
        </CardTitle>
        <Badge tone="brand">
          {total.score.toFixed(1)} / {total.maximum.toFixed(0)} · {normalized.toFixed(1)}%
        </Badge>
      </CardHeader>
      <CardBody className="space-y-3">
        {rubric.criteria.map((criterion) => (
          <div key={criterion.id} className="rounded-xl border border-slate-200 px-3 py-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-medium text-slate-800">
                {criterion.name}
                {criterion.weight > 0 && <span className="ml-1 text-xs text-slate-500">(bobot {criterion.weight})</span>}
              </p>
              <span className="text-xs text-slate-500">maks {criterion.maximum_score}</span>
            </div>

            <div className="mt-2 flex flex-wrap gap-2">
              {criterion.levels.map((level) => (
                <button
                  key={level.id}
                  type="button"
                  onClick={() => setSelections((current) => ({ ...current, [criterion.id]: level.id }))}
                  className={`rounded-lg border px-3 py-1.5 text-left text-xs transition ${
                    selections[criterion.id] === level.id
                      ? 'border-brand-500 bg-brand-50 text-brand-800'
                      : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300'
                  }`}
                  title={level.description ?? undefined}
                >
                  <span className="block font-medium">{level.name}</span>
                  <span className="block text-[11px] text-slate-500">{level.score}</span>
                </button>
              ))}
            </div>

            <input
              value={comments[criterion.id] ?? ''}
              onChange={(event) => setComments((current) => ({ ...current, [criterion.id]: event.target.value }))}
              placeholder="Catatan kriteria (opsional)"
              className="mt-2 w-full rounded-lg border border-slate-200 px-2 py-1 text-xs text-slate-700"
            />
          </div>
        ))}

        <Textarea
          value={feedback}
          onChange={(event) => setFeedback(event.target.value)}
          rows={3}
          placeholder="Umpan balik umum untuk siswa…"
        />

        {error && <p className="text-xs text-red-600">{error}</p>}

        {assessment !== null && assessment.is_released && (
          <p className="text-xs text-emerald-700">Penilaian rubric sebelumnya sudah dirilis ke siswa.</p>
        )}

        <div className="flex flex-wrap gap-2">
          <Button type="button" variant="outline" disabled={busy} onClick={() => void submit(false)}>
            Simpan draf penilaian
          </Button>
          <Button type="button" disabled={busy} onClick={() => void submit(true)}>
            Simpan &amp; kirim ke siswa
          </Button>
        </div>
      </CardBody>
    </Card>
  );
}
