import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Sparkles, Trash2, X } from 'lucide-react';
import { toast } from 'sonner';
import { timeSlotsApi } from '../api/schedulesApi';
import { Modal } from '@/components/ui/Modal';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Badge } from '@/components/ui/Badge';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { useOptions } from '@/hooks/useOptions';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { TimeSlot, TimeSlotPayload, TimeSlotType } from '@/types';

const TYPE_LABEL: Record<TimeSlotType, string> = {
  pelajaran: 'Pelajaran',
  istirahat: 'Istirahat',
  upacara: 'Upacara',
  lainnya: 'Lainnya',
};

const TYPE_TONE: Record<TimeSlotType, 'brand' | 'amber' | 'blue' | 'gray'> = {
  pelajaran: 'brand',
  istirahat: 'amber',
  upacara: 'blue',
  lainnya: 'gray',
};

/** Hari yang tersedia di template default — dipakai panel "isi dari template". */
const TEMPLATE_DAYS = [1, 2, 3, 4, 5];

const emptyForm = (dayId: number | '' = ''): TimeSlotPayload => ({
  day_id: dayId,
  slot_number: '',
  label: '',
  start_time: '07:00',
  end_time: '07:45',
  type: 'pelajaran',
  is_active: true,
});

/**
 * Kelola jam pelajaran — baris-baris yang dipakai grid jadwal.
 *
 * Jam pelajaran dipisah per hari supaya sekolah bisa memakai pola berbeda
 * (mis. Jumat lebih pendek) tanpa memaksa satu pola untuk semua hari.
 */
export function TimeSlotsModal({
  open,
  canEdit,
  onClose,
}: {
  open: boolean;
  canEdit: boolean;
  onClose: () => void;
}) {
  const queryClient = useQueryClient();
  const [values, setValues] = useState<TimeSlotPayload>(emptyForm());
  const [editing, setEditing] = useState<TimeSlot | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [showGenerate, setShowGenerate] = useState(false);
  const [generateDays, setGenerateDays] = useState<number[]>([1, 2, 3, 4, 5]);
  const [overwrite, setOverwrite] = useState(false);

  const { options: dayOptions } = useOptions('days');
  const { can } = usePermission();
  const canCreate = can('academic.create');
  const canDelete = can('academic.delete');

  const slotsQuery = useQuery({
    queryKey: ['time-slots'],
    enabled: open,
    queryFn: () => timeSlotsApi.list({ all: true }),
  });

  useEffect(() => {
    if (open) {
      setEditing(null);
      setErrors({});
      setValues(emptyForm());
      setShowGenerate(false);
    }
  }, [open]);

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['time-slots'] });
    queryClient.invalidateQueries({ queryKey: ['schedule-grid'] });
  };

  const resetForm = () => {
    setEditing(null);
    setValues(emptyForm());
    setErrors({});
  };

  const saveMutation = useMutation({
    mutationFn: () =>
      editing
        ? timeSlotsApi.update(editing.id, values)
        : timeSlotsApi.create(values),
    onSuccess: () => {
      toast.success(editing ? 'Jam pelajaran diperbarui' : 'Jam pelajaran ditambahkan');
      resetForm();
      invalidate();
    },
    onError: (err) => {
      toast.error(getErrorMessage(err));
    },
  });

  const removeMutation = useMutation({
    mutationFn: (id: number) => timeSlotsApi.remove(id),
    onSuccess: () => {
      toast.success('Jam pelajaran dihapus');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const generateMutation = useMutation({
    mutationFn: () => timeSlotsApi.generate(generateDays, overwrite),
    onSuccess: (res) => {
      toast.success(res.message);
      setShowGenerate(false);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const slots = slotsQuery.data ?? [];

  const byDay = useMemo(() => {
    const map = new Map<number, TimeSlot[]>();
    for (const slot of slots) {
      const list = map.get(slot.day_id) ?? [];
      list.push(slot);
      map.set(slot.day_id, list);
    }
    for (const list of map.values()) {
      list.sort((a, b) => a.slot_number - b.slot_number);
    }
    return map;
  }, [slots]);

  const dayName = (id: number) =>
    dayOptions.find((o) => Number(o.value) === id)?.label ?? `Hari ${id}`;

  const handleSubmit = () => {
    const local: Record<string, string> = {};
    if (!values.day_id) local.day_id = 'Hari wajib dipilih';
    if (!values.slot_number) local.slot_number = 'Nomor jam wajib diisi';
    if (!values.start_time) local.start_time = 'Jam mulai wajib diisi';
    if (!values.end_time) local.end_time = 'Jam selesai wajib diisi';
    if (Object.keys(local).length) {
      setErrors(local);
      return;
    }
    setErrors({});
    saveMutation.mutate();
  };

  const startEdit = (slot: TimeSlot) => {
    setEditing(slot);
    setErrors({});
    setValues({
      day_id: slot.day_id,
      slot_number: slot.slot_number,
      label: slot.label ?? '',
      start_time: slot.start_time,
      end_time: slot.end_time,
      type: slot.type,
      is_active: slot.is_active,
    });
  };

  const err = (key: string) => errors[key] || getValidationErrors(saveMutation.error)?.[key]?.[0];

  return (
    <Modal
      open={open}
      onClose={onClose}
      title="Jam Pelajaran"
      size="xl"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      <div className="space-y-4">
        {canEdit && (
          <div className="space-y-3 rounded-lg border border-slate-200 bg-slate-50/60 p-3">
            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-slate-700">
                {editing ? `Ubah jam ke-${editing.slot_number}` : 'Tambah jam pelajaran'}
              </span>
              {editing && (
                <Button variant="ghost" size="sm" onClick={resetForm}>
                  <X className="h-3.5 w-3.5" /> Batal ubah
                </Button>
              )}
            </div>

            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              <div>
                <Label required>Hari</Label>
                <Select
                  value={values.day_id}
                  error={err('day_id')}
                  onChange={(e) =>
                    setValues((p) => ({ ...p, day_id: e.target.value === '' ? '' : Number(e.target.value) }))
                  }
                >
                  <option value="">-- Pilih --</option>
                  {dayOptions.map((o) => (
                    <option key={String(o.value)} value={String(o.value)}>
                      {o.label}
                    </option>
                  ))}
                </Select>
              </div>
              <div>
                <Label required>No. Jam</Label>
                <Input
                  type="number"
                  min={1}
                  max={30}
                  value={values.slot_number}
                  error={err('slot_number')}
                  onChange={(e) =>
                    setValues((p) => ({
                      ...p,
                      slot_number: e.target.value === '' ? '' : Number(e.target.value),
                    }))
                  }
                />
              </div>
              <div>
                <Label>Label</Label>
                <Input
                  value={values.label}
                  placeholder="Jam ke-1"
                  onChange={(e) => setValues((p) => ({ ...p, label: e.target.value }))}
                />
              </div>
              <div>
                <Label required>Mulai</Label>
                <Input
                  type="time"
                  value={values.start_time}
                  error={err('start_time')}
                  onChange={(e) => setValues((p) => ({ ...p, start_time: e.target.value }))}
                />
              </div>
              <div>
                <Label required>Selesai</Label>
                <Input
                  type="time"
                  value={values.end_time}
                  error={err('end_time')}
                  onChange={(e) => setValues((p) => ({ ...p, end_time: e.target.value }))}
                />
              </div>
              <div>
                <Label required>Jenis</Label>
                <Select
                  value={values.type}
                  error={err('type')}
                  onChange={(e) =>
                    setValues((p) => ({ ...p, type: e.target.value as TimeSlotType }))
                  }
                >
                  {(Object.keys(TYPE_LABEL) as TimeSlotType[]).map((t) => (
                    <option key={t} value={t}>
                      {TYPE_LABEL[t]}
                    </option>
                  ))}
                </Select>
              </div>
            </div>

            <div className="flex justify-end">
              <Button
                size="sm"
                loading={saveMutation.isPending}
                disabled={!values.day_id || !values.slot_number}
                onClick={handleSubmit}
              >
                <Plus className="h-4 w-4" /> {editing ? 'Simpan Perubahan' : 'Tambah Jam'}
              </Button>
            </div>

            <div className="border-t border-slate-200 pt-2">
              {showGenerate ? (
                <div className="space-y-2">
                  <p className="text-xs text-slate-500">
                    Isi jam pelajaran otomatis untuk hari terpilih. Pola: Senin–Kamis 45 menit
                    (8 jam pelajaran), Jumat 40 menit (6 jam pelajaran).
                  </p>
                  <div className="flex flex-wrap gap-3">
                    {TEMPLATE_DAYS.map((id) => (
                      <label key={id} className="flex cursor-pointer items-center gap-1.5 text-sm text-slate-700">
                        <input
                          type="checkbox"
                          className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                          checked={generateDays.includes(id)}
                          onChange={(e) =>
                            setGenerateDays((prev) =>
                              e.target.checked ? [...prev, id] : prev.filter((d) => d !== id),
                            )
                          }
                        />
                        {dayName(id)}
                      </label>
                    ))}
                  </div>
                  <label className="flex cursor-pointer items-center gap-2 text-sm text-slate-700">
                    <input
                      type="checkbox"
                      className="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500"
                      checked={overwrite}
                      onChange={(e) => setOverwrite(e.target.checked)}
                    />
                    Timpa jam yang sudah ada
                  </label>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      disabled={generateDays.length === 0}
                      loading={generateMutation.isPending}
                      onClick={() => generateMutation.mutate()}
                    >
                      <Sparkles className="h-4 w-4" /> Isi Template
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setShowGenerate(false)}>
                      Batal
                    </Button>
                  </div>
                </div>
              ) : (
                canCreate && (
                  <Button variant="ghost" size="sm" onClick={() => setShowGenerate(true)}>
                    <Sparkles className="h-4 w-4" /> Isi dari template default
                  </Button>
                )
              )}
            </div>
          </div>
        )}

        {slotsQuery.isLoading ? (
          <div className="flex items-center justify-center gap-2 py-10 text-slate-400">
            <Spinner /> Memuat...
          </div>
        ) : slots.length === 0 ? (
          <EmptyState
            title="Belum ada jam pelajaran"
            description="Tambahkan jam pelajaran, atau isi otomatis dari template default."
          />
        ) : (
          <div className="space-y-3">
            {[...byDay.entries()]
              .sort(([a], [b]) => a - b)
              .map(([dayId, daySlots]) => (
                <div key={dayId} className="overflow-hidden rounded-lg border border-slate-200">
                  <div className="border-b border-slate-100 bg-slate-50/60 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
                    {dayName(dayId)}
                  </div>
                  <table className="w-full text-sm">
                    <tbody>
                      {daySlots.map((slot) => (
                        <tr key={slot.id} className="border-b border-slate-50 last:border-0 hover:bg-slate-50/60">
                          <td className="w-16 px-4 py-2 text-center text-slate-500">{slot.slot_number}</td>
                          <td className="px-4 py-2 text-slate-700">{slot.label ?? '-'}</td>
                          <td className="px-4 py-2 font-mono text-xs text-slate-600">
                            {slot.start_time}–{slot.end_time}
                          </td>
                          <td className="px-4 py-2">
                            <Badge tone={TYPE_TONE[slot.type]}>{TYPE_LABEL[slot.type]}</Badge>
                          </td>
                          {(canEdit || canDelete) && (
                            <td className="px-4 py-2 text-right">
                              <div className="flex justify-end gap-1">
                                {canEdit && (
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Ubah"
                                    onClick={() => startEdit(slot)}
                                  >
                                    <Pencil className="h-4 w-4" />
                                  </Button>
                                )}
                                {canDelete && (
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="text-red-500 hover:bg-red-50"
                                    title="Hapus"
                                    onClick={() => removeMutation.mutate(slot.id)}
                                  >
                                    <Trash2 className="h-4 w-4" />
                                  </Button>
                                )}
                              </div>
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ))}
          </div>
        )}
      </div>
    </Modal>
  );
}
