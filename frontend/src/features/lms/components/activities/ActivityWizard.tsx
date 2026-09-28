import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { activityApi } from '@/features/lms/api/activityApi';
import type { ActivityTypeOption } from '@/features/lms/types.phase2';
import { ArrowRight, Check } from 'lucide-react';
import { cn } from '@/lib/utils';

const STEPS = ['Tipe', 'Dasar', 'Jadwal', 'Penyelesaian'] as const;

/**
 * Wizard pembuatan aktivitas (§43).
 *
 * Guru tidak pernah menghadapi satu formulir raksasa: tipe → dasar → jadwal →
 * penyelesaian, lalu tersimpan sebagai draf dan diarahkan ke halaman kelola
 * untuk detail konten (soal, halaman, pilihan).
 */
export function ActivityWizard({
  open,
  sectionId,
  onClose,
  onCreated,
}: {
  open: boolean;
  sectionId: number;
  onClose: () => void;
  onCreated: (activityId: number) => void;
}) {
  const [step, setStep] = useState(0);
  const [types, setTypes] = useState<ActivityTypeOption[]>([]);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [form, setForm] = useState({
    activity_type: 'ASSIGNMENT' as ActivityTypeOption['value'],
    title: '',
    description: '',
    instructions: '',
    start_at: '',
    due_at: '',
    close_at: '',
    is_required: true,
    completion_type: 'SUBMIT',
    completion_min_grade: '',
    require_previous_item: false,
    estimated_minutes: '',
  });

  useEffect(() => {
    if (open && types.length === 0) {
      void activityApi.types().then((payload) => setTypes(payload.types)).catch(() => undefined);
    }
  }, [open, types.length]);

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const created = await activityApi.create(sectionId, {
        ...form,
        completion_min_grade: form.completion_min_grade === '' ? null : Number(form.completion_min_grade),
        estimated_minutes: form.estimated_minutes === '' ? null : Number(form.estimated_minutes),
        start_at: form.start_at || null,
        due_at: form.due_at || null,
        close_at: form.close_at || null,
      });
      onCreated(created.id);
      onClose();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Aktivitas gagal dibuat.');
    } finally {
      setBusy(false);
    }
  }

  const canNext = step !== 1 || form.title.trim().length >= 3;

  return (
    <Modal open={open} onClose={onClose} title="Aktivitas baru" size="lg">
      <div className="space-y-4">
        <ol className="flex flex-wrap items-center gap-2 text-xs">
          {STEPS.map((label, index) => (
            <li key={label} className="flex items-center gap-2">
              <span
                className={cn(
                  'flex h-6 w-6 items-center justify-center rounded-full border text-[11px]',
                  index < step ? 'border-emerald-500 bg-emerald-500 text-white' : index === step ? 'border-brand-500 text-brand-700' : 'border-slate-300 text-slate-400',
                )}
              >
                {index < step ? <Check className="h-3 w-3" /> : index + 1}
              </span>
              <span className={index === step ? 'font-medium text-slate-800' : 'text-slate-500'}>{label}</span>
              {index < STEPS.length - 1 && <ArrowRight className="h-3 w-3 text-slate-300" />}
            </li>
          ))}
        </ol>

        {step === 0 && (
          <div className="grid gap-2 sm:grid-cols-2">
            {types.map((type) => (
              <button
                key={type.value}
                type="button"
                onClick={() => setForm((prev) => ({ ...prev, activity_type: type.value }))}
                className={cn(
                  'rounded-lg border p-3 text-left text-sm',
                  form.activity_type === type.value ? 'border-brand-400 bg-brand-50' : 'border-slate-200 hover:border-brand-300',
                )}
              >
                <p className="font-medium text-slate-800">{type.label}</p>
                <p className="mt-1 text-xs text-slate-500">{type.graded ? 'Menghasilkan nilai' : 'Tidak menghasilkan nilai'}</p>
              </button>
            ))}
          </div>
        )}

        {step === 1 && (
          <div className="space-y-3">
            <label className="block text-sm">
              <span className="mb-1 block text-xs text-slate-500">Judul aktivitas</span>
              <Input value={form.title} onChange={(event) => setForm((prev) => ({ ...prev, title: event.target.value }))} placeholder="mis. Latihan Aljabar 1" />
            </label>
            <label className="block text-sm">
              <span className="mb-1 block text-xs text-slate-500">Deskripsi singkat</span>
              <Textarea rows={2} value={form.description} onChange={(event) => setForm((prev) => ({ ...prev, description: event.target.value }))} />
            </label>
            <label className="block text-sm">
              <span className="mb-1 block text-xs text-slate-500">Instruksi (HTML sederhana diizinkan)</span>
              <Textarea rows={4} value={form.instructions} onChange={(event) => setForm((prev) => ({ ...prev, instructions: event.target.value }))} />
            </label>
          </div>
        )}

        {step === 2 && (
          <div className="grid gap-3 sm:grid-cols-3">
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Dibuka</span>
              <Input type="datetime-local" value={form.start_at} onChange={(event) => setForm((prev) => ({ ...prev, start_at: event.target.value }))} />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Tenggat</span>
              <Input type="datetime-local" value={form.due_at} onChange={(event) => setForm((prev) => ({ ...prev, due_at: event.target.value }))} />
            </label>
            <label className="text-sm">
              <span className="mb-1 block text-xs text-slate-500">Ditutup (cutoff)</span>
              <Input type="datetime-local" value={form.close_at} onChange={(event) => setForm((prev) => ({ ...prev, close_at: event.target.value }))} />
            </label>
          </div>
        )}

        {step === 3 && (
          <div className="space-y-3">
            <label className="block text-sm">
              <span className="mb-1 block text-xs text-slate-500">Aturan penyelesaian</span>
              <Select value={form.completion_type} onChange={(event) => setForm((prev) => ({ ...prev, completion_type: event.target.value }))}>
                <option value="SUBMIT">Setelah dikirim/dikerjakan</option>
                <option value="VIEW">Setelah dibuka</option>
                <option value="PASS">Setelah lulus nilai minimum</option>
                <option value="MANUAL">Ditandai manual</option>
                <option value="COMPLETE_ACTIVITY">Setelah aktivitas tuntas</option>
              </Select>
            </label>

            <label className="block text-sm">
              <span className="mb-1 block text-xs text-slate-500">Nilai minimum (opsional)</span>
              <Input
                type="number"
                value={form.completion_min_grade}
                onChange={(event) => setForm((prev) => ({ ...prev, completion_min_grade: event.target.value }))}
                placeholder="mis. 70"
              />
            </label>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={form.require_previous_item}
                onChange={(event) => setForm((prev) => ({ ...prev, require_previous_item: event.target.checked }))}
              />
              Wajib menyelesaikan item sebelumnya
            </label>

            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={form.is_required}
                onChange={(event) => setForm((prev) => ({ ...prev, is_required: event.target.checked }))}
              />
              Hitung dalam progres course
            </label>
          </div>
        )}

        {error && <p className="text-sm text-red-600">{error}</p>}

        <div className="flex items-center justify-between border-t border-slate-200 pt-3">
          <Button type="button" variant="ghost" onClick={() => setStep((value) => Math.max(0, value - 1))} disabled={step === 0}>
            Kembali
          </Button>

          <div className="flex items-center gap-2">
            {step < STEPS.length - 1 ? (
              <Button type="button" onClick={() => setStep((value) => value + 1)} disabled={!canNext}>
                Lanjut
              </Button>
            ) : (
              <Button type="button" onClick={save} disabled={busy || form.title.trim() === ''}>
                Simpan sebagai draf
              </Button>
            )}
          </div>
        </div>
      </div>
    </Modal>
  );
}
