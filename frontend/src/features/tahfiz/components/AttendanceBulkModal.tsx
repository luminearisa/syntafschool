import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { halaqahsApi } from '../api/tahfizApi';
import { useHalaqahOptions } from '../hooks/useTahfizOptions';
import { ATTENDANCE_STATUS_LABELS, toOptions } from '../lib/labels';
import type { TahfizAttendanceStatus } from '@/types/tahfiz';

export interface TahfizAttendanceBulkPayload {
  halaqah_id: number | '';
  date: string;
  attendances: {
    participant_id: number;
    status: TahfizAttendanceStatus;
    permission_reason?: string;
    notes?: string;
  }[];
}

interface Props {
  open: boolean;
  submitting: boolean;
  serverErrors?: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: TahfizAttendanceBulkPayload) => void;
}

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

const today = (): string => new Date().toISOString().slice(0, 10);

const STATUS_OPTIONS = toOptions(ATTENDANCE_STATUS_LABELS);

const NEEDS_REASON: TahfizAttendanceStatus[] = ['izin', 'sakit'];

interface RowState {
  status: TahfizAttendanceStatus;
  permission_reason: string;
  notes: string;
}

const emptyRow: RowState = { status: 'hadir', permission_reason: '', notes: '' };

/**
 * Absensi massal satu halaqah pada satu tanggal.
 *
 * Anggota aktif dimuat dari detail halaqah; backend memperbarui catatan yang
 * sudah ada sehingga pengiriman ulang tidak menggandakan data.
 */
export function AttendanceBulkModal({
  open,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: Props) {
  const { options: halaqahOptions } = useHalaqahOptions();

  const [halaqahId, setHalaqahId] = useState<number | ''>('');
  const [date, setDate] = useState(today());
  const [rows, setRows] = useState<Record<number, RowState>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});

  const membersQuery = useQuery({
    queryKey: ['tahfiz', 'halaqah', halaqahId],
    enabled: open && !!halaqahId,
    queryFn: () => halaqahsApi.show(Number(halaqahId)),
  });

  const members = useMemo(() => membersQuery.data?.members ?? [], [membersQuery.data]);

  useEffect(() => {
    if (!open) return;
    setErrors({});
    setHalaqahId('');
    setDate(today());
    setRows({});
  }, [open]);

  // Siapkan baris default untuk setiap anggota yang baru dimuat.
  useEffect(() => {
    setRows((prev) => {
      const next = { ...prev };
      for (const member of members) {
        if (!next[member.participant_id]) next[member.participant_id] = { ...emptyRow };
      }
      return next;
    });
  }, [members]);

  const row = (participantId: number): RowState => rows[participantId] ?? emptyRow;

  const updateRow = (participantId: number, patch: Partial<RowState>) => {
    setRows((prev) => ({ ...prev, [participantId]: { ...row(participantId), ...patch } }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[`reason_${participantId}`];
      return next;
    });
  };

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    const local: Record<string, string> = {};
    if (!halaqahId) local.halaqah_id = 'Halaqah wajib dipilih';
    if (!date) local.date = 'Tanggal wajib diisi';

    for (const member of members) {
      const state = row(member.participant_id);
      if (NEEDS_REASON.includes(state.status) && !state.permission_reason.trim()) {
        local[`reason_${member.participant_id}`] = 'Keterangan wajib diisi';
      }
    }

    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }

    onSubmit({
      halaqah_id: halaqahId,
      date,
      attendances: members.map((member) => {
        const state = row(member.participant_id);
        const entry: TahfizAttendanceBulkPayload['attendances'][number] = {
          participant_id: member.participant_id,
          status: state.status,
        };
        const reason = state.permission_reason.trim();
        if (reason) entry.permission_reason = reason;
        const notes = state.notes.trim();
        if (notes) entry.notes = notes;
        return entry;
      }),
    });
  };

  const err = (key: string) => errors[key] || serverErrors?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Absensi Massal"
      size="xl"
      footer={
        <>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button
            type="submit"
            form="attendance-bulk-form"
            loading={submitting}
            disabled={members.length === 0}
          >
            Simpan Kehadiran
          </Button>
        </>
      }
    >
      <form id="attendance-bulk-form" onSubmit={handleSubmit} className="space-y-4">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div>
            <Label required>Halaqah</Label>
            <SearchSelect
              options={halaqahOptions}
              value={halaqahId}
              error={err('halaqah_id')}
              onChange={(v) => setHalaqahId(toId(v))}
              placeholder="-- Pilih Halaqah --"
            />
          </div>
          <div>
            <Label required>Tanggal</Label>
            <Input
              type="date"
              value={date}
              error={err('date')}
              onChange={(e) => setDate(e.target.value)}
            />
          </div>
        </div>

        <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
          Catatan yang sudah ada pada tanggal ini akan diperbarui, bukan digandakan. Status izin/sakit
          wajib disertai keterangan.
        </p>

        {!halaqahId ? (
          <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-400">
            Pilih halaqah untuk memuat daftar anggota.
          </p>
        ) : membersQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
            <Spinner /> Memuat anggota halaqah...
          </div>
        ) : members.length === 0 ? (
          <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-400">
            Halaqah ini belum memiliki anggota aktif.
          </p>
        ) : (
          <div className="space-y-2">
            {members.map((member, index) => {
              const state = row(member.participant_id);
              const needsReason = NEEDS_REASON.includes(state.status);
              const rowError = err(`reason_${member.participant_id}`) ?? err(`attendances.${index}.permission_reason`);
              return (
                <div
                  key={member.id}
                  className="grid grid-cols-1 gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-12 sm:items-start"
                >
                  <div className="sm:col-span-4">
                    <p className="text-sm font-medium text-slate-800">
                      {member.participant?.name ?? `Peserta #${member.participant_id}`}
                    </p>
                  </div>
                  <div className="sm:col-span-3">
                    <Select
                      value={state.status}
                      onChange={(e) =>
                        updateRow(member.participant_id, {
                          status: e.target.value as TahfizAttendanceStatus,
                        })
                      }
                    >
                      {STATUS_OPTIONS.map((o) => (
                        <option key={String(o.value)} value={String(o.value)}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="sm:col-span-3">
                    <Input
                      value={state.permission_reason}
                      error={rowError}
                      maxLength={150}
                      disabled={!needsReason}
                      placeholder={needsReason ? 'Keterangan (wajib)' : 'Keterangan'}
                      onChange={(e) =>
                        updateRow(member.participant_id, { permission_reason: e.target.value })
                      }
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Input
                      value={state.notes}
                      placeholder="Catatan"
                      onChange={(e) => updateRow(member.participant_id, { notes: e.target.value })}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </form>
    </Modal>
  );
}