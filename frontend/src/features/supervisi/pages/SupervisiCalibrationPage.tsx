import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Gauge, Plus, Scale, Users } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiInsightApi, type DivergenceRow } from '../api/supervisiInsightApi';
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
import { api, getErrorMessage } from '@/lib/axios';
import type { ApiResponse } from '@/types';

interface EmployeeOption {
  id: number;
  full_name: string;
  position?: string | null;
}

interface IndicatorOption {
  id: number;
  statement: string;
  section?: string | null;
}

const STATUS_TONES: Record<string, 'gray' | 'blue' | 'green'> = {
  DRAFT: 'blue',
  FINALIZED: 'green',
};

/**
 * Kalibrasi penilai: menyamakan ukuran antar supervisor sebelum nilai dipakai
 * menilai kinerja. Menampilkan selisih maks−min per indikator dan bahan
 * moderasi dari kegiatan nyata.
 */
export default function SupervisiCalibrationPage() {
  const queryClient = useQueryClient();
  const [selected, setSelected] = useState<number | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [conclusion, setConclusion] = useState('');
  const [form, setForm] = useState({ title: '', threshold: '1', held_at: new Date().toISOString().slice(0, 10), note: '' });
  const [entry, setEntry] = useState<{ supervisor: string; indicator: string; score: string; comment: string }>({
    supervisor: '',
    indicator: '',
    score: '',
    comment: '',
  });

  const listQuery = useQuery({
    queryKey: ['supervisi', 'calibrations'],
    queryFn: () => supervisiInsightApi.calibrations({ per_page: 50 }),
  });

  const divergenceQuery = useQuery({
    queryKey: ['supervisi', 'calibration-divergence'],
    queryFn: () => supervisiInsightApi.calibrationDivergence(8),
  });

  const detailQuery = useQuery({
    queryKey: ['supervisi', 'calibration', selected],
    queryFn: () => supervisiInsightApi.calibrationDetail(selected ?? 0),
    enabled: selected !== null,
  });

  const employeesQuery = useQuery({
    queryKey: ['employees', 'options'],
    queryFn: async () => {
      const response = await api.get<ApiResponse<EmployeeOption[]>>('/employees', { params: { per_page: 200, is_active: 1 } });
      const payload = response.data.data;

      return Array.isArray(payload) ? payload : [];
    },
    enabled: createOpen || selected !== null,
    staleTime: 5 * 60 * 1000,
  });

  const instrumentsQuery = useQuery({
    queryKey: ['supervisi', 'instruments', 'options'],
    queryFn: async () => {
      const response = await api.get<ApiResponse<{ versions: Array<{ id: number; instrument?: { name?: string }; version: number }> }>>(
        '/supervisi/instruments',
      );

      return response.data.data.versions ?? [];
    },
    enabled: createOpen,
    staleTime: 5 * 60 * 1000,
  });

  const [versionId, setVersionId] = useState<string>('');

  const indicatorsQuery = useQuery({
    queryKey: ['supervisi', 'indicators', versionId],
    queryFn: async () => {
      const response = await api.get<ApiResponse<{ indicators: IndicatorOption[] }>>(`/supervisi/instruments/versions/${versionId}`);
      const payload = response.data.data;

      return (payload.indicators ?? []) as IndicatorOption[];
    },
    enabled: versionId !== '' && selected !== null,
    staleTime: 10 * 60 * 1000,
  });

  useEffect(() => {
    const version = detailQuery.data?.session.instrument_version_id;
    if (version) setVersionId(String(version));
  }, [detailQuery.data?.session.instrument_version_id]);

  const createMutation = useMutation({
    mutationFn: () =>
      supervisiInsightApi.createCalibration({
        title: form.title,
        threshold: Number(form.threshold),
        held_at: form.held_at,
        note: form.note || null,
      }),
    onSuccess: (data) => {
      toast.success('Sesi kalibrasi dibuat');
      setCreateOpen(false);
      setForm({ title: '', threshold: '1', held_at: new Date().toISOString().slice(0, 10), note: '' });
      setSelected(data.session.id);
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'calibrations'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const scoreMutation = useMutation({
    mutationFn: () =>
      supervisiInsightApi.saveCalibrationScores(selected ?? 0, [
        {
          supervisor_employee_id: Number(entry.supervisor),
          indicator_id: entry.indicator === '' ? null : Number(entry.indicator),
          indicator_label: entry.indicator === '' ? 'Kasus bersama (tanpa indikator)' : null,
          score: entry.score === '' ? null : Number(entry.score),
          comment: entry.comment || null,
        },
      ]),
    onSuccess: () => {
      toast.success('Skor penilai tersimpan');
      setEntry({ supervisor: '', indicator: '', score: '', comment: '' });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'calibration', selected] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const finalizeMutation = useMutation({
    mutationFn: () => supervisiInsightApi.finalizeCalibration(selected ?? 0, conclusion),
    onSuccess: () => {
      toast.success('Sesi kalibrasi difinalkan');
      setConclusion('');
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'calibration', selected] });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'calibrations'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const detail = detailQuery.data;
  const rows = listQuery.data?.rows ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kalibrasi Penilai</h1>
          <p className="text-sm text-slate-500">
            Sesi moderasi antar supervisor: selisih skor per indikator, kesepakatan penilaian, dan bahan dari kegiatan nyata.
          </p>
        </div>
        <Button onClick={() => setCreateOpen(true)}>
          <Plus className="mr-2 h-4 w-4" /> Sesi Baru
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-1">
          <CardHeader>
            <div className="flex items-center justify-between">
              <CardTitle>Sesi Kalibrasi</CardTitle>
              <Badge tone="gray">{listQuery.data?.pending ?? 0} belum final</Badge>
            </div>
          </CardHeader>
          <CardBody className="space-y-2">
            {listQuery.isLoading && <div className="flex items-center gap-2 py-6 text-slate-400"><Spinner /> Memuat...</div>}
            {!listQuery.isLoading && rows.length === 0 && <EmptyState title="Belum ada sesi kalibrasi" />}
            {rows.map((row) => (
              <button
                key={row.id}
                type="button"
                onClick={() => setSelected(row.id)}
                className={`w-full rounded-lg border p-3 text-left text-sm ${
                  selected === row.id ? 'border-brand-300 bg-brand-50' : 'border-slate-200 hover:bg-slate-50'
                }`}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="font-medium text-slate-800">{row.title}</span>
                  <Badge tone={STATUS_TONES[row.status] ?? 'gray'}>{row.status === 'FINALIZED' ? 'final' : 'draf'}</Badge>
                </div>
                <div className="mt-1 text-xs text-slate-500">
                  {row.held_at ? new Date(row.held_at).toLocaleDateString('id-ID') : '—'} · ambang selisih {row.threshold}
                </div>
              </button>
            ))}
          </CardBody>
        </Card>

        <div className="space-y-4 lg:col-span-2">
          {detail === undefined ? (
            <Card>
              <CardBody>
                <EmptyState
                  title="Pilih sesi untuk melihat rinciannya"
                  description="Setiap sesi menampilkan rata-rata dan selisih skor antar penilai per indikator."
                />
              </CardBody>
            </Card>
          ) : (
            <>
              <Card>
                <CardHeader>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex items-center gap-2">
                      <Scale className="h-4 w-4 text-brand-600" />
                      <CardTitle>{detail.session.title}</CardTitle>
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                      <Badge tone="gray">ambang {detail.session.threshold}</Badge>
                      <Badge tone={detail.statistics.exceeding > 0 ? 'red' : 'green'}>
                        {detail.statistics.exceeding} indikator melewati ambang
                      </Badge>
                      <Badge tone="gray">rata-rata selisih {detail.statistics.average_spread ?? '—'}</Badge>
                    </div>
                  </div>
                </CardHeader>
                <CardBody className="space-y-3">
                  {detail.session.conclusion && (
                    <div className="rounded-lg bg-green-50 p-3 text-sm text-green-800">
                      <div className="font-medium">Kesepakatan penilaian</div>
                      {detail.session.conclusion}
                    </div>
                  )}

                  {detail.indicators.length === 0 ? (
                    <EmptyState title="Belum ada skor penilai" description="Masukkan skor dua supervisor atau lebih untuk indikator yang sama." />
                  ) : (
                    <div className="overflow-x-auto">
                      <table className="w-full text-sm">
                        <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                          <tr>
                            <th className="px-3 py-2 font-medium">Indikator / kasus</th>
                            <th className="px-3 py-2 font-medium">Penilai</th>
                            <th className="px-3 py-2 font-medium">Sebaran skor</th>
                            <th className="px-3 py-2 font-medium">Rata-rata</th>
                            <th className="px-3 py-2 font-medium">Selisih</th>
                          </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                          {detail.indicators.map((row) => (
                            <tr key={row.label} className={row.exceeds_threshold ? 'bg-red-50/50' : ''}>
                              <td className="px-3 py-2">
                                <div className="text-slate-800">{row.label}</div>
                                <div className="text-xs text-slate-500">{row.section ?? ''}</div>
                              </td>
                              <td className="px-3 py-2 text-xs text-slate-600">
                                {row.scores
                                  .map((score) => {
                                    const name = (employeesQuery.data ?? []).find((item) => item.id === score.supervisor_employee_id)?.full_name;
                                    return `${name ?? `#${score.supervisor_employee_id}`}: ${score.score ?? '—'}`;
                                  })
                                  .join(' · ')}
                              </td>
                              <td className="px-3 py-2">{row.raters}</td>
                              <td className="px-3 py-2">{row.average ?? '—'}</td>
                              <td className="px-3 py-2">
                                <Badge tone={row.exceeds_threshold ? 'red' : 'green'}>
                                  {row.spread ?? '—'}
                                  {row.exceeds_threshold ? ' · melewati' : ''}
                                </Badge>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}

                  {detail.abilities.finalize && (
                    <div className="space-y-2 border-t border-slate-100 pt-3">
                      <Label>Kesepakatan penilaian (wajib bila ada indikator melewati ambang)</Label>
                      <Textarea
                        rows={3}
                        value={conclusion}
                        onChange={(event) => setConclusion(event.target.value)}
                        placeholder="mis. Disepakati indikator 1 dinilai setelah bukti tambahan dikumpulkan."
                      />
                      <Button
                        onClick={() => finalizeMutation.mutate()}
                        disabled={finalizeMutation.isPending || conclusion.trim().length < 10}
                      >
                        {finalizeMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Finalkan sesi
                      </Button>
                    </div>
                  )}
                </CardBody>
              </Card>

              {detail.abilities.manage && detail.session.finalized_at === null && (
                <Card>
                  <CardHeader>
                    <div className="flex items-center gap-2">
                      <Users className="h-4 w-4 text-brand-600" />
                      <CardTitle>Tambah Skor Penilai</CardTitle>
                    </div>
                  </CardHeader>
                  <CardBody className="grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div>
                      <Label required>Penilai</Label>
                      <Select value={entry.supervisor} onChange={(event) => setEntry((previous) => ({ ...previous, supervisor: event.target.value }))}>
                        <option value="">Pilih penilai...</option>
                        {(employeesQuery.data ?? []).map((employee) => (
                          <option key={employee.id} value={employee.id}>{employee.full_name}</option>
                        ))}
                      </Select>
                    </div>
                    <div>
                      <Label required>Kasus / indikator</Label>
                      <Select value={entry.indicator} onChange={(event) => setEntry((previous) => ({ ...previous, indicator: event.target.value }))}>
                        <option value="">Kasus bersama (tanpa indikator)</option>
                        {(indicatorsQuery.data ?? []).map((indicator) => (
                          <option key={indicator.id} value={indicator.id}>{indicator.statement.slice(0, 60)}</option>
                        ))}
                      </Select>
                    </div>
                    <div>
                      <Label required>Skor (0–100)</Label>
                      <Input
                        type="number"
                        value={entry.score}
                        onChange={(event) => setEntry((previous) => ({ ...previous, score: event.target.value }))}
                      />
                    </div>
                    <div className="flex items-end">
                      <Button
                        className="w-full"
                        onClick={() => scoreMutation.mutate()}
                        disabled={scoreMutation.isPending || entry.supervisor === '' || entry.score === ''}
                      >
                        {scoreMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : <Plus className="mr-2 h-4 w-4" />} Simpan skor
                      </Button>
                    </div>
                    <div className="sm:col-span-4">
                      <Label>Catatan penilai</Label>
                      <Input value={entry.comment} onChange={(event) => setEntry((previous) => ({ ...previous, comment: event.target.value }))} />
                    </div>
                  </CardBody>
                </Card>
              )}
            </>
          )}

          <Card>
            <CardHeader>
              <div className="flex items-center gap-2">
                <Gauge className="h-4 w-4 text-brand-600" />
                <CardTitle>Kesenjangan pada Kegiatan Nyata</CardTitle>
              </div>
            </CardHeader>
            <CardBody>
              {(divergenceQuery.data ?? []).length === 0 ? (
                <EmptyState
                  title="Belum ada kesenjangan terdeteksi"
                  description="Bila dua supervisor menilai indikator yang sama pada kegiatan berbeda, selisihnya muncul di sini sebagai bahan moderasi."
                />
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                      <tr>
                        <th className="px-3 py-2 font-medium">Indikator</th>
                        <th className="px-3 py-2 font-medium">Rata-rata sekolah</th>
                        <th className="px-3 py-2 font-medium">Selisih</th>
                        <th className="px-3 py-2 font-medium">Rincian penilai</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {(divergenceQuery.data ?? []).map((row: DivergenceRow) => (
                        <tr key={row.indicator_id}>
                          <td className="px-3 py-2 text-slate-800">{row.statement}</td>
                          <td className="px-3 py-2">{row.school_average}</td>
                          <td className="px-3 py-2"><Badge tone={row.spread >= 2 ? 'red' : 'amber'}>{row.spread}</Badge></td>
                          <td className="px-3 py-2 text-xs text-slate-600">
                            {row.supervisors.map((item) => `${item.full_name ?? `#${item.supervisor_employee_id}`}: ${item.average} (${item.answers})`).join(' · ')}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardBody>
          </Card>
        </div>
      </div>

      <Modal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Sesi Kalibrasi Baru"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>Batal</Button>
            <Button onClick={() => createMutation.mutate()} disabled={createMutation.isPending || form.title.trim() === ''}>
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <div>
            <Label required>Judul sesi</Label>
            <Input
              value={form.title}
              onChange={(event) => setForm((previous) => ({ ...previous, title: event.target.value }))}
              placeholder="mis. Moderasi nilai observasi kelas semester ganjil"
            />
          </div>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
              <Label required>Ambang selisih yang disepakati</Label>
              <Input type="number" step="0.1" value={form.threshold} onChange={(event) => setForm((previous) => ({ ...previous, threshold: event.target.value }))} />
            </div>
            <div>
              <Label>Tanggal pelaksanaan</Label>
              <Input type="date" value={form.held_at} onChange={(event) => setForm((previous) => ({ ...previous, held_at: event.target.value }))} />
            </div>
          </div>
          <div>
            <Label>Instrumen acuan (opsional)</Label>
            <Select value={versionId} onChange={(event) => setVersionId(event.target.value)}>
              <option value="">Tanpa instrumen (kasus bebas)</option>
              {(instrumentsQuery.data ?? []).map((version) => (
                <option key={version.id} value={version.id}>
                  {version.instrument?.name ?? `Versi #${version.id}`} — v{version.version}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Catatan</Label>
            <Textarea rows={2} value={form.note} onChange={(event) => setForm((previous) => ({ ...previous, note: event.target.value }))} />
          </div>
        </div>
      </Modal>
    </div>
  );
}
