import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { gradebookApi } from '../../api/gradebookApi';
import type { CompetencyRow, LearningOutcome, OutcomeAchievementRow, OutcomeCourseAchievement } from '../../types.phase3';

const STATUS_TONE: Record<string, 'gray' | 'green' | 'amber' | 'red' | 'blue' | 'brand'> = {
  NOT_ASSESSED: 'gray',
  NOT_YET: 'red',
  DEVELOPING: 'amber',
  ACHIEVED: 'green',
  MASTERED: 'brand',
  NOT_STARTED: 'gray',
  COMPETENT: 'green',
};

/**
 * Learning outcome & kompetensi pada halaman gradebook guru
 * (§26–§31, §48).
 */
export function OutcomeCompetencyPanel({
  courseId,
  activities,
  students,
}: {
  courseId: number;
  activities: { id: number; title: string }[];
  students: { user_id: number; name: string | null }[];
}) {
  const [outcomes, setOutcomes] = useState<LearningOutcome[]>([]);
  const [statement, setStatement] = useState('');
  const [achievement, setAchievement] = useState<OutcomeCourseAchievement[]>([]);
  const [competencies, setCompetencies] = useState<CompetencyRow[]>([]);
  const [studentId, setStudentId] = useState<number>(students[0]?.user_id ?? 0);
  const [studentOutcomeRows, setStudentOutcomeRows] = useState<OutcomeAchievementRow[]>([]);
  const [studentCompetencyRows, setStudentCompetencyRows] = useState<CompetencyRow[]>([]);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const [outcomePayload, competencyPayload] = await Promise.all([
      gradebookApi.outcomes(courseId).catch(() => null),
      gradebookApi.courseCompetencies(courseId).catch(() => null),
    ]);

    if (outcomePayload !== null) {
      setOutcomes(outcomePayload.outcomes);
      setAchievement((outcomePayload.achievement as OutcomeCourseAchievement[]) ?? []);
    }

    if (competencyPayload !== null) {
      setCompetencies(competencyPayload.competencies as CompetencyRow[]);
    }
  };

  useEffect(() => {
    void load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [courseId]);

  useEffect(() => {
    if (studentId === 0) {
      return;
    }

    void (async () => {
      const [outcomeRows, competencyRows] = await Promise.all([
        gradebookApi.studentOutcomes(courseId, studentId).catch(() => [] as OutcomeAchievementRow[]),
        gradebookApi.studentCompetencies(courseId, studentId).catch(() => [] as CompetencyRow[]),
      ]);

      setStudentOutcomeRows(outcomeRows);
      setStudentCompetencyRows(competencyRows as CompetencyRow[]);
    })();
  }, [courseId, studentId]);

  const createOutcome = async () => {
    if (statement.trim().length < 5) {
      return;
    }

    setBusy(true);

    try {
      await gradebookApi.createOutcome(courseId, { statement });
      setStatement('');
      await load();
    } finally {
      setBusy(false);
    }
  };

  const assess = async (competencyId: number, status: string) => {
    if (studentId === 0) {
      return;
    }

    setBusy(true);

    try {
      await gradebookApi.assessCompetency(competencyId, studentId, { course_id: courseId, status });
      setStudentCompetencyRows((await gradebookApi.studentCompetencies(courseId, studentId)) as CompetencyRow[]);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle>Learning outcome ({outcomes.length})</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <div className="flex flex-wrap items-end gap-2">
            <Input
              value={statement}
              onChange={(event) => setStatement(event.target.value)}
              placeholder="Rumusan capaian, mis. Memahami konsep aljabar"
              className="min-w-[260px] flex-1"
            />
            <Button type="button" disabled={busy || statement.trim().length < 5} onClick={() => void createOutcome()}>
              Tambah outcome
            </Button>
            <Button
              type="button"
              variant="outline"
              disabled={busy || outcomes.length === 0}
              onClick={async () => {
                setBusy(true);

                try {
                  await gradebookApi.recalculateOutcomes(courseId);
                  await load();
                } finally {
                  setBusy(false);
                }
              }}
            >
              Hitung ulang capaian
            </Button>
          </div>

          {outcomes.map((outcome) => (
            <div key={outcome.id} className="rounded-xl border border-slate-200 px-3 py-2">
              <p className="text-sm font-medium text-slate-800">
                {outcome.code ? `${outcome.code} · ` : ''}
                {outcome.statement}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                Ambang batas: berkembang ≥ {outcome.thresholds.developing}, tercapai ≥ {outcome.thresholds.achieved}, mahir ≥{' '}
                {outcome.thresholds.mastered}
              </p>
              <div className="mt-2 flex flex-wrap gap-2">
                {activities.map((activity) => (
                  <label key={activity.id} className="flex items-center gap-1 text-[11px] text-slate-600">
                    <input
                      type="checkbox"
                      checked={outcome.activity_ids.includes(activity.id)}
                      onChange={async (event) => {
                        const next = event.target.checked
                          ? [...outcome.activity_ids, activity.id]
                          : outcome.activity_ids.filter((id) => id !== activity.id);

                        await gradebookApi.mapOutcome(outcome.id, next);
                        await load();
                      }}
                    />
                    {activity.title}
                  </label>
                ))}
                {activities.length === 0 && <span className="text-[11px] text-slate-400">Belum ada aktivitas pada course ini.</span>}
              </div>
            </div>
          ))}

          {achievement.length > 0 && (
            <div className="space-y-1 rounded-xl bg-slate-50 px-3 py-2">
              <p className="text-xs font-medium text-slate-600">Capaian kelas</p>
              {achievement.map((row) => (
                <p key={row.learning_outcome_id} className="text-xs text-slate-600">
                  {row.code ?? row.statement}: {row.achieved_count}/{row.assessed_count || 0} tercapai
                  {row.achievement_rate === null ? '' : ` (${row.achievement_rate.toFixed(0)}%)`}
                </p>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader className="flex flex-wrap items-center justify-between gap-2">
          <CardTitle>Kompetensi</CardTitle>
          <Select value={String(studentId)} onChange={(event) => setStudentId(Number(event.target.value))} className="w-52">
            <option value="0">Pilih siswa…</option>
            {students.map((student) => (
              <option key={student.user_id} value={student.user_id}>
                {student.name ?? `Siswa #${student.user_id}`}
              </option>
            ))}
          </Select>
        </CardHeader>
        <CardBody className="space-y-3">
          {competencies.length === 0 && (
            <p className="text-sm text-slate-500">
              Belum ada kompetensi yang dipetakan ke course ini. Admin dapat menambahkannya pada kerangka kompetensi.
            </p>
          )}

          {competencies.map((competency) => {
            const record = studentCompetencyRows.find((row) => row.competency_id === competency.competency_id);

            return (
              <div key={competency.competency_id} className="rounded-xl border border-slate-200 px-3 py-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {competency.code ? `${competency.code} · ` : ''}
                    {competency.name}
                  </p>
                  <Badge tone={STATUS_TONE[record?.status ?? 'NOT_STARTED'] ?? 'gray'}>
                    {record?.status_label ?? 'Belum dimulai'}
                  </Badge>
                </div>

                {record?.evidence && record.evidence.length > 0 && (
                  <div className="mt-2 space-y-1">
                    {record.evidence.map((evidence) => (
                      <p key={evidence.id} className="text-[11px] text-slate-500">
                        {evidence.evidence_type_label}: {evidence.title ?? '—'}
                        {evidence.score === null ? '' : ` · ${evidence.score.toFixed(1)}`}
                      </p>
                    ))}
                  </div>
                )}

                <div className="mt-2 flex flex-wrap gap-2">
                  {(['NOT_STARTED', 'DEVELOPING', 'COMPETENT', 'MASTERED'] as const).map((status) => (
                    <button
                      key={status}
                      type="button"
                      disabled={busy || studentId === 0}
                      onClick={() => void assess(competency.competency_id, status)}
                      className={`rounded-lg border px-2 py-1 text-[11px] ${
                        record?.status === status ? 'border-brand-500 bg-brand-50 text-brand-800' : 'border-slate-200 text-slate-600'
                      }`}
                    >
                      {status === 'NOT_STARTED'
                        ? 'Belum dimulai'
                        : status === 'DEVELOPING'
                          ? 'Berkembang'
                          : status === 'COMPETENT'
                            ? 'Kompeten'
                            : 'Mahir'}
                    </button>
                  ))}
                </div>
              </div>
            );
          })}

          {studentOutcomeRows.length > 0 && (
            <div className="space-y-1 rounded-xl bg-slate-50 px-3 py-2">
              <p className="text-xs font-medium text-slate-600">Capaian siswa terpilih</p>
              {studentOutcomeRows.map((row) => (
                <p key={row.learning_outcome_id} className="text-xs text-slate-600">
                  {row.code ?? row.statement}: <Badge tone={STATUS_TONE[row.status] ?? 'gray'}>{row.status_label}</Badge>
                  {row.score === null ? '' : ` · ${row.score.toFixed(2)}`}
                </p>
              ))}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
