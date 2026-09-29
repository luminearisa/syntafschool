import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ClipboardCheck, Plus, RefreshCw, Signature, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiApi } from '../api/supervisiApi';
import { supervisiVisitApi } from '../api/supervisiVisitApi';
import type { Instrument, Supervisor, VisitListRow } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { api, getErrorMessage } from '@/lib/axios';
import type { ApiResponse } from '@/types';

interface EmployeeOption {
  id: number;
  full_name: string;
  position?: string | null;
  employee_type?: string | null;
}

const ACTIVITY_KINDS = [
  { value: 'CLASS_OBSERVATION', label: 'Observasi Kelas Penuh' },
  { value: 'WALKTHROUGH', label: 'Kunjungan Kelas Singkat (Walkthrough)' },
  { value: 'CLINICAL', label: 'Supervisi Klinis' },
  { value: 'PEER', label: 'Supervisi Sejawat' },
  { value: 'DOCUMENT_REVIEW', label: 'Telaah Dokumen/Perangkat' },
  { value: 'STAFF_REVIEW', label: 'Supervisi Tenaga Kependidikan' },
];

const STATUS_TONES: Record<string, 'gray' | 'blue' | 'amber' | 'green' | 'red' | 'brand'> = {
  DRAFT: 'gray',
  SCHEDULED: 'blue',
  PRE_CONFERENCE: 'blue',
  OBSERVED: 'brand',
  ANALYZED: 'brand',
  POST_CONFERENCE: 'amber',
  ACTION_PLANNED: 'amber',
  ACTION_IN_PROGRESS: 'amber',
  COMPLETED: 'green',
  EVALUATED: 'green',
  DISPUTED: 'red',
  CANCELLED: 'gray',
};

/**
 * Daftar kegiatan supervisi.
 *
 * Menyaring tugas yang perlu dikerjakan sekarang ("perlu tindakan",
 * "menunggu tanda tangan") agar supervisor dan guru tahu langkah berikutnya.
 */
export default function SupervisiVisitsPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'action' | 'signature' | 'all'>('action');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState({
    supervisor_employee_id: '',
    supervisee_employee_id: '',
    instrument_version_id: '',
    activity_kind: 'CLASS_OBSERVATION',
    lesson_topic: '',
    observed_at: '',
  });

  const filters = {
    page,
    search: search || undefined,
    ...(tab === 'action' ? { open: true } : {}),
    ...(tab === 'signature' ? { status: ['COMPLETED', 'EVALUATED'] } : {}),
  };

  const visitsQuery = useQuery({
    queryKey: ['supervisi', 'visits', tab, search, page],
    queryFn: () => supervisiVisitApi.list(filters),
  });

  const supervisorsQuery = useQuery({
    queryKey: ['supervisi', 'supervisors'],
    queryFn: () => supervisiApi.supervisors({ active: true }),
    enabled: modalOpen,
  });

  const instrumentsQuery = useQuery({
    queryKey: ['supervisi', 'instruments'],
    queryFn: () => supervisiApi.instruments(),
    enabled: modalOpen,
  });

  const employeesQuery = useQuery({
    queryKey: ['employees', 'options'],
    queryFn: async () => {
      const response = await api.get<ApiResponse<EmployeeOption[]>>('/employees', { params: { per_page: 200, is_active: 1 } });
      const payload = response.data.data;
      return Array.isArray(payload) ? payload : [];
    },
    enabled: modalOpen,
    staleTime: 5 * 60 * 1000,
  });

  const createMutation = useMutation({
    mutationFn: () =>
      supervisiVisitApi.create({
        supervisee_employee_id: Number(form.supervisee_employee_id),
        supervisor_employee_id: form.supervisor_employee_id ? Number(form.supervisor_employee_id) : undefined,
        instrument_version_id: form.instrument_version_id ? Number(form.instrument_version_id) : undefined,
        activity_kind: form.activity_kind,
        lesson_topic: form.lesson_topic || null,
        observed_at: form.observed_at || null,
      }),
    onSuccess: (detail) => {
      toast.success(`Kegiatan ${detail.visit.code} dibuat`);
      setModalOpen(false);
      setForm({
        supervisor_employee_id: '',
        supervisee_employee_id: '',
        instrument_version_id: '',
        activity_kind: 'CLASS_OBSERVATION',
        lesson_topic: '',
        observed_at: '',
      });
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'visits'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = visitsQuery.data?.rows ?? [];
  const meta = visitsQuery.data?.meta;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Kegiatan Supervisi</h1>
          <p className="text-sm text-slate-500">
            Kunjungan kelas, observasi penuh, telaah dokumen, dan supervisi tenaga kependidikan.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => visitsQuery.refetch()}>
            <RefreshCw className={visitsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="supervisi.visit.create">
            <Button onClick={() => setModalOpen(true)}>
              <Plus className="mr-2 h-4 w-4" /> Kegiatan Baru
            </Button>
          </Can>
        </div>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex items-center gap-2">
              <ClipboardCheck className="h-4 w-4 text-brand-600" />
              <CardTitle>Daftar Kegiatan</CardTitle>
            </div>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
              <div className="inline-flex rounded-lg border border-slate-200 p-0.5">
                {[
                  { key: 'action' as const, label: 'Perlu tindakan' },
                  { key: 'signature' as const, label: 'Hasil final' },
                  { key: 'all' as const, label: 'Semua' },
                ].map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    onClick={() => {
                      setTab(item.key);
                      setPage(1);
                    }}
                    className={`rounded-md px-3 py-1.5 text-sm ${
                      tab === item.key ? 'bg-brand-50 font-medium text-brand-700' : 'text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
              <Input
                placeholder="Cari kode / topik..."
                value={search}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setPage(1);
                }}
                className="sm:w-56"
              />
            </div>
          </div>
        </CardHeader>
        <CardBody>
          {visitsQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
              <Spinner /> Memuat kegiatan...
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              title={tab === 'signature' ? 'Belum ada hasil final' : 'Tidak ada kegiatan pada filter ini'}
              description="Kegiatan muncul di sini setelah dijadwalkan atau dibuat oleh supervisor."
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Kegiatan</th>
                    <th className="px-4 py-3 font-medium">Yang disupervisi</th>
                    <th className="px-4 py-3 font-medium">Pelaksana</th>
                    <th className="px-4 py-3 font-medium">Instrumen</th>
                    <th className="px-4 py-3 font-medium">Nilai</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((row: VisitListRow) => (
                    <tr key={row.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-900">{row.activity_kind_label}</div>
                        <div className="text-xs text-slate-500">
                          {row.code}
                          {row.observed_at ? ` • ${new Date(row.observed_at).toLocaleDateString('id-ID')}` : ''}
                        </div>
                        {row.lesson_topic && <div className="text-xs text-slate-500">{row.lesson_topic}</div>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="text-slate-800">{row.supervisee?.full_name ?? '—'}</div>
                        <div className="text-xs text-slate-500">{row.supervisee?.position ?? ''}</div>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{row.supervisor?.full_name ?? '—'}</td>
                      <td className="px-4 py-3">
                        <div className="text-slate-700">{row.instrument?.name ?? '—'}</div>
                        <div className="text-xs text-slate-500">versi {row.instrument?.version ?? '-'}</div>
                      </td>
                      <td className="px-4 py-3">
                        {row.final_score === null || row.final_score === undefined ? (
                          <span className="text-slate-400">—</span>
                        ) : (
                          <div className="flex items-center gap-2">
                            <span className="font-medium text-slate-900">{row.final_score}</span>
                            {row.grade_label && <Badge tone="brand">{row.grade_label}</Badge>}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap items-center gap-1">
                          <Badge tone={STATUS_TONES[row.status] ?? 'gray'}>{row.status_label}</Badge>
                          {row.is_disputed && <Badge tone="red">Keberatan</Badge>}
                          {row.needs_acknowledgment && (
                            <Badge tone="amber">
                              <Signature className="mr-1 h-3 w-3" /> perlu tanda tangan
                            </Badge>
                          )}
                        </div>
                      </td>
                      <td className="px-4 py-3 text-right">
                        <Link to={`/supervisi/visits/${row.id}`}>
                          <Button size="sm" variant={row.needs_acknowledgment ? 'primary' : 'outline'}>
                            {row.needs_acknowledgment ? 'Tandai hasil' : row.is_finalized ? 'Lihat hasil' : 'Lanjutkan'}
                          </Button>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </CardBody>
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Kegiatan Supervisi Baru"
        size="lg"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => {
                if (!form.supervisee_employee_id) {
                  toast.error('Pilih pegawai yang akan disupervisi.');
                  return;
                }
                createMutation.mutate();
              }}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Buat Kegiatan
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label required>Yang disupervisi</Label>
              <Select
                value={form.supervisee_employee_id}
                onChange={(event) => setForm((previous) => ({ ...previous, supervisee_employee_id: event.target.value }))}
              >
                <option value="">Pilih pegawai...</option>
                {(employeesQuery.data ?? []).map((employee) => (
                  <option key={employee.id} value={employee.id}>
                    {employee.full_name} — {employee.position ?? employee.employee_type ?? ''}
                  </option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-slate-500">Daftar diambil dari pegawai aktif. Ketik untuk mencari di daftar.</p>
            </div>
            <div>
              <Label>Pelaksana (supervisor)</Label>
              <Select
                value={form.supervisor_employee_id}
                onChange={(event) => setForm((previous) => ({ ...previous, supervisor_employee_id: event.target.value }))}
              >
                <option value="">Saya sendiri</option>
                {(supervisorsQuery.data ?? []).map((supervisor: Supervisor) => (
                  <option key={supervisor.id} value={supervisor.employee_id}>
                    {supervisor.full_name} — {supervisor.kind_label}
                  </option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-slate-500">
                Hanya pegawai pada Pengaturan Supervisor yang tersedia; aturan kewenangan diperiksa server.
              </p>
            </div>
            <div className="sm:col-span-2">
              <Label required>Instrumen</Label>
              <Select
                value={form.instrument_version_id}
                onChange={(event) => setForm((previous) => ({ ...previous, instrument_version_id: event.target.value }))}
              >
                <option value="">Pilih instrumen...</option>
                {(instrumentsQuery.data ?? [])
                  .filter((instrument: Instrument) => instrument.current_version)
                  .map((instrument: Instrument) => (
                    <option key={instrument.id} value={instrument.current_version?.id ?? ''}>
                      {instrument.name} (versi {instrument.current_version?.version ?? '-'})
                    </option>
                  ))}
              </Select>
            </div>
            <div>
              <Label required>Jenis kegiatan</Label>
              <Select
                value={form.activity_kind}
                onChange={(event) => setForm((previous) => ({ ...previous, activity_kind: event.target.value }))}
              >
                {ACTIVITY_KINDS.map((kind) => (
                  <option key={kind.value} value={kind.value}>{kind.label}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Waktu pelaksanaan</Label>
              <Input
                type="datetime-local"
                value={form.observed_at}
                onChange={(event) => setForm((previous) => ({ ...previous, observed_at: event.target.value }))}
              />
            </div>
            <div className="sm:col-span-2">
              <Label>Topik / fokus</Label>
              <Input
                value={form.lesson_topic}
                onChange={(event) => setForm((previous) => ({ ...previous, lesson_topic: event.target.value }))}
                placeholder="mis. Sistem Ekskresi — diskusi kelompok"
              />
            </div>
          </div>
          <p className="flex items-start gap-2 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
            <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0 text-amber-500" />
            Instrumen dikunci pada versi terbit saat kegiatan dibuat. Revisi instrumen di kemudian hari tidak mengubah
            kegiatan yang sudah berjalan.
          </p>
        </div>
      </Modal>
    </div>
  );
}
