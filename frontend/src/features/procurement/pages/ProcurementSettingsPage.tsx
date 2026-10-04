import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Info, Save, Settings2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { reportsApi } from '../api/procurementApi';
import { useIntegrationHealth } from '../components/ProcurementUi';
import type { ProcurementSetting } from '../types';

/**
 * Pengaturan modul.
 *
 * Nilai di sini adalah aturan bisnis yang boleh berbeda antar sekolah — bukan
 * konstanta kode. Ambang metode pengadaan, toleransi pencocokan, dan bobot
 * evaluasi semuanya dibaca dari sini.
 */
export default function ProcurementSettingsPage() {
  const qc = useQueryClient();
  const { health } = useIntegrationHealth();

  const { data, isLoading } = useQuery({
    queryKey: ['procurement', 'settings'],
    queryFn: () => reportsApi.settings(),
  });

  const [draft, setDraft] = useState<Record<string, string>>({});
  const [baru, setBaru] = useState({ key: '', value: '', group: 'general', description: '' });

  const simpan = useMutation({
    mutationFn: (payload: { key: string; value: unknown; group?: string; description?: string }) =>
      reportsApi.updateSetting(payload),
    onSuccess: () => {
      toast.success('Pengaturan disimpan');
      qc.invalidateQueries({ queryKey: ['procurement', 'settings'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const settings = data ?? [];
  const groups = Array.from(new Set(settings.map((s) => s.group ?? 'general')));

  const asText = (v: unknown) => (typeof v === 'string' ? v : JSON.stringify(v));

  return (
    <div className="space-y-4">
      <header>
        <h1 className="text-lg font-semibold text-slate-800">Pengaturan Pengadaan</h1>
        <p className="text-xs text-slate-500">
          Ambang dan toleransi di sini dapat diubah tanpa deploy — sesuaikan dengan kebijakan sekolah.
        </p>
      </header>

      <Card className="p-5">
        <h2 className="flex items-center gap-2 text-sm font-semibold text-slate-800">
          <Settings2 className="h-4 w-4 text-brand-600" /> Status integrasi modul
        </h2>
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
          {Object.entries(health?.providers ?? {}).map(([name, installed]) => (
            <div key={name} className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
              <span className="capitalize text-slate-600">{name}</span>
              <span className={installed ? 'text-xs font-medium text-green-600' : 'text-xs font-medium text-amber-600'}>
                {installed ? 'Terpasang' : 'Belum'}
              </span>
            </div>
          ))}
          {!health && <p className="text-xs text-slate-500">Status integrasi tidak tersedia.</p>}
        </div>
        <p className="mt-3 flex items-start gap-1.5 text-[11px] text-slate-500">
          <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          Modul yang belum terpasang tidak menghalangi alur pengadaan, tetapi langkah integrasinya (jurnal, stok,
          aset) tidak akan berjalan otomatis.
        </p>
      </Card>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <Spinner />
        </div>
      ) : settings.length === 0 ? (
        <Card>
          <EmptyState title="Belum ada pengaturan tersimpan" description="Tambahkan pengaturan pertama di bawah." />
        </Card>
      ) : (
        groups.map((group) => (
          <Card key={group} className="p-5">
            <h2 className="mb-3 text-sm font-semibold capitalize text-slate-800">{group}</h2>
            <div className="space-y-3">
              {settings
                .filter((s) => (s.group ?? 'general') === group)
                .map((s) => (
                  <SettingRow
                    key={s.id}
                    setting={s}
                    value={draft[s.key] ?? asText(s.value)}
                    onChange={(v) => setDraft((p) => ({ ...p, [s.key]: v }))}
                    onSave={() => simpan.mutate({ key: s.key, value: parseValue(draft[s.key] ?? asText(s.value)), group: s.group ?? 'general', description: s.description ?? undefined })}
                    saving={simpan.isPending}
                  />
                ))}
            </div>
          </Card>
        ))
      )}

      <Card className="p-5">
        <h2 className="mb-3 text-sm font-semibold text-slate-800">Tambah / perbarui pengaturan</h2>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <Input placeholder="kunci (mis. methods.competitive.min_quotations)" value={baru.key} onChange={(e) => setBaru({ ...baru, key: e.target.value })} />
          <Input placeholder="nilai" value={baru.value} onChange={(e) => setBaru({ ...baru, value: e.target.value })} />
          <Input placeholder="grup" value={baru.group} onChange={(e) => setBaru({ ...baru, group: e.target.value })} />
          <Button
            onClick={() => simpan.mutate({ key: baru.key, value: parseValue(baru.value), group: baru.group })}
            loading={simpan.isPending}
            disabled={!baru.key || baru.value === ''}
          >
            <Save className="mr-1 h-4 w-4" /> Simpan
          </Button>
        </div>
      </Card>
    </div>
  );
}

function SettingRow({
  setting,
  value,
  onChange,
  onSave,
  saving,
}: {
  setting: ProcurementSetting;
  value: string;
  onChange: (v: string) => void;
  onSave: () => void;
  saving: boolean;
}) {
  return (
    <div className="flex flex-wrap items-center gap-2">
      <div className="min-w-[220px] flex-1">
        <p className="font-mono text-xs text-slate-700">{setting.key}</p>
        {setting.description && <p className="text-[11px] text-slate-500">{setting.description}</p>}
      </div>
      <Input className="max-w-[240px]" value={value} onChange={(e) => onChange(e.target.value)} />
      <Button size="sm" variant="outline" onClick={onSave} loading={saving}>
        Simpan
      </Button>
    </div>
  );
}

/** Coba tebak tipe nilai: angka, boolean, JSON, atau string. */
function parseValue(raw: string): unknown {
  const t = raw.trim();
  if (t === 'true') return true;
  if (t === 'false') return false;
  if (t !== '' && !Number.isNaN(Number(t))) return Number(t);
  if ((t.startsWith('{') && t.endsWith('}')) || (t.startsWith('[') && t.endsWith(']'))) {
    try {
      return JSON.parse(t);
    } catch {
      return raw;
    }
  }
  return raw;
}
