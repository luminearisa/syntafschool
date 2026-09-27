import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Save } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { FormField } from '../components/BoardingUi';
import { settingsApi } from '../api/boardingApi';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingSettings } from '@/types/boarding';

export function BoardingSettingsPage() {
  const qc = useQueryClient();
  const settingsQuery = useQuery({
    queryKey: ['boarding', 'settings'],
    queryFn: () => settingsApi.show(),
  });

  const [values, setValues] = useState<BoardingSettings | null>(null);
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (settingsQuery.data) setValues(settingsQuery.data as BoardingSettings);
  }, [settingsQuery.data]);

  const save = useMutation({
    mutationFn: () => settingsApi.update(values as BoardingSettings),
    onSuccess: () => {
      toast.success('Pengaturan asrama disimpan');
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  if (settingsQuery.isLoading || !values) {
    return (
      <Card>
        <div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat pengaturan...</div>
      </Card>
    );
  }

  const set = <K extends keyof BoardingSettings>(key: K, value: BoardingSettings[K]) =>
    setValues((v) => (v ? { ...v, [key]: value } : v));

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Pengaturan Boarding</h1>
        <p className="text-sm text-slate-500">Poin, ambang tindakan, alur persetujuan izin, dan RFID absensi (§35–§38).</p>
      </div>

      <Can perm="boarding.settings.view">
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card className="p-4">
            <p className="mb-3 text-sm font-semibold text-slate-700">Kedisiplinan & Poin</p>
            <div className="space-y-3">
              <FormField label="Istilah poin" error={errors.point_label}>
                <Input value={String(values.point_label ?? '')} onChange={(e) => set('point_label', e.target.value)} />
              </FormField>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <FormField label="Ambang peringatan" error={errors.point_threshold_warning}>
                  <Input type="number" min={0} value={String(values.point_threshold_warning ?? 0)} onChange={(e) => set('point_threshold_warning', Number(e.target.value))} />
                </FormField>
                <FormField label="Ambang bimbingan" error={errors.point_threshold_coaching}>
                  <Input type="number" min={0} value={String(values.point_threshold_coaching ?? 0)} onChange={(e) => set('point_threshold_coaching', Number(e.target.value))} />
                </FormField>
                <FormField label="Ambang lapor orang tua" error={errors.point_threshold_parent_notification}>
                  <Input type="number" min={0} value={String(values.point_threshold_parent_notification ?? 0)} onChange={(e) => set('point_threshold_parent_notification', Number(e.target.value))} />
                </FormField>
              </div>
              <p className="text-xs text-slate-400">
                Ambang harus menaik: peringatan &lt; bimbingan &lt; lapor orang tua. Pelanggaran dengan poin melewati ambang akan memunculkan tindakan otomatis (§36–§37).
              </p>
            </div>
          </Card>

          <Card className="p-4">
            <p className="mb-3 text-sm font-semibold text-slate-700">Perizinan & Absensi</p>
            <div className="space-y-3">
              <FormField label="Langkah persetujuan izin keluar" error={errors.permission_approval_steps}>
                <Select
                  value={String(values.permission_approval_steps ?? 1)}
                  onChange={(e) => set('permission_approval_steps', Number(e.target.value))}
                >
                  <option value="1">1 langkah (musyrif/petugas)</option>
                  <option value="2">2 langkah (bertingkat)</option>
                </Select>
              </FormField>
              <FormField label="Toleransi check-in terlambat (menit)" error={errors.permission_checkin_grace_minutes}>
                <Input type="number" min={0} value={String(values.permission_checkin_grace_minutes ?? 0)} onChange={(e) => set('permission_checkin_grace_minutes', Number(e.target.value))} />
              </FormField>
              <FormField label="Absensi via kartu RFID">
                <Select
                  value={values.rfid_attendance_enabled ? '1' : '0'}
                  onChange={(e) => set('rfid_attendance_enabled', e.target.value === '1')}
                >
                  <option value="1">Aktif — scan kartuWallet santri di gerbang/kegiatan</option>
                  <option value="0">Nonaktif — absensi manual saja</option>
                </Select>
              </FormField>
              <p className="text-xs text-slate-400">
                RFID memakai kartu wallet yang sudah ada — tidak ada sistem kartu baru (§26).
              </p>
            </div>
          </Card>
        </div>

        <Can perm="boarding.settings.manage">
          <div className="flex justify-end">
            <Button onClick={() => save.mutate()} disabled={save.isPending}>
              {save.isPending ? <Spinner /> : <Save className="h-4 w-4" />} Simpan Pengaturan
            </Button>
          </div>
        </Can>
      </Can>
    </div>
  );
}
