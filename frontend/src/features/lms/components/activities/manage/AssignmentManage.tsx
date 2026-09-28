import { useCallback, useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import type { AssignmentOverview, AssignmentSubmission, LmsActivity } from '@/features/lms/types.phase2';
import { CheckCircle2, Download, Star } from 'lucide-react';

/** Panel pengelolaan tugas: pengumpulan, penilaian, statistik, pengaturan (§7, §11). */
export function AssignmentManage({ activity }: { activity: LmsActivity }) {
  const [tab, setTab] = useState<'submissions' | 'grading' | 'statistics' | 'settings'>('submissions');
  const [overview, setOverview] = useState<AssignmentOverview | null>(null);
  const [filter, setFilter] = useState({ status: '', late: '', graded: '' });
  const [selected, setSelected] = useState<AssignmentSubmission | null>(null);
  const [grade, setGrade] = useState('');
  const [feedback, setFeedback] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [config, setConfig] = useState<Record<string, unknown>>({});

  const load = useCallback(async () => {
    const payload = await activityApi.assignmentOverview(activity.id, {
      status: filter.status || undefined,
      late: filter.late || undefined,
      graded: filter.graded || undefined,
    });
    setOverview(payload);
    setConfig({
      submission_type: payload.assignment.submission_type,
      maximum_grade: payload.assignment.maximum_grade,
      passing_grade: payload.assignment.passing_grade ?? '',
      maximum_attempts: payload.assignment.maximum_attempts,
      allow_late_submission: payload.assignment.allow_late_submission,
      late_penalty_percent: payload.assignment.late_penalty_percent,
      require_file: payload.assignment.require_file,
      require_text: payload.assignment.require_text,
      allowed_file_types: payload.assignment.allowed_file_types,
    });
  }, [activity.id, filter.graded, filter.late, filter.status]);

  useEffect(() => {
    void load().catch(() => undefined);
  }, [load]);

  async function saveGrade() {
    if (!selected) return;
    setBusy(true);
    setError(null);
    try {
      await activityApi.gradeSubmission(selected.id, { grade: Number(grade), feedback: feedback || null });
      setSelected(null);
      setGrade('');
      setFeedback('');
      await load();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Nilai gagal disimpan.');
    } finally {
      setBusy(false);
    }
  }

  async function saveConfig() {
    setBusy(true);
    setError(null);
    try {
      await activityApi.updateAssignment(activity.id, config);
      await load();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Konfigurasi gagal disimpan.');
    } finally {
      setBusy(false);
    }
  }

  const statistics = overview?.statistics;

  return (
    <div className="space-y-4">
      <TabBar
        active={tab}
        onChange={(value) => setTab(value as typeof tab)}
        tabs={[
          { key: 'submissions', label: 'Pengumpulan' },
          { key: 'grading', label: 'Penilaian' },
          { key: 'statistics', label: 'Statistik' },
          { key: 'settings', label: 'Pengaturan' },
        ]}
      />

      {tab === 'submissions' && (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2">
            <Select value={filter.status} onChange={(event) => setFilter((prev) => ({ ...prev, status: event.target.value }))}>
              <option value="">Semua status</option>
              <option value="DRAFT">Draf</option>
              <option value="SUBMITTED">Terkirim</option>
              <option value="GRADED">Sudah dinilai</option>
              <option value="RETURNED">Dikembalikan</option>
            </Select>
            <Select value={filter.late} onChange={(event) => setFilter((prev) => ({ ...prev, late: event.target.value }))}>
              <option value="">Tepat waktu & terlambat</option>
              <option value="true">Terlambat</option>
              <option value="false">Tepat waktu</option>
            </Select>
            <Select value={filter.graded} onChange={(event) => setFilter((prev) => ({ ...prev, graded: event.target.value }))}>
              <option value="">Dinilai & belum</option>
              <option value="false">Belum dinilai</option>
              <option value="true">Sudah dinilai</option>
            </Select>
          </div>

          <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full text-sm">
              <thead className="bg-slate-50 text-left text-xs text-slate-600">
                <tr>
                  <th className="px-3 py-2">Siswa</th>
                  <th className="px-3 py-2">Status</th>
                  <th className="px-3 py-2">Dikirim</th>
                  <th className="px-3 py-2">Terlambat</th>
                  <th className="px-3 py-2">Nilai</th>
                  <th className="px-3 py-2" />
                </tr>
              </thead>
              <tbody>
                {(overview?.submissions.data ?? []).map((submission) => (
                  <tr key={submission.id} className="border-t border-slate-100">
                    <td className="px-3 py-2 text-slate-800">{submission.student?.name ?? `Pengguna #${submission.student?.user_id}`}</td>
                    <td className="px-3 py-2">
                      <Badge tone={submission.status === 'GRADED' ? 'green' : submission.status === 'DRAFT' ? 'gray' : 'blue'}>
                        {submission.status_label}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-xs text-slate-600">{formatDate(submission.submitted_at, true)}</td>
                    <td className="px-3 py-2 text-xs">{submission.late ? <span className="text-red-600">Terlambat</span> : '—'}</td>
                    <td className="px-3 py-2 text-slate-800">
                      {submission.grade !== null ? `${submission.grade} / ${statistics?.maximum_grade ?? 100}` : '—'}
                    </td>
                    <td className="px-3 py-2 text-right">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setSelected(submission);
                          setGrade(String(submission.grade ?? ''));
                          setFeedback(submission.feedback ?? '');
                          setTab('grading');
                        }}
                      >
                        <Star className="mr-1 h-4 w-4" /> Nilai
                      </Button>
                    </td>
                  </tr>
                ))}
                {(overview?.submissions.data ?? []).length === 0 && (
                  <tr>
                    <td colSpan={6} className="px-3 py-6 text-center text-sm text-slate-500">
                      Belum ada pengumpulan yang cocok dengan filter.
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {tab === 'grading' && (
        <div className="space-y-3">
          {!selected ? (
            <p className="text-sm text-slate-500">Pilih pengumpulan pada tab “Pengumpulan” untuk menilai.</p>
          ) : (
            <Card className="space-y-3 p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="text-sm font-medium text-slate-800">{selected.student?.name ?? `Pengguna #${selected.student?.user_id}`}</p>
                  <p className="text-xs text-slate-500">
                    Percobaan {selected.attempt} · dikirim {formatDate(selected.submitted_at, true)}
                    {selected.late ? ` · terlambat (penalti ${selected.late_penalty_percent ?? 0}%)` : ''}
                  </p>
                </div>
                <Badge tone={selected.status === 'GRADED' ? 'green' : 'blue'}>{selected.status_label}</Badge>
              </div>

              {selected.text_content && (
                <div className="rounded-md border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">{selected.text_content}</div>
              )}

              {selected.files.length > 0 && (
                <ul className="space-y-1">
                  {selected.files.map((file) => (
                    <li key={file.id} className="flex items-center gap-2 text-xs">
                      <a className="inline-flex items-center gap-1 text-brand-700 underline" href={activityApi.submissionFileUrl(file.id)} target="_blank" rel="noreferrer">
                        <Download className="h-3.5 w-3.5" /> {file.name}
                      </a>
                      <span className="text-slate-400">{file.size_label}</span>
                    </li>
                  ))}
                </ul>
              )}

              <div className="grid gap-3 sm:grid-cols-[140px_1fr]">
                <label className="text-sm">
                  <span className="mb-1 block text-xs text-slate-500">Nilai (0–{statistics?.maximum_grade ?? 100})</span>
                  <Input type="number" value={grade} onChange={(event) => setGrade(event.target.value)} />
                </label>
                <label className="text-sm">
                  <span className="mb-1 block text-xs text-slate-500">Umpan balik</span>
                  <Textarea rows={2} value={feedback} onChange={(event) => setFeedback(event.target.value)} />
                </label>
              </div>

              <div className="flex flex-wrap items-center gap-2">
                <Button type="button" size="sm" onClick={saveGrade} disabled={busy || grade === ''}>
                  <CheckCircle2 className="mr-1 h-4 w-4" /> Simpan nilai
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  disabled={busy}
                  onClick={() =>
                    void activityApi.returnSubmission(selected.id, 'Perbaiki sesuai catatan guru.').then(async () => {
                      setSelected(null);
                      await load();
                    })
                  }
                >
                  Kembalikan untuk perbaikan
                </Button>
              </div>

              {error && <p className="text-sm text-red-600">{error}</p>}
            </Card>
          )}
        </div>
      )}

      {tab === 'statistics' && statistics && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatTile label="Peserta" value={String(statistics.participants)} />
          <StatTile label="Sudah mengumpulkan" value={`${statistics.submitted} (${statistics.submission_rate ?? 0}%)`} />
          <StatTile label="Belum mengumpulkan" value={String(statistics.not_submitted)} />
          <StatTile label="Terlambat" value={String(statistics.late)} />
          <StatTile label="Sudah dinilai" value={String(statistics.graded)} />
          <StatTile label="Belum dinilai" value={String(statistics.ungraded)} />
          <StatTile label="Rata-rata" value={statistics.average === null ? '—' : String(statistics.average)} />
          <StatTile label="Lulus" value={`${statistics.passed} (${statistics.pass_rate ?? 0}%)`} />
          <StatTile label="Tertinggi" value={statistics.highest === null ? '—' : String(statistics.highest)} />
          <StatTile label="Terendah" value={statistics.lowest === null ? '—' : String(statistics.lowest)} />
        </div>
      )}

      {tab === 'settings' && (
        <Card className="space-y-3 p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Bentuk pengumpulan</span>
              <Select
                value={String(config.submission_type ?? '')}
                onChange={(event) => setConfig((prev) => ({ ...prev, submission_type: event.target.value }))}
              >
                <option value="ONLINE_TEXT">Teks daring</option>
                <option value="FILE">Unggah berkas</option>
                <option value="ONLINE_TEXT_AND_FILE">Teks + berkas</option>
                <option value="NONE">Tanpa pengumpulan</option>
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
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Percobaan maksimum</span>
              <Input
                type="number"
                min="1"
                value={String(config.maximum_attempts ?? 1)}
                onChange={(event) => setConfig((prev) => ({ ...prev, maximum_attempts: Number(event.target.value) }))}
              />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Penalti keterlambatan (%)</span>
              <Input
                type="number"
                min="0"
                value={String(config.late_penalty_percent ?? 0)}
                onChange={(event) => setConfig((prev) => ({ ...prev, late_penalty_percent: Number(event.target.value) }))}
              />
            </label>
          </div>

          <div className="flex flex-wrap gap-4 text-sm text-slate-700">
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.allow_late_submission)}
                onChange={(event) => setConfig((prev) => ({ ...prev, allow_late_submission: event.target.checked }))}
              /> Izinkan pengumpulan terlambat
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.require_text)}
                onChange={(event) => setConfig((prev) => ({ ...prev, require_text: event.target.checked }))}
              /> Wajib teks
            </label>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={Boolean(config.require_file)}
                onChange={(event) => setConfig((prev) => ({ ...prev, require_file: event.target.checked }))}
              /> Wajib berkas
            </label>
          </div>

          <Button type="button" size="sm" onClick={saveConfig} disabled={busy}>
            Simpan pengaturan
          </Button>

          {error && <p className="text-sm text-red-600">{error}</p>}
        </Card>
      )}
    </div>
  );
}

export function TabBar({
  tabs,
  active,
  onChange,
}: {
  tabs: Array<{ key: string; label: string }>;
  active: string;
  onChange: (key: string) => void;
}) {
  return (
    <div className="flex flex-wrap gap-1 border-b border-slate-200">
      {tabs.map((tab) => (
        <button
          key={tab.key}
          type="button"
          onClick={() => onChange(tab.key)}
          className={
            active === tab.key
              ? 'border-b-2 border-brand-500 px-3 py-2 text-sm font-medium text-brand-700'
              : 'px-3 py-2 text-sm text-slate-600 hover:text-slate-900'
          }
        >
          {tab.label}
        </button>
      ))}
    </div>
  );
}

export function StatTile({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white p-3">
      <p className="text-xs text-slate-500">{label}</p>
      <p className="mt-1 text-lg font-semibold text-slate-900">{value}</p>
    </div>
  );
}
