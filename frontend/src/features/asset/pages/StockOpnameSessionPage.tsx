import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CheckCircle2, ClipboardCheck, RefreshCw, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { opnamesApi } from '../api/assetGovernanceApi';
import { AssetScannerInput } from '../components/AssetScannerInput';
import { FormField } from '../components/AssetUi';
import { fmtDateTime } from '../lib/format';
import { assetCondition, opnameStatus, scanResult, varianceDecision } from '../lib/labels';
import type { StockOpnameVariance } from '../types';

/**
 * Layar penghitungan.
 *
 * Rancangan utamanya bukan tabel, melainkan **kolom pindai yang selalu siap**:
 * orang berdiri di depan rak dengan pemindai genggam dan tidak boleh diminta
 * mengklik apa pun antar barang. Angka di kanan terisi sendiri.
 */
export default function StockOpnameSessionPage() {
  const { opnameId } = useParams();
  const id = Number(opnameId);
  const qc = useQueryClient();
  const [kondisi, setKondisi] = useState('');
  const [catatan, setCatatan] = useState('');
  const [putusan, setPutusan] = useState<StockOpnameVariance | null>(null);
  const [terakhir, setTerakhir] = useState<string | null>(null);

  const { data: opname, isLoading } = useQuery({
    queryKey: ['asset', 'opname', id],
    queryFn: () => opnamesApi.show(id),
    enabled: Number.isFinite(id),
  });

  const { data: ringkasan } = useQuery({
    queryKey: ['asset', 'opname', id, 'summary'],
    queryFn: () => opnamesApi.summary(id),
    enabled: Number.isFinite(id),
  });

  const mulai = useMutation({
    mutationFn: () => opnamesApi.start(id),
    onSuccess: () => {
      toast.success('Penghitungan dimulai');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const pindai = useMutation({
    mutationFn: (code: string) => opnamesApi.scan(id, { code, condition: kondisi || undefined, notes: catatan || undefined }),
    onSuccess: ({ item, result }) => {
      // Selalu ada nama yang dapat dibaca manusia: nama aset, kode yang
      // dipindai, atau kode yang barusan diketik — bukan "undefined".
      const nama = item.asset?.name ?? [item.asset?.asset_code, item.scanned_code].find(Boolean) ?? 'barang tak dikenal';
      setTerakhir(`${nama} — ${scanResult.label(result)}`);

      if (result && result !== 'FOUND') {
        toast.warning(`${scanResult.label(result)}: ${nama}`);
      } else {
        toast.success(`Tercatat: ${nama}`);
      }
      qc.invalidateQueries({ queryKey: ['asset', 'opname', id] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const verifikasi = useMutation({
    mutationFn: () => opnamesApi.verify(id),
    onSuccess: () => {
      toast.success('Penghitungan ditutup — aset yang tidak dipindai kini menjadi selisih menunggu keputusan');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const selesaikan = useMutation({
    mutationFn: () => opnamesApi.complete(id),
    onSuccess: () => {
      toast.success('Sesi selesai — penyesuaian yang disetujui sudah diterapkan');
      qc.invalidateQueries({ queryKey: ['asset'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const putuskan = useMutation({
    mutationFn: ({ varianceId, decision, notes }: { varianceId: number; decision: string; notes?: string }) =>
      opnamesApi.decideVariance(id, varianceId, { decision, notes }),
    onSuccess: () => {
      toast.success('Keputusan selisih dicatat');
      setPutusan(null);
      qc.invalidateQueries({ queryKey: ['asset', 'opname', id] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  if (isLoading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (!opname) {
    return <EmptyState title="Sesi tidak ditemukan" />;
  }

  const s = ringkasan?.summary;
  const variances = opname.variances ?? [];
  const belumDihitung = (opname.items ?? []).filter((i) => i.scan_result === 'MISSING');

  return (
    <div className="space-y-4">
      <nav className="text-xs text-slate-500">
        <Link to="/assets/stock-opnames" className="hover:underline">
          Stok Opname
        </Link>{' '}
        / <span className="font-mono">{opname.opname_number}</span>
      </nav>

      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-lg font-semibold text-slate-800">{opname.title}</h1>
            <Badge tone={opnameStatus.tone(opname.status)}>{opnameStatus.label(opname.status)}</Badge>
          </div>
          <p className="mt-1 text-xs text-slate-500">
            Diharapkan {s?.expected ?? opname.expected_count ?? 0} · ditemukan {s?.found ?? 0} · belum dihitung {s?.missing ?? 0}
            {opname.started_at && <> · mulai {fmtDateTime(opname.started_at)}</>}
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {opname.status === 'PLANNED' && (
            <Can perm="asset.stockopname.manage">
              <Button size="sm" onClick={() => mulai.mutate()} disabled={mulai.isPending}>
                Mulai penghitungan
              </Button>
            </Can>
          )}
          {opname.status === 'IN_PROGRESS' && (
            <Can perm="asset.stockopname.manage">
              <Button size="sm" variant="outline" onClick={() => verifikasi.mutate()} disabled={verifikasi.isPending}>
                <ShieldCheck className="mr-1 h-4 w-4" /> Tutup penghitungan
              </Button>
            </Can>
          )}
          {opname.status === 'VERIFICATION' && (
            <Can perm="asset.stockopname.approve">
              <Button size="sm" onClick={() => selesaikan.mutate()} disabled={selesaikan.isPending || (s?.pending_decisions ?? 0) > 0}>
                <CheckCircle2 className="mr-1 h-4 w-4" /> Selesaikan sesi
              </Button>
            </Can>
          )}
        </div>
      </header>

      {(s?.pending_decisions ?? 0) > 0 && opname.status === 'VERIFICATION' && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-xs text-amber-800">
          Masih ada {s?.pending_decisions} selisih yang belum diputuskan. Sesi tidak dapat diselesaikan sebelum semuanya diputuskan —
          inilah yang mencegah “selisih dirapikan sendiri”.
        </p>
      )}

      <div className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        <Card className="p-4 lg:col-span-2">
          <h2 className="text-sm font-semibold text-slate-800">Catat temuan</h2>
          <p className="mb-3 text-xs text-slate-500">
            Pemindai genggam langsung terkirim begitu menekan Enter. Bila barang ditemukan di ruang lain, ubah kondisinya di sini.
          </p>

          <AssetScannerInput
            onScan={(code) => pindai.mutate(code)}
            disabled={opname.status !== 'IN_PROGRESS' || pindai.isPending}
            hint={opname.status === 'IN_PROGRESS' ? 'Kolom ini tetap siap — tidak perlu mengklik apa pun antar barang.' : 'Sesi belum dimulai atau sudah ditutup.'}
          />

          {terakhir && <p className="mt-2 text-xs text-slate-600">Terakhir: {terakhir}</p>}

          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            <FormField label="Kondisi barang saat ditemukan" hint="Kosong berarti kondisi tercatat sebelumnya.">
              <Select value={kondisi} onChange={(e) => setKondisi(e.target.value)}>
                <option value="">— tidak berubah —</option>
                {assetCondition.options().map((o) => (
                  <option key={o.value} value={o.value}>
                    {o.label}
                  </option>
                ))}
              </Select>
            </FormField>
            <FormField label="Catatan pemeriksa">
              <Input value={catatan} onChange={(e) => setCatatan(e.target.value)} placeholder="Misal: ditemukan di gudang lantai 2" />
            </FormField>
          </div>
        </Card>

        <Card className="p-4">
          <h2 className="text-sm font-semibold text-slate-800">Ringkasan</h2>
          <dl className="mt-3 space-y-2 text-sm">
            {[
              ['Diharapkan', s?.expected ?? 0],
              ['Ditemukan', s?.found ?? 0],
              ['Belum dihitung', s?.missing ?? 0],
              ['Tidak dikenal', s?.unidentified ?? 0],
              ['Berlebih', s?.extra ?? 0],
              ['Selisih', s?.variance ?? 0],
              ['Menunggu keputusan', s?.pending_decisions ?? 0],
            ].map(([label, value]) => (
              <div key={String(label)} className="flex items-center justify-between">
                <dt className="text-slate-500">{label}</dt>
                <dd className="tabular-nums font-medium text-slate-800">{value}</dd>
              </div>
            ))}
          </dl>

          {s && s.expected > 0 && (
            <div className="mt-3">
              <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div className="h-full bg-brand-500" style={{ width: `${Math.min(100, Math.round(((s.found ?? 0) / s.expected) * 100))}%` }} />
              </div>
              <p className="mt-1 text-[11px] text-slate-500">
                {Math.round(((s.found ?? 0) / s.expected) * 100)}% dari daftar harapan sudah terlihat
              </p>
            </div>
          )}

          <Button
            variant="ghost"
            size="sm"
            className="mt-3"
            onClick={() => qc.invalidateQueries({ queryKey: ['asset', 'opname', id] })}
          >
            <RefreshCw className="mr-1 h-3.5 w-3.5" /> Segarkan
          </Button>
        </Card>
      </div>

      {variances.length > 0 && (
        <Card>
          <header className="border-b border-slate-100 px-5 py-3">
            <h2 className="text-sm font-semibold text-slate-800">Selisih ({variances.length})</h2>
            <p className="text-xs text-slate-500">
              Setiap selisih harus diputuskan manusia. “Diterima” berarti penyesuaian diterapkan pada aset saat sesi diselesaikan.
            </p>
          </header>
          <ul className="divide-y divide-slate-100">
            {variances.map((variance) => (
              <li key={variance.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={scanResult.tone(variance.variance_type)}>{scanResult.label(variance.variance_type)}</Badge>
                    <Badge tone={varianceDecision.tone(variance.decision)}>{varianceDecision.label(variance.decision)}</Badge>
                  </div>
                  <p className="mt-1 text-sm text-slate-800">{variance.asset?.name ?? variance.item?.scanned_code ?? 'Barang tidak dikenal'}</p>
                  <p className="text-[11px] text-slate-500">{variance.description ?? '-'}</p>
                </div>
                {variance.decision === 'PENDING' && (
                  <Can perm="asset.stockopname.approve">
                    <Button size="sm" variant="outline" onClick={() => setPutusan(variance)}>
                      Putuskan
                    </Button>
                  </Can>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {belumDihitung.length > 0 && (
        <Card>
          <header className="border-b border-slate-100 px-5 py-3">
            <h2 className="text-sm font-semibold text-slate-800">Belum dihitung ({belumDihitung.length})</h2>
            <p className="text-xs text-slate-500">Barang ini ada pada daftar harapan tetapi belum terlihat. Statusnya tidak berubah sampai selisihnya diputuskan.</p>
          </header>
          <ul className="max-h-80 divide-y divide-slate-100 overflow-y-auto">
            {belumDihitung.map((item) => (
              <li key={item.id} className="flex items-center justify-between px-5 py-2.5 text-sm">
                <span className="text-slate-800">{item.asset?.name ?? '-'}</span>
                <span className="font-mono text-[11px] text-slate-500">{item.asset?.asset_code ?? '-'}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      {putusan && (
        <PutusanModal
          variance={putusan}
          onClose={() => setPutusan(null)}
          onSubmit={(decision, notes) => putuskan.mutate({ varianceId: putusan.id, decision, notes })}
          loading={putuskan.isPending}
        />
      )}
    </div>
  );
}

function PutusanModal({
  variance,
  onClose,
  onSubmit,
  loading,
}: {
  variance: StockOpnameVariance;
  onClose: () => void;
  onSubmit: (decision: string, notes: string) => void;
  loading: boolean;
}) {
  const [decision, setDecision] = useState('ACCEPTED');
  const [notes, setNotes] = useState('');

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-lg rounded-xl bg-white p-5 shadow-lg">
        <h3 className="text-sm font-semibold text-slate-800">Putuskan selisih</h3>
        <p className="mt-1 text-xs text-slate-500">
          {scanResult.label(variance.variance_type)} · {variance.asset?.name ?? variance.item?.scanned_code ?? 'Barang tidak dikenal'}
        </p>

        <div className="mt-3 space-y-3">
          <FormField label="Keputusan" required>
            <Select value={decision} onChange={(e) => setDecision(e.target.value)}>
              {['ACCEPTED', 'REJECTED', 'ESCALATED', 'INVESTIGATED'].map((d) => (
                <option key={d} value={d}>
                  {varianceDecision.label(d)}
                </option>
              ))}
            </Select>
          </FormField>
          <FormField label="Catatan" hint="Contoh: “barang dipinjam panitia, bukan hilang” — catatan ini yang menyelamatkan audit berikutnya.">
            <Textarea rows={3} value={notes} onChange={(e) => setNotes(e.target.value)} />
          </FormField>
        </div>

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button onClick={() => onSubmit(decision, notes)} disabled={loading}>
            <ClipboardCheck className="mr-1 h-4 w-4" /> Simpan keputusan
          </Button>
        </div>
      </div>
    </div>
  );
}
