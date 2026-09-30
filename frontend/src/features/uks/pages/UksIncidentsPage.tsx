import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Plus } from 'lucide-react';
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

/** Insiden/kecelakaan di lingkungan sekolah. */
export default function UksIncidentsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [studentId, setStudentId] = useState<number | null>(null);
  const [form, setForm] = useState({ location: '', severity: 'RINGAN', description: '', first_aid: '' });

  const meta = useQuery({ queryKey: ['uks', 'meta'], queryFn: () => uksApi.metadata() });
  const incidents = useQuery({
    queryKey: ['uks', 'incidents', page],
    queryFn: () => uksApi.incidents({ page }),
  });

  const create = useMutation({
    mutationFn: () => uksApi.createIncident({ student_id: studentId, ...form }),
    onSuccess: () => {
      toast.success('Insiden dilaporkan');
      setCreating(false);
      queryClient.invalidateQueries({ queryKey: ['uks', 'incidents'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const update = useMutation({
    mutationFn: ({ id, status }: { id: number; status: string }) => uksApi.updateIncident(id, { status }),
    onSuccess: () => { toast.success('Status diperbarui'); queryClient.invalidateQueries({ queryKey: ['uks', 'incidents'] }); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-900">Insiden & Kecelakaan</h1>
        <Can perm="uks.incident.create">
          <Button onClick={() => setCreating(true)}><Plus className="mr-1 h-4 w-4" /> Laporkan</Button>
        </Can>
      </div>

      <Card>
        <CardBody className="p-0">
          {incidents.isLoading ? <Spinner /> : null}
          {incidents.data && incidents.data.data.length === 0 ? (
            <EmptyState title="Tidak ada insiden" description="Semoga tetap demikian." />
          ) : null}
          <ul className="divide-y divide-slate-100">
            {incidents.data?.data.map((incident) => (
              <li key={incident.id} className="flex items-center justify-between gap-3 px-4 py-3">
                <div>
                  <p className="text-sm font-medium">
                    {incident.student?.full_name ?? `Siswa #${incident.student_id}`}
                    <span className="ml-2 text-xs text-slate-400">{incident.code}</span>
                  </p>
                  <p className="text-xs text-slate-500">{incident.location ?? '-'} · {incident.description}</p>
                </div>
                <div className="flex items-center gap-2">
                  <Badge tone={SEVERITY_TONE[incident.severity] ?? 'gray'}>{incident.severity}</Badge>
                  <Can perm="uks.incident.manage">
                    <Select
                      value={incident.status}
                      onChange={(e) => update.mutate({ id: incident.id, status: e.target.value })}
                    >
                      {meta.data?.incident_statuses.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                      ))}
                    </Select>
                  </Can>
                  <Badge tone={STATUS_TONE[incident.status] ?? 'gray'}>{incident.status}</Badge>
                </div>
              </li>
            ))}
          </ul>
          {incidents.data ? <Pagination meta={incidents.data.meta} onPageChange={setPage} /> : null}
        </CardBody>
      </Card>

      <Modal
        open={creating}
        onClose={() => setCreating(false)}
        title="Lapor Insiden"
        footer={<><Button variant="ghost" onClick={() => setCreating(false)}>Batal</Button>
          <Button loading={create.isPending} disabled={!studentId || form.description.trim() === ''} onClick={() => create.mutate()}>Kirim</Button></>}
      >
        <div className="space-y-3">
          <StudentPicker value={studentId} onChange={(id) => setStudentId(id)} />
          <div className="grid grid-cols-2 gap-3">
            <div><Label>Lokasi</Label><Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} /></div>
            <div>
              <Label>Tingkat</Label>
              <Select value={form.severity} onChange={(e) => setForm({ ...form, severity: e.target.value })}>
                {meta.data?.severities.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
              </Select>
            </div>
          </div>
          <div><Label>Kronologi</Label><Textarea rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></div>
          <div><Label>Pertolongan pertama</Label><Textarea rows={2} value={form.first_aid} onChange={(e) => setForm({ ...form, first_aid: e.target.value })} /></div>
        </div>
      </Modal>
    </div>
  );
}
