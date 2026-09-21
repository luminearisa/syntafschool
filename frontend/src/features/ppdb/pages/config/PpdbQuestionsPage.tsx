import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ppdbQuestionsApi } from '../../api/ppdbApi';
import { getValidationErrors } from '../../api/http';
import { usePpdbPeriodOptions } from '../../hooks/usePpdbPeriodOptions';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage } from '@/lib/axios';
import {
  CHOICE_QUESTION_TYPES,
  PpdbActiveBadge,
  PpdbErrorCard,
  PpdbField,
  PpdbFilterCard,
  PpdbLoadingCard,
  PpdbPageHeader,
  PpdbRequiredBadge,
  QUESTION_TYPE_LABELS,
  QUESTION_TYPE_ORDER,
  describeError,
  toNumOrEmpty,
} from '../../components/config/PpdbConfigUi';
import { PpdbNoPeriodState, PpdbPeriodPicker } from '../../components/config/PpdbPeriodPicker';
import {
  PpdbScopeBadge,
  PpdbScopeControls,
  PpdbScopeFilters,
} from '../../components/config/PpdbScopeSelectors';
import {
  PpdbOptionRows,
  fromOptionRows,
  toOptionRows,
  type OptionRow,
} from '../../components/config/PpdbOptionRows';
import type { PpdbQuestion, PpdbQuestionOption, PpdbQuestionPayload, PpdbQuestionType } from '@/types/ppdb';

const PER_PAGE = 10;
const CODE_PATTERN = /^[A-Z0-9][A-Z0-9_-]*$/;

interface QuestionForm {
  period_id: string;
  program_id: string;
  wave_id: string;
  code: string;
  question: string;
  description: string;
  type: PpdbQuestionType;
  required: boolean;
  active: boolean;
  order: string;
}

type FormErrors = Partial<Record<keyof QuestionForm | 'options', string>>;

function questionToForm(
  question: PpdbQuestion | null,
  fallbackPeriodId: number | '',
): QuestionForm {
  return {
    period_id: String(question?.period_id ?? fallbackPeriodId ?? ''),
    program_id: question?.program_id === null || question?.program_id === undefined ? '' : String(question.program_id),
    wave_id: question?.wave_id === null || question?.wave_id === undefined ? '' : String(question.wave_id),
    code: question?.code ?? '',
    question: question?.question ?? '',
    description: question?.description ?? '',
    type: question?.type ?? 'text',
    required: question?.required ?? false,
    active: question?.active ?? true,
    order: question ? String(question.order) : '',
  };
}

function validate(form: QuestionForm, optionCount: number): FormErrors {
  const errors: FormErrors = {};

  if (form.period_id === '') errors.period_id = 'Periode wajib dipilih.';
  if (!form.code.trim()) errors.code = 'Kode pertanyaan wajib diisi.';
  else if (form.code.length > 50) errors.code = 'Kode maksimal 50 karakter.';
  else if (!CODE_PATTERN.test(form.code.trim()))
    errors.code = 'Kode hanya boleh huruf kapital, angka, garis bawah, atau tanda hubung.';

  if (!form.question.trim()) errors.question = 'Teks pertanyaan wajib diisi.';
  else if (form.question.length > 250) errors.question = 'Pertanyaan maksimal 250 karakter.';

  const needsOptions = CHOICE_QUESTION_TYPES.includes(form.type);
  if (needsOptions && optionCount === 0)
    errors.options = 'Tipe pilihan membutuhkan minimal satu opsi jawaban.';

  if (form.order !== '' && (Number(form.order) < 0 || Number(form.order) > 65535))
    errors.order = 'Urutan harus berada di rentang 0 sampai 65535.';

  return errors;
}

/* ============================ FORM MODAL ============================ */

function QuestionFormModal({
  open,
  editing,
  periodId,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  editing: PpdbQuestion | null;
  periodId: number | '';
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: PpdbQuestionPayload) => void;
}) {
  const { options: periodOptions } = usePpdbPeriodOptions();
  const [form, setForm] = useState<QuestionForm>(() => questionToForm(editing, periodId));
  const [options, setOptions] = useState<OptionRow[]>(() => toOptionRows(editing?.options));
  const [localErrors, setLocalErrors] = useState<FormErrors>({});

  useEffect(() => {
    if (!open) return;
    setForm(questionToForm(editing, periodId));
    setOptions(toOptionRows(editing?.options));
    setLocalErrors({});
  }, [open, editing, periodId]);

  const set = <K extends keyof QuestionForm>(key: K, value: QuestionForm[K]) =>
    setForm((prev) => ({ ...prev, [key]: value }));

  const errorOf = (key: keyof FormErrors) => localErrors[key] ?? serverErrors[key]?.[0];
  const needsOptions = CHOICE_QUESTION_TYPES.includes(form.type);

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault();
    const cleanOptions = fromOptionRows(options);
    const errors = validate(form, cleanOptions.length);
    setLocalErrors(errors);
    if (Object.keys(errors).length > 0) {
      toast.error('Periksa kembali isian pertanyaan.');
      return;
    }

    const scope: { program_id: number | ''; wave_id: number | '' } = {
      program_id: form.program_id === '' ? '' : Number(form.program_id),
      wave_id: form.wave_id === '' ? '' : Number(form.wave_id),
    };

    onSubmit({
      period_id: Number(form.period_id),
      ...scope,
      code: form.code.trim().toUpperCase(),
      question: form.question.trim(),
      description: form.description.trim(),
      type: form.type,
      required: form.required,
      active: form.active,
      order: toNumOrEmpty(form.order),
      options: needsOptions
        ? cleanOptions.map<PpdbQuestionOption>((row, index) => ({
            label: row.label,
            value: row.value,
            order: index + 1,
          }))
        : [],
    });
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Ubah Pertanyaan' : 'Tambah Pertanyaan'}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="ppdb-question-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="ppdb-question-form" onSubmit={handleSubmit} className="space-y-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbField label="Periode" required error={errorOf('period_id')}>
            <Select
              value={form.period_id}
              error={errorOf('period_id')}
              onChange={(e) => set('period_id', e.target.value)}
            >
              <option value="">-- Pilih periode --</option>
              {periodOptions.map((option) => (
                <option key={String(option.value)} value={String(option.value)}>
                  {option.label}
                </option>
              ))}
            </Select>
          </PpdbField>
          <PpdbField label="Kode" required error={errorOf('code')} hint="Pengujuk jawaban di laporan, mis. ASAL_SEKOLAH.">
            <Input
              value={form.code}
              placeholder="ASAL_SEKOLAH"
              error={errorOf('code')}
              onChange={(e) => set('code', e.target.value.toUpperCase())}
            />
          </PpdbField>
        </div>

        <PpdbField label="Pertanyaan" required error={errorOf('question')}>
          <Textarea
            rows={2}
            value={form.question}
            placeholder="Dari sekolah mana calon siswa lulus?"
            error={errorOf('question')}
            onChange={(e) => set('question', e.target.value)}
          />
        </PpdbField>

        <PpdbField label="Keterangan" error={errorOf('description')}>
          <Textarea
            rows={2}
            value={form.description}
            placeholder="Petunjuk pengisian yang tampil di bawah pertanyaan."
            error={errorOf('description')}
            onChange={(e) => set('description', e.target.value)}
          />
        </PpdbField>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <PpdbField label="Tipe Jawaban" required error={errorOf('type')}>
            <Select
              value={form.type}
              error={errorOf('type')}
              onChange={(e) => set('type', e.target.value as PpdbQuestionType)}
            >
              {QUESTION_TYPE_ORDER.map((type) => (
                <option key={type} value={type}>
                  {QUESTION_TYPE_LABELS[type]}
                </option>
              ))}
            </Select>
          </PpdbField>
          <PpdbField label="Urutan" error={errorOf('order')}>
            <Input
              type="number"
              min={0}
              value={form.order}
              error={errorOf('order')}
              onChange={(e) => set('order', e.target.value)}
            />
          </PpdbField>
          <div className="flex gap-4 pt-1">
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={form.required}
                onChange={(e) => set('required', e.target.checked)}
              />
              Wajib
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input
                type="checkbox"
                className="h-4 w-4 rounded border-slate-300"
                checked={form.active}
                onChange={(e) => set('active', e.target.checked)}
              />
              Aktif
            </label>
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <PpdbScopeControls
            periodId={form.period_id === '' ? '' : Number(form.period_id)}
            programId={form.program_id === '' ? '' : Number(form.program_id)}
            waveId={form.wave_id === '' ? '' : Number(form.wave_id)}
            programError={errorOf('program_id')}
            waveError={errorOf('wave_id')}
            onProgramChange={(value) => set('program_id', String(value))}
            onWaveChange={(value) => set('wave_id', String(value))}
          />
        </div>

        {needsOptions && (
          <Card className="p-3">
            <PpdbOptionRows
              rows={options}
              error={errorOf('options')}
              label="Opsi jawaban"
              valueLabel="Nilai tersimpan"
              emptyHint="Belum ada opsi. Tambah minimal satu opsi jawaban."
              onChange={setOptions}
            />
          </Card>
        )}
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

export function PpdbQuestionsPage() {
  const queryClient = useQueryClient();
  const { options: periodOptions, firstPeriodId, isInitialLoading } = usePpdbPeriodOptions();
  const [picked, setPicked] = useState<number | ''>('');
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [programFilter, setProgramFilter] = useState<number | ''>('');
  const [waveFilter, setWaveFilter] = useState<number | ''>('');
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<PpdbQuestion | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<PpdbQuestion | null>(null);

  const periodId = picked === '' ? firstPeriodId : picked;

  const listQuery = useQuery({
    queryKey: ['ppdb', 'questions', 'list', periodId, page, search, programFilter, waveFilter],
    enabled: periodId !== '',
    queryFn: () =>
      ppdbQuestionsApi.list({
        period_id: periodId,
        page,
        per_page: PER_PAGE,
        search: search || undefined,
        program_id: programFilter === '' ? undefined : programFilter,
        wave_id: waveFilter === '' ? undefined : waveFilter,
      }),
  });

  const rows = useMemo(() => {
    const data = listQuery.data?.data ?? [];
    return [...data].sort((a, b) => a.order - b.order);
  }, [listQuery.data]);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'questions'] });
    queryClient.invalidateQueries({ queryKey: ['ppdb', 'periods'] });
  };

  const saveMutation = useMutation({
    mutationFn: (payload: PpdbQuestionPayload) =>
      editing ? ppdbQuestionsApi.update(editing.id, payload) : ppdbQuestionsApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Pertanyaan diperbarui' : 'Pertanyaan dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (error) => {
      setServerErrors(getValidationErrors(error) ?? {});
      toast.error(getErrorMessage(error));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => ppdbQuestionsApi.remove(id),
    onSuccess: () => {
      toast.success('Pertanyaan dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (error) => {
      toast.error(describeError(error));
      setDeleting(null);
    },
  });

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (question: PpdbQuestion) => {
    setEditing(question);
    setServerErrors({});
    setFormOpen(true);
  };

  if (!isInitialLoading && periodOptions.length === 0) {
    return (
      <div className="space-y-4">
        <PpdbPageHeader
          title="Pertanyaan Pendaftaran"
          description="Pertanyaan isian tambahan dibuat per periode. Buat periode lebih dulu."
        />
        <PpdbNoPeriodState />
      </div>
    );
  }

  const meta = listQuery.data?.meta;

  return (
    <div className="space-y-4">
      <PpdbPageHeader
        title="Pertanyaan Pendaftaran"
        description="Bedanya dengan formulir: pertanyaan dapat dibatasi hanya untuk program atau gelombang tertentu."
        actions={
          <>
            <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
              <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
            </Button>
            <Can perm="ppdb.question.manage">
              <Button onClick={openCreate} disabled={periodId === ''}>
                <Plus className="h-4 w-4" /> Tambah Pertanyaan
              </Button>
            </Can>
          </>
        }
      />

      <PpdbFilterCard>
        <PpdbPeriodPicker
          value={periodId}
          options={periodOptions}
          isLoading={isInitialLoading}
          onChange={(value) => {
            setPicked(value);
            setPage(1);
          }}
        />
        <PpdbField label="Cari">
          <Input
            value={search}
            placeholder="Cari kode atau teks pertanyaan..."
            onChange={(e) => {
              setSearch(e.target.value);
              setPage(1);
            }}
          />
        </PpdbField>
        <PpdbScopeFilters
          periodId={periodId}
          programId={programFilter}
          waveId={waveFilter}
          onProgramChange={(value) => {
            setProgramFilter(value);
            setPage(1);
          }}
          onWaveChange={(value) => {
            setWaveFilter(value);
            setPage(1);
          }}
        />
      </PpdbFilterCard>

      {listQuery.isLoading ? (
        <PpdbLoadingCard label="Memuat pertanyaan..." />
      ) : listQuery.isError ? (
        <PpdbErrorCard error={listQuery.error} onRetry={() => listQuery.refetch()} />
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada pertanyaan"
            description="Pertanyaan tambahan membuat formulir portal menyesuaikan pilihan program pendaftar."
            action={
              <Can perm="ppdb.question.manage">
                <Button size="sm" className="mt-2" onClick={openCreate} disabled={periodId === ''}>
                  <Plus className="h-4 w-4" /> Tambah Pertanyaan
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Kode</th>
                  <th className="px-4 py-2 font-medium">Pertanyaan</th>
                  <th className="px-4 py-2 font-medium">Tipe</th>
                  <th className="px-4 py-2 font-medium">Cakupan</th>
                  <th className="px-4 py-2 font-medium">Sifat</th>
                  <th className="px-4 py-2 font-medium">Urutan</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((question) => (
                  <tr key={question.id} className="border-b border-slate-50 align-top last:border-0">
                    <td className="px-4 py-2 font-mono text-xs text-slate-600">{question.code}</td>
                    <td className="px-4 py-2">
                      <div className="font-medium text-slate-800">{question.question}</div>
                      {question.description && (
                        <div className="mt-0.5 text-xs text-slate-400">{question.description}</div>
                      )}
                      {question.options && question.options.length > 0 && (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {question.options.map((option, index) => (
                            <Badge key={option.id ?? index} tone="gray">
                              {option.label}
                            </Badge>
                          ))}
                        </div>
                      )}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {QUESTION_TYPE_LABELS[question.type] ?? question.type}
                    </td>
                    <td className="px-4 py-2">
                      <PpdbScopeBadge
                        programId={question.program_id}
                        programName={question.program?.name}
                        waveId={question.wave_id}
                        waveName={question.wave?.name}
                      />
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap gap-1">
                        <PpdbRequiredBadge required={question.required} />
                        <PpdbActiveBadge active={question.active} />
                      </div>
                    </td>
                    <td className="px-4 py-2 text-slate-700">{question.order}</td>
                    <td className="px-4 py-2">
                      <div className="flex items-center gap-1">
                        <Can perm="ppdb.question.manage">
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(question)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Hapus"
                            onClick={() => setDeleting(question)}
                          >
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <QuestionFormModal
        open={formOpen}
        editing={editing}
        periodId={periodId}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Pertanyaan"
        message={`Hapus "${deleting?.code ?? ''}"? Pertanyaan yang sudah dijawab pendaftar biasanya ditolak backend.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}
