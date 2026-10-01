import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarClock, Plus, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { assetsApi } from '../api/assetApi';
import { maintenanceApi } from '../api/assetOpsApi';
import { FormField } from '../components/AssetUi';
import { fmtDateTime, toDateInput } from '../lib/format';
import { frequency } from '../lib/labels';

/**
 * Pemeliharaan preventif.
 *
 * Menjalankan generator dua kali tidak menggandakan pekerjaan — sifat idempoten
 * itu yang membuat tombol “Jalankan sekarang” aman ditekan siapa pun.
 */
export default function MaintenanceSchedulePage() {
  const qc = useQueryClient();
  const [bukaBuat, setBukaBuat] = useState(false);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'maintenance', 'schedules'],
    queryFn: () => maintenanceApi.schedules({ per_page: 50 }),
  });

  const jalankan = useMutation({
    mutationFn: () => maintenanceApi.runSchedules(),
    onSuccess: (hasil) => {
      toast.success(
        hasil.generated.length > 0
          ? `${hasil.generated.length} perintah kerja preventif dibuat`
          : 'Tidak ada jadwal yang jatuh tempo — tidak ada yang digandakan',
      );
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Jadwal Preventif</h1>
          <p className="text-xs text-slate-500">
            Perawatan berkala yang menghasilkan perintah kerja sendiri saat jatuh tempo. Menjalankannya berulang tidak menggandakan pekerjaan.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Can perm="asset.maintenance.manage">
            <Button variant="outline" size="sm" onClick={() => jalankan.mutate()} disabled={jalankan.isPending}>
              <RefreshCw className={`mr-1 h-4 w-4 ${jalankan.isPending ? 'animate-spin' : ''}`} /> Jalankan generator
            </Button>
            <Button size="sm" onClick={() => setBukaBuat(true)}>
              <Plus className="mr-1 h-4 w-4" /> Jadwal baru
            </Button>
          </Can>
        </div>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada jadwal" description="Jadwal preventif mencegah kerusakan, bukan menunggu laporan." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((schedule) => (
              <li key={schedule.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-slate-800">{schedule.title}</span>
                    <Badge tone="blue">
                      <CalendarClock className="mr-1 h-3 w-3" />
                      {frequency.label(schedule.frequency)}
                    </Badge>
                    {!schedule.is_active && <Badge tone="gray">Nonaktif</Badge>}
                  </div>
                  <p className="text-[11px] text-slate-500">
                    {schedule.asset?.name ?? schedule.facility?.name ?? '-'} · jatuh tempo {fmtDateTime(schedule.next_due_at)}
                    {schedule.last_run_at && <> · terakhir dijalankan {fmtDateTime(schedule.last_run_at)}</>}
                  </p>
                </div>
                <span className="text-[11px] text-slate-500">
                  {schedule.last_work_order_id ? `WO #${schedule.last_work_order_id}` : 'belum pernah dijalankan'}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      {bukaBuat && <ScheduleFormModal onClose={() => setBukaBuat(false)} />}
    </div>
  );
}

function ScheduleFormModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [cari, setCari] = useState('');
  const [form, setForm] = useState({
    title: '',
    frequency: 'DAYS_90',
    interval_days: '',
    next_due_at: toDateInput(new Date()),
    asset_id: '' as number | '',
    notes: '',
    checklist: 'Bersihkan filter\nPeriksa keausan\nUji fungsi',
  });

  const { data: kandidat } = useQuery({
    queryKey: ['asset', 'schedule-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 15, status: 'ACTIVE' }),
  });

  const simpan = useMutation({
    mutationFn: () =>
      maintenanceApi.createSchedule({
        ...form,
        interval_days: form.interval_days === '' ? undefined : Number(form.interval_days),
        checklist: form.checklist
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean)
          .map((label) => ({ label })),
      }),
    onSuccess: () => {
      toast.success('Jadwal preventif dibuat');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Jadwal preventif baru"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || !form.title}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Nama pekerjaan" required className="sm:col-span-2">
          <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Misal: Servis AC berkala" />
        </FormField>
        <FormField label="Frekuensi">
          <Select value={form.frequency} onChange={(e) => setForm({ ...form, frequency: e.target.value })}>
            {frequency.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Atau interval hari" hint="Diisi bila frekuensi bukan pilihan tetap.">
          <Input type="number" min={1} value={form.interval_days} onChange={(e) => setForm({ ...form, interval_days: e.target.value })} />
        </FormField>
        <FormField label="Jatuh tempo pertama" required>
          <Input type="date" value={form.next_due_at} onChange={(e) => setForm({ ...form, next_due_at: e.target.value })} />
        </FormField>
        <FormField label="Catatan">
          <Input value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
        <FormField label="Daftar periksa" className="sm:col-span-2" hint="Satu baris satu butir — menjadi tugas pada perintah kerja.">
          <Textarea rows={4} value={form.checklist} onChange={(e) => setForm({ ...form, checklist: e.target.value })} />
        </FormField>
      </div>

      <div className="mt-4">
        <FormField label="Barang (opsional)">
          <Input placeholder="Cari barang…" value={cari} onChange={(e) => setCari(e.target.value)} />
        </FormField>
        <div className="mt-2 max-h-40 overflow-y-auto rounded-lg border border-slate-200">
          {(kandidat?.data ?? []).map((asset) => (
            <label key={asset.id} className="flex items-center gap-2 px-3 py-2 text-sm">
              <input
                type="radio"
                name="asset"
                checked={form.asset_id === asset.id}
                onChange={() => setForm({ ...form, asset_id: asset.id })}
              />
              {asset.name}
              <span className="font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
            </label>
          ))}
        </div>
      </div>
    </Modal>
  );
}
