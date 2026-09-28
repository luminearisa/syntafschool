import { useEffect, useMemo, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import { formatDate } from '@/features/lms/lib/lmsLabels';
import type { AssignmentStudentView, SubmissionFile } from '@/features/lms/types.phase2';
import { Download, FileText, Paperclip, Save, Send, Trash2 } from 'lucide-react';

/**
 * Tampilan tugas untuk siswa (§10): instruksi, draf, unggah berkas, kirim,
 * dan hasil penilaian beserta umpan balik.
 */
export function AssignmentPlayer({
  activityId,
  view,
  onChanged,
}: {
  activityId: number;
  view: AssignmentStudentView;
  onChanged: () => void;
}) {
  const [text, setText] = useState(view.submission?.text_content ?? '');
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    setText(view.submission?.text_content ?? '');
  }, [view.submission?.id, view.submission?.text_content]);

  const config = view.assignment;
  const submission = view.submission;
  const editable = !submission || submission.status === 'DRAFT' || submission.status === 'RETURNED';
  const graded = submission?.status === 'GRADED';

  const allowedTypes = useMemo(() => config.allowed_file_types.join(', '), [config.allowed_file_types]);

  async function withBusy<T>(key: string, fn: () => Promise<T>) {
    setBusy(key);
    setError(null);
    setMessage(null);
    try {
      return await fn();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Terjadi kesalahan. Coba lagi.');
      throw err;
    } finally {
      setBusy(null);
    }
  }

  async function handleDraft() {
    await withBusy('draft', async () => {
      await activityApi.saveDraft(activityId, text);
      setMessage('Draf tersimpan.');
      onChanged();
    });
  }

  async function handleSubmit() {
    await withBusy('submit', async () => {
      await activityApi.submitAssignment(activityId, text);
      setMessage('Tugas berhasil dikirim.');
      onChanged();
    });
  }

  async function handleUpload(file: File) {
    await withBusy('upload', async () => {
      const draft = submission?.status === 'DRAFT' ? submission : await activityApi.saveDraft(activityId, text);
      await activityApi.uploadSubmissionFile(draft.id, file);
      setMessage('Berkas berhasil diunggah.');
      onChanged();
    });
  }

  function download(file: SubmissionFile) {
    void activityApi.submissionFileUrl(file.id);
    window.open(`${activityApi.submissionFileUrl(file.id)}`, '_blank');
  }

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-3">
        <div className="rounded-lg border border-slate-200 bg-white p-3">
          <p className="text-xs text-slate-500">Status pengumpulan</p>
          <p className="mt-1 text-sm font-medium text-slate-800">{view.status_label}</p>
        </div>
        <div className="rounded-lg border border-slate-200 bg-white p-3">
          <p className="text-xs text-slate-500">Nilai maksimum</p>
          <p className="mt-1 text-sm font-medium text-slate-800">
            {config.maximum_grade}
            {config.passing_grade !== null && <span className="text-xs text-slate-500"> (lulus ≥ {config.passing_grade})</span>}
          </p>
        </div>
        <div className="rounded-lg border border-slate-200 bg-white p-3">
          <p className="text-xs text-slate-500">Sisa percobaan</p>
          <p className="mt-1 text-sm font-medium text-slate-800">
            {view.attempts_remaining} dari {config.maximum_attempts}
          </p>
        </div>
      </div>

      {graded && submission && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-4">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <p className="text-sm font-semibold text-emerald-900">
              Nilai: {submission.grade} / {config.maximum_grade}
              {submission.passed !== null && (
                <Badge tone={submission.passed ? 'green' : 'red'} className="ml-2">
                  {submission.passed ? 'Lulus' : 'Belum lulus'}
                </Badge>
              )}
            </p>
            <p className="text-xs text-emerald-800">Dinilai {formatDate(submission.graded_at, true)}</p>
          </div>

          {submission.late && (submission.late_penalty_percent ?? 0) > 0 && (
            <p className="mt-2 text-xs text-emerald-900">
              Nilai asli {submission.original_grade} dikurangi penalti keterlambatan {submission.late_penalty_percent}%.
            </p>
          )}

          {submission.feedback && (
            <div className="mt-3 rounded-md border border-emerald-200 bg-white p-3 text-sm text-slate-700">
              <p className="mb-1 text-xs font-medium text-slate-500">Umpan balik guru</p>
              {submission.feedback}
            </div>
          )}

          {submission.files.filter((file) => file.purpose === 'FEEDBACK').length > 0 && (
            <div className="mt-3 space-y-1">
              {submission.files
                .filter((file) => file.purpose === 'FEEDBACK')
                .map((file) => (
                  <button
                    key={file.id}
                    type="button"
                    onClick={() => download(file)}
                    className="flex items-center gap-2 text-xs text-emerald-900 underline"
                  >
                    <Download className="h-3.5 w-3.5" /> {file.name}
                  </button>
                ))}
            </div>
          )}
        </div>
      )}

      {submission?.status === 'RETURNED' && submission.return_reason && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          Dikembalikan untuk diperbaiki: {submission.return_reason}
        </div>
      )}

      {view.can_submit && config.submission_type !== 'NONE' && (
        <div className="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
          <p className="text-sm font-medium text-slate-800">Jawaban Anda</p>

          {config.submission_type !== 'FILE' && (
            <Textarea
              value={text}
              onChange={(event) => setText(event.target.value)}
              rows={6}
              placeholder="Tulis jawaban atau catatan pengerjaan di sini…"
              disabled={!editable}
            />
          )}

          {config.submission_type !== 'ONLINE_TEXT' && (
            <div className="space-y-2">
              <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                <Paperclip className="h-4 w-4" />
                <span>Unggah berkas ({allowedTypes})</span>
                <input
                  type="file"
                  className="ml-1 text-xs"
                  disabled={!editable || busy === 'upload'}
                  onChange={(event) => {
                    const file = event.target.files?.[0];
                    if (file) void handleUpload(file);
                    event.target.value = '';
                  }}
                />
              </label>

              {submission?.files.filter((file) => file.purpose === 'SUBMISSION').length ? (
                <ul className="space-y-1">
                  {submission.files
                    .filter((file) => file.purpose === 'SUBMISSION')
                    .map((file) => (
                      <li key={file.id} className="flex items-center justify-between rounded-md border border-slate-200 px-2 py-1 text-xs">
                        <button type="button" onClick={() => download(file)} className="flex items-center gap-1 text-brand-700 underline">
                          <FileText className="h-3.5 w-3.5" /> {file.name}
                        </button>
                        {editable && (
                          <button
                            type="button"
                            className="text-red-600"
                            onClick={() =>
                              void withBusy('delete', async () => {
                                await activityApi.deleteSubmissionFile(file.id);
                                onChanged();
                              })
                            }
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </button>
                        )}
                      </li>
                    ))}
                </ul>
              ) : (
                <p className="text-xs text-slate-500">Belum ada berkas yang diunggah.</p>
              )}
            </div>
          )}

          <div className="flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" size="sm" onClick={handleDraft} disabled={busy !== null}>
              <Save className="mr-1 h-4 w-4" /> Simpan draf
            </Button>
            <Button type="button" size="sm" onClick={handleSubmit} disabled={busy !== null}>
              <Send className="mr-1 h-4 w-4" /> {submission && submission.status !== 'DRAFT' ? 'Kirim ulang' : 'Kirim tugas'}
            </Button>
            {config.allow_late_submission && view.late_policy.is_past_due && (
              <span className="text-xs text-amber-700">
                Pengumpulan terlambat dikenai penalti {config.late_penalty_percent}%.
              </span>
            )}
          </div>
        </div>
      )}

      {!view.can_submit && submission && !editable && (
        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
          Pengumpulan sudah dikirim pada {formatDate(submission.submitted_at, true)}. Submission final tidak dapat diubah.
        </div>
      )}

      {error && <p className="text-sm text-red-600">{error}</p>}
      {message && <p className="text-sm text-emerald-600">{message}</p>}

      {view.submissions.length > 1 && (
        <div className="rounded-lg border border-slate-200 bg-white p-4">
          <p className="mb-2 text-sm font-medium text-slate-800">Riwayat percobaan</p>
          <ul className="space-y-1 text-xs text-slate-600">
            {view.submissions.map((item) => (
              <li key={item.id} className="flex items-center justify-between">
                <span>
                  Percobaan {item.attempt} · {item.status_label}
                </span>
                <span>
                  {item.grade !== null ? `${item.grade} / ${config.maximum_grade}` : formatDate(item.submitted_at, true)}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
