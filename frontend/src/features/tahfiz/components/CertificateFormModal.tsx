import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { examsApi } from '../api/tahfizApi';
import { useParticipantOptions, useProgramOptions } from '../hooks/useTahfizOptions';
import { ACHIEVEMENT_TYPE_LABELS, toOptions } from '../lib/labels';
import type { SelectOption } from '@/constants/masterData';
import type { TahfizCertificatePayload } from '@/types/tahfiz';

interface Props {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizCertificatePayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

const ACHIEVEMENT_OPTIONS = toOptions(ACHIEVEMENT_TYPE_LABELS);

const emptyForm: TahfizCertificatePayload = {
  participant_id: '',
  enrollment_id: '',
  program_id: '',
  achievement_type: 'selesai_program',
  juz_count: '',
  title: '',
  description: '',
  issue_date: today(),
  exam_id: '',
  examiner_id: '',
  signature_name: '',
};

/**
 * Terbitkan sertifikat pencapaian.
 *
 * Nomor sertifikat dan token QR dibuat server-side — jangan dikirim dari klien.
 */
export function CertificateFormModal({ open, submitting, serverErrors, onClose, onSubmit }: Props) {
  const [values, setValues] = useState<TahfizCertificatePayload>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: programOptions } = useProgramOptions();
  const { options: examinerOptions } = useEmployeeOptions(false);

  const examsQuery = useQuery({
    queryKey: ['tahfiz', 'exams', 'options'],
    enabled: open,
    staleTime: 1000 * 60 * 10,
    queryFn: () => examsApi.list({ all: true }),
  });

  const examOptions = useMemo<SelectOption[]>(
    () => (examsQuery.data?.data ?? []).map((e) => ({ value: e.id, label: `${e.code} — ${e.name}` })),
    [examsQuery.data],
  );

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setValues({ ...emptyForm, issue_date: today() });
  }, [open]);

  const set = <K extends keyof TahfizCertificatePayload>(
    key: K,
    value: TahfizCertificatePayload[K],
  ) => {
    setValues((prev) => ({ ...prev, [key]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[key as string];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.achievement_type) local.achievement_type = 'Jenis pencapaian wajib dipilih';
    if (!values.issue_date) local.issue_date = 'Tanggal terbit wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    onSubmit(values);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Terbitkan Sertifikat"
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="certificate-form" loading={submitting}>
            Terbitkan
          </Button>
        </>
      }
    >
      <form id="certificate-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Peserta</Label>
            <SearchSelect
              options={participantOptions}
              value={values.participant_id}
              error={err('participant_id')}
              onChange={(v) => set('participant_id', toId(v))}
              placeholder="-- Pilih Peserta --"
            />
          </div>
          <div>
            <Label required>Jenis Pencapaian</Label>
            <Select
              value={values.achievement_type}
              error={err('achievement_type')}
              onChange={(e) => set('achievement_type', e.target.value as TahfizCertificatePayload['achievement_type'])}
            >
              {ACHIEVEMENT_OPTIONS.map((o) => (
                <option key={String(o.value)} value={String(o.value)}>
                  {o.label}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Program</Label>
            <SearchSelect
              options={programOptions}
              value={values.program_id}
              error={err('program_id')}
              onChange={(v) => set('program_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Enrollment ID</Label>
            <Input
              type="number"
              min={1}
              value={values.enrollment_id}
              error={err('enrollment_id')}
              placeholder="-- Opsional --"
              onChange={(e) =>
                set('enrollment_id', e.target.value === '' ? '' : Number(e.target.value))
              }
            />
          </div>
          <div>
            <Label>Jumlah Juz</Label>
            <Input
              type="number"
              min={0}
              value={values.juz_count}
              error={err('juz_count')}
              placeholder="mis. 5"
              onChange={(e) => set('juz_count', e.target.value === '' ? '' : Number(e.target.value))}
            />
          </div>
          <div>
            <Label required>Tanggal Terbit</Label>
            <Input
              type="date"
              value={values.issue_date}
              error={err('issue_date')}
              onChange={(e) => set('issue_date', e.target.value)}
            />
          </div>
          <div>
            <Label>Ujian Terkait</Label>
            <SearchSelect
              options={examOptions}
              value={values.exam_id}
              error={err('exam_id')}
              onChange={(v) => set('exam_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Penguji</Label>
            <SearchSelect
              options={examinerOptions}
              value={values.examiner_id}
              error={err('examiner_id')}
              onChange={(v) => set('examiner_id', toId(v))}
              placeholder="-- Opsional --"
            />
          </div>
          <div>
            <Label>Nama Penanda Tangan</Label>
            <Input
              value={values.signature_name}
              error={err('signature_name')}
              placeholder="Opsional"
              onChange={(e) => set('signature_name', e.target.value)}
            />
          </div>
          <div>
            <Label>Judul Sertifikat</Label>
            <Input
              value={values.title}
              error={err('title')}
              placeholder="Opsional"
              onChange={(e) => set('title', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Deskripsi</Label>
            <Textarea
              value={values.description}
              error={err('description')}
              placeholder="Opsional"
              onChange={(e) => set('description', e.target.value)}
            />
          </div>
        </div>

        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Nomor sertifikat dan token QR dibuat otomatis di server saat sertifikat diterbitkan.
        </p>
      </form>
    </Modal>
  );
}