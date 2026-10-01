import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarDays, FolderTree, Plus, Stethoscope, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';
import { workflowApi } from '../api/workflowApi';

/**
 * Pengaturan workflow: kalender kerja & direktori organisasi (§70, §25–§27).
 *
 * Kedua hal ini yang paling sering membuat SLA meleset dan approver tidak
 * ditemukan, jadi keduanya dapat diatur sendiri oleh Workflow Admin tanpa
 * menunggu perubahan kode.
 */
export default function WorkflowSettingsPage() {
  const [tab, setTab] = useState<'calendar' | 'directory'>('calendar');
  const health = useQuery({ queryKey: ['workflow', 'health'], queryFn: () => workflowApi.health() });

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Pengaturan Workflow</h1>
        <p className="text-sm text-slate-500">Kalender kerja menentukan arti "48 jam"; direktori menentukan siapa yang berwenang.</p>
      </div>

      {health.data ? (
        <Card>
          <CardBody className="flex flex-wrap items-center gap-3 py-3 text-xs text-slate-600">
            <Stethoscope className="h-4 w-4 text-brand-600" />
            {(health.data.checks as Record<string, unknown>)?.audit_chain_valid ? (
              <Badge tone="success">Rantai audit utuh</Badge>
            ) : (
              <Badge tone="danger">Rantai audit perlu diperiksa</Badge>
            )}
            <span>Resolver terdaftar: {String((health.data.checks as Record<string, unknown>)?.registrar_resolvers ?? 0)}</span>
            <span>Aksi terdaftar: {String((health.data.checks as Record<string, unknown>)?.registrar_actions ?? 0)}</span>
            <span>Step menunggu penetapan: {String((health.data.checks as Record<string, unknown>)?.steps_waiting_for_approver ?? 0)}</span>
            <span>Kalender aktif: {String((health.data.checks as Record<string, unknown>)?.calendars ?? 0)}</span>
          </CardBody>
        </Card>
      ) : null}

      <div className="flex gap-2">
        <Button variant={tab === 'calendar' ? 'primary' : 'outline'} size="sm" onClick={() => setTab('calendar')}>
          <CalendarDays className="mr-1 h-4 w-4" /> Kalender kerja
        </Button>
        <Button variant={tab === 'directory' ? 'primary' : 'outline'} size="sm" onClick={() => setTab('directory')}>
          <FolderTree className="mr-1 h-4 w-4" /> Direktori organisasi
        </Button>
      </div>

      {tab === 'calendar' ? <CalendarTab /> : <DirectoryTab />}
    </div>
  );
}

function CalendarTab() {
  const queryClient = useQueryClient();
  const calendars = useQuery({ queryKey: ['workflow', 'calendars'], queryFn: () => workflowApi.calendars() });
  const [holiday, setHoliday] = useState({ calendarId: '', date: '', name: '' });

  const saveHoliday = useMutation({
    mutationFn: () => workflowApi.saveHoliday(Number(holiday.calendarId), { date: holiday.date, name: holiday.name }),
    onSuccess: () => {
      toast.success('Hari kalender disimpan.');
      setHoliday({ calendarId: '', date: '', name: '' });
      queryClient.invalidateQueries({ queryKey: ['workflow', 'calendars'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const removeHoliday = useMutation({
    mutationFn: (id: number) => workflowApi.deleteHoliday(id),
    onSuccess: () => {
      toast.success('Hari kalender dihapus.');
      queryClient.invalidateQueries({ queryKey: ['workflow', 'calendars'] });
    },
  });

  if (calendars.isLoading) return <Spinner />;

  return (
    <div className="space-y-4">
      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Kalender</CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          <ul className="divide-y divide-slate-100">
            {calendars.data?.calendars.map((calendar) => (
              <li key={calendar.id} className="px-4 py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div>
                    <div className="flex items-center gap-2">
                      <p className="text-sm font-medium text-slate-800">{calendar.name}</p>
                      <Badge tone="info">{calendar.code}</Badge>
                      {calendar.is_default ? <Badge tone="success">default</Badge> : null}
                    </div>
                    <p className="text-xs text-slate-500">
                      {calendar.timezone} · hari kerja {calendar.work_days.join(', ')} · jam {calendar.work_start}–{calendar.work_end}
                    </p>
                  </div>
                </div>

                <ul className="mt-2 flex flex-wrap gap-2">
                  {calendar.holidays.map((entry) => (
                    <li key={entry.id} className="flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                      {entry.date} · {entry.name}
                      <Can perm="workflow.settings.manage">
                        <button type="button" onClick={() => removeHoliday.mutate(entry.id)} className="text-slate-500 hover:text-red-600">
                          <Trash2 className="h-3 w-3" />
                        </button>
                      </Can>
                    </li>
                  ))}
                </ul>
              </li>
            ))}
          </ul>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Tambah hari libur / hari kerja pengganti</CardTitle>
        </CardHeader>
        <CardBody className="grid items-end gap-3 sm:grid-cols-4">
          <div>
            <Label>Kalender</Label>
            <Select value={holiday.calendarId} onChange={(event) => setHoliday({ ...holiday, calendarId: event.target.value })}>
              <option value="">— pilih —</option>
              {calendars.data?.calendars.map((calendar) => (
                <option key={calendar.id} value={calendar.id}>
                  {calendar.name}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <Label>Tanggal</Label>
            <Input type="date" value={holiday.date} onChange={(event) => setHoliday({ ...holiday, date: event.target.value })} />
          </div>
          <div>
            <Label>Nama</Label>
            <Input value={holiday.name} onChange={(event) => setHoliday({ ...holiday, name: event.target.value })} placeholder="Cuti bersama" />
          </div>
          <Can perm="workflow.settings.manage">
            <Button
              onClick={() => saveHoliday.mutate()}
              loading={saveHoliday.isPending}
              disabled={!holiday.calendarId || !holiday.date || !holiday.name}
            >
              <Plus className="mr-1 h-4 w-4" /> Simpan
            </Button>
          </Can>
        </CardBody>
      </Card>
    </div>
  );
}

function DirectoryTab() {
  const queryClient = useQueryClient();
  const directory = useQuery({ queryKey: ['workflow', 'directory'], queryFn: () => workflowApi.directory() });
  const [assignment, setAssignment] = useState({ scope_type: 'DEPARTMENT', scope_key: '', user_id: '' });
  const [report, setReport] = useState({ user_id: '', manager_user_id: '' });

  const saveAssignment = useMutation({
    mutationFn: () => workflowApi.saveAssignment({ ...assignment, user_id: Number(assignment.user_id) }),
    onSuccess: () => {
      toast.success('Cakupan organisasi disimpan.');
      setAssignment({ ...assignment, scope_key: '', user_id: '' });
      queryClient.invalidateQueries({ queryKey: ['workflow', 'directory'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveReport = useMutation({
    mutationFn: () => workflowApi.saveReport({ user_id: Number(report.user_id), manager_user_id: Number(report.manager_user_id) }),
    onSuccess: () => {
      toast.success('Relasi atasan disimpan.');
      setReport({ user_id: '', manager_user_id: '' });
      queryClient.invalidateQueries({ queryKey: ['workflow', 'directory'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (directory.isLoading) return <Spinner />;

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Cakupan organisasi → pengguna berwenang</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <p className="text-xs text-slate-500">
            Dipakai resolver <code>department</code>, <code>position</code>, dan <code>cost_center</code>. Untuk cost center, manajer yang sudah
            tercatat di modul Finance otomatis dipakai bila pemetaan di sini kosong.
          </p>

          <ul className="divide-y divide-slate-100 text-sm">
            {directory.data?.assignments.map((row) => (
              <li key={row.id} className="flex items-center justify-between py-1.5">
                <span className="text-slate-600">
                  <Badge tone="neutral">{row.scope_type}</Badge> {row.scope_key} → {row.user ?? `#${row.user_id}`}
                </span>
                {row.is_primary ? <Badge tone="info">utama</Badge> : null}
              </li>
            ))}
            {directory.data?.assignments.length === 0 ? <li className="py-2 text-slate-500">Belum ada pemetaan.</li> : null}
          </ul>

          <div className="grid items-end gap-2 sm:grid-cols-4">
            <div>
              <Label>Jenis</Label>
              <Select value={assignment.scope_type} onChange={(event) => setAssignment({ ...assignment, scope_type: event.target.value })}>
                {directory.data?.scope_types.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Kunci cakupan</Label>
              <Input
                value={assignment.scope_key}
                onChange={(event) => setAssignment({ ...assignment, scope_key: event.target.value })}
                placeholder="mis. SARANA atau Kepala Sekolah"
              />
            </div>
            <div>
              <Label>User id</Label>
              <Input type="number" value={assignment.user_id} onChange={(event) => setAssignment({ ...assignment, user_id: event.target.value })} />
            </div>
            <Can perm="workflow.directory.manage">
              <Button
                onClick={() => saveAssignment.mutate()}
                loading={saveAssignment.isPending}
                disabled={!assignment.scope_key || !assignment.user_id}
              >
                Simpan
              </Button>
            </Can>
          </div>
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Hierarki atasan (resolver “manager”)</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          <ul className="divide-y divide-slate-100 text-sm">
            {directory.data?.reports.map((row) => (
              <li key={row.id} className="py-1.5 text-slate-600">
                {row.user ?? `#${row.user_id}`} → atasan {row.manager ?? `#${row.manager_user_id}`}
                {row.backup_manager ? ` (cadangan: ${row.backup_manager})` : ''}
              </li>
            ))}
            {directory.data?.reports.length === 0 ? <li className="py-2 text-slate-500">Belum ada relasi atasan.</li> : null}
          </ul>

          <div className="grid items-end gap-2 sm:grid-cols-3">
            <div>
              <Label>User id bawahan</Label>
              <Input type="number" value={report.user_id} onChange={(event) => setReport({ ...report, user_id: event.target.value })} />
            </div>
            <div>
              <Label>User id atasan</Label>
              <Input type="number" value={report.manager_user_id} onChange={(event) => setReport({ ...report, manager_user_id: event.target.value })} />
            </div>
            <Can perm="workflow.directory.manage">
              <Button onClick={() => saveReport.mutate()} loading={saveReport.isPending} disabled={!report.user_id || !report.manager_user_id}>
                Simpan
              </Button>
            </Can>
          </div>

          <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-500">
            Contoh resolusi Anda saat ini: unit{' '}
            <span className="font-medium">{directory.data?.resolved_example.requester_department ?? '—'}</span>, atasan{' '}
            <span className="font-medium">{directory.data?.resolved_example.requester_manager ?? '—'}</span>.
          </p>
        </CardBody>
      </Card>
    </div>
  );
}
