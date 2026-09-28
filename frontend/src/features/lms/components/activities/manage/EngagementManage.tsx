import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { ContentEditor } from '@/features/lms/components/ContentEditor';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import type { LmsActivity, SurveyResults } from '@/features/lms/types.phase2';
import { Check, Plus, ShieldCheck, Trash2, X } from 'lucide-react';
import { StatTile, TabBar } from './AssignmentManage';

/** Pengelolaan forum: pengaturan, moderasi, statistik (§32, §35). */
export function ForumManage({ activity }: { activity: LmsActivity }) {
  const [tab, setTab] = useState<'settings' | 'moderation' | 'statistics'>('settings');
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [queue, setQueue] = useState<{ posts: Array<Record<string, unknown>>; discussions: Array<Record<string, unknown>> } | null>(null);
  const [statistics, setStatistics] = useState<Record<string, unknown> | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const payload = await activityApi.forum(activity.id);
    setConfig({
      forum_type: payload.forum.forum_type,
      moderation_enabled: payload.forum.moderation_enabled,
      subscription_enabled: payload.forum.subscription_enabled,
      grading_enabled: payload.forum.grading_enabled,
      required_posts: payload.forum.required_posts,
      max_depth: payload.forum.max_depth,
    });
  }, [activity.id]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  return (
    <div className="space-y-4">
      <TabBar
        active={tab}
        onChange={(value) => {
          setTab(value as typeof tab);
          if (value === 'moderation') void activityApi.forumModerationQueue(activity.id).then(setQueue).catch(() => undefined);
          if (value === 'statistics') void activityApi.forumStatistics(activity.id).then(setStatistics).catch(() => undefined);
        }}
        tabs={[
          { key: 'settings', label: 'Pengaturan' },
          { key: 'moderation', label: 'Moderasi' },
          { key: 'statistics', label: 'Statistik' },
        ]}
      />

      {tab === 'settings' && (
        <Card className="space-y-3 p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Jenis forum</span>
              <Select value={String(config.forum_type ?? '')} onChange={(event) => setConfig((prev) => ({ ...prev, forum_type: event.target.value }))}>
                <option value="GENERAL">Forum umum</option>
                <option value="STANDARD">Forum standar</option>
                <option value="Q_AND_A">Tanya jawab</option>
                <option value="COURSE_ANNOUNCEMENT">Pengumuman (guru saja)</option>
              </Select>
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Kontribusi minimum untuk selesai</span>
              <Input
                type="number"
                min="1"
                value={String(config.required_posts ?? 1)}
                onChange={(event) => setConfig((prev) => ({ ...prev, required_posts: Number(event.target.value) }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Kedalaman balasan maksimum</span>
              <Input
                type="number"
                min="1"
                max="10"
                value={String(config.max_depth ?? 3)}
                onChange={(event) => setConfig((prev) => ({ ...prev, max_depth: Number(event.target.value) }))}
              />
            </label>
          </div>

          <div className="flex flex-wrap gap-4 text-sm text-slate-700">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.moderation_enabled)}
                onChange={(event) => setConfig((prev) => ({ ...prev, moderation_enabled: event.target.checked }))}
              /> Aktifkan moderasi
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.subscription_enabled)}
                onChange={(event) => setConfig((prev) => ({ ...prev, subscription_enabled: event.target.checked }))}
              /> Izinkan langganan notifikasi
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.grading_enabled)}
                onChange={(event) => setConfig((prev) => ({ ...prev, grading_enabled: event.target.checked }))}
              /> Nilai partisipasi
            </label>
          </div>

          <Button
            type="button"
            size="sm"
            disabled={busy}
            onClick={() => {
              setBusy(true);
              void activityApi.updateForum(activity.id, config).finally(() => setBusy(false));
            }}
          >
            Simpan pengaturan forum
          </Button>
        </Card>
      )}

      {tab === 'moderation' && (
        <div className="space-y-3">
          {queue === null || (queue.posts.length === 0 && queue.discussions.length === 0) ? (
            <p className="text-sm text-slate-500">Tidak ada kiriman yang menunggu moderasi.</p>
          ) : (
            <>
              {queue.discussions.map((discussion) => (
                <Card key={`discussion-${discussion.id}`} className="space-y-2 p-3 text-sm">
                  <p className="text-xs text-slate-500">Diskusi baru · {String(discussion.author ?? '')}</p>
                  <p className="font-medium text-slate-800">{String(discussion.title ?? '')}</p>
                  <p className="text-slate-600">{String(discussion.content ?? '')}</p>
                  <ModerationActions
                    onApprove={() => void activityApi.moderateForum('discussion', Number(discussion.id), 'approve').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                    onHide={() => void activityApi.moderateForum('discussion', Number(discussion.id), 'hide').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                    onDelete={() => void activityApi.moderateForum('discussion', Number(discussion.id), 'delete').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                  />
                </Card>
              ))}

              {queue.posts.map((post) => (
                <Card key={`post-${post.id}`} className="space-y-2 p-3 text-sm">
                  <p className="text-xs text-slate-500">
                    Balasan · {String(post.author ?? '')} · {formatDate(String(post.created_at ?? ''), true)}
                  </p>
                  <p className="text-slate-700">{String(post.content ?? '')}</p>
                  <ModerationActions
                    onApprove={() => void activityApi.moderateForum('post', Number(post.id), 'approve').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                    onHide={() => void activityApi.moderateForum('post', Number(post.id), 'hide').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                    onDelete={() => void activityApi.moderateForum('post', Number(post.id), 'delete').then(() => activityApi.forumModerationQueue(activity.id).then(setQueue))}
                  />
                </Card>
              ))}
            </>
          )}
        </div>
      )}

      {tab === 'statistics' && statistics && (
        <div className="grid gap-3 sm:grid-cols-3">
          <StatTile label="Diskusi" value={String(statistics.discussions ?? 0)} />
          <StatTile label="Kiriman" value={String(statistics.posts ?? 0)} />
          <StatTile label="Menunggu moderasi" value={String(statistics.pending_moderation ?? 0)} />
          <StatTile label="Peserta" value={String(statistics.participants ?? 0)} />
          <StatTile label="Berpartisipasi" value={String(statistics.participating ?? 0)} />
          <StatTile label="Tingkat partisipasi" value={`${statistics.participation_rate ?? 0}%`} />
        </div>
      )}
    </div>
  );
}

function ModerationActions({ onApprove, onHide, onDelete }: { onApprove: () => void; onHide: () => void; onDelete: () => void }) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <Button type="button" size="sm" onClick={onApprove}>
        <Check className="mr-1 h-4 w-4" /> Setujui
      </Button>
      <Button type="button" size="sm" variant="outline" onClick={onHide}>
        <ShieldCheck className="mr-1 h-4 w-4" /> Sembunyikan
      </Button>
      <Button type="button" size="sm" variant="ghost" onClick={onDelete}>
        <X className="mr-1 h-4 w-4 text-red-500" /> Hapus
      </Button>
    </div>
  );
}

/** Pengelolaan lesson: halaman + cabang + statistik (§37). */
export function LessonManage({ activity }: { activity: LmsActivity }) {
  const [tab, setTab] = useState<'pages' | 'statistics'>('pages');
  const [pages, setPages] = useState<Array<Record<string, unknown>>>([]);
  const [statistics, setStatistics] = useState<Record<string, unknown> | null>(null);
  const [form, setForm] = useState({ title: '', content: '', page_type: 'CONTENT' });
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const payload = await activityApi.lessonBuilder(activity.id);
    setPages((payload.pages as Array<Record<string, unknown>>) ?? []);
  }, [activity.id]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  async function addPage() {
    setBusy(true);
    try {
      await activityApi.createLessonPage(activity.id, form);
      setForm({ title: '', content: '', page_type: 'CONTENT' });
      await load();
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
          if (value === 'statistics') void activityApi.lessonBuilder(activity.id).then((payload) => setStatistics(payload.statistics as Record<string, unknown>)).catch(() => undefined);
        }}
        tabs={[
          { key: 'pages', label: 'Halaman' },
          { key: 'statistics', label: 'Statistik' },
        ]}
      />

      {tab === 'pages' && (
        <>
          <div className="space-y-2">
            {pages.map((page, index) => (
              <Card key={String(page.id)} className="space-y-2 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {index + 1}. {String(page.title ?? '')}
                    {page.page_type === 'QUESTION' && <Badge tone="amber" className="ml-2">pertanyaan bercabang</Badge>}
                    {page.is_required === false && <Badge tone="gray" className="ml-2">opsional</Badge>}
                  </p>
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={() => void activityApi.deleteLessonPage(Number(page.id)).then(load)}
                  >
                    <Trash2 className="h-4 w-4 text-red-500" />
                  </Button>
                </div>

                {(page.branches as Array<Record<string, unknown>>)?.length > 0 && (
                  <ul className="space-y-1 text-xs text-slate-600">
                    {(page.branches as Array<Record<string, unknown>>).map((branch) => (
                      <li key={String(branch.id)}>
                        {branch.is_correct ? 'Benar' : 'Salah'} · “{String(branch.label ?? branch.answer_key ?? '')}” → halaman #
                        {String(branch.next_page_id ?? '-')}
                      </li>
                    ))}
                  </ul>
                )}

                <BranchEditor page={page} pages={pages} onSaved={load} />
              </Card>
            ))}
            {pages.length === 0 && <p className="text-sm text-slate-500">Lesson belum memiliki halaman.</p>}
          </div>

          <Card className="space-y-3 p-4">
            <p className="inline-flex items-center gap-2 text-sm font-medium text-slate-800">
              <Plus className="h-4 w-4" /> Halaman baru
            </p>
            <Input value={form.title} onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))} placeholder="Judul halaman" />
            <Select value={form.page_type} onChange={(event) => setForm((prev) => ({ ...prev, page_type: event.target.value }))}>
              <option value="CONTENT">Materi</option>
              <option value="QUESTION">Pertanyaan bercabang</option>
            </Select>
            <ContentEditor value={form.content} onChange={(value) => setForm((prev) => ({ ...prev, content: value }))} />
            <Button type="button" size="sm" onClick={addPage} disabled={busy || form.title.trim() === ''}>
              Tambah halaman
            </Button>
          </Card>
        </>
      )}

      {tab === 'statistics' && statistics && (
        <div className="space-y-3">
          <div className="grid gap-3 sm:grid-cols-3">
            <StatTile label="Peserta" value={String(statistics.participants ?? 0)} />
            <StatTile label="Mulai" value={String(statistics.started ?? 0)} />
            <StatTile label="Selesai" value={`${statistics.completed ?? 0} (${statistics.completion_rate ?? 0}%)`} />
          </div>

          <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs text-slate-600">
                <tr>
                  <th className="px-3 py-2">Halaman</th>
                  <th className="px-3 py-2">Wajib</th>
                  <th className="px-3 py-2">Cabang</th>
                  <th className="px-3 py-2">Dikunjungi</th>
                </tr>
              </thead>
              <tbody>
                {(statistics.pages as Array<Record<string, unknown>>)?.map((page) => (
                  <tr key={String(page.id)} className="border-t border-slate-100">
                    <td className="px-3 py-2 text-slate-800">{String(page.title ?? '')}</td>
                    <td className="px-3 py-2">{page.is_required ? 'Ya' : 'Tidak'}</td>
                    <td className="px-3 py-2">{String(page.branches ?? 0)}</td>
                    <td className="px-3 py-2">{String(page.visits ?? 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}

function BranchEditor({ page, pages, onSaved }: { page: Record<string, unknown>; pages: Array<Record<string, unknown>>; onSaved: () => void }) {
  const [label, setLabel] = useState('');
  const [answerKey, setAnswerKey] = useState('');
  const [correct, setCorrect] = useState(true);
  const [target, setTarget] = useState<number | ''>('');

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-slate-50 p-2 text-xs">
      <Input className="w-32" value={label} onChange={(event) => setLabel(event.target.value)} placeholder="Label" />
      <Input className="w-28" value={answerKey} onChange={(event) => setAnswerKey(event.target.value)} placeholder="Kunci jawaban" />
      <label className="flex items-center gap-1">
        <input type="checkbox" checked={correct} onChange={(event) => setCorrect(event.target.checked)} /> benar
      </label>
      <Select value={String(target)} onChange={(event) => setTarget(event.target.value === '' ? '' : Number(event.target.value))}>
        <option value="">Halaman tujuan…</option>
        {pages
          .filter((item) => Number(item.id) !== Number(page.id))
          .map((item) => (
            <option key={String(item.id)} value={String(item.id)}>
              {String(item.title ?? '')}
            </option>
          ))}
      </Select>
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={target === ''}
        onClick={() =>
          void activityApi
            .updateLessonPage(Number(page.id), {
              branches: [{ label, answer_key: answerKey, is_correct: correct, next_page_id: target }],
            })
            .then(onSaveDone)
        }
      >
        Tambah cabang
      </Button>
    </div>
  );

  function onSaveDone() {
    setLabel('');
    setAnswerKey('');
    setTarget('');
    onSaved();
  }
}

/** Pengelolaan polling: pilihan + hasil (§38). */
export function ChoiceManage({ activity }: { activity: LmsActivity }) {
  const [view, setView] = useState<Record<string, unknown> | null>(null);
  const [options, setOptions] = useState<string[]>([]);
  const [results, setResults] = useState<Record<string, unknown> | null>(null);
  const [config, setConfig] = useState<Record<string, unknown>>({});

  const load = useCallback(async () => {
    const payload = await activityApi.choice(activity.id);
    setView(payload as unknown as Record<string, unknown>);
    setOptions(payload.options.map((option) => option.text));
    setConfig({
      allow_multiple: payload.choice.allow_multiple,
      limit_responses: payload.choice.limit_responses,
      anonymous: payload.choice.anonymous,
      results_visibility: payload.choice.results_visibility,
    });
    const resultPayload = await activityApi.choiceResults(activity.id);
    setResults(resultPayload as unknown as Record<string, unknown>);
  }, [activity.id]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card className="space-y-3 p-4">
        <p className="text-sm font-medium text-slate-800">Pilihan</p>
        {options.map((option, index) => (
          <div key={index} className="flex items-center gap-2">
            <Input
              value={option}
              onChange={(event) => setOptions((prev) => prev.map((item, i) => (i === index ? event.target.value : item)))}
            />
            <Button type="button" variant="ghost" size="sm" onClick={() => setOptions((prev) => prev.filter((_, i) => i !== index))}>
              <Trash2 className="h-4 w-4 text-red-500" />
            </Button>
          </div>
        ))}

        <Button type="button" variant="outline" size="sm" onClick={() => setOptions((prev) => [...prev, ''])}>
          <Plus className="mr-1 h-4 w-4" /> Tambah pilihan
        </Button>

        <div className="grid gap-2 sm:grid-cols-2">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Visibilitas hasil</span>
            <Select value={String(config.results_visibility)} onChange={(event) => setConfig((prev) => ({ ...prev, results_visibility: event.target.value }))}>
              <option value="AFTER_RESPONSE">Setelah menjawab</option>
              <option value="AFTER_CLOSE">Setelah ditutup</option>
              <option value="ALWAYS">Selalu</option>
              <option value="NEVER">Tidak pernah</option>
            </Select>
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Batas pilihan per siswa</span>
            <Input
              type="number"
              min="0"
              value={String(config.limit_responses ?? 1)}
              onChange={(event) => setConfig((prev) => ({ ...prev, limit_responses: Number(event.target.value) }))}
            />
          </label>
        </div>

        <div className="flex flex-wrap gap-4 text-sm text-slate-700">
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={Boolean(config.allow_multiple)}
              onChange={(event) => setConfig((prev) => ({ ...prev, allow_multiple: event.target.checked }))}
            /> Boleh memilih lebih dari satu
          </label>
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={Boolean(config.anonymous)} onChange={(event) => setConfig((prev) => ({ ...prev, anonymous: event.target.checked }))} />{' '}
            Anonim
          </label>
        </div>

        <Button
          type="button"
          size="sm"
          onClick={() =>
            void activityApi
              .updateChoice(activity.id, { ...config, options: options.map((text) => ({ text })) })
              .then(() => load())
          }
        >
          Simpan polling
        </Button>
      </Card>

      <Card className="space-y-3 p-4">
        <p className="text-sm font-medium text-slate-800">Hasil</p>
        {results && (
          <>
            <p className="text-xs text-slate-500">
              {String(results.total_responses ?? 0)} jawaban dari {String(results.participants ?? 0)} peserta
            </p>
            <ul className="space-y-2">
              {(results.options as Array<{ id: number; text: string; count: number; percent: number }>)?.map((option) => (
                <li key={option.id}>
                  <div className="flex items-center justify-between text-xs text-slate-600">
                    <span>{option.text}</span>
                    <span>
                      {option.count} · {option.percent}%
                    </span>
                  </div>
                  <div className="mt-1 h-2 rounded bg-slate-100">
                    <div className="h-2 rounded bg-brand-500" style={{ width: `${Math.min(100, option.percent)}%` }} />
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}
        {view === null && <p className="text-sm text-slate-500">Memuat…</p>}
      </Card>
    </div>
  );
}

/** Pengelolaan survei: pertanyaan + hasil (§39). */
export function SurveyManage({ activity }: { activity: LmsActivity }) {
  const [questions, setQuestions] = useState<Array<{ id?: number; text: string; question_type: string; options?: string[]; is_required?: boolean }>>([]);
  const [config, setConfig] = useState<Record<string, unknown>>({});
  const [results, setResults] = useState<SurveyResults | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    const payload = await activityApi.survey(activity.id);
    setQuestions(
      payload.questions.map((question) => ({
        id: question.id,
        text: question.text,
        question_type: question.question_type,
        options: question.options ?? [],
        is_required: question.is_required,
      })),
    );
    setConfig({
      anonymous: payload.survey.anonymous,
      allow_multiple_submissions: payload.survey.allow_multiple_submissions,
      show_results_to_students: payload.survey.show_results_to_students,
    });
    if (payload.can_manage) {
      const resultPayload = await activityApi.surveyResults(activity.id);
      setResults(resultPayload);
    }
  }, [activity.id]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card className="space-y-3 p-4">
        <p className="text-sm font-medium text-slate-800">Pertanyaan</p>

        {questions.map((question, index) => (
          <div key={index} className="space-y-2 rounded-md border border-slate-200 p-3">
            <Input
              value={question.text}
              onChange={(event) => setQuestions((prev) => prev.map((item, i) => (i === index ? { ...item, text: event.target.value } : item)))}
              placeholder="Teks pertanyaan"
            />
            <Select
              value={question.question_type}
              onChange={(event) => setQuestions((prev) => prev.map((item, i) => (i === index ? { ...item, question_type: event.target.value } : item)))}
            >
              <option value="RATING">Skala penilaian</option>
              <option value="MULTIPLE_CHOICE">Pilihan ganda</option>
              <option value="YES_NO">Ya / Tidak</option>
              <option value="TEXT">Teks bebas</option>
            </Select>

            {question.question_type === 'MULTIPLE_CHOICE' && (
              <Input
                value={(question.options ?? []).join(', ')}
                onChange={(event) =>
                  setQuestions((prev) =>
                    prev.map((item, i) => (i === index ? { ...item, options: event.target.value.split(',').map((v) => v.trim()) } : item)),
                  )
                }
                placeholder="Pilihan dipisah koma"
              />
            )}

            <label className="flex items-center gap-2 text-xs text-slate-600">
              <input
                type="checkbox"
                checked={question.is_required !== false}
                onChange={(event) => setQuestions((prev) => prev.map((item, i) => (i === index ? { ...item, is_required: event.target.checked } : item)))}
              />
              Wajib diisi
            </label>
          </div>
        ))}

        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setQuestions((prev) => [...prev, { text: '', question_type: 'RATING', is_required: true }])}
        >
          <Plus className="mr-1 h-4 w-4" /> Tambah pertanyaan
        </Button>

        <div className="flex flex-wrap gap-4 text-sm text-slate-700">
          <label className="flex items-center gap-2">
            <input type="checkbox" checked={Boolean(config.anonymous)} onChange={(event) => setConfig((prev) => ({ ...prev, anonymous: event.target.checked }))} />{' '}
            Survei anonim
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={Boolean(config.show_results_to_students)}
              onChange={(event) => setConfig((prev) => ({ ...prev, show_results_to_students: event.target.checked }))}
            />{' '}
            Tampilkan rekap ke siswa
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              checked={Boolean(config.allow_multiple_submissions)}
              onChange={(event) => setConfig((prev) => ({ ...prev, allow_multiple_submissions: event.target.checked }))}
            />{' '}
            Izinkan mengisi berulang
          </label>
        </div>

        <Button
          type="button"
          size="sm"
          disabled={busy}
          onClick={() => {
            setBusy(true);
            void activityApi
              .updateSurvey(activity.id, {
                ...config,
                questions: questions.map((question, index) => ({
                  id: question.id,
                  text: question.text,
                  question_type: question.question_type,
                  options: question.options,
                  is_required: question.is_required,
                  sort_order: index + 1,
                })),
              })
              .then(() => load())
              .finally(() => setBusy(false));
          }}
        >
          Simpan survei
        </Button>
      </Card>

      <Card className="space-y-3 p-4">
        <p className="text-sm font-medium text-slate-800">Hasil survei</p>
        {results === null && <p className="text-sm text-slate-500">Belum ada jawaban.</p>}
        {results && (
          <>
            <p className="text-xs text-slate-500">
              {results.responses} pengisian{results.anonymous ? ' (anonim)' : ''} · {results.response_rate ?? 0}% peserta
            </p>
            {results.questions.map((question) => (
              <div key={question.id} className="rounded-md border border-slate-200 p-3">
                <p className="text-sm text-slate-800">{question.text}</p>
                {question.average !== undefined && question.average !== null && (
                  <p className="text-xs text-slate-600">Rata-rata: {question.average}</p>
                )}
                {question.distribution && (
                  <ul className="mt-1 space-y-1 text-xs text-slate-600">
                    {Object.entries(question.distribution).map(([label, count]) => (
                      <li key={label} className="flex justify-between">
                        <span>{label}</span>
                        <span>{count}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {question.comments && question.comments.length > 0 && (
                  <ul className="mt-1 space-y-1 text-xs text-slate-600">
                    {question.comments.map((comment, index) => (
                      <li key={index} className="rounded bg-slate-50 px-2 py-1">
                        {comment}
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </>
        )}
      </Card>
    </div>
  );
}
