import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { FlaskConical, Plus, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiApi } from '../api/supervisiApi';
import type { Instrument } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

const CATEGORY_TONES: Record<string, 'brand' | 'blue' | 'green' | 'amber' | 'gray'> = {
  AKADEMIK: 'brand',
  MANAJERIAL: 'blue',
  PKG: 'green',
  KEPALA_SEKOLAH: 'amber',
  KINERJA_PEGAWAI: 'green',
  PIP: 'amber',
  EVALUASI_PROGRAM: 'gray',
  SURVEI: 'gray',
};

/**
 * Pustaka instrumen supervisi.
 *
 * Instrumen bawaan modul (perangkat pembelajaran, telaah RPP, observasi 40 butir,
 * PKG, tendik, dst.) tampil di sini; sekolah dapat menambah instrumen sendiri
 * dan menyusunnya di layar pembuat instrumen.
 */
export default function SupervisiInstrumentsPage() {
  const queryClient = useQueryClient();
  const [category, setCategory] = useState('');
  const [search, setSearch] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState({ name: '', category: 'AKADEMIK', target_type: 'GURU', scoring_mode: 'PERCENT', description: '' });

  const metaQuery = useQuery({ queryKey: ['supervisi', 'meta'], queryFn: () => supervisiApi.meta() });
  const instrumentsQuery = useQuery({
    queryKey: ['supervisi', 'instruments', category, search],
    queryFn: () => supervisiApi.instruments({ category: category || undefined, search: search || undefined }),
  });

  const createMutation = useMutation({
    mutationFn: () => supervisiApi.createInstrument({
      name: form.name.trim(),
      category: form.category,
      target_type: form.target_type,
      scoring_mode: form.scoring_mode,
      description: form.description.trim() || null,
    }),
    onSuccess: (result) => {
      toast.success(`Instrumen dibuat sebagai draf versi ${result.version.version}`);
      setModalOpen(false);
      setForm({ name: '', category: 'AKADEMIK', target_type: 'GURU', scoring_mode: 'PERCENT', description: '' });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'instruments'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const deactivateMutation = useMutation({
    mutationFn: (instrument: Instrument) => supervisiApi.deactivateInstrument(instrument.id, true),
    onSuccess: () => {
      toast.success('Instrumen dinonaktifkan');
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'instruments'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const instruments = instrumentsQuery.data ?? [];
  const meta = metaQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Instrumen Supervisi</h1>
          <p className="text-sm text-slate-500">
            Susun instrumen fleksibel: indikator berskala rubrik maupun entri angka bebas, dalam satu instrumen.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => instrumentsQuery.refetch()}>
            <RefreshCw className={instrumentsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="supervisi.instrument.create">
            <Button onClick={() => setModalOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Instrumen Baru
            </Button>
          </Can>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
              <FlaskConical className="h-4 w-4 text-brand-600" />
              <CardTitle>Pustaka Instrumen</CardTitle>
            </div>
            <div className="flex w-full gap-2 sm:w-auto">
              <Select value={category} onChange={(event) => setCategory(event.target.value)} className="sm:w-52">
                <option value="">Semua kategori</option>
                {(meta?.categories ?? []).map((item) => (
                  <option key={item.value} value={item.value}>{item.label}</option>
                ))}
              </Select>
              <Input
                placeholder="Cari nama instrumen..."
                value={search}
                onChange={(event) => setSearch(event.target.value)}
                className="sm:w-64"
              />
            </div>
          </div>
        </CardHeader>
        <CardBody>
          {instrumentsQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
              <Spinner /> Memuat instrumen...
            </div>
          ) : instruments.length === 0 ? (
            <EmptyState
              title="Belum ada instrumen pada filter ini"
              description="Instrumen bawaan modul akan tersedia setelah seeder dijalankan, atau tambahkan instrumen sekolah sendiri."
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Instrumen</th>
                    <th className="px-4 py-3 font-medium">Kategori</th>
                    <th className="px-4 py-3 font-medium">Sasaran</th>
                    <th className="px-4 py-3 font-medium">Versi Terbit</th>
                    <th className="px-4 py-3 font-medium">Skor Maks.</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {instruments.map((instrument) => {
                    const version = instrument.current_version;
                    const editable = version?.is_editable;

                    return (
                      <tr key={instrument.id} className="hover:bg-slate-50/60">
                        <td className="px-4 py-3">
                          <div className="font-medium text-slate-900">{instrument.name}</div>
                          <div className="text-xs text-slate-500">{instrument.code}{instrument.is_system ? ' • bawaan modul' : ''}</div>
                        </td>
                        <td className="px-4 py-3">
                          <Badge tone={CATEGORY_TONES[instrument.category] ?? 'gray'}>{instrument.category_label}</Badge>
                        </td>
                        <td className="px-4 py-3">{instrument.target_type_label}</td>
                        <td className="px-4 py-3">
                          {version ? (
                            <div className="flex items-center gap-2">
                              <span className="text-slate-700">v{version.version}</span>
                              <Badge tone={version.status === 'PUBLISHED' ? 'green' : version.status === 'DRAFT' ? 'amber' : 'gray'}>
                                {version.status_label}
                              </Badge>
                            </div>
                          ) : (
                            <span className="text-slate-400">belum ada</span>
                          )}
                        </td>
                        <td className="px-4 py-3">{version?.max_score ?? '—'}</td>
                        <td className="px-4 py-3">
                          <div className="flex justify-end gap-2">
                            {version && (
                              <Link to={`/supervisi/instruments/${instrument.id}/versions/${version.id}`}>
                                <Button size="sm" variant="outline">
                                  {editable ? 'Susun struktur' : 'Lihat struktur'}
                                </Button>
                              </Link>
                            )}
                            <Can perm="supervisi.instrument.delete">
                              {!instrument.is_system && instrument.is_active && (
                                <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => deactivateMutation.mutate(instrument)}
                                  disabled={deactivateMutation.isPending}
                                >
                                  Nonaktifkan
                                </Button>
                              )}
                            </Can>
                          </div>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Instrumen Baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => (form.name.trim() ? createMutation.mutate() : toast.error('Nama instrumen wajib diisi.'))}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Buat Draf
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div>
            <Label required>Nama Instrumen</Label>
            <Input
              value={form.name}
              onChange={(event) => setForm((previous) => ({ ...previous, name: event.target.value }))}
              placeholder="mis. Supervisi Pembelajaran Daring"
            />
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <Label required>Kategori</Label>
              <Select value={form.category} onChange={(event) => setForm((previous) => ({ ...previous, category: event.target.value }))}>
                {(meta?.categories ?? []).map((item) => (
                  <option key={item.value} value={item.value}>{item.label}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label required>Sasaran</Label>
              <Select value={form.target_type} onChange={(event) => setForm((previous) => ({ ...previous, target_type: event.target.value }))}>
                {(meta?.target_types ?? []).map((item) => (
                  <option key={item.value} value={item.value}>{item.label}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Cara Menghitung Nilai</Label>
              <Select value={form.scoring_mode} onChange={(event) => setForm((previous) => ({ ...previous, scoring_mode: event.target.value }))}>
                {(meta?.scoring_modes ?? []).map((item) => (
                  <option key={item.value} value={item.value}>{item.label}</option>
                ))}
              </Select>
            </div>
          </div>
          <div>
            <Label>Keterangan</Label>
            <Textarea
              rows={3}
              value={form.description}
              onChange={(event) => setForm((previous) => ({ ...previous, description: event.target.value }))}
              placeholder="Untuk apa instrumen ini dipakai dan siapa yang menilai."
            />
          </div>
          <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
            Instrumen dibuat sebagai <strong>draf versi 1</strong>. Setelah struktur selesai dan lolos pemeriksaan,
            versi diterbitkan agar dapat dipakai pada jadwal supervisi. Versi yang sudah terbit tidak dapat diubah —
            revisi dilakukan dengan mengkloning versi baru.
          </p>
        </div>
      </Modal>
    </div>
  );
}
