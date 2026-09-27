import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2, Users } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Can } from '@/components/common/Can';
import { useEmployeeOptions, useStudentOptions } from '@/hooks/useLookupOptions';
import { ConfigFormModal, FormField, Tabs, defaultValues, fmtDate, type FormValues } from '../components/BoardingUi';
import { groupsApi, musyrifsApi } from '../api/boardingApi';
import { useDormitoryOptions } from '../hooks/useBoardingOptions';
import { MUSYRIF_POSITION_LABELS } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingGroup, BoardingGroupMember, BoardingMusyrif } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));

function MusyrifTab() {
  const qc = useQueryClient();
  const [page] = useState(1);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingMusyrif | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<BoardingMusyrif | null>(null);

  const { options: employeeOptions } = useEmployeeOptions();
  const { options: dormitoryOptions } = useDormitoryOptions();

  const listQuery = useQuery({
    queryKey: ['boarding', 'musyrifs', page],
    queryFn: () => musyrifsApi.list({ page, per_page: 15 }),
  });

  const fields: FIELD_SET = [
    { name: 'employee_id', label: 'Pegawai (Pengasuh)', type: 'search-select', options: employeeOptions, required: true },
    { name: 'position', label: 'Jabatan', type: 'select', options: Object.entries(MUSYRIF_POSITION_LABELS).map(([value, label]) => ({ value, label })), required: true },
    { name: 'dormitory_id', label: 'Asrama', type: 'search-select', options: dormitoryOptions },
    { name: 'start_date', label: 'Mulai', type: 'date', required: true },
    { name: 'end_date', label: 'Selesai', type: 'date' },
    { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Selesai' }] },
    { name: 'notes', label: 'Catatan', type: 'textarea' },
  ];

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? musyrifsApi.update(editing.id, payload as never) : musyrifsApi.create(payload as never),
    onSuccess: () => {
      toast.success('Penugasan pengasuh disimpan');
      setFormOpen(false);
      setEditing(null);
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => musyrifsApi.remove(id),
    onSuccess: () => {
      toast.success('Penugasan dihapus');
      setDeleting(null);
      qc.invalidateQueries({ queryKey: ['boarding'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <Can perm="boarding.musyrif.manage">
          <Button
            onClick={() => {
              setEditing(null);
              setErrors({});
              setValues(defaultValues(fields, { status: 'active' }));
              setFormOpen(true);
            }}
          >
            <Plus className="h-4 w-4" /> Tambah Pengasuh
          </Button>
        </Can>
      </div>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada pengasuh ditugaskan" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Pengasuh</th>
                  <th className="px-4 py-2 font-medium">Jabatan</th>
                  <th className="px-4 py-2 font-medium">Area</th>
                  <th className="px-4 py-2 font-medium">Periode</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {(rows as BoardingMusyrif[]).map((m) => (
                  <tr key={m.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2 font-medium text-slate-800">{m.employee?.full_name ?? `Karyawan #${m.employee_id}`}</td>
                    <td className="px-4 py-2 text-slate-600">{m.position_label}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{m.area_label ?? '—'}</td>
                    <td className="px-4 py-2 text-xs text-slate-600">{fmtDate(m.start_date)} — {fmtDate(m.end_date)}</td>
                    <td className="px-4 py-2"><Badge tone={m.status === 'active' ? 'green' : 'gray'}>{m.status_label}</Badge></td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.musyrif.manage">
                        <div className="flex gap-1">
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => {
                            setEditing(m);
                            setErrors({});
                            setValues(defaultValues(fields, m as unknown as Record<string, unknown>));
                            setFormOpen(true);
                          }}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Hapus" onClick={() => setDeleting(m)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </div>
                      </Can>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}

      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Penugasan Pengasuh' : 'Tambah Pengasuh'}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={errors}
        onChange={setValues}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={() => save.mutate(values)}
      />

      <Modal open={!!deleting} onClose={() => setDeleting(null)} title="Hapus Penugasan"
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setDeleting(null)}>Batal</Button>
            <Button variant="danger" disabled={remove.isPending} onClick={() => deleting && remove.mutate(deleting.id)}>Hapus</Button>
          </div>
        }
      >
        <p className="text-sm text-slate-600">
          Hapus penugasan {deleting?.employee?.full_name ?? ''}? Riwayat absensi dan catatan lama tidak ikut terhapus.
        </p>
      </Modal>
    </div>
  );
}

type FIELD_SET = Array<import('../components/BoardingUi').FormFieldConfig>;

function GroupMembersModal({ group, onClose }: { group: BoardingGroup | null; onClose: () => void }) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const [studentId, setStudentId] = useState<number | ''>('');
  const [joinedDate, setJoinedDate] = useState(new Date().toISOString().slice(0, 10));

  const membersQuery = useQuery({
    queryKey: ['boarding', 'groups', group?.id ?? null, 'members'],
    enabled: !!group,
    queryFn: () => groupsApi.members(group!.id, { all: 1 }),
  });

  const attach = useMutation({
    mutationFn: () => groupsApi.attachMember(group!.id, { student_id: studentId as number, joined_date: joinedDate }),
    onSuccess: () => {
      toast.success('Anggota ditambahkan');
      setStudentId('');
      qc.invalidateQueries({ queryKey: ['boarding', 'groups'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const detach = useMutation({
    mutationFn: (memberId: number) => groupsApi.detachMember(group!.id, memberId),
    onSuccess: () => {
      toast.success('Anggota dilepas');
      qc.invalidateQueries({ queryKey: ['boarding', 'groups'] });
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const members = (membersQuery.data?.rows ?? []) as BoardingGroupMember[];

  return (
    <Modal open={!!group} onClose={onClose} title={`Anggota — ${group?.name ?? ''}`} size="lg"
      footer={<Button variant="outline" onClick={onClose}>Tutup</Button>}
    >
      <Can perm="boarding.group.manage">
        <div className="mb-3 flex flex-wrap items-end gap-2">
          <div className="min-w-56 flex-1">
            <FormField label="Santri">
              <SearchSelect options={studentOptions} value={studentId} onChange={(v) => setStudentId(toId(v))} placeholder="Cari santri..." />
            </FormField>
          </div>
          <div className="w-40">
            <FormField label="Bergabung">
              <Input type="date" value={joinedDate} onChange={(e) => setJoinedDate(e.target.value)} />
            </FormField>
          </div>
          <Button disabled={!studentId || attach.isPending} onClick={() => attach.mutate()}>
            <Plus className="h-4 w-4" /> Tambah
          </Button>
        </div>
      </Can>

      {membersQuery.isLoading ? (
        <div className="flex items-center justify-center gap-2 py-8 text-slate-400"><Spinner /> Memuat anggota...</div>
      ) : members.length === 0 ? (
        <EmptyState title="Belum ada anggota" />
      ) : (
        <ul className="divide-y divide-slate-50">
          {members.map((mem) => (
            <li key={mem.id} className="flex items-center justify-between py-2 text-sm">
              <span className="text-slate-800">
                {mem.student?.full_name ?? `Santri #${mem.student_id}`}
                <span className="ml-2 text-xs text-slate-400">{mem.student?.nis ?? ''}</span>
              </span>
              <span className="flex items-center gap-2">
                <span className="text-xs text-slate-500">
                  {mem.is_current ? `sejak ${fmtDate(mem.joined_date)}` : `sampai ${fmtDate(mem.left_date)}`}
                </span>
                <Can perm="boarding.group.manage">
                  <Button variant="ghost" size="icon" title="Lepas" onClick={() => detach.mutate(mem.id)}>
                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                  </Button>
                </Can>
              </span>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  );
}

function GroupTab() {
  const qc = useQueryClient();
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingGroup | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [viewing, setViewing] = useState<BoardingGroup | null>(null);

  const { options: employeeOptions } = useEmployeeOptions();
  const { options: dormitoryOptions } = useDormitoryOptions();

  const listQuery = useQuery({
    queryKey: ['boarding', 'groups'],
    queryFn: () => groupsApi.list({ per_page: 50 }),
  });

  const fields: FIELD_SET = [
    { name: 'code', label: 'Kode', required: true },
    { name: 'name', label: 'Nama', required: true },
    { name: 'musyrif_employee_id', label: 'Musyrif Penanggung Jawab', type: 'search-select', options: employeeOptions },
    { name: 'dormitory_id', label: 'Asrama', type: 'search-select', options: dormitoryOptions },
    { name: 'status', label: 'Status', type: 'select', options: [{ value: 'active', label: 'Aktif' }, { value: 'inactive', label: 'Nonaktif' }] },
    { name: 'description', label: 'Deskripsi', type: 'textarea' },
  ];

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? groupsApi.update(editing.id, payload as never) : groupsApi.create(payload as never),
    onSuccess: () => {
      toast.success('Kelompok disimpan');
      setFormOpen(false);
      setEditing(null);
      qc.invalidateQueries({ queryKey: ['boarding', 'groups'] });
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const rows = (listQuery.data?.rows ?? []) as BoardingGroup[];

  return (
    <div className="space-y-3">
      <div className="flex justify-end">
        <Can perm="boarding.group.manage">
          <Button onClick={() => {
            setEditing(null);
            setErrors({});
            setValues(defaultValues(fields, { status: 'active' }));
            setFormOpen(true);
          }}>
            <Plus className="h-4 w-4" /> Tambah Kelompok
          </Button>
        </Can>
      </div>

      {listQuery.isLoading ? (
        <Card><div className="flex items-center justify-center gap-2 py-14 text-slate-400"><Spinner /> Memuat...</div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada kelompok binaan" /></Card>
      ) : (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {rows.map((g) => (
            <Card key={g.id} className="p-4">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-sm font-semibold text-slate-800">{g.name}</p>
                  <p className="text-xs text-slate-500">{g.code} • {g.dormitory?.name ?? 'semua asrama'}</p>
                </div>
                <Badge tone={g.status === 'active' ? 'green' : 'gray'}>{g.status_label}</Badge>
              </div>
              <p className="mt-2 text-xs text-slate-500">
                Musyrif: {g.musyrif?.full_name ?? '—'} • Anggota: {g.members_count}
              </p>
              <div className="mt-3 flex gap-1">
                <Button size="sm" variant="outline" onClick={() => setViewing(g)}>
                  <Users className="h-3.5 w-3.5" /> Anggota
                </Button>
                <Can perm="boarding.group.manage">
                  <Button size="sm" variant="ghost" onClick={() => {
                    setEditing(g);
                    setErrors({});
                    setValues(defaultValues(fields, g as unknown as Record<string, unknown>));
                    setFormOpen(true);
                  }}>
                    <Pencil className="h-3.5 w-3.5" />
                  </Button>
                </Can>
              </div>
            </Card>
          ))}
        </div>
      )}

      <ConfigFormModal
        open={formOpen}
        title={editing ? 'Edit Kelompok' : 'Tambah Kelompok'}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={errors}
        onChange={setValues}
        onClose={() => { setFormOpen(false); setEditing(null); }}
        onSubmit={() => save.mutate(values)}
      />

      <GroupMembersModal group={viewing} onClose={() => setViewing(null)} />
    </div>
  );
}

export function BoardingCaregiversPage() {
  const [tab, setTab] = useState('musyrifs');
  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Pengasuh & Kelompok Binaan</h1>
        <p className="text-sm text-slate-500">Penugasan musyrif/wali kamar dan kelompok binaan santri.</p>
      </div>
      <Tabs
        tabs={[
          { key: 'musyrifs', label: 'Pengasuh' },
          { key: 'groups', label: 'Kelompok Binaan' },
        ]}
        active={tab}
        onChange={setTab}
      />
      {tab === 'musyrifs' ? <MusyrifTab /> : <GroupTab />}
    </div>
  );
}
