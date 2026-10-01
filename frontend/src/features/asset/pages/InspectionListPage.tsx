import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardCheck, Plus } from 'lucide-react';
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
import { inspectionsApi } from '../api/assetOpsApi';
import { FormField } from '../components/AssetUi';
import { fmtDateTime } from '../lib/format';
import { assetCondition, inspectionResult, inspectionStatus, inspectionType } from '../lib/labels';
import type { AssetInspection } from '../types';

type ButirHasil = { code: string; label: string; result: 'PASS' | 'FAIL' | 'NOT_APPLICABLE'; notes: string };

/**
 * Inspeksi.
 *
 * Bila ada butir yang GAGAL, backend menerbitkan permintaan pemeliharaan —
 * layar ini hanya menampilkan bahwa hal itu terjadi, bukan menggantikannya.
 */
export default function InspectionListPage() {
  const [bukaJadwal, setBukaJadwal] = useState(false);
  const [periksa, setPeriksa] = useState<AssetInspection | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['asset', 'inspections'],
    queryFn: () => inspectionsApi.list({ per_page: 30 }),
  });

  const rows = data?.data ?? [];

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">Inspeksi</h1>
          <p className="text-xs text-slate-500">
            Inspeksi yang gagal melahirkan permintaan pemeliharaan sendiri — bukan sekadar lembar yang diarsipkan.
          </p>
        </div>
        <Can perm="asset.inspect.manage">
          <Button size="sm" onClick={() => setBukaJadwal(true)}>
            <Plus className="mr-1 h-4 w-4" /> Jadwalkan inspeksi
          </Button>
        </Can>
      </header>

      <Card>
        {isLoading ? (
          <div className="flex justify-center py-16">
            <Spinner />
          </div>
        ) : rows.length === 0 ? (
          <EmptyState title="Belum ada inspeksi" description="Jadwalkan inspeksi berkala untuk barang kritis." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((row) => (
              <li key={row.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-mono text-xs text-slate-500">{row.inspection_number}</span>
                    <Badge tone={inspectionStatus.tone(row.status)}>{inspectionStatus.label(row.status)}</Badge>
                    <Badge tone="gray">{row.inspection_type.replace(/_/g, ' ')}</Badge>
                    {row.follow_up_required && <Badge tone="amber">Perlu tindak lanjut</Badge>}
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{row.asset?.name ?? row.facility?.name ?? '-'}</p>
                  <p className="text-[11px] text-slate-500">
                    Dijadwalkan {fmtDateTime(row.scheduled_at)}
                    {row.performed_at && <> · diperiksa {fmtDateTime(row.performed_at)}</>}
                    {row.score && <> · skor {row.score}%</>}
                  </p>
                </div>
                {['SCHEDULED', 'IN_PROGRESS'].includes(row.status) && (
                  <Can perm="asset.inspect.manage">
                    <Button size="sm" variant="outline" onClick={() => setPeriksa(row)}>
                      <ClipboardCheck className="mr-1 h-3.5 w-3.5" /> Isi hasil
                    </Button>
                  </Can>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      {bukaJadwal && <ScheduleInspectionModal onClose={() => setBukaJadwal(false)} />}
      {periksa && <PerformModal inspection={periksa} onClose={() => setPeriksa(null)} />}
    </div>
  );
}

function ScheduleInspectionModal({ onClose }: { onClose: () => void }) {
  const qc = useQueryClient();
  const [cari, setCari] = useState('');
  const [form, setForm] = useState({ asset_id: '' as number | '', inspection_type: 'ROUTINE', scheduled_at: '', notes: '' });

  const { data: kandidat } = useQuery({
    queryKey: ['asset', 'inspection-candidates', cari],
    queryFn: () => assetsApi.list({ search: cari, per_page: 15 }),
  });

  const simpan = useMutation({
    mutationFn: () => inspectionsApi.create(form),
    onSuccess: () => {
      toast.success('Inspeksi dijadwalkan');
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  return (
    <Modal
      open
      onClose={onClose}
      title="Jadwalkan inspeksi"
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending || form.asset_id === ''}>
            Jadwalkan
          </Button>
        </div>
      }
    >
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Jenis inspeksi">
          <Select value={form.inspection_type} onChange={(e) => setForm({ ...form, inspection_type: e.target.value })}>
            {inspectionType.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal">
          <Input type="datetime-local" value={form.scheduled_at} onChange={(e) => setForm({ ...form, scheduled_at: e.target.value })} />
        </FormField>
        <FormField label="Catatan" className="sm:col-span-2">
          <Textarea rows={2} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
        </FormField>
      </div>
      <div className="mt-4">
        <FormField label="Barang">
          <Input placeholder="Cari barang…" value={cari} onChange={(e) => setCari(e.target.value)} />
        </FormField>
        <div className="mt-2 max-h-44 overflow-y-auto rounded-lg border border-slate-200">
          {(kandidat?.data ?? []).map((asset) => (
            <label key={asset.id} className="flex items-center gap-2 px-3 py-2 text-sm">
              <input type="radio" name="asset-inspeksi" checked={form.asset_id === asset.id} onChange={() => setForm({ ...form, asset_id: asset.id })} />
              {asset.name}
              <span className="font-mono text-[11px] text-slate-500">{asset.asset_code}</span>
            </label>
          ))}
        </div>
      </div>
    </Modal>
  );
}

function PerformModal({ inspection, onClose }: { inspection: AssetInspection; onClose: () => void }) {
  const qc = useQueryClient();
  const [condition, setCondition] = useState(inspection.condition_found ?? 'GOOD');
  const [notes, setNotes] = useState('');
  const [butir, setButir] = useState<ButirHasil[]>(
    (inspection.results ?? []).length > 0
      ? (inspection.results ?? []).map((r) => ({ code: r.code, label: r.label, result: (r.result as ButirHasil['result']) ?? 'PASS', notes: r.notes ?? '' }))
      : [
          { code: 'CONDITION', label: 'Kondisi fisik', result: 'PASS', notes: '' },
          { code: 'FUNCTION', label: 'Fungsi berjalan', result: 'PASS', notes: '' },
          { code: 'SAFETY', label: 'Keamanan', result: 'PASS', notes: '' },
        ],
  );

  const simpan = useMutation({
    mutationFn: () =>
      inspectionsApi.perform(inspection.id, {
        results: butir.map((b) => ({ ...b, notes: b.notes || undefined })),
        condition,
        notes: notes || undefined,
      }),
    onSuccess: (hasil) => {
      toast.success(
        hasil.follow_up_required
          ? 'Inspeksi dicatat — ada butir gagal, permintaan pemeliharaan diterbitkan'
          : 'Inspeksi dicatat — semua butir lulus',
      );
      qc.invalidateQueries({ queryKey: ['asset'] });
      onClose();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const adaGagal = butir.some((b) => b.result === 'FAIL');

  return (
    <Modal
      open
      onClose={onClose}
      title={`Isi hasil inspeksi — ${inspection.inspection_number}`}
      size="lg"
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => simpan.mutate()} disabled={simpan.isPending}>
            Simpan hasil
          </Button>
        </div>
      }
    >
      <div className="space-y-3">
        <FormField label="Kondisi ditemukan">
          <Select value={condition} onChange={(e) => setCondition(e.target.value)}>
            {assetCondition.options().map((o) => (
              <option key={o.value} value={o.value}>
                {o.label}
              </option>
            ))}
          </Select>
        </FormField>

        <div className="rounded-lg border border-slate-200">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-xs uppercase text-slate-500">
              <tr>
                <th className="px-3 py-2">Butir</th>
                <th className="px-3 py-2 w-40">Hasil</th>
                <th className="px-3 py-2">Catatan</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {butir.map((b, index) => (
                <tr key={b.code}>
                  <td className="px-3 py-2">{b.label}</td>
                  <td className="px-3 py-2">
                    <Select
                      value={b.result}
                      onChange={(e) =>
                        setButir((prev) => prev.map((row, i) => (i === index ? { ...row, result: e.target.value as ButirHasil['result'] } : row)))
                      }
                    >
                      {inspectionResult.options().map((o) => (
                        <option key={o.value} value={o.value}>
                          {o.label}
                        </option>
                      ))}
                    </Select>
                  </td>
                  <td className="px-3 py-2">
                    <Input
                      value={b.notes}
                      onChange={(e) => setButir((prev) => prev.map((row, i) => (i === index ? { ...row, notes: e.target.value } : row)))}
                    />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <FormField label="Catatan umum">
          <Textarea rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} />
        </FormField>

        {adaGagal && (
          <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Ada butir gagal: sistem akan menerbitkan permintaan pemeliharaan atas barang ini.
          </p>
        )}
      </div>
    </Modal>
  );
}
