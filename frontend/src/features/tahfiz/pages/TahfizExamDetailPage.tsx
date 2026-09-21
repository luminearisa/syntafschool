import { useEffect, useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  CheckCircle2,
  ClipboardCheck,
  ListPlus,
  RefreshCw,
  UserPlus,
  XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import { examsApi } from '../api/tahfizApi';
import { ExamParticipantPickerModal } from '../components/ExamParticipantPickerModal';
import { ExamResultModal } from '../components/ExamResultModal';
import { ExamScoreItemModal } from '../components/ExamScoreItemModal';
import { FieldRow, SectionTitle } from '../components/TahfizUi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import {
  EXAM_ITEM_TYPE_LABELS,
  EXAM_PARTICIPANT_STATUS_LABELS,
  EXAM_STATUS_LABELS,
  examResultTone,
  examStatusTone,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  TahfizExamGenerateItemsPayload,
  TahfizExamItem,
  TahfizExamItemType,
  TahfizExamParticipant,
  TahfizExamParticipantStatus,
  TahfizExamResultPayload,
  TahfizExamScoreItemPayload,
} from '@/types/tahfiz';

const ITEM_TYPE_OPTIONS: { value: TahfizExamItemType; label: string }[] = [
  { value: 'sambung_ayat', label: EXAM_ITEM_TYPE_LABELS.sambung_ayat },
  { value: 'acak_ayat', label: EXAM_ITEM_TYPE_LABELS.acak_ayat },
  { value: 'hafalan', label: EXAM_ITEM_TYPE_LABELS.hafalan },
  { value: 'tahsin', label: EXAM_ITEM_TYPE_LABELS.tahsin },
];

function participantStatusTone(status: TahfizExamParticipantStatus) {
  switch (status) {
    case 'dinilai':
      return 'green' as const;
    case 'hadir':
      return 'blue' as const;
    case 'menunggu_nilai':
      return 'amber' as const;
    case 'tidak_hadir':
      return 'red' as const;
    default:
      return 'gray' as const;
  }
}

/* ============================ MODAL GENERATE BUTIR ============================ */

function GenerateItemsModal({
  open,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizExamGenerateItemsPayload) => void;
}) {
  const [types, setTypes] = useState<TahfizExamItemType[]>([]);
  const [count, setCount] = useState<number | ''>('');
  const [errors, setErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setTypes([]);
    setCount('');
    setErrors({});
  }, [open]);

  const toggle = (value: TahfizExamItemType) => {
    setTypes((prev) => (prev.includes(value) ? prev.filter((v) => v !== value) : [...prev, value]));
    setErrors((prev) => {
      const next = { ...prev };
      delete next.item_types;
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (types.length === 0) {
      setErrors({ item_types: 'Pilih minimal satu tipe butir' });
      return;
    }
    onSubmit({ item_types: types, count: count === '' ? undefined : count });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Generate Butir Ujian"
      size="md"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="generate-items-form" loading={submitting}>
            Generate
          </Button>
        </>
      }
    >
      <form id="generate-items-form" onSubmit={handleSubmit} className="space-y-4">
        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Butir dibuat untuk seluruh peserta ujian. Butir acak &amp; sambung ayat hanya diambil dari
          rentang hafalan yang benar-benar sudah dihafal peserta — penentuannya dilakukan di server.
        </p>

        <div>
          <Label required>Tipe Butir</Label>
          <div className="grid grid-cols-2 gap-2">
            {ITEM_TYPE_OPTIONS.map((o) => (
              <label
                key={o.value}
                className="flex cursor-pointer items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm text-slate-700 hover:bg-slate-50"
              >
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={types.includes(o.value)}
                  onChange={() => toggle(o.value)}
                />
                {o.label}
              </label>
            ))}
          </div>
          {err('item_types') && <p className="mt-1 text-xs text-red-500">{err('item_types')}</p>}
        </div>

        <div>
          <Label>Jumlah Butir per Peserta</Label>
          <Input
            type="number"
            min={1}
            value={count}
            error={err('count')}
            placeholder="Kosongkan = default server"
            onChange={(e) => setCount(e.target.value === '' ? '' : Number(e.target.value))}
          />
        </div>
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Detail ujian: ringkasan, peserta, butir, dan penilaian.
 *
 * Butir di-generate pada level ujian (bukan per peserta) karena endpoint hanya
 * menerima tipe butir + jumlah; setelah generate daftar peserta disegarkan.
 */
export function TahfizExamDetailPage() {
  const { id: idParam } = useParams();
  const examId = Number(idParam);
  const queryClient = useQueryClient();

  const [pickerOpen, setPickerOpen] = useState(false);
  const [registerErrors, setRegisterErrors] = useState<Record<string, string[]>>({});
  const [generateOpen, setGenerateOpen] = useState(false);
  const [generateErrors, setGenerateErrors] = useState<Record<string, string[]>>({});
  const [scoring, setScoring] = useState<TahfizExamItem | null>(null);
  const [scoreErrors, setScoreErrors] = useState<Record<string, string[]>>({});
  const [resulting, setResulting] = useState<TahfizExamParticipant | null>(null);
  const [resultErrors, setResultErrors] = useState<Record<string, string[]>>({});

  const examQuery = useQuery({
    queryKey: ['tahfiz', 'exams', 'detail', examId],
    enabled: Number.isFinite(examId),
    queryFn: () => examsApi.show(examId),
  });

  const participantsQuery = useQuery({
    queryKey: ['tahfiz', 'exams', 'participants', examId],
    enabled: Number.isFinite(examId),
    queryFn: () => examsApi.participants(examId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['tahfiz', 'exams'] });

  const registerMutation = useMutation({
    mutationFn: (participantIds: number[]) => examsApi.registerParticipants(examId, participantIds),
    onSuccess: (res) => {
      toast.success(res.message);
      setPickerOpen(false);
      setRegisterErrors({});
      invalidate();
    },
    onError: (err) => {
      setRegisterErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const generateMutation = useMutation({
    mutationFn: (payload: TahfizExamGenerateItemsPayload) => examsApi.generateItems(examId, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setGenerateOpen(false);
      setGenerateErrors({});
      invalidate();
    },
    onError: (err) => {
      setGenerateErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const scoreMutation = useMutation({
    mutationFn: ({ itemId, payload }: { itemId: number; payload: TahfizExamScoreItemPayload }) =>
      examsApi.scoreItem(examId, itemId, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setScoring(null);
      setScoreErrors({});
      invalidate();
    },
    onError: (err) => {
      setScoreErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const resultMutation = useMutation({
    mutationFn: ({
      participantId,
      payload,
    }: {
      participantId: number;
      payload: TahfizExamResultPayload;
    }) => examsApi.recordResult(examId, participantId, payload),
    onSuccess: (res) => {
      toast.success(res.message);
      setResulting(null);
      setResultErrors({});
      invalidate();
    },
    onError: (err) => {
      setResultErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  if (examQuery.isLoading) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
          <Spinner /> Memuat ujian...
        </div>
      </Card>
    );
  }

  if (examQuery.isError || !examQuery.data) {
    return (
      <Card>
        <EmptyState
          title="Gagal memuat ujian"
          description={getErrorMessage(examQuery.error)}
          action={
            <Link to="/tahfiz/exams">
              <Button size="sm" variant="outline" className="mt-2">
                Kembali ke daftar
              </Button>
            </Link>
          }
        />
      </Card>
    );
  }

  const exam = examQuery.data;
  const participants = participantsQuery.data ?? [];
  const existingIds = participants.map((p) => p.participant_id);

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center gap-2">
          <Link to="/tahfiz/exams">
            <Button variant="ghost" size="icon" title="Kembali">
              <ArrowLeft className="h-4 w-4" />
            </Button>
          </Link>
          <div>
            <h1 className="text-lg font-semibold text-slate-900">{exam.name}</h1>
            <p className="font-mono text-xs text-slate-500">{exam.code}</p>
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => participantsQuery.refetch()}
          >
            <RefreshCw className={participantsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="tahfiz.update">
            <Button
              variant="outline"
              onClick={() => {
                setGenerateErrors({});
                setGenerateOpen(true);
              }}
            >
              <ListPlus className="h-4 w-4" /> Generate Butir
            </Button>
            <Button
              onClick={() => {
                setRegisterErrors({});
                setPickerOpen(true);
              }}
            >
              <UserPlus className="h-4 w-4" /> Daftarkan Peserta
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-4">
        <SectionTitle>Ringkasan Ujian</SectionTitle>
        <div className="grid grid-cols-1 gap-x-8 sm:grid-cols-2">
          <div>
            <FieldRow label="Jenis">{exam.type_label}</FieldRow>
            <FieldRow label="Tanggal">{exam.exam_date ?? '—'}</FieldRow>
            <FieldRow label="Status">
              <Badge tone={examStatusTone(exam.status)}>
                {EXAM_STATUS_LABELS[exam.status] ?? exam.status_label}
              </Badge>
            </FieldRow>
          </div>
          <div>
            <FieldRow label="Nilai Lulus / Maks">
              {exam.passing_score} / {exam.max_score}
            </FieldRow>
            <FieldRow label="Penguji">{exam.examiner?.full_name ?? '—'}</FieldRow>
            <FieldRow label="Level Tujuan">{exam.level?.name ?? '—'}</FieldRow>
          </div>
        </div>
      </Card>

      {participantsQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
            <Spinner /> Memuat peserta...
          </div>
        </Card>
      ) : participants.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada peserta"
            description="Daftarkan peserta terlebih dahulu untuk mulai membuat butir dan menilai."
          />
        </Card>
      ) : (
        <div className="space-y-3">
          {participants.map((p) => {
            const items = p.items ?? [];
            return (
              <Card key={p.id} className="overflow-hidden">
                <div className="flex flex-col gap-2 border-b border-slate-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-medium text-slate-800">
                      {p.participant?.name ?? `Peserta #${p.participant_id}`}
                    </span>
                    <Badge tone={participantStatusTone(p.status)}>
                      {EXAM_PARTICIPANT_STATUS_LABELS[p.status] ?? p.status_label}
                    </Badge>
                    <span className="text-xs text-slate-500">
                      Nilai: <span className="font-medium text-slate-700">{p.score ?? '—'}</span>
                      {' · '}Grade:{' '}
                      <span className="font-medium text-slate-700">{p.grade ?? '—'}</span>
                    </span>
                    {p.result && (
                      <Badge tone={examResultTone(p.result)}>
                        {p.result_label ?? p.result}
                      </Badge>
                    )}
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <Can perm="tahfiz.update">
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                          setGenerateErrors({});
                          setGenerateOpen(true);
                        }}
                      >
                        <ListPlus className="h-3.5 w-3.5" /> Buat Butir
                      </Button>
                    </Can>
                    <Can perm="tahfiz.assess">
                      <Button size="sm" onClick={() => setResulting(p)}>
                        <ClipboardCheck className="h-3.5 w-3.5" /> Catat Hasil
                      </Button>
                    </Can>
                  </div>
                </div>

                {items.length === 0 ? (
                  <p className="px-4 py-6 text-center text-xs text-slate-400">
                    Belum ada butir untuk peserta ini.
                  </p>
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                      <thead>
                        <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                          <th className="px-4 py-2 font-medium">Tipe</th>
                          <th className="px-4 py-2 font-medium">Surah</th>
                          <th className="px-3 py-2 font-medium">Ayat</th>
                          <th className="px-3 py-2 text-center font-medium">Dibaca</th>
                          <th className="px-3 py-2 text-center font-medium">Benar</th>
                          <th className="px-3 py-2 text-center font-medium">Nilai</th>
                          <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                        </tr>
                      </thead>
                      <tbody>
                        {items.map((item) => (
                          <tr key={item.id} className="border-b border-slate-50 last:border-0">
                            <td className="px-4 py-2 text-slate-700">
                              {EXAM_ITEM_TYPE_LABELS[item.item_type] ?? item.item_type}
                            </td>
                            <td className="px-4 py-2 text-xs text-slate-600">
                              {item.surah?.name ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-xs text-slate-600">
                              {item.ayah_start ?? '—'}–{item.expected_ayah_end ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-center text-slate-700">
                              {item.actual_ayah_end ?? '—'}
                            </td>
                            <td className="px-3 py-2 text-center">
                              {item.is_correct ? (
                                <CheckCircle2 className="mx-auto h-4 w-4 text-green-600" />
                              ) : (
                                <XCircle className="mx-auto h-4 w-4 text-slate-300" />
                              )}
                            </td>
                            <td className="px-3 py-2 text-center font-medium text-slate-700">
                              {item.score ?? '—'}
                            </td>
                            <td className="px-4 py-2">
                              {item.score === null ? (
                                <Can perm="tahfiz.assess">
                                  <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => {
                                      setScoreErrors({});
                                      setScoring(item);
                                    }}
                                  >
                                    Nilai
                                  </Button>
                                </Can>
                              ) : (
                                <span className="text-xs text-slate-400">—</span>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </Card>
            );
          })}
        </div>
      )}

      <ExamParticipantPickerModal
        open={pickerOpen}
        submitting={registerMutation.isPending}
        serverErrors={registerErrors}
        existingIds={existingIds}
        onClose={() => setPickerOpen(false)}
        onSubmit={(participantIds) => registerMutation.mutate(participantIds)}
      />

      <GenerateItemsModal
        open={generateOpen}
        submitting={generateMutation.isPending}
        serverErrors={generateErrors}
        onClose={() => setGenerateOpen(false)}
        onSubmit={(payload) => generateMutation.mutate(payload)}
      />

      <ExamScoreItemModal
        open={!!scoring}
        item={scoring}
        submitting={scoreMutation.isPending}
        serverErrors={scoreErrors}
        onClose={() => setScoring(null)}
        onSubmit={(payload) => {
          if (scoring) scoreMutation.mutate({ itemId: scoring.id, payload });
        }}
      />

      <ExamResultModal
        open={!!resulting}
        participant={resulting}
        submitting={resultMutation.isPending}
        serverErrors={resultErrors}
        onClose={() => setResulting(null)}
        onSubmit={(payload) => {
          if (resulting) resultMutation.mutate({ participantId: resulting.id, payload });
        }}
      />
    </div>
  );
}