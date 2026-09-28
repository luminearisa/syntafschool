import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import type { QuestionPayload, QuestionTypeValue } from '@/features/lms/types.phase2';
import { Plus, Trash2 } from 'lucide-react';

type OptionDraft = { option_key: string; text: string; is_correct: boolean; match_target: string; feedback?: string };

const TYPE_OPTIONS: Array<{ value: QuestionTypeValue; label: string }> = [
  { value: 'MULTIPLE_CHOICE_SINGLE', label: 'Pilihan ganda (satu jawaban)' },
  { value: 'MULTIPLE_CHOICE_MULTIPLE', label: 'Pilihan ganda (banyak jawaban)' },
  { value: 'TRUE_FALSE', label: 'Benar / Salah' },
  { value: 'SHORT_ANSWER', label: 'Jawaban singkat' },
  { value: 'NUMERICAL', label: 'Numerik' },
  { value: 'ESSAY', label: 'Esai (dinilai manual)' },
  { value: 'MATCHING', label: 'Menjodohkan' },
];

/**
 * Editor soal untuk bank soal & kuis (§14–§21).
 *
 * Satu komponen menangani seluruh tipe soal: guru memilih tipe, mengisi opsi,
 * dan (bila tersedia) memakai soal yang sudah ada dari bank.
 */
export function QuestionEditor({
  bankId,
  categoryId,
  courseId,
  existing,
  onSaved,
  onCancel,
}: {
  bankId?: number | null;
  categoryId?: number | null;
  courseId?: number | null;
  existing?: QuestionPayload | null;
  onSaved: (question: QuestionPayload) => void;
  onCancel?: () => void;
}) {
  const [type, setType] = useState<QuestionTypeValue>(existing?.question_type ?? 'MULTIPLE_CHOICE_SINGLE');
  const [name, setName] = useState(existing?.name ?? '');
  const [text, setText] = useState(existing?.question_text ?? '');
  const [mark, setMark] = useState(existing?.default_mark ?? 1);
  const [difficulty, setDifficulty] = useState(existing?.difficulty ?? 'MEDIUM');
  const [feedback, setFeedback] = useState(existing?.general_feedback ?? '');
  const [options, setOptions] = useState<OptionDraft[]>([]);
  const [accepted, setAccepted] = useState('');
  const [caseSensitive, setCaseSensitive] = useState(false);
  const [correctAnswer, setCorrectAnswer] = useState('');
  const [tolerance, setTolerance] = useState('0');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (existing) {
      setType(existing.question_type);
      setName(existing.name);
      setText(existing.question_text);
      setMark(existing.default_mark);
      setDifficulty(existing.difficulty);
      setFeedback(existing.general_feedback ?? '');
      setOptions(
        existing.options.map((option, index) => ({
          option_key: option.option_key ?? String.fromCharCode(65 + index),
          text: option.text,
          is_correct: Boolean(option.is_correct),
          match_target: option.match_target ?? '',
        })),
      );

      const settings = (existing.settings ?? {}) as Record<string, unknown>;
      setAccepted(((settings.accepted_answers as string[]) ?? []).join('\n'));
      setCaseSensitive(Boolean(settings.case_sensitive));
      setCorrectAnswer(settings.correct_answer === undefined || settings.correct_answer === null ? '' : String(settings.correct_answer));
      setTolerance(String(settings.tolerance ?? 0));
    }
  }, [existing]);

  // Tipe TRUE_FALSE otomatis memiliki dua opsi Benar/Salah.
  useEffect(() => {
    if (type === 'TRUE_FALSE') {
      setOptions([
        { option_key: 'TRUE', text: 'Benar', is_correct: options.find((option) => option.option_key === 'TRUE')?.is_correct ?? true, match_target: '' },
        { option_key: 'FALSE', text: 'Salah', is_correct: options.find((option) => option.option_key === 'FALSE')?.is_correct ?? false, match_target: '' },
      ]);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [type]);

  function addOption() {
    setOptions((prev) => [
      ...prev,
      { option_key: String.fromCharCode(65 + prev.length), text: '', is_correct: false, match_target: '' },
    ]);
  }

  async function save() {
    setBusy(true);
    setError(null);

    const settings: Record<string, unknown> = {};

    if (type === 'SHORT_ANSWER') {
      settings.accepted_answers = accepted.split('\n').map((line) => line.trim()).filter(Boolean);
      settings.case_sensitive = caseSensitive;
      settings.exact_match = true;
    }

    if (type === 'NUMERICAL') {
      settings.correct_answer = Number(correctAnswer);
      settings.tolerance = Number(tolerance);
    }

    const payload: Record<string, unknown> = {
      bank_id: bankId ?? existing?.bank_id ?? null,
      category_id: categoryId ?? existing?.category_id ?? null,
      course_id: courseId ?? existing?.course_id ?? null,
      name: name || text.slice(0, 60),
      question_type: type,
      question_text: text,
      general_feedback: feedback || null,
      default_mark: Number(mark),
      difficulty,
      settings,
      options:
        type === 'SHORT_ANSWER' || type === 'NUMERICAL' || type === 'ESSAY'
          ? []
          : options.filter((option) => option.text.trim() !== '').map((option) => ({
              option_key: option.option_key,
              text: option.text,
              is_correct: option.is_correct,
              match_target: option.match_target || null,
            })),
    };

    try {
      const saved = existing
        ? await activityApi.updateQuestion(existing.id, payload)
        : await activityApi.createQuestion(payload);
      onSaved(saved);
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Soal gagal disimpan. Periksa kembali isian Anda.');
    } finally {
      setBusy(false);
    }
  }

  const showOptions = ['MULTIPLE_CHOICE_SINGLE', 'MULTIPLE_CHOICE_MULTIPLE', 'MATCHING'].includes(type);

  return (
    <div className="space-y-3 rounded-lg border border-slate-200 bg-white p-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h3 className="text-sm font-semibold text-slate-800">{existing ? `Ubah soal (versi ${existing.version})` : 'Soal baru'}</h3>
        {existing && <Badge tone="gray">Versi {existing.version}</Badge>}
      </div>

      <div className="grid gap-3 sm:grid-cols-2">
        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">Tipe soal</span>
          <Select value={type} onChange={(event) => setType(event.target.value as QuestionTypeValue)}>
            {TYPE_OPTIONS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </Select>
        </label>

        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">Judul singkat (untuk guru)</span>
          <Input value={name} onChange={(event) => setName(event.target.value)} placeholder="mis. Penjumlahan dasar" />
        </label>
      </div>

      <label className="block text-sm">
        <span className="mb-1 block text-xs text-slate-500">Pertanyaan</span>
        <Textarea rows={3} value={text} onChange={(event) => setText(event.target.value)} placeholder="Tulis pertanyaan…" />
      </label>

      {showOptions && (
        <div className="space-y-2">
          <p className="text-xs text-slate-500">
            {type === 'MATCHING' ? 'Isi pasangan kiri (teks) dan kanan (target).' : 'Tandai jawaban benar.'}
          </p>
          {options.map((option, index) => (
            <div key={index} className="flex flex-wrap items-center gap-2">
              <Input
                className="w-16"
                value={option.option_key}
                onChange={(event) =>
                  setOptions((prev) => prev.map((item, i) => (i === index ? { ...item, option_key: event.target.value } : item)))
                }
              />
              <Input
                className="min-w-[180px] flex-1"
                value={option.text}
                placeholder={type === 'MATCHING' ? 'Kiri' : 'Pilihan'}
                onChange={(event) => setOptions((prev) => prev.map((item, i) => (i === index ? { ...item, text: event.target.value } : item)))}
              />
              {type === 'MATCHING' && (
                <Input
                  className="min-w-[160px] flex-1"
                  value={option.match_target}
                  placeholder="Pasangan kanan"
                  onChange={(event) =>
                    setOptions((prev) => prev.map((item, i) => (i === index ? { ...item, match_target: event.target.value } : item)))
                  }
                />
              )}
              <label className="flex items-center gap-1 text-xs text-slate-600">
                <input
                  type={type === 'MULTIPLE_CHOICE_MULTIPLE' ? 'checkbox' : 'radio'}
                  name="correct-option"
                  checked={option.is_correct}
                  onChange={() =>
                    setOptions((prev) =>
                      prev.map((item, i) =>
                        type === 'MULTIPLE_CHOICE_MULTIPLE'
                          ? i === index
                            ? { ...item, is_correct: !item.is_correct }
                            : item
                          : { ...item, is_correct: i === index },
                      ),
                    )
                  }
                />
                Benar
              </label>
              {type !== 'TRUE_FALSE' && (
                <button type="button" className="text-red-500" onClick={() => setOptions((prev) => prev.filter((_, i) => i !== index))}>
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </div>
          ))}
          {type !== 'TRUE_FALSE' && (
            <Button type="button" variant="outline" size="sm" onClick={addOption}>
              <Plus className="mr-1 h-4 w-4" /> Tambah pilihan
            </Button>
          )}
        </div>
      )}

      {type === 'SHORT_ANSWER' && (
        <div className="space-y-2">
          <label className="block text-sm">
            <span className="mb-1 block text-xs text-slate-500">Jawaban yang diterima (satu per baris)</span>
            <Textarea rows={3} value={accepted} onChange={(event) => setAccepted(event.target.value)} placeholder={'Jakarta\nDKI Jakarta'} />
          </label>
          <label className="flex items-center gap-2 text-xs text-slate-600">
            <input type="checkbox" checked={caseSensitive} onChange={(event) => setCaseSensitive(event.target.checked)} /> Peka huruf besar/kecil
          </label>
        </div>
      )}

      {type === 'NUMERICAL' && (
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Jawaban benar</span>
            <Input type="number" step="any" value={correctAnswer} onChange={(event) => setCorrectAnswer(event.target.value)} placeholder="3.14" />
          </label>
          <label className="text-sm">
            <span className="mb-1 block text-xs text-slate-500">Toleransi</span>
            <Input type="number" step="any" value={tolerance} onChange={(event) => setTolerance(event.target.value)} placeholder="0.01" />
          </label>
        </div>
      )}

      <div className="grid gap-3 sm:grid-cols-3">
        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">Mark bawaan</span>
          <Input type="number" min="0.5" step="0.5" value={mark} onChange={(event) => setMark(Number(event.target.value))} />
        </label>
        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">Kesulitan</span>
          <Select value={difficulty} onChange={(event) => setDifficulty(event.target.value as 'EASY' | 'MEDIUM' | 'HARD')}>
            <option value="EASY">Mudah</option>
            <option value="MEDIUM">Sedang</option>
            <option value="HARD">Sulit</option>
          </Select>
        </label>
        <label className="text-sm">
          <span className="mb-1 block text-xs text-slate-500">Umpan balik umum</span>
          <Input value={feedback} onChange={(event) => setFeedback(event.target.value)} placeholder="Opsional" />
        </label>
      </div>

      <div className="flex items-center gap-2">
        <Button type="button" size="sm" onClick={save} disabled={busy || text.trim() === ''}>
          {existing ? 'Simpan perubahan (versi baru)' : 'Simpan soal'}
        </Button>
        {onCancel && (
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            Batal
          </Button>
        )}
      </div>

      {error && <p className="text-sm text-red-600">{error}</p>}

      {existing && existing.versions && existing.versions.length > 0 && (
        <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
          <p className="text-xs font-medium text-slate-600">Riwayat versi (percobaan lama tetap memakai versinya sendiri)</p>
          <ul className="mt-1 space-y-1 text-xs text-slate-600">
            {existing.versions.map((version) => (
              <li key={version.id}>
                v{version.version} · {version.question_text.slice(0, 60)}
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  );
}
