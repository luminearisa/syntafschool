import { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryOperatingHoursApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { DAY_LABELS } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { usePermission } from '@/hooks/usePermission';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LibraryOperatingHour, LibraryOperatingHourRow } from '@/types/library';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

interface IntervalRow {
  id: string;
  open_time: string;
  close_time: string;
}

interface DayState {
  is_open: boolean;
  intervals: IntervalRow[];
}

let uidSeq = 0;
const uid = () => `iv-${++uidSeq}`;

function emptyDays(): Record<number, DayState> {
  const out: Record<number, DayState> = {};
  for (let d = 0; d <= 6; d++) out[d] = { is_open: false, intervals: [] };
  return out;
}

function daysFromHours(hours: LibraryOperatingHour[]): Record<number, DayState> {
  const out = emptyDays();
  for (const hour of hours) {
    const day = out[hour.day_of_week];
    if (!day) continue;
    if (hour.is_open) {
      day.is_open = true;
      day.intervals.push({
        id: uid(),
        open_time: hour.open_time ?? '',
        close_time: hour.close_time ?? '',
      });
    }
  }
  return out;
}

/** Validasi klien: jam wajib, urutan jam, dan interval yang tidak tumpang tindih. */
function collectLocalErrors(days: Record<number, DayState>): Record<string, string> {
  const errors: Record<string, string> = {};

  for (let d = 0; d <= 6; d++) {
    const day = days[d];
    if (!day?.is_open) continue;

    for (const iv of day.intervals) {
      if (!iv.open_time) errors[`${iv.id}:open`] = 'Jam buka wajib diisi';
      if (!iv.close_time) errors[`${iv.id}:close`] = 'Jam tutup wajib diisi';
      if (iv.open_time && iv.close_time && iv.close_time <= iv.open_time) {
        errors[`${iv.id}:close`] = 'Jam tutup harus setelah jam buka';
      }
    }

    const sorted = [...day.intervals]
      .filter((iv) => iv.open_time && iv.close_time)
      .sort((a, b) => a.open_time.localeCompare(b.open_time));

    for (let i = 1; i < sorted.length; i++) {
      if (sorted[i].open_time < sorted[i - 1].close_time) {
        errors[`${sorted[i].id}:open`] = 'Interval pada hari yang sama tidak boleh tumpang tindih';
      }
    }
  }

  return errors;
}

/**
 * Jam operasional perpustakaan (editor mingguan).
 *
 * Satu hari boleh punya beberapa interval (mis. 08:00–12:00 dan 13:00–16:00).
 * Penyimpanan bersifat bulk replace — hari yang dihilangkan akan terhapus.
 */
export function LibraryOperatingHoursPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions, isLoading: librariesLoading } = useLibraryOptions();
  const { can } = usePermission();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [days, setDays] = useState<Record<number, DayState>>(emptyDays);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [clientErrors, setClientErrors] = useState<Record<string, string>>({});

  const canEdit = can('library.operating_hours.update');

  const hoursQuery = useQuery({
    queryKey: ['library', 'operating-hours', libraryId],
    enabled: libraryId !== '',
    queryFn: () => libraryOperatingHoursApi.list(Number(libraryId)),
  });

  useEffect(() => {
    if (libraryId === '') {
      setDays(emptyDays());
      return;
    }
    setDays(daysFromHours(hoursQuery.data ?? []));
    setServerErrors({});
    setClientErrors({});
  }, [libraryId, hoursQuery.data]);

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const saveMutation = useMutation({
    mutationFn: (rows: LibraryOperatingHourRow[]) =>
      libraryOperatingHoursApi.update(Number(libraryId), rows),
    onSuccess: (res) => {
      toast.success(res.message);
      setServerErrors({});
      setClientErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const payloadRows = useMemo(() => {
    const rows: { rowId: string; payload: LibraryOperatingHourRow }[] = [];

    for (let d = 0; d <= 6; d++) {
      const day = days[d];
      if (!day || !day.is_open) {
        rows.push({ rowId: `day-${d}`, payload: { day_of_week: d, is_open: false, open_time: '', close_time: '' } });
        continue;
      }

      if (day.intervals.length === 0) {
        rows.push({ rowId: `day-${d}`, payload: { day_of_week: d, is_open: true, open_time: '', close_time: '' } });
        continue;
      }

      for (const iv of day.intervals) {
        rows.push({
          rowId: iv.id,
          payload: {
            day_of_week: d,
            is_open: true,
            open_time: iv.open_time,
            close_time: iv.close_time,
          },
        });
      }
    }

    return rows;
  }, [days]);

  const serverRowErrors = useMemo(() => {
    const map: Record<string, string> = {};
    payloadRows.forEach((row, index) => {
      const open = serverErrors[`hours.${index}.open_time`]?.[0];
      const close = serverErrors[`hours.${index}.close_time`]?.[0];
      const dayErr =
        serverErrors[`hours.${index}.day_of_week`]?.[0] ?? serverErrors[`hours.${index}.is_open`]?.[0];
      if (open) map[`${row.rowId}:open`] = open;
      if (close) map[`${row.rowId}:close`] = close;
      if (dayErr && !map[`${row.rowId}:open`]) map[`${row.rowId}:open`] = dayErr;
    });
    return map;
  }, [payloadRows, serverErrors]);

  const fieldError = (rowId: string, field: 'open' | 'close') =>
    clientErrors[`${rowId}:${field}`] ?? serverRowErrors[`${rowId}:${field}`];

  const updateDay = (day: number, updater: (prev: DayState) => DayState) => {
    setDays((prev) => ({ ...prev, [day]: updater(prev[day]) }));
  };

  const toggleOpen = (day: number) => {
    updateDay(day, (prev) => {
      if (prev.is_open) return { ...prev, is_open: false };
      const intervals = prev.intervals.length
        ? prev.intervals
        : [{ id: uid(), open_time: '', close_time: '' }];
      return { is_open: true, intervals };
    });
  };

  const addInterval = (day: number) => {
    updateDay(day, (prev) => ({
      is_open: true,
      intervals: [...prev.intervals, { id: uid(), open_time: '', close_time: '' }],
    }));
  };

  const removeInterval = (day: number, rowId: string) => {
    updateDay(day, (prev) => ({
      ...prev,
      intervals: prev.intervals.filter((iv) => iv.id !== rowId),
    }));
  };

  const setIntervalTime = (day: number, rowId: string, field: 'open_time' | 'close_time', value: string) => {
    updateDay(day, (prev) => ({
      ...prev,
      intervals: prev.intervals.map((iv) => (iv.id === rowId ? { ...iv, [field]: value } : iv)),
    }));
  };

  const handleSubmit = () => {
    const errors = collectLocalErrors(days);
    setClientErrors(errors);

    if (payloadRows.length === 0) {
      toast.error('Tidak ada jam operasional untuk disimpan.');
      return;
    }

    if (Object.keys(errors).length) {
      toast.error('Periksa kembali jam yang ditandai.');
      return;
    }

    saveMutation.mutate(payloadRows.map((row) => row.payload));
  };

  const globalError = serverErrors.hours?.[0];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Jam Operasional</h1>
          <p className="text-sm text-slate-500">
            Jadwal mingguan layanan perpustakaan. Satu hari boleh memiliki beberapa interval.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            disabled={libraryId === ''}
            onClick={() => hoursQuery.refetch()}
          >
            <RefreshCw className={hoursQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.operating_hours.update">
            <Button
              disabled={libraryId === '' || !canEdit}
              loading={saveMutation.isPending}
              onClick={handleSubmit}
            >
              Simpan
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="max-w-md">
          <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
          <SearchSelect
            options={libraryOptions}
            value={libraryId}
            disabled={librariesLoading}
            onChange={(v) => setLibraryId(toId(v))}
            placeholder="-- Pilih perpustakaan --"
          />
        </div>
      </Card>

      {globalError && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
          {globalError}
        </div>
      )}

      {libraryId === '' ? (
        <Card>
          <EmptyState
            title="Pilih perpustakaan"
            description="Pilih salah satu perpustakaan untuk mengatur jam operasionalnya."
          />
        </Card>
      ) : hoursQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat jam operasional...
          </div>
        </Card>
      ) : hoursQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat jam operasional"
            description={getErrorMessage(hoursQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => hoursQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : (
        <>
          <Card>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                    <th className="px-4 py-2 font-medium">Hari</th>
                    <th className="px-4 py-2 font-medium">Status</th>
                    <th className="px-4 py-2 font-medium">Buka</th>
                    <th className="px-4 py-2 font-medium">Tutup</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.keys(DAY_LABELS).map((key) => {
                    const d = Number(key);
                    const day = days[d];
                    const opens = day.is_open
                      ? day.intervals.map((iv) => iv.open_time).filter(Boolean).join(', ')
                      : '';
                    const closes = day.is_open
                      ? day.intervals.map((iv) => iv.close_time).filter(Boolean).join(', ')
                      : '';
                    return (
                      <tr key={d} className="border-b border-slate-50 last:border-0">
                        <td className="px-4 py-2 font-medium text-slate-700">{DAY_LABELS[d]}</td>
                        <td className="px-4 py-2">
                          <Badge tone={day.is_open ? 'green' : 'gray'}>
                            {day.is_open ? 'Buka' : 'Tutup'}
                          </Badge>
                        </td>
                        <td className="px-4 py-2 font-mono text-xs text-slate-600">
                          {opens || '—'}
                        </td>
                        <td className="px-4 py-2 font-mono text-xs text-slate-600">
                          {closes || '—'}
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Card>

          <div className="space-y-2">
            {Object.keys(DAY_LABELS).map((key) => {
              const d = Number(key);
              const day = days[d];
              return (
                <Card key={d} className="p-3">
                  <div className="flex flex-col gap-2 sm:flex-row sm:items-start">
                    <div className="flex w-40 shrink-0 items-center gap-2">
                      <span className="text-sm font-medium text-slate-800">{DAY_LABELS[d]}</span>
                      <Badge tone={day.is_open ? 'green' : 'gray'}>
                        {day.is_open ? 'Buka' : 'Tutup'}
                      </Badge>
                    </div>

                    <div className="flex-1 space-y-2">
                      {!day.is_open ? (
                        <p className="py-1 text-sm text-slate-400">Tutup sepanjang hari.</p>
                      ) : day.intervals.length === 0 ? (
                        <p className="py-1 text-sm text-slate-400">
                          Belum ada interval — tambahkan minimal satu.
                        </p>
                      ) : (
                        day.intervals.map((iv) => (
                          <div key={iv.id} className="flex flex-wrap items-center gap-2">
                            <Input
                              type="time"
                              className="w-32"
                              value={iv.open_time}
                              error={fieldError(iv.id, 'open')}
                              disabled={!canEdit}
                              onChange={(e) => setIntervalTime(d, iv.id, 'open_time', e.target.value)}
                            />
                            <span className="text-slate-400">s/d</span>
                            <Input
                              type="time"
                              className="w-32"
                              value={iv.close_time}
                              error={fieldError(iv.id, 'close')}
                              disabled={!canEdit}
                              onChange={(e) => setIntervalTime(d, iv.id, 'close_time', e.target.value)}
                            />
                            <Can perm="library.operating_hours.update">
                              <Button
                                variant="ghost"
                                size="icon"
                                title="Hapus interval"
                                onClick={() => removeInterval(d, iv.id)}
                              >
                                <Trash2 className="h-3.5 w-3.5 text-red-500" />
                              </Button>
                            </Can>
                          </div>
                        ))
                      )}

                      <Can perm="library.operating_hours.update">
                        <div className="flex flex-wrap gap-2 pt-1">
                          <Button variant="outline" size="sm" onClick={() => toggleOpen(d)}>
                            {day.is_open ? 'Tutup' : 'Buka'}
                          </Button>
                          <Button variant="ghost" size="sm" onClick={() => addInterval(d)}>
                            <Plus className="h-3.5 w-3.5" /> Tambah interval
                          </Button>
                        </div>
                      </Can>
                    </div>
                  </div>
                </Card>
              );
            })}
          </div>
        </>
      )}
    </div>
  );
}