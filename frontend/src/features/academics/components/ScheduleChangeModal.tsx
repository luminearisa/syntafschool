import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Trash2, UserCheck } from 'lucide-react';
import { toast } from 'sonner';
import { schedulesApi } from '../api/schedulesApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Select } from '@/components/ui/Select';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { useOptions } from '@/hooks/useOptions';
import { useEmployeeOptions } from '@/hooks/useLookupOptions';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { Schedule, ScheduleChangePayload, ScheduleChangeType } from '@/types';

const TYPE_LABEL: Record<ScheduleChangeType, string> = {
  pengganti: 'Guru Pengganti',
  pindah: 'Pindah Jam',
  batal: 'Dibatalkan',
};

const TYPE_TONE: Record<ScheduleChangeType, 'blue' | 'amber' | 'red'> = {
  pengganti: 'blue',
  pindah: 'amber',
  batal: 'red',
};

const today = () => new Date().toISOString().slice(0, 10);

const emptyForm = (): ScheduleChangePayload => ({
  date: today(),
  type: 'pengganti',
  substitute_employee_id: '',
  new_room_id: '',
  new_day_id: '',
  new_start_time: '',
  new_end_time: '',
  reason: '',
});

/**
 * Perubahan jadwal untuk satu tanggal tertentu.
 *
 * Jadwal pokok sengaja tidak diubah — perubahan harian dicatat terpisah agar
 * jadwal asli tetap utuh dan bisa diaudit kapan pun.
 */
export function ScheduleChangeModal({
  open,
  schedule,
  canEdit,
  onClose,
}: {
  open: boolean;
  schedule: Schedule | null;
  canEdit: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [values, setValues] = useState<ScheduleChangePayload>(emptyForm());
  const [errors, setErrors] = useState<Record<string, string>>({});

  const { options: teacherOptions } = useEmployeeOptions(true);
  const { options: roomOptions } = useOptions('rooms');
  const { options: dayOptions } = useOptions('days');

  useEffect(() => {
    if (open) {
      setValues(emptyForm());
      setErrors({});
    }
  }, [open]);

  const scheduleId = schedule?.id ?? 0;

  const changesQuery = useQuery({
    queryKey: ['schedule-changes', scheduleId],
    enabled: open && scheduleId > 0,
    queryFn: () => schedulesApi.changes({ schedule_id: scheduleId }),
  });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['schedule-changes', scheduleId] });
    queryClient.invalidateQueries({ queryKey: ['schedule-grid'] });
  };

  const createMutation = useMutation({
    mutationFn: () => schedulesApi.createChange(scheduleId, values),
    onSuccess: () => {
      toast.success('Perubahan jadwal dicatat');
      setValues(emptyForm());
      setErrors({});
      invalidate();
    },
    onError: (err) => {
      toast.error(getErrorMessage(err));
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => schedulesApi.removeChange(id),
    onSuccess: () => {
      toast.success('Perubahan jadwal dibatalkan');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const set = (name: keyof ScheduleChangePayload, value: unknown) => {
    setValues((prev) => ({ ...prev, [name]: value }));
    setErrors((prev) => {
      const next = { ...prev };
      delete next[name];
      return next;
    });
  };

  const err = (key: string) =>
    errors[key] || getValidationErrors(createMutation.error)?.[key]?.[0];

  const handleSubmit = () => {
    const local: Record<string, string> = {};
    if (!values.date) local.date = 'Tanggal wajib diisi';
    if (values.type === 'pengganti' && !values.substitute_employee_id) {
      local.substitute_employee_id = 'Guru pengganti wajib dipilih';
    }
    if (values.type === 'pindah' && (!values.new_start_time || !values.new_end_time)) {
      local.new_start_time = 'Jam baru wajib diisi';
    }
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setErrors({});
    createMutation.mutate();
  };

  const changes = changesQuery.data ?? [];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={schedule ? `Perubahan Jadwal — ${schedule.subject?.name ?? 'Mapel'}` : 'Perubahan Jadwal'}
      size="lg"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      <div className="space-y-4">
        {schedule && (
          <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3 py-2 text-sm">
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-slate-600">
              <span className="font-medium text-slate-800">{schedule.school_class?.name}</span>
              <span>{schedule.subject?.name}</span>
              <span className="font-mono text-xs">
                {schedule.day?.name} {schedule.start_time}–{schedule.end_time}
              </span>
              <span className="text-xs">{schedule.teacher?.full_name ?? 'Belum ada guru'}</span>
            </div>
          </div>
        )}

        {canEdit && (
          <div className="space-y-3 rounded-lg border border-slate-200 p-3">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div>
                <Label required>Tanggal</Label>
                <Input
                  type="date"
                  value={values.date}
                  error={err('date')}
                  onChange={(e) => set('date', e.target.value)}
                />
              </div>
              <div>
                <Label required>Jenis Perubahan</Label>
                <Select
                  value={values.type}
                  error={err('type')}
                  onChange={(e) => set('type', e.target.value as ScheduleChangeType)}
                >
                  {(Object.keys(TYPE_LABEL) as ScheduleChangeType[]).map((t) => (
                    <option key={t} value={t}>
                      {TYPE_LABEL[t]}
                    </option>
                  ))}
                </Select>
              </div>

              {values.type === 'pengganti' && (
                <div className="sm:col-span-2">
                  <Label required>Guru Pengganti</Label>
                  <SearchSelect
                    options={teacherOptions}
                    value={values.substitute_employee_id ?? ''}
                    error={err('substitute_employee_id')}
                    onChange={(v) => set('substitute_employee_id', v)}
                    placeholder="-- Pilih Guru Pengganti --"
                  />
                </div>
              )}

              {values.type === 'pindah' && (
                <>
                  <div>
                    <Label required>Jam Mulai Baru</Label>
                    <Input
                      type="time"
                      value={values.new_start_time}
                      error={err('new_start_time')}
                      onChange={(e) => set('new_start_time', e.target.value)}
                    />
                  </div>
                  <div>
                    <Label required>Jam Selesai Baru</Label>
                    <Input
                      type="time"
                      value={values.new_end_time}
                      error={err('new_end_time')}
                      onChange={(e) => set('new_end_time', e.target.value)}
                    />
                  </div>
                  <div>
                    <Label>Hari Baru</Label>
                    <SearchSelect
                      options={dayOptions}
                      value={values.new_day_id ?? ''}
                      onChange={(v) => set('new_day_id', v)}
                      placeholder="-- Tetap hari yang sama --"
                    />
                  </div>
                  <div>
                    <Label>Ruangan Baru</Label>
                    <SearchSelect
                      options={roomOptions}
                      value={values.new_room_id ?? ''}
                      onChange={(v) => set('new_room_id', v)}
                      placeholder="-- Tetap ruangan yang sama --"
                    />
                  </div>
                </>
              )}

              <div className="sm:col-span-2">
                <Label>Alasan</Label>
                <Input
                  value={values.reason}
                  error={err('reason')}
                  placeholder="Contoh: guru utama sakit"
                  onChange={(e) => set('reason', e.target.value)}
                />
              </div>
            </div>

            <div className="flex justify-end">
              <Button size="sm" loading={createMutation.isPending} onClick={handleSubmit}>
                <CalendarClock className="h-4 w-4" /> Catat Perubahan
              </Button>
            </div>
          </div>
        )}

        {changesQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-8 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : changes.length === 0 ? (
          <EmptyState
            title="Belum ada perubahan"
            description="Jadwal ini masih berjalan sesuai jadwal pokok."
          />
        ) : (
          <div className="overflow-hidden rounded-lg border border-slate-200">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 bg-slate-50/60 text-left text-xs uppercase tracking-wide text-slate-500">
                  <th className="px-4 py-2.5 font-medium">Tanggal</th>
                  <th className="px-4 py-2.5 font-medium">Jenis</th>
                  <th className="px-4 py-2.5 font-medium">Keterangan</th>
                  <th className="px-4 py-2.5 font-medium">Alasan</th>
                  {canEdit && <th className="px-4 py-2.5 text-right font-medium">Aksi</th>}
                </tr>
              </thead>
              <tbody>
                {changes.map((change) => (
                  <tr key={change.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                    <td className="px-4 py-2.5 font-mono text-xs text-slate-600">{change.date}</td>
                    <td className="px-4 py-2.5">
                      <Badge tone={TYPE_TONE[change.type]}>{TYPE_LABEL[change.type]}</Badge>
                    </td>
                    <td className="px-4 py-2.5 text-slate-700">
                      {change.type === 'pengganti' && (
                        <span className="inline-flex items-center gap-1">
                          <UserCheck className="h-3.5 w-3.5 text-slate-400" />
                          {change.substitute_teacher?.full_name ?? '-'}
                        </span>
                      )}
                      {change.type === 'pindah' && (
                        <span className="font-mono text-xs">
                          {change.new_day?.name ? `${change.new_day.name} ` : ''}
                          {change.new_start_time}–{change.new_end_time}
                          {change.new_room ? ` · ${change.new_room.name}` : ''}
                        </span>
                      )}
                      {change.type === 'batal' && <span className="text-slate-500">Kegiatan dibatalkan</span>}
                    </td>
                    <td className="px-4 py-2.5 text-slate-500">{change.reason ?? '-'}</td>
                    {canEdit && (
                      <td className="px-4 py-2.5 text-right">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="text-red-500 hover:bg-red-50"
                          title="Batalkan perubahan"
                          onClick={() => removeMutation.mutate(change.id)}
                        >
                          <Trash2 className="h-4 w-4" />
                        </Button>
                      </td>
                    )}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </Modal>
  );
}
