import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Plus, RefreshCw } from 'lucide-react';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { StudentPicker } from '../components/StudentPicker';
import { SEVERITY_TONE, STATUS_TONE } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

/** Daftar kunjungan UKS sesuai cakupan pengguna + pencatatan kunjungan baru. */
export default function UksVisitsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const [openOnly, setOpenOnly] = useState(false);
  const [creating, setCreating] = useState(false);
  const [studentId, setStudentId] = useState<number | null>(null);
  const [form, setForm] = useState({ complaint: '', visit_type: 'KELUHAN', severity: 'RINGAN', symptoms: '' });

  const meta = useQuery({ queryKey: ['uks', 'meta'], queryFn: () => uksApi.metadata() });

  const visits = useQuery({
    queryKey: ['uks', 'visits', { page, status, openOnly }],
    queryFn: () => uksApi.visits({ page, status: status || undefined, open_only: openOnly ? 1 : undefined }),
  });

  const create = useMutation({
    mutationFn: () => uksApi.createVisit({ student_id: studentId, ...form }),
    onSuccess: () => {
      toast.success('Kunjungan dicatat');
      setCreating(false);
      setForm({ complaint: '', visit_type: 'KELUHAN', severity: 'RINGAN', symptoms: '' });
      queryClient.invalidateQueries({ queryKey: ['uks', 'visits'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Kunjungan UKS</h1>
        <div className="flex gap-2">
          <Button variant="ghost" onClick={() => visits.refetch()}><RefreshCw className="h-4 w-4" /></Button>
          <Can perm="uks.visit.create">
            <Button onClick={() => setCreating(true)}><Plus className="mr-1 h-4 w-4" /> Catat Kunjungan</Button>
          </Can>
        </div>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <Label>Status</Label>
          <Select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}>
            <option value="">Semua</option>
            {meta.data?.visit_statuses.map((option) => (
              <option key={option.value} value={option.value}>{option.label}</option>
            ))}
          </Select>
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" checked={openOnly} onChange={(e) => setOpenOnly(e.target.checked)} />
          Hanya yang masih terbuka
        </label>
      </div>

      <Card>
        <CardBody className="p-0">
          {visits.isLoading ? <Spinner /> : null}
          {visits.data && visits.data.data.length === 0 ? (
            <EmptyState title="Belum ada kunjungan" description="Kunjungan yang Anda catat atau yang masuk cakupan Anda akan muncul di sini." />
          ) : null}
          <ul className="divide-y divide-slate-100">
            {visits.data?.data.map((visit) => (
              <li key={visit.id}>
                <Link to={`/uks/visits/${visit.id}`} className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-slate-50">
                  <div>
                    <p className="text-sm font-medium text-slate-900">
                      {visit.student?.full_name ?? `Siswa #${visit.student_id}`}
                      <span className="ml-2 text-xs text-slate-400">{visit.code}</span>
                    </p>
                    <p className="text-xs text-slate-500">
                      {visit.complaint} · {visit.class_name ?? 'tanpa kelas'} · {visit.arrived_at}
                    </p>
                  </div>
                  <div className="flex gap-1">
                    <Badge tone={SEVERITY_TONE[visit.severity] ?? 'gray'}>{visit.severity_label}</Badge>
                    <Badge tone={STATUS_TONE[visit.status] ?? 'gray'}>{visit.status_label}</Badge>
                    {visit.parent_notified ? <Badge tone="green">Ortu tahu</Badge> : null}
                  </div>
                </Link>
              </li>
            ))}
          </ul>
          {visits.data ? <Pagination meta={visits.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Catat Kunjungan UKS"
        footer={
          <>
            <Button variant="ghost" onClick={() => setCreating(false)}>Batal</Button>
            <Button loading={create.isPending} disabled={!studentId || form.complaint.trim() === ''} onClick={() => create.mutate()}>
              Simpan
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <StudentPicker value={studentId} onChange={(id) => setStudentId(id)} />
          <div>
            <Label>Keluhan</Label>
            <Input value={form.complaint} onChange={(e) => setForm({ ...form, complaint: e.target.value })} placeholder="Mis. demam sejak tadi malam" />
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div>
              <Label>Jenis</Label>
              <Select value={form.visit_type} onChange={(e) => setForm({ ...form, visit_type: e.target.value })}>
                {meta.data?.visit_types.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Tingkat</Label>
              <Select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}>
                {meta.data?.severities.map((option) => (
                  <option key={option.value} value={option.value}>{option.label}</option>
                ))}
              </Select>
            </div>
          </div>
          <div>
            <Label>Gejala (opsional)</Label>
            <Textarea value={form.symptoms} onChange={(e) => setForm({ ...form, symptoms: e.target.value })} rows={2} />
          </div>
          <p className="text-xs text-slate-400">
            Kasus berat dan darurat otomatis mengirim kabar ke akun orang tua/wali siswa.
          </p>
        </div>
      </Modal>
    </div>
  );
}
