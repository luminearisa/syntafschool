import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import type { LmsActivity, QuestionBankTree, QuizBuilderPayload, QuizStatistics } from '@/features/lms/types.phase2';
import { Database, ListPlus, Save, Trash2 } from 'lucide-react';
import { StatTile, TabBar } from './AssignmentManage';

/** Panel pengelolaan kuis: soal, pengaturan, statistik, percobaan (§13, §23, §31). */
export function QuizManage({ activity }: { activity: LmsActivity }) {
  const [tab, setTab] = useState<'builder' | 'settings' | 'statistics' | 'attempts'>('builder');
  const [builder, setBuilder] = useState<QuizBuilderPayload | null>(null);
  const [statistics, setStatistics] = useState<QuizStatistics | null>(null);
  const [banks, setBanks] = useState<Array<{ id: number; name: string; questions_count: number }>>([]);
  const [tree, setTree] = useState<QuestionBankTree | null>(null);
  const [questions, setQuestions] = useState<Array<{ id: number; name: string; question_type: string; default_mark: number; category_id: number | null }>>([]);
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [attempts, setAttempts] = useState<Array<Record<string, unknown>>>([]);

  const load = useCallback(async () => {
    const payload = await activityApi.quizBuilder(activity.id);
    setBuilder(payload);
    setConfig({
      time_limit_minutes: payload.quiz.time_limit_minutes ?? '',
      attempts_allowed: payload.quiz.attempts_allowed,
      grade_strategy: payload.quiz.grade_strategy,
      shuffle_questions: payload.quiz.shuffle_questions,
      shuffle_answers: payload.quiz.shuffle_answers,
      show_one_question_at_time: payload.quiz.show_one_question_at_time,
      navigation_method: payload.quiz.navigation_method,
      maximum_grade: payload.quiz.maximum_grade,
      passing_grade: payload.quiz.passing_grade ?? '',
      review_options: payload.quiz.review_options,
    });

    // Daftar bank soal guru (untuk memilih soal).
    const bankList = await activityApi.questionBanks({ course_id: activity.course_id });
    setBanks(bankList.map((bank) => ({ id: bank.id, name: bank.name, questions_count: bank.questions_count })));

    if (bankList.length > 0) {
      const first = await activityApi.questionBank(bankList[0].id);
      setTree(first);

      const list = await activityApi.questions({ bank_id: bankList[0].id });
      setQuestions(list.data.map((question) => ({
        id: question.id,
        name: question.name,
        question_type: question.question_type,
        default_mark: question.default_mark,
        category_id: question.category_id,
      })));
    }
  }, [activity.course_id, activity.id]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    setError(null);
    try {
      await action();
      await load();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Perubahan gagal disimpan.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4">
      <TabBar
        active={tab}
        onChange={(value) => {
          setTab(value as typeof tab);
          if (value === 'statistics') void activityApi.quizStatistics(activity.id).then(setStatistics).catch(() => undefined);
          if (value === 'attempts') void activityApi.quizAttempts(activity.id).then((page) => setAttempts(page.data)).catch(() => undefined);
        }}
        tabs={[
          { key: 'builder', label: 'Soal' },
          { key: 'settings', label: 'Pengaturan' },
          { key: 'statistics', label: 'Statistik' },
          { key: 'attempts', label: 'Percobaan' },
        ]}
      />

      {error && <p className="text-sm text-red-600">{error}</p>}

      {tab === 'builder' && builder && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm">
            <span className="text-slate-600">
              Total mark: <strong>{builder.total_marks}</strong> · {builder.slots.length} entri soal
            </span>
            <span className="text-xs text-slate-500">Mark dinormalisasi ke nilai maksimum {builder.quiz.maximum_grade} saat penilaian.</span>
          </div>

          <div className="space-y-2">
            {builder.slots.length === 0 && <p className="text-sm text-slate-500">Belum ada soal pada kuis ini.</p>}
            {builder.slots.map((slot) => (
              <div key={slot.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white p-3">
                <div className="min-w-0">
                  {slot.is_random ? (
                    <p className="inline-flex items-center gap-2 text-sm text-slate-800">
                      <Database className="h-4 w-4 text-brand-600" />
                      Acak {slot.question_count} soal dari kategori “{slot.category?.name}”
                    </p>
                  ) : (
                    <p className="text-sm text-slate-800">
                      {slot.question?.name}
                      <span className="ml-2 text-xs text-slate-500">{slot.question?.question_type_label} · v{slot.question?.version}</span>
                    </p>
                  )}
                </div>
                <div className="flex items-center gap-2">
                  <Input
                    type="number"
                    className="w-20"
                    min="0.5"
                    step="0.5"
                    defaultValue={slot.marks}
                    onBlur={(event) => void run(() => activityApi.updateQuizSlot(slot.id, Number(event.target.value)))}
                  />
                  <Button type="button" variant="ghost" size="sm" onClick={() => void run(() => activityApi.removeQuizSlot(slot.id))}>
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>
              </div>
            ))}
          </div>

          <Card className="space-y-3 p-4">
            <p className="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
              <ListPlus className="h-4 w-4" /> Tambah soal dari bank soal
            </p>

            {banks.length === 0 ? (
              <p className="text-sm text-slate-500">
                Anda belum memiliki bank soal pada course ini. Buat bank soal terlebih dahulu di menu “Bank Soal”.
              </p>
            ) : (
              <>
                <div className="flex flex-wrap gap-2">
                  <Select
                    value={String(banks[0].id)}
                    onChange={(event) => {
                      const bankId = Number(event.target.value);
                      void activityApi.questionBank(bankId).then(setTree);
                      void activityApi.questions({ bank_id: bankId }).then((page) =>
                        setQuestions(page.data.map((question) => ({
                          id: question.id,
                          name: question.name,
                          question_type: question.question_type,
                          default_mark: question.default_mark,
                          category_id: question.category_id,
                        }))),
                      );
                    }}
                  >
                    {banks.map((bank) => (
                      <option key={bank.id} value={bank.id}>
                        {bank.name} ({bank.questions_count} soal)
                      </option>
                    ))}
                  </Select>
                </div>

                <ul className="max-h-64 space-y-1 overflow-y-auto">
                  {questions.map((question) => (
                    <li key={question.id} className="flex items-center justify-between gap-2 rounded-md border border-slate-200 px-2 py-1 text-sm">
                      <span className="truncate">
                        {question.name}
                        <span className="ml-2 text-xs text-slate-500">{question.default_mark} mark</span>
                      </span>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() => void run(() => activityApi.addQuizQuestions(activity.id, [{ question_id: question.id, marks: question.default_mark }]))}
                      >
                        Tambah
                      </Button>
                    </li>
                  ))}
                </ul>

                {tree && tree.categories.length > 0 && (
                  <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-medium text-slate-600">Slot soal acak (kombinasi berbeda per siswa)</p>
                    {tree.categories.map((category) => (
                      <div key={category.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span>
                          {category.name} · {category.questions_count} soal
                        </span>
                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          disabled={busy || category.questions_count === 0}
                          onClick={() =>
                            void run(() =>
                              activityApi.addQuizQuestions(activity.id, [
                                { category_id: category.id, question_count: Math.min(5, category.questions_count), marks: 1 },
                              ]),
                            )
                          }
                        >
                          <Database className="mr-1 h-4 w-4" /> Acak 5 soal
                        </Button>
                      </div>
                    ))}
                  </div>
                )}
              </>
            )}
          </Card>
        </div>
      )}

      {tab === 'settings' && builder && (
        <Card className="space-y-3 p-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Batas waktu (menit, kosong = tanpa batas)</span>
              <Input
                type="number"
                value={String(config.time_limit_minutes ?? '')}
                onChange={(event) => setConfig((prev) => ({ ...prev, time_limit_minutes: event.target.value === '' ? null : Number(event.target.value) }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Percobaan diizinkan (0 = tak terbatas)</span>
              <Input
                type="number"
                min="0"
                value={String(config.attempts_allowed ?? 1)}
                onChange={(event) => setConfig((prev) => ({ ...prev, attempts_allowed: Number(event.target.value) }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Strategi nilai</span>
              <Select value={String(config.grade_strategy)} onChange={(event) => setConfig((prev) => ({ ...prev, grade_strategy: event.target.value }))}>
                <option value="HIGHEST">Nilai tertinggi</option>
                <option value="LATEST">Percobaan terakhir</option>
                <option value="AVERAGE">Rata-rata</option>
                <option value="FIRST">Percobaan pertama</option>
              </Select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Navigasi</span>
              <Select value={String(config.navigation_method)} onChange={(event) => setConfig((prev) => ({ ...prev, navigation_method: event.target.value }))}>
                <option value="FREE">Bebas</option>
                <option value="SEQUENTIAL">Berurutan</option>
              </Select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Nilai maksimum</span>
              <Input
                type="number"
                value={String(config.maximum_grade ?? 100)}
                onChange={(event) => setConfig((prev) => ({ ...prev, maximum_grade: Number(event.target.value) }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Nilai lulus</span>
              <Input
                type="number"
                value={String(config.passing_grade ?? '')}
                onChange={(event) => setConfig((prev) => ({ ...prev, passing_grade: event.target.value === '' ? null : Number(event.target.value) }))}
              />
            </label>
          </div>

          <div className="flex flex-wrap gap-4 text-sm text-slate-700">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.shuffle_questions)}
                onChange={(event) => setConfig((prev) => ({ ...prev, shuffle_questions: event.target.checked }))}
              /> Acak urutan soal
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.shuffle_answers)}
                onChange={(event) => setConfig((prev) => ({ ...prev, shuffle_answers: event.target.checked }))}
              /> Acak urutan pilihan
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.show_one_question_at_time)}
                onChange={(event) => setConfig((prev) => ({ ...prev, show_one_question_at_time: event.target.checked }))}
              /> Tampilkan satu soal per layar
            </label>
          </div>

          <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3">
            <p className="text-xs font-medium text-slate-600">Yang boleh dilihat siswa setelah selesai (§29)</p>
            {['show_score', 'show_correct_answer', 'show_feedback', 'show_question', 'show_answer'].map((key) => (
              <label key={key} className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  checked={Boolean((config.review_options as Record<string, boolean>)?.[key])}
                  onChange={(event) =>
                    setConfig((prev) => ({
                      ...prev,
                      review_options: { ...((prev.review_options as Record<string, boolean>) ?? {}), [key]: event.target.checked },
                    }))
                  }
                />
                {key === 'show_score'
                  ? 'Nilai'
                  : key === 'show_correct_answer'
                    ? 'Kunci jawaban'
                    : key === 'show_feedback'
                      ? 'Umpan balik'
                      : key === 'show_question'
                        ? 'Teks soal'
                        : 'Jawaban siswa'}
              </label>
            ))}
          </div>

          <Button type="button" size="sm" onClick={() => void run(() => activityApi.updateQuiz(activity.id, config))} disabled={busy}>
            <Save className="mr-1 h-4 w-4" /> Simpan pengaturan kuis
          </Button>
        </Card>
      )}

      {tab === 'statistics' && statistics && (
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatTile label="Percobaan" value={String(statistics.attempts)} />
            <StatTile label="Siswa mengerjakan" value={`${statistics.students_attempted} / ${statistics.participants}`} />
            <StatTile label="Rata-rata" value={statistics.average === null ? '—' : String(statistics.average)} />
            <StatTile label="Tingkat lulus" value={`${statistics.pass_rate ?? 0}%`} />
            <StatTile label="Tertinggi" value={statistics.highest === null ? '—' : String(statistics.highest)} />
            <StatTile label="Terendah" value={statistics.lowest === null ? '—' : String(statistics.lowest)} />
            <StatTile label="Penyelesaian" value={`${statistics.completion_rate ?? 0}%`} />
            <StatTile label="Menunggu penilaian esai" value={String(statistics.pending_manual_grading)} />
          </div>

          <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs text-slate-600">
                <tr>
                  <th className="px-3 py-2">Soal</th>
                  <th className="px-3 py-2">Jawaban</th>
                  <th className="px-3 py-2">Benar</th>
                  <th className="px-3 py-2">Salah</th>
                  <th className="px-3 py-2">Rata-rata mark</th>
                </tr>
              </thead>
              <tbody>
                {statistics.questions.map((question) => (
                  <tr key={question.slot_id} className="border-t border-slate-100">
                    <td className="px-3 py-2 text-slate-800">
                      {question.title}
                      {question.is_random && <Badge tone="gray" className="ml-2">acak</Badge>}
                    </td>
                    <td className="px-3 py-2">{question.answers}</td>
                    <td className="px-3 py-2 text-emerald-700">{question.correct} ({question.correct_percent ?? 0}%)</td>
                    <td className="px-3 py-2 text-red-600">{question.incorrect} ({question.incorrect_percent ?? 0}%)</td>
                    <td className="px-3 py-2">{question.average_marks ?? '—'} / {question.marks}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'attempts' && (
        <div className="space-y-3">
          {attempts.length === 0 && <p className="text-sm text-slate-500">Belum ada percobaan siswa.</p>}
          {attempts.map((attempt) => (
            <Card key={String(attempt.id)} className="flex flex-wrap items-center justify-between gap-3 p-3 text-sm">
              <div>
                <p className="font-medium text-slate-800">{String((attempt.student as { name?: string })?.name ?? `Pengguna #${attempt.user_id}`)}</p>
                <p className="text-xs text-slate-500">
                  Percobaan {String(attempt.attempt_number)} · {String(attempt.status_label)} · dikirim {formatDate(String(attempt.submitted_at ?? ''), true)}
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Badge tone={attempt.needs_manual_grading ? 'amber' : 'green'}>
                  {attempt.final_grade === null ? 'Menunggu' : `${String(attempt.final_grade)} / ${builder ? builder.quiz.maximum_grade : 100}`}
                </Badge>
                <ManualGradingButton attemptId={Number(attempt.id)} onDone={() => void activityApi.quizAttempts(activity.id).then((page) => setAttempts(page.data))} />
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}

/** Penilaian esai: memuat detail jawaban lalu menyimpan mark (§20). */
function ManualGradingButton({ attemptId, onDone }: { attemptId: number; onDone: () => void }) {
  const [open, setOpen] = useState(false);
  const [detail, setDetail] = useState<Array<Record<string, unknown>>>([]);

  async function load() {
    const payload = await activityApi.attemptDetail(attemptId);
    setDetail(payload.questions);
    setOpen(true);
  }

  return (
    <>
      <Button type="button" size="sm" variant="outline" onClick={() => void load()}>
        Periksa jawaban
      </Button>

      {open && (
        <div className="w-full space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
          {detail.map((question, index) => {
            const answer = question.answer as { id?: number; answer?: string; marks?: number | null; feedback?: string | null } | null;

            return (
              <div key={index} className="rounded-md border border-slate-200 bg-white p-3 text-xs">
                <p className="text-sm text-slate-800">{String(question.question_text ?? '')}</p>
                <p className="mt-1 text-slate-600">Jawaban: {answer?.answer ?? '(tidak dijawab)'}</p>

                {answer?.id !== undefined && answer?.id !== null && (
                  <ManualMarkForm
                    answerId={answer.id}
                    initialMarks={answer.marks ?? null}
                    initialFeedback={answer.feedback ?? null}
                    onSaved={() => {
                      setOpen(false);
                      onDone();
                    }}
                  />
                )}
              </div>
            );
          })}
        </div>
      )}
    </>
  );
}

/** Formulir penilaian satu jawaban esai. */
function ManualMarkForm({
  answerId,
  initialMarks,
  initialFeedback,
  onSaved,
}: {
  answerId: number;
  initialMarks: number | null;
  initialFeedback: string | null;
  onSaved: () => void;
}) {
  const [marks, setMarks] = useState(initialMarks === null ? '' : String(initialMarks));
  const [feedback, setFeedback] = useState(initialFeedback ?? '');

  return (
    <div className="mt-2 flex flex-wrap items-center gap-2">
      <Input type="number" className="w-20" placeholder="Mark" value={marks} onChange={(event) => setMarks(event.target.value)} />
      <Textarea
        rows={1}
        className="min-w-[200px] flex-1"
        placeholder="Umpan balik"
        value={feedback}
        onChange={(event) => setFeedback(event.target.value)}
      />
      <Button type="button" size="sm" onClick={() => void activityApi.gradeQuizAnswer(answerId, Number(marks || 0), feedback || undefined).then(onSaved)}>
        Simpan mark
      </Button>
    </div>
  );
}
