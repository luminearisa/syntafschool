import { useEffect, useState, type FormEvent } from 'react';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Textarea } from '@/components/ui/Textarea';
import { useHalaqahOptions, useParticipantOptions } from '../hooks/useTahfizOptions';
import { INTERVENTION_STATUS_LABELS, INTERVENTION_TYPE_LABELS } from '../lib/labels';
import type {
  TahfizIntervention,
  TahfizInterventionPayload,
  TahfizInterventionStatus,
  TahfizInterventionType,
} from '@/types/tahfiz';

interface Props {
  open: boolean;
  editing: TahfizIntervention | null;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizInterventionPayload) => void;
}

interface FormState {
  participant_id: number | '';
  enrollment_id: number | '';
  halaqah_id: number | '';
  type: TahfizInterventionType | '';
  reason: string;
  action: string;
  status: TahfizInterventionStatus | '';
  due_date: string;
  completed_at: string;
  result: string;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const emptyForm: FormState = {
  participant_id: '',
  enrollment_id: '',
  halaqah_id: '',
  type: '',
  reason: '',
  action: '',
  status: 'proposed',
  due_date: '',
  completed_at: '',
  result: '',
};

/**
 * Form intervensi peserta.
 *
 * Aturan status: `done` wajib menyertakan tanggal selesai, dan `cancelled`
 * tidak boleh menyertakan tanggal selesai (cermin validasi backend).
 */
export function InterventionFormModal({
  open,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const [values, setValues] = useState<FormState>(emptyForm);
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: participantOptions } = useParticipantOptions();
  const { options: halaqahOptions } = useHalaqahOptions();

  useEffect(() => {
    if (!open) return;
    setErrors({});
    if (editing) {
      setValues({
        participant_id: editing.participant_id ?? '',
        enrollment_id: editing.enrollment_id ?? '',
        halaqah_id: editing.halaqah_id ?? '',
        type: editing.type,
        reason: editing.reason ?? '',
        action: editing.action ?? '',
        status: editing.status,
        due_date: editing.due_date ?? '',
        completed_at: editing.completed_at ?? '',
        result: editing.result ?? '',
      });
    } else {
      setValues({ ...emptyForm });
    }
  }, [open, editing]);

  const set = <K extends keyof FormState>(key: K, value: FormState[K]) => {
    setValues((prev) => {
      const next = { ...prev, [key]: value };
      // `cancelled` tidak boleh menyertakan completed_at.
      if (key === 'status' && value === 'cancelled') next.completed_at = '';
      return next;
    });
    setErrors((prev) => {
      const copy = { ...prev };
      delete copy[key];
      return copy;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!values.participant_id) local.participant_id = 'Peserta wajib dipilih';
    if (!values.type) local.type = 'Jenis intervensi wajib dipilih';
    if (!values.reason.trim()) local.reason = 'Alasan wajib diisi';
    if (values.status === 'done' && !values.completed_at) {
      local.completed_at = 'Tanggal selesai wajib diisi untuk status Selesai';
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    const payload: TahfizInterventionPayload = {
      participant_id: values.participant_id,
      enrollment_id: values.enrollment_id,
      halaqah_id: values.halaqah_id,
      type: values.type as TahfizInterventionType,
      reason: values.reason,
      action: values.action,
      due_date: values.due_date,
      result: values.result,
      ...(values.status ? { status: values.status } : {}),
      ...(values.status === 'done' ? { completed_at: values.completed_at } : {}),
    };

    onSubmit(payload);
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? 'Edit Intervensi' : 'Tambah Intervensi'}
      size="lg"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="intervention-form" loading={submitting}>
            {editing ? 'Perbarui' : 'Simpan'}
          </Button>
        </>
      }
    >
      <form id="intervention-form" onSubmit={handleSubmit} className="space-y-4">
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
            <Label>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={values.halaqah_id}
              error={err('halaqah_id')}
              onChange={(v) => set('halaqah_id', toId(v))}
              placeholder="-- Tidak ada --"
            />
          </div>

          <div>
            <Label required>Jenis Intervensi</Label>
            <Select
              value={values.type}
              error={err('type')}
              onChange={(e) => set('type', e.target.value as TahfizInterventionType | '')}
            >
              <option value="">-- Pilih Jenis --</option>
              {(Object.keys(INTERVENTION_TYPE_LABELS) as TahfizInterventionType[]).map((key) => (
                <option key={key} value={key}>
                  {INTERVENTION_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Status</Label>
            <Select
              value={values.status}
              error={err('status')}
              onChange={(e) => set('status', e.target.value as TahfizInterventionStatus | '')}
            >
              {(Object.keys(INTERVENTION_STATUS_LABELS) as TahfizInterventionStatus[]).map((key) => (
                <option key={key} value={key}>
                  {INTERVENTION_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>

          <div className="sm:col-span-2">
            <Label required>Alasan</Label>
            <Textarea
              value={values.reason}
              error={err('reason')}
              placeholder="Alasan intervensi diberikan"
              onChange={(e) => set('reason', e.target.value)}
            />
          </div>
          <div className="sm:col-span-2">
            <Label>Tindakan</Label>
            <Textarea
              value={values.action}
              error={err('action')}
              placeholder="Tindakan yang akan/ telah dilakukan (opsional)"
              onChange={(e) => set('action', e.target.value)}
            />
          </div>

          <div>
            <Label>Batas Waktu</Label>
            <Input
              type="date"
              value={values.due_date}
              error={err('due_date')}
              onChange={(e) => set('due_date', e.target.value)}
            />
          </div>
          {values.status !== 'cancelled' && (
            <div>
              <Label required={values.status === 'done'}>Tanggal Selesai</Label>
              <Input
                type="date"
                value={values.completed_at}
                error={err('completed_at')}
                disabled={values.status !== 'done'}
                title={
                  values.status === 'done'
                    ? undefined
                    : 'Hanya diisi saat status Selesai'
                }
                onChange={(e) => set('completed_at', e.target.value)}
              />
            </div>
          )}
          <div className="sm:col-span-2">
            <Label>Hasil</Label>
            <Textarea
              value={values.result}
              error={err('result')}
              placeholder="Hasil setelah intervensi dijalankan (opsional)"
              onChange={(e) => set('result', e.target.value)}
            />
          </div>
        </div>
      </form>
    </Modal>
  );
}