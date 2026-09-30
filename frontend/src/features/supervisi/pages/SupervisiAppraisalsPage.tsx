import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Calculator, Plus, RefreshCw, TrendingUp } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiGrowthApi, type AppraisalRow } from '../api/supervisiGrowthApi';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { getErrorMessage } from '@/lib/axios';
import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

interface EmployeeOption {
  id: number;
  full_name: string;
  position?: string | null;
}

const TYPES = [
  { value: 'PKG', label: 'PKG Guru (14 kompetensi, maks 56)' },
  { value: 'PKG_BK', label: 'PKG Guru BK (17 kompetensi, maks 68)' },
  { value: 'NKKS', label: 'NKKS Kepala Sekolah (6 aspek, maks 24)' },
  { value: 'STAFF_REVIEW', label: 'Tinjauan Pegawai (berbobot 1–5 + multi-sumber)' },
  { value: 'SKP_PERIODIK', label: 'SKP Periodik (kuadran hasil × perilaku kerja)' },
  { value: 'PIP', label: 'Evaluasi PIP' },
];

const STATUS_TONES: Record<string, 'gray' | 'blue' | 'amber' | 'green' | 'red'> = {
  DRAFT: 'gray',
  SUBMITTED: 'blue',
  REVIEWED: 'blue',
  PUBLISHED: 'green',
  DISPUTED: 'red',
  ARCHIVED: 'gray',
};

/** Daftar dokumen penilaian kinerja (PKG, NKKS, tinjauan pegawai, SKP). */
export default function SupervisiAppraisalsPage() {
  const queryClient = useQueryClient();
  const [type, setType] = useState('');
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [modalOpen, setModalOpen] = useState(false);
  const [form, setForm] = useState({
    supervisee_employee_id: '',
    appraisal_type: 'PKG',
    period_type: 'SEMESTER',
    period_start: '',
    period_end: '',
    assessor_employee_id: '',
  });

  const listQuery = useQuery({
    queryKey: ['supervisi', 'appraisals', type, year],
    queryFn: () => supervisiGrowthApi.appraisals({ ...(type ? { type } : {}), year }),
  });

  const referenceQuery = useQuery({
    queryKey: ['supervisi', 'appraisal-reference'],
    queryFn: () => supervisiGrowthApi.appraisalReference(),
    staleTime: 30 * 60 * 1000,
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
      supervisiGrowthApi.createAppraisal({
        supervisee_employee_id: Number(form.supervisee_employee_id),
        appraisal_type: form.appraisal_type,
        period_type: form.period_type,
        period_start: form.period_start || null,
        period_end: form.period_end || null,
        assessor_employee_id: form.assessor_employee_id ? Number(form.assessor_employee_id) : undefined,
      }),
    onSuccess: (detail) => {
      toast.success('Dokumen penilaian dibuat');
      setModalOpen(false);
      queryClient.invalidateQueries({ queryKey: ['supervisi', 'appraisals'] });
      window.location.href = `/supervisi/appraisals/${detail.appraisal.id}`;
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows: AppraisalRow[] = listQuery.data?.rows ?? [];
  const summary = listQuery.data?.summary ?? {};
  const reference = referenceQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Penilaian Kinerja</h1>
          <p className="text-sm text-slate-500">
            PKG (56/68), NKKS (24), tinjauan pegawai berbobot 1–5 dengan penilaian multi-sumber, dan kuadran SKP.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Button onClick={() => setModalOpen(true)}>
            <Plus className="mr-2 h-4 w-4" /> Dokumen Baru
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card><CardBody>
          <div className="text-xs text-slate-500">Total dokumen</div>
          <div className="text-xl font-semibold text-slate-900">{String(summary.total ?? 0)}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Draf</div>
          <div className="text-xl font-semibold text-slate-900">{String(summary.draft ?? 0)}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Terbit</div>
          <div className="text-xl font-semibold text-slate-900">{String(summary.published ?? 0)}</div>
        </CardBody></Card>
        <Card><CardBody>
          <div className="text-xs text-slate-500">Perlu PIP</div>
          <div className="text-xl font-semibold text-slate-900">{String(summary.needs_pip ?? 0)}</div>
        </CardBody></Card>
      </div>

      <Card>
        <CardHeader>
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex items-center gap-2">
              <TrendingUp className="h-4 w-4 text-brand-600" />
              <CardTitle>Dokumen Penilaian</CardTitle>
            </div>
            <div className="flex items-center gap-2">
              <Select value={type} onChange={(event) => setType(event.target.value)} className="sm:w-60">
                <option value="">Semua jenis</option>
                {TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </Select>
              <Input value={year} onChange={(event) => setYear(event.target.value)} className="w-24" placeholder="Tahun" />
            </div>
          </div>
        </CardHeader>
        <CardBody>
          {listQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-12 text-slate-400"><Spinner /> Memuat...</div>
          ) : rows.length === 0 ? (
            <EmptyState title="Belum ada dokumen penilaian" description="Buat dokumen untuk menghitung PKG, NKKS, atau tinjauan pegawai." />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Pegawai</th>
                    <th className="px-4 py-3 font-medium">Jenis</th>
                    <th className="px-4 py-3 font-medium">Periode</th>
                    <th className="px-4 py-3 font-medium">Nilai</th>
                    <th className="px-4 py-3 font-medium">Teknis</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.map((row) => (
                    <tr key={row.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-900">{row.supervisee?.full_name ?? '-'}</div>
                        <div className="text-xs text-slate-500">{row.supervisee?.position ?? ''}</div>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{row.appraisal_type}</td>
                      <td className="px-4 py-3 text-xs text-slate-500">
                        {row.period_start ? new Date(row.period_start).toLocaleDateString('id-ID') : '-'}
                        {' – '}
                        {row.period_end ? new Date(row.period_end).toLocaleDateString('id-ID') : '-'}
                        <div>{row.period_type}</div>
                      </td>
                      <td className="px-4 py-3">
                        {row.final_score === null || row.final_score === undefined ? (
                          <span className="text-slate-400">—</span>
                        ) : (
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium text-slate-900">{row.final_score}</span>
                            <span className="text-xs text-slate-500">{row.raw_score}/{row.max_score}</span>
                            {row.grade_label && <Badge tone="brand">{row.grade_label}</Badge>}
                            {row.credit_percent !== null && row.credit_percent !== undefined && (
                              <Badge tone="gray">angka kredit {row.credit_percent}%</Badge>
                            )}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3 text-xs text-slate-500">
                        {row.attendance_correction_percent !== null && row.attendance_correction_percent !== undefined && (
                          <div>koreksi kehadiran: {row.attendance_correction_percent}%</div>
                        )}
                        {(row.final_predicate || row.supervision_contribution !== null) && (
                          <div>
                            {row.final_predicate ? `predikat ${row.final_predicate}` : ''}
                            {row.supervision_contribution !== null && row.supervision_contribution !== undefined
                              ? ` · supervisi ${row.supervision_contribution}`
                              : ''}
                          </div>
                        )}
                      </td>
                      <td className="px-4 py-3"><Badge tone={STATUS_TONES[row.status] ?? 'gray'}>{row.status}</Badge></td>
                      <td className="px-4 py-3 text-right">
                        <Link to={`/supervisi/appraisals/${row.id}`}>
                          <Button size="sm" variant="outline">
                            <Calculator className="mr-2 h-4 w-4" /> Buka
                          </Button>
                        </Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      {reference && (
        <Card>
          <CardHeader><CardTitle>Acuan Perhitungan</CardTitle></CardHeader>
          <CardBody className="space-y-2 text-sm text-slate-600">
            <p>{reference.attendance_formula}</p>
            <ul className="list-disc space-y-0.5 pl-5 text-xs">
              {reference.notes.map((note) => <li key={note}>{note}</li>)}
            </ul>
            <div className="flex flex-wrap gap-2 pt-1">
              {Object.entries(reference.predicates).map(([key, value]) => (
                <Badge key={key} tone={value.triggers_pip ? 'red' : 'green'}>
                  {value.label} · {value.credit_percent}%
                </Badge>
              ))}
              <Badge tone="gray">hari kerja {reference.working_days}</Badge>
            </div>
          </CardBody>
        </Card>
      )}

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title="Dokumen Penilaian Baru"
        size="lg"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button
              onClick={() => {
                if (!form.supervisee_employee_id) {
                  toast.error('Pilih pegawai yang dinilai.');
                  return;
                }
                createMutation.mutate();
              }}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Buat
            </Button>
          </div>
        }
      >
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
          <div className="sm:col-span-2">
            <Label required>Pegawai yang dinilai</Label>
            <Select
              value={form.supervisee_employee_id}
              onChange={(event) => setForm((previous) => ({ ...previous, supervisee_employee_id: event.target.value }))}
            >
              <option value="">Pilih pegawai...</option>
              {(employeesQuery.data ?? []).map((employee) => (
                <option key={employee.id} value={employee.id}>
                  {employee.full_name} — {employee.position ?? ''}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label required>Jenis penilaian</Label>
            <Select
              value={form.appraisal_type}
              onChange={(event) => setForm((previous) => ({ ...previous, appraisal_type: event.target.value }))}
            >
              {TYPES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
            </Select>
          </div>
          <div>
            <Label>Jenis periode</Label>
            <Select
              value={form.period_type}
              onChange={(event) => setForm((previous) => ({ ...previous, period_type: event.target.value }))}
            >
              <option value="BULANAN">Bulanan</option>
              <option value="TRIWULAN">Triwulan</option>
              <option value="SEMESTER">Semester</option>
              <option value="TAHUNAN">Tahunan</option>
            </Select>
          </div>
          <div>
            <Label>Mulai periode</Label>
            <Input type="date" value={form.period_start} onChange={(event) => setForm((previous) => ({ ...previous, period_start: event.target.value }))} />
          </div>
          <div>
            <Label>Akhir periode</Label>
            <Input type="date" value={form.period_end} onChange={(event) => setForm((previous) => ({ ...previous, period_end: event.target.value }))} />
          </div>
          <div className="sm:col-span-2">
            <Label>Penilai (assessor)</Label>
            <Select
              value={form.assessor_employee_id}
              onChange={(event) => setForm((previous) => ({ ...previous, assessor_employee_id: event.target.value }))}
            >
              <option value="">Saya sendiri (dari akun)</option>
              {(employeesQuery.data ?? []).map((employee) => (
                <option key={employee.id} value={employee.id}>{employee.full_name} — {employee.position ?? ''}</option>
              ))}
            </Select>
            <p className="mt-1 text-xs text-slate-500">
              Instrumen dipilih otomatis menurut jenis penilaian (PKG 14 kompetensi, PKG BK 17, NKKS 6 aspek, tinjauan pegawai 12 butir).
            </p>
          </div>
        </div>
      </Modal>
    </div>
  );
}
