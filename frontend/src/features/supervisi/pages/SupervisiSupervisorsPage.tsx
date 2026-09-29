import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Plus, RefreshCw, ShieldCheck, UserCog } from 'lucide-react';
import { toast } from 'sonner';
import { supervisiApi } from '../api/supervisiApi';
import type { Supervisor } from '../types';
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

interface FormState {
  employee_id: string;
  kind: string;
  can_finalize: boolean;
  can_appraise: boolean;
  max_active_cases: string;
  active_from: string;
  active_to: string;
  certification_no: string;
  notes: string;
}

const emptyForm: FormState = {
  employee_id: '',
  kind: 'KEPALA_SEKOLAH',
  can_finalize: true,
  can_appraise: false,
  max_active_cases: '',
  active_from: '',
  active_to: '',
  certification_no: '',
  notes: '',
};

/**
 * Pengaturan Supervisor — memilih **siapa saja** yang boleh menilai/membina.
 *
 * Halaman ini mengelola registry supervisor: siapa orangnya, jenis
 * kewenangannya (kepala sekolah, wakasek, koordinator, pengawas, mentor,
 * sejawat), cakupan, kapasitas, dan hak finalisasi. Aturan penting (mis.
 * rekan sejawat tidak boleh memfinalkan nilai) ditegakkan server.
 */
export default function SupervisiSupervisorsPage() {
  const queryClient = useQueryClient();
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<Supervisor | null>(null);
  const [form, setForm] = useState<FormState>(emptyForm);
  const [candidateSearch, setCandidateSearch] = useState('');
  const [kindFilter, setKindFilter] = useState('');

  const supervisorsQuery = useQuery({ queryKey: ['supervisi', 'supervisors'], queryFn: () => supervisiApi.supervisors() });
  const kindsQuery = useQuery({ queryKey: ['supervisi', 'supervisor-kinds'], queryFn: () => supervisiApi.supervisorKinds() });
  const coverageQuery = useQuery({ queryKey: ['supervisi', 'coverage'], queryFn: () => supervisiApi.coverage() });
  const candidatesQuery = useQuery({
    queryKey: ['supervisi', 'candidates', candidateSearch],
    queryFn: () => supervisiApi.candidates(candidateSearch || undefined),
    enabled: modalOpen && editing === null,
  });

  const saveMutation = useMutation({
    mutationFn: async () => {
      const payload = {
        kind: form.kind,
        can_finalize: form.can_finalize,
        can_appraise: form.can_appraise,
        max_active_cases: form.max_active_cases ? Number(form.max_active_cases) : null,
        active_from: form.active_from || null,
        active_to: form.active_to || null,
        certification_no: form.certification_no || null,
        notes: form.notes || null,
      };

      if (editing) {
        return supervisiApi.updateSupervisor(editing.id, payload);
      }

      return supervisiApi.createSupervisor({ ...payload, employee_id: Number(form.employee_id) });
    },
    onSuccess: () => {
      toast.success(editing ? 'Pengaturan supervisor diperbarui' : 'Supervisor berhasil ditambahkan');
      setModalOpen(false);
      setEditing(null);
      setForm(emptyForm);
      queryClient.invalidateQueries({ queryKey: ['supervisi'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const deactivateMutation = useMutation({
    mutationFn: (id: number) => supervisiApi.deactivateSupervisor(id, 'Dinonaktifkan dari layar pengaturan'),
    onSuccess: () => {
      toast.success('Supervisor dinonaktifkan (riwayat penilaian tetap tersimpan)');
      queryClient.invalidateQueries({ queryKey: ['supervisi'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const toggleActiveMutation = useMutation({
    mutationFn: ({ id, is_active }: { id: number; is_active: boolean }) => supervisiApi.updateSupervisor(id, { is_active }),
    onSuccess: () => {
      toast.success('Status supervisor diperbarui');
      queryClient.invalidateQueries({ queryKey: ['supervisi'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const openAdd = () => {
    setEditing(null);
    setForm(emptyForm);
    setCandidateSearch('');
    setModalOpen(true);
  };

  const openEdit = (supervisor: Supervisor) => {
    setEditing(supervisor);
    setForm({
      employee_id: String(supervisor.employee_id),
      kind: supervisor.kind,
      can_finalize: supervisor.can_finalize,
      can_appraise: supervisor.can_appraise,
      max_active_cases: supervisor.max_active_cases ? String(supervisor.max_active_cases) : '',
      active_from: supervisor.active_from ?? '',
      active_to: supervisor.active_to ?? '',
      certification_no: supervisor.certification_no ?? '',
      notes: supervisor.notes ?? '',
    });
    setModalOpen(true);
  };

  const supervisors = (supervisorsQuery.data ?? []).filter((item) => !kindFilter || item.kind === kindFilter);
  const coverage = coverageQuery.data;
  const kindOptions = kindsQuery.data ?? [];

  const submit = () => {
    if (!editing && !form.employee_id) {
      toast.error('Pilih pegawai yang akan dijadikan supervisor.');
      return;
    }
    saveMutation.mutate();
  };

  const updateForm = <K extends keyof FormState>(key: K, value: FormState[K]) =>
    setForm((previous) => ({ ...previous, [key]: value }));

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Pengaturan Supervisor</h1>
          <p className="text-sm text-slate-500">
            Pilih siapa saja yang boleh menilai dan membina guru/tenaga kependidikan, beserta batas kewenangannya.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => supervisorsQuery.refetch()}>
            <RefreshCw className={supervisorsQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="supervisi.supervisor.manage">
            <Button onClick={openAdd}>
              <Plus className="mr-2 h-4 w-4" /> Tambah Supervisor
            </Button>
          </Can>
        </div>
      </div>

      {coverage && (
        <Card>
          <CardHeader>
            <div className="flex items-center gap-2">
              <ShieldCheck className="h-4 w-4 text-brand-600" />
              <CardTitle>Ringkasan Tata Kelola</CardTitle>
            </div>
          </CardHeader>
          <CardBody className="space-y-3">
            <div className="flex flex-wrap gap-2 text-sm">
              <Badge tone="brand">{coverage.summary.supervisors_active} supervisor aktif</Badge>
              <Badge tone="blue">{coverage.summary.assignments_active} penugasan aktif</Badge>
              <Badge tone="gray">{coverage.summary.supervisees} pegawai disupervisi</Badge>
            </div>
            {coverage.warnings.length > 0 ? (
              <div className="space-y-1 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                {coverage.warnings.map((warning) => (
                  <div key={warning} className="flex items-start gap-2">
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{warning}</span>
                  </div>
                ))}
              </div>
            ) : (
              <p className="text-sm text-slate-500">Tidak ada peringatan tata kelola. Penugasan terlihat sehat.</p>
            )}
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-center gap-2">
              <UserCog className="h-4 w-4 text-brand-600" />
              <CardTitle>Daftar Supervisor</CardTitle>
            </div>
            <div className="w-full sm:w-64">
              <Select value={kindFilter} onChange={(event) => setKindFilter(event.target.value)}>
                <option value="">Semua jenis</option>
                {kindOptions.map((kind) => (
                  <option key={kind.value} value={kind.value}>{kind.label}</option>
                ))}
              </Select>
            </div>
          </div>
        </CardHeader>
        <CardBody>
          {supervisorsQuery.isLoading ? (
            <div className="flex items-center justify-center gap-2 py-12 text-slate-400">
              <Spinner /> Memuat supervisor...
            </div>
          ) : supervisors.length === 0 ? (
            <EmptyState
              title="Belum ada supervisor terdaftar"
              description="Tambahkan kepala sekolah, wakil kepala sekolah, koordinator, pengawas, atau mentor yang akan melakukan supervisi."
              action={
                <Can perm="supervisi.supervisor.manage">
                  <Button size="sm" className="mt-2" onClick={openAdd}>
                    <Plus className="mr-2 h-4 w-4" /> Tambah Supervisor
                  </Button>
                </Can>
              }
            />
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3 font-medium">Pegawai</th>
                    <th className="px-4 py-3 font-medium">Jenis</th>
                    <th className="px-4 py-3 font-medium">Kewenangan</th>
                    <th className="px-4 py-3 font-medium">Kapasitas</th>
                    <th className="px-4 py-3 font-medium">Masa Berlaku</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 text-right font-medium">Aksi</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {supervisors.map((supervisor) => (
                    <tr key={supervisor.id} className="hover:bg-slate-50/60">
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-900">{supervisor.full_name ?? '-'}</div>
                        <div className="text-xs text-slate-500">
                          {supervisor.position ?? '-'}{supervisor.nip ? ` • ${supervisor.nip}` : ''}
                        </div>
                      </td>
                      <td className="px-4 py-3">{supervisor.kind_label}</td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-1">
                          {supervisor.can_finalize ? <Badge tone="green">Finalisasi nilai</Badge> : <Badge tone="gray">Tanpa finalisasi</Badge>}
                          {supervisor.can_appraise && <Badge tone="blue">Penilaian kinerja</Badge>}
                        </div>
                      </td>
                      <td className="px-4 py-3">{supervisor.max_active_cases ?? '—'}</td>
                      <td className="px-4 py-3 text-xs text-slate-500">
                        {supervisor.active_from ?? '—'} s.d. {supervisor.active_to ?? 'tanpa batas'}
                      </td>
                      <td className="px-4 py-3">
                        {supervisor.is_active ? <Badge tone="green">Aktif</Badge> : <Badge tone="red">Nonaktif</Badge>}
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex justify-end gap-2">
                          <Can perm="supervisi.supervisor.manage">
                            <Button size="sm" variant="outline" onClick={() => openEdit(supervisor)}>Ubah</Button>
                            {supervisor.is_active ? (
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => deactivateMutation.mutate(supervisor.id)}
                                disabled={deactivateMutation.isPending}
                              >
                                Nonaktifkan
                              </Button>
                            ) : (
                              <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => toggleActiveMutation.mutate({ id: supervisor.id, is_active: true })}
                                disabled={toggleActiveMutation.isPending}
                              >
                                Aktifkan
                              </Button>
                            )}
                          </Can>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardBody>
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? `Ubah Supervisor — ${editing.full_name ?? ''}` : 'Tambah Supervisor'}
        size="lg"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModalOpen(false)}>Batal</Button>
            <Button onClick={submit} disabled={saveMutation.isPending}>
              {saveMutation.isPending ? <Spinner className="mr-2 h-4 w-4" /> : null} Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          {!editing && (
            <div>
              <Label required>Pegawai</Label>
              <Input
                placeholder="Cari nama, NIP, atau jabatan..."
                value={candidateSearch}
                onChange={(event) => setCandidateSearch(event.target.value)}
              />
              <div className="mt-2 max-h-56 overflow-y-auto rounded-lg border border-slate-200">
                {candidatesQuery.isLoading ? (
                  <div className="p-3 text-sm text-slate-400">Memuat calon supervisor...</div>
                ) : (candidatesQuery.data ?? []).length === 0 ? (
                  <div className="p-3 text-sm text-slate-400">Tidak ada calon pada pencarian ini.</div>
                ) : (
                  (candidatesQuery.data ?? []).map((candidate) => (
                    <button
                      key={candidate.employee_id}
                      type="button"
                      className={`flex w-full items-center justify-between gap-2 border-b border-slate-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-slate-50 ${
                        form.employee_id === String(candidate.employee_id) ? 'bg-brand-50' : ''
                      }`}
                      onClick={() => setForm((previous) => ({
                        ...previous,
                        employee_id: String(candidate.employee_id),
                        kind: candidate.suggested_kind,
                        can_finalize: candidate.suggested_can_finalize,
                      }))}
                    >
                      <span>
                        <span className="font-medium text-slate-800">{candidate.full_name}</span>
                        <span className="ml-2 text-xs text-slate-500">{candidate.position ?? '-'}</span>
                      </span>
                      <Badge tone="gray">usulan: {candidate.suggested_kind}</Badge>
                    </button>
                  ))
                )}
              </div>
              <p className="mt-1 text-xs text-slate-500">
                Calon diambil dari pegawai aktif yang belum terdaftar. Jenis kewenangan diusulkan otomatis dari jabatan.
              </p>
            </div>
          )}

          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label required>Jenis Supervisor</Label>
              <Select
                value={form.kind}
                onChange={(event) => {
                  const next = event.target.value;
                  const option = kindOptions.find((kind) => kind.value === next);
                  // Jenis kewenangan menentukan hak bawaan: pilih WAKASEK →
                  // boleh finalisasi; pilih MENTOR → tidak boleh (server juga
                  // menegakkan aturan ini).
                  setForm((previous) => ({
                    ...previous,
                    kind: next,
                    can_finalize: option?.may_finalize ?? previous.can_finalize,
                    can_appraise: option?.may_appraise ?? previous.can_appraise,
                  }));
                }}
              >
                {kindOptions.map((kind) => (
                  <option key={kind.value} value={kind.value}>{kind.label}</option>
                ))}
              </Select>
              <p className="mt-1 text-xs text-slate-500">
                {kindOptions.find((kind) => kind.value === form.kind)?.may_finalize
                  ? 'Jenis ini berwenang memfinalkan nilai.'
                  : 'Jenis ini tidak dapat memfinalkan nilai resmi (hanya membina).'}
              </p>
            </div>
            <div>
              <Label>Kapasitas Maksimum (orang)</Label>
              <Input
                type="number"
                min={1}
                placeholder="mis. 12 — kosongkan bila tidak dibatasi"
                value={form.max_active_cases}
                onChange={(event) => updateForm('max_active_cases', event.target.value)}
              />
            </div>
            <div>
              <Label>Berlaku Sejak</Label>
              <Input type="date" value={form.active_from} onChange={(event) => updateForm('active_from', event.target.value)} />
            </div>
            <div>
              <Label>Berlaku Sampai</Label>
              <Input type="date" value={form.active_to} onChange={(event) => updateForm('active_to', event.target.value)} />
            </div>
            <div>
              <Label>Nomor Sertifikat Pelatihan Penilai</Label>
              <Input value={form.certification_no} onChange={(event) => updateForm('certification_no', event.target.value)} />
            </div>
            <div className="flex items-end gap-4">
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={form.can_finalize}
                  onChange={(event) => updateForm('can_finalize', event.target.checked)}
                />
                Boleh finalisasi nilai
              </label>
              <label className="flex items-center gap-2 text-sm text-slate-700">
                <input
                  type="checkbox"
                  className="h-4 w-4 rounded border-slate-300"
                  checked={form.can_appraise}
                  onChange={(event) => updateForm('can_appraise', event.target.checked)}
                />
                Boleh menilai kinerja
              </label>
            </div>
          </div>

          <div>
            <Label>Catatan</Label>
            <Textarea
              rows={3}
              value={form.notes}
              onChange={(event) => updateForm('notes', event.target.value)}
              placeholder="mis. fokus pada mata pelajaran IPA, hasil pelatihan supervisi 2025"
            />
          </div>
        </div>
      </Modal>
    </div>
  );
}
