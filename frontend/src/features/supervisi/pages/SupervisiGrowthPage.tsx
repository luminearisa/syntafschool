import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Award, GraduationCap, Plus, RefreshCw, ShieldAlert, Users2 } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiGrowthApi } from '../api/supervisiGrowthApi';
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

const DEV_KINDS = [
  { value: 'DIKLAT', label: 'Diklat' },
  { value: 'WORKSHOP', label: 'Workshop' },
  { value: 'COACHING', label: 'Coaching' },
  { value: 'MENTORING', label: 'Mentoring' },
  { value: 'LESSON_STUDY', label: 'Lesson study' },
  { value: 'MANDIRI', label: 'Belajar mandiri' },
  { value: 'PEER_VISIT', label: 'Kunjungan sejawat' },
];

const PIP_OUTCOMES = [
  { value: 'IMPROVED', label: 'Membaik' },
  { value: 'PARTIAL', label: 'Sebagian membaik' },
  { value: 'NOT_IMPROVED', label: 'Tidak membaik' },
  { value: 'EXTENDED', label: 'Diperpanjang' },
];

/** Pengembangan kompetensi, coaching, dan rencana perbaikan kinerja (PIP). */
export default function SupervisiGrowthPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'development' | 'coaching' | 'pip'>('development');
  const [modal, setModal] = useState<null | 'development' | 'coaching' | 'pip'>(null);
  const [pipAction, setPipAction] = useState<{ id: number; kind: 'review' | 'conclude' } | null>(null);
  const [pipForm, setPipForm] = useState({ progress_note: '', next_step: '', outcome: 'IMPROVED', note: '' });
  const [form, setForm] = useState({
    employee: '',
    kind: 'DIKLAT',
    title: '',
    provider: '',
    hours: '',
    certificate_no: '',
    focus: '',
    commitments: '',
  });

  const employeesQuery = useQuery({
    queryKey: ['employees', 'options'],
    queryFn: async () => {
      const response = await api.get<ApiResponse<EmployeeOption[]>>('/employees', { params: { per_page: 200, is_active: 1 } });
      const payload = response.data.data;

      return Array.isArray(payload) ? payload : [];
    },
    enabled: modal !== null,
    staleTime: 5 * 60 * 1000,
  });

  const developmentsQuery = useQuery({
    queryKey: ['supervisi', 'developments'],
    queryFn: () => supervisiGrowthApi.developments(),
    enabled: tab === 'development',
  });

  const coachingsQuery = useQuery({
    queryKey: ['supervisi', 'coachings'],
    queryFn: () => supervisiGrowthApi.coachings(),
    enabled: tab === 'coaching',
  });

  const pipsQuery = useQuery({
    queryKey: ['supervisi', 'pips'],
    queryFn: () => supervisiGrowthApi.pips(),
    enabled: tab === 'pip',
  });

  const invalidateAll = () => {
    queryClient.invalidateQueries({ queryKey: ['supervisi', 'developments'] });
    queryClient.invalidateQueries({ queryKey: ['supervisi', 'coachings'] });
    queryClient.invalidateQueries({ queryKey: ['supervisi', 'pips'] });
  };

  const createMutation = useMutation({
    mutationFn: async () => {
      if (modal === 'development') {
        return supervisiGrowthApi.createDevelopment({
          supervisee_employee_id: Number(form.employee),
          kind: form.kind,
          title: form.title,
          provider: form.provider || null,
          hours: form.hours === '' ? null : Number(form.hours),
          certificate_no: form.certificate_no || null,
          status: 'PLANNED',
        });
      }

      if (modal === 'coaching') {
        return supervisiGrowthApi.createCoaching({
          supervisee_employee_id: Number(form.employee),
          focus: form.focus || null,
          commitments: form.commitments.split('\n').map((line) => line.trim()).filter(Boolean),
        });
      }

      return supervisiGrowthApi.createPip({
        supervisee_employee_id: Number(form.employee),
        reason: form.title || null,
        expected_standard: form.focus || null,
      });
    },
    onSuccess: () => {
      toast.success('Data tersimpan');
      setModal(null);
      setForm({ employee: '', kind: 'DIKLAT', title: '', provider: '', hours: '', certificate_no: '', focus: '', commitments: '' });
      invalidateAll();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const reviewMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.reviewPip(pipAction?.id ?? 0, {
      progress_note: pipForm.progress_note || null,
      next_step: pipForm.next_step || null,
    }),
    onSuccess: () => {
      toast.success('Tinjauan PIP tersimpan');
      setPipAction(null);
      setPipForm({ progress_note: '', next_step: '', outcome: 'IMPROVED', note: '' });
      invalidateAll();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const concludeMutation = useMutation({
    mutationFn: () => supervisiGrowthApi.concludePip(pipAction?.id ?? 0, {
      outcome: pipForm.outcome,
      note: pipForm.note || null,
    }),
    onSuccess: () => {
      toast.success('Hasil PIP diputuskan');
      setPipAction(null);
      setPipForm({ progress_note: '', next_step: '', outcome: 'IMPROVED', note: '' });
      invalidateAll();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const acknowledgeMutation = useMutation({
    mutationFn: (pipId: number) => supervisiGrowthApi.acknowledgePip(pipId),
    onSuccess: () => {
      toast.success('PIP ditandai sudah dipahami');
      invalidateAll();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const developmentRows = developmentsQuery.data?.rows ?? [];
  const developmentSummary = developmentsQuery.data?.summary ?? {};
  const coachingRows = coachingsQuery.data?.rows ?? [];
  const pipRows = pipsQuery.data?.rows ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pengembangan &amp; Pembinaan</h1>
          <p className="text-sm text-slate-500">
            Diklat dan jam pengembangan (bahan angka kredit PKB), sesi coaching beserta komitmennya, serta rencana perbaikan kinerja.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => invalidateAll()}>
            <RefreshCw className="h-4 w-4" />
          </Button>
          <Button onClick={() => setModal(tab)}>
            <Plus className="mr-2 h-4 w-4" />
            {tab === 'development' ? 'Catat Pengembangan' : tab === 'coaching' ? 'Sesi Coaching' : 'Buat PIP'}
          </Button>
        </div>
      </div>

      <div className="inline-flex rounded-lg border border-slate-200 p-0.5">
        {[
          { key: 'development' as const, label: 'Pengembangan', icon: GraduationCap },
          { key: 'coaching' as const, label: 'Coaching', icon: Users2 },
          { key: 'pip' as const, label: 'PIP', icon: ShieldAlert },
        ].map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            className={`flex items-center gap-2 rounded-md px-3 py-1.5 text-sm ${
              tab === item.key ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50'
            }`}
          >
            <item.icon className="h-4 w-4" /> {item.label}
          </button>
        ))}
      </div>

      {tab === 'development' && (
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <CardTitle>Pengembangan Kompetensi</CardTitle>
              <div className="flex flex-wrap items-center gap-2 text-sm text-slate-500">
                <Badge tone="brand">total {String(developmentSummary.total_hours ?? 0)} jam</Badge>
                <Badge tone="gray">bersertifikat {String(developmentSummary.certified ?? 0)}</Badge>
                <Badge tone="gray">selesai {String(developmentSummary.completed ?? 0)}</Badge>
              </div>
            </div>
          </CardHeader>
          <CardBody>
            {developmentsQuery.isLoading ? (
              <div className="flex items-center justify-center gap-2 py-10 text-slate-400"><Spinner /> Memuat...</div>
            ) : developmentRows.length === 0 ? (
              <EmptyState title="Belum ada catatan pengembangan" description="Catat diklat, workshop, atau belajar mandiri beserta jam dan sertifikatnya." />
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                      <th className="px-3 py-2 font-medium">Pegawai</th>
                      <th className="px-3 py-2 font-medium">Kegiatan</th>
                      <th className="px-3 py-2 font-medium">Jenis</th>
                      <th className="px-3 py-2 font-medium">Jam</th>
                      <th className="px-3 py-2 font-medium">Sertifikat</th>
                      <th className="px-3 py-2 font-medium">Status</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {developmentRows.map((row) => (
                      <tr key={row.id}>
                        <td className="px-3 py-2">
                          <div className="text-slate-800">{row.supervisee?.full_name ?? '-'}</div>
                          <div className="text-xs text-slate-500">{row.supervisee?.position ?? ''}</div>
                        </td>
                        <td className="px-3 py-2">
                          <div className="text-slate-800">{row.title}</div>
                          <div className="text-xs text-slate-500">{row.provider ?? ''}</div>
                        </td>
                        <td className="px-3 py-2"><Badge tone="gray">{row.kind}</Badge></td>
                        <td className="px-3 py-2">{row.hours ?? '—'}</td>
                        <td className="px-3 py-2 text-xs text-slate-500">{row.certificate_no ?? '—'}</td>
                        <td className="px-3 py-2"><Badge tone={row.status === 'DONE' ? 'green' : 'blue'}>{row.status}</Badge></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardBody>
        </Card>
      )}

      {tab === 'coaching' && (
        <Card>
          <CardHeader>
            <div className="flex flex-wrap items-center justify-between gap-2">
              <CardTitle>Sesi Coaching &amp; Mentoring</CardTitle>
              <div className="flex items-center gap-2">
                <Badge tone="blue">{String(coachingsQuery.data?.open ?? 0)} berjalan</Badge>
                <Badge tone="amber">{String(coachingsQuery.data?.due_follow_ups ?? 0)} tindak lanjut jatuh tempo</Badge>
              </div>
            </div>
          </CardHeader>
          <CardBody className="space-y-3">
            {coachingRows.length === 0 && <EmptyState title="Belum ada sesi coaching" />}
            {coachingRows.map((row) => (
              <div key={row.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <div className="font-medium text-slate-800">{row.supervisee?.full_name ?? '-'}</div>
                    <div className="text-xs text-slate-500">
                      Pembina {row.supervisor?.full_name ?? '-'} •{' '}
                      {row.session_at ? new Date(row.session_at).toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' }) : '-'}
                      {row.follow_up_at ? ` • tindak lanjut ${new Date(row.follow_up_at).toLocaleDateString('id-ID')}` : ''}
                    </div>
                  </div>
                  <Badge tone={row.status === 'OPEN' ? 'blue' : 'green'}>{row.status}</Badge>
                </div>
                {row.focus && <p className="mt-1 text-slate-600">Fokus: {row.focus}</p>}
                {row.commitments.length > 0 && (
                  <ul className="mt-1 list-disc pl-5 text-xs text-slate-600">
                    {row.commitments.map((commitment) => <li key={commitment}>{commitment}</li>)}
                  </ul>
                )}
                {row.note && <p className="mt-1 text-xs text-slate-500">Catatan: {row.note}</p>}
              </div>
            ))}
          </CardBody>
        </Card>
      )}

      {tab === 'pip' && (
        <Card>
          <CardHeader><CardTitle>Rencana Perbaikan Kinerja</CardTitle></CardHeader>
          <CardBody className="space-y-3">
            {pipRows.length === 0 && (
              <EmptyState
                title="Belum ada PIP"
                description="PIP dibuat ketika hasil penilaian kinerja berada pada predikat butuh perbaikan atau lebih rendah."
              />
            )}
            {pipRows.map((row) => (
              <div key={row.id} className="rounded-xl border border-slate-200 p-4 text-sm">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <div className="font-medium text-slate-900">{row.supervisee?.full_name ?? '-'}</div>
                    <div className="text-xs text-slate-500">
                      {row.appraisal ? `dari ${row.appraisal.appraisal_type} (${row.appraisal.final_score ?? '—'}) • ` : ''}
                      mulai {row.start_date ? new Date(row.start_date).toLocaleDateString('id-ID') : '-'}
                      {row.final_review_date ? ` • tinjauan akhir ${new Date(row.final_review_date).toLocaleDateString('id-ID')}` : ''}
                      {row.review_cadence ? ` • tinjauan ${row.review_cadence}` : ''}
                    </div>
                  </div>
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone={row.status === 'CLOSED' ? 'green' : row.status === 'ACTIVE' ? 'blue' : 'gray'}>{row.status}</Badge>
                    {row.outcome && <Badge tone="brand">{row.outcome}</Badge>}
                    {row.acknowledged_at && <Badge tone="gray">ditandatangani</Badge>}
                  </div>
                </div>

                {row.reason && <p className="mt-2 text-slate-600">{row.reason}</p>}
                {row.expected_standard && <p className="text-slate-600">Standar yang diharapkan: {row.expected_standard}</p>}
                {row.support_provided && <p className="text-slate-600">Dukungan: {row.support_provided}</p>}

                {row.items.length > 0 && (
                  <ul className="mt-2 space-y-1">
                    {row.items.map((item) => (
                      <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2">
                        <span className="text-slate-700">
                          {item.statement}
                          {item.target_value !== null && item.target_value !== undefined && (
                            <span className="ml-1 text-xs text-slate-500">
                              (target {item.target_value} {item.target_unit ?? ''})
                            </span>
                          )}
                        </span>
                        <span className="flex items-center gap-2">
                          {item.due_date && <span className="text-xs text-slate-500">{new Date(item.due_date).toLocaleDateString('id-ID')}</span>}
                          <Badge tone={item.status === 'DONE' ? 'green' : item.status === 'IN_PROGRESS' ? 'blue' : 'gray'}>{item.status}</Badge>
                          {row.status !== 'CLOSED' && (
                            <Button
                              size="sm"
                              variant="ghost"
                              onClick={() =>
                                mutationToggleItem(item.id, item.status === 'DONE' ? 'IN_PROGRESS' : 'DONE')
                              }
                            >
                              {item.status === 'DONE' ? 'Buka lagi' : 'Tandai selesai'}
                            </Button>
                          )}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}

                {row.reviews.length > 0 && (
                  <ul className="mt-2 space-y-1 border-t border-slate-100 pt-2 text-xs text-slate-500">
                    {row.reviews.map((review) => (
                      <li key={review.id}>
                        {review.reviewed_at ? new Date(review.reviewed_at).toLocaleDateString('id-ID') : ''} — {review.progress_note ?? ''}
                        {review.next_step ? ` · langkah berikutnya: ${review.next_step}` : ''}
                      </li>
                    ))}
                  </ul>
                )}

                {row.abilities.manage && row.status !== 'CLOSED' && (
                  <div className="mt-3 flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => setPipAction({ id: row.id, kind: 'review' })}>
                      Catat tinjauan
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => {
                        setPipForm((previous) => ({ ...previous, outcome: 'IMPROVED', note: '' }));
                        setPipAction({ id: row.id, kind: 'conclude' });
                      }}
                    >
                      <Award className="mr-2 h-4 w-4" /> Putuskan hasil
                    </Button>
                  </div>
                )}

                {row.abilities.acknowledge && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="mt-3"
                    onClick={() => acknowledgeMutation.mutate(row.id)}
                    disabled={acknowledgeMutation.isPending}
                  >
                    Tandai saya memahami PIP ini
                  </Button>
                )}
              </div>
            ))}
          </CardBody>
        </Card>
      )}

      <Modal
        open={modal !== null}
        onClose={() => setModal(null)}
        title={modal === 'development' ? 'Catat Pengembangan' : modal === 'coaching' ? 'Sesi Coaching' : 'Buat PIP'}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModal(null)}>Batal</Button>
            <Button
              onClick={() => {
                if (!form.employee) {
                  toast.error('Pilih pegawai terlebih dahulu.');
                  return;
                }
                createMutation.mutate();
              }}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-3">
          <div>
            <Label required>Pegawai</Label>
            <Select value={form.employee} onChange={(event) => setForm((previous) => ({ ...previous, employee: event.target.value }))}>
              <option value="">Pilih pegawai...</option>
              {(employeesQuery.data ?? []).map((employee) => (
                <option key={employee.id} value={employee.id}>{employee.full_name} — {employee.position ?? ''}</option>
              ))}
            </Select>
          </div>

          {modal === 'development' && (
            <>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <Label required>Jenis</Label>
                  <Select value={form.kind} onChange={(event) => setForm((previous) => ({ ...previous, kind: event.target.value }))}>
                    {DEV_KINDS.map((kind) => <option key={kind.value} value={kind.value}>{kind.label}</option>)}
                  </Select>
                </div>
                <div>
                  <Label>Jam pelatihan</Label>
                  <Input type="number" value={form.hours} onChange={(event) => setForm((previous) => ({ ...previous, hours: event.target.value }))} />
                </div>
              </div>
              <div>
                <Label required>Nama kegiatan</Label>
                <Input value={form.title} onChange={(event) => setForm((previous) => ({ ...previous, title: event.target.value }))} />
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                  <Label>Penyelenggara</Label>
                  <Input value={form.provider} onChange={(event) => setForm((previous) => ({ ...previous, provider: event.target.value }))} />
                </div>
                <div>
                  <Label>Nomor sertifikat</Label>
                  <Input value={form.certificate_no} onChange={(event) => setForm((previous) => ({ ...previous, certificate_no: event.target.value }))} />
                </div>
              </div>
            </>
          )}

          {modal === 'coaching' && (
            <>
              <div>
                <Label>Fokus pendampingan</Label>
                <Input value={form.focus} onChange={(event) => setForm((previous) => ({ ...previous, focus: event.target.value }))} placeholder="mis. pengelolaan kelas jam terakhir" />
              </div>
              <div>
                <Label>Komitmen (satu per baris)</Label>
                <Textarea rows={4} value={form.commitments} onChange={(event) => setForm((previous) => ({ ...previous, commitments: event.target.value }))} />
              </div>
            </>
          )}

          {modal === 'pip' && (
            <>
              <div>
                <Label>Alasan / dasar PIP</Label>
                <Textarea rows={3} value={form.title} onChange={(event) => setForm((previous) => ({ ...previous, title: event.target.value }))} />
              </div>
              <div>
                <Label>Standar yang diharapkan</Label>
                <Textarea rows={3} value={form.focus} onChange={(event) => setForm((previous) => ({ ...previous, focus: event.target.value }))} />
              </div>
            </>
          )}
        </div>
      </Modal>

      <Modal
        open={pipAction !== null}
        onClose={() => setPipAction(null)}
        title={pipAction?.kind === 'review' ? 'Catat Tinjauan PIP' : 'Putuskan Hasil PIP'}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setPipAction(null)}>Batal</Button>
            <Button
              onClick={() => (pipAction?.kind === 'review' ? reviewMutation.mutate() : concludeMutation.mutate())}
              disabled={reviewMutation.isPending || concludeMutation.isPending}
            >
              Simpan
            </Button>
          </div>
        }
      >
        {pipAction?.kind === 'review' ? (
          <div className="space-y-3">
            <div>
              <Label>Catatan kemajuan</Label>
              <Textarea rows={3} value={pipForm.progress_note} onChange={(event) => setPipForm((previous) => ({ ...previous, progress_note: event.target.value }))} />
            </div>
            <div>
              <Label>Langkah berikutnya</Label>
              <Input value={pipForm.next_step} onChange={(event) => setPipForm((previous) => ({ ...previous, next_step: event.target.value }))} />
            </div>
          </div>
        ) : (
          <div className="space-y-3">
            <div>
              <Label required>Hasil</Label>
              <Select value={pipForm.outcome} onChange={(event) => setPipForm((previous) => ({ ...previous, outcome: event.target.value }))}>
                {PIP_OUTCOMES.map((outcome) => <option key={outcome.value} value={outcome.value}>{outcome.label}</option>)}
              </Select>
              <p className="mt-1 text-xs text-slate-500">
                “Membaik” hanya dapat diputuskan bila seluruh sasaran sudah selesai; “Diperpanjang” wajib menyebut tanggal tinjauan akhir baru.
              </p>
            </div>
            <div>
              <Label>Catatan keputusan</Label>
              <Textarea rows={3} value={pipForm.note} onChange={(event) => setPipForm((previous) => ({ ...previous, note: event.target.value }))} />
            </div>
          </div>
        )}
      </Modal>
    </div>
  );

  function mutationToggleItem(itemId: number, status: string) {
    supervisiGrowthApi
      .updatePipItem(itemId, { status })
      .then(() => {
        toast.success('Sasaran PIP diperbarui');
        invalidateAll();
      })
      .catch((error) => toast.error(getErrorMessage(error)));
  }
}
