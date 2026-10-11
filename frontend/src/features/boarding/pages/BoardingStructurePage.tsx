import { useState, type ReactNode } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import {
  ConfigFormModal,
  Tabs,
  defaultValues,
  type FormFieldConfig,
  type FormValues,
} from '../components/BoardingUi';
import {
  bedsApi,
  buildingsApi,
  dormitoriesApi,
  floorsApi,
  roomsApi,
} from '../api/boardingApi';
import { BoardingOccupancyTab } from '../components/BoardingOccupancyTab';
import {
  useBuildingOptions,
  useDormitoryOptions,
  useFloorOptions,
  useRoomOptions,
} from '../hooks/useBoardingOptions';
import { BED_STATUS_LABELS, GENDER_LABELS, ROOM_TYPE_LABELS, STRUCTURE_STATUS_LABELS, labelOf, toOptions } from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingListParams } from '@/types/boarding';

type Row = Record<string, unknown> & { id: number };

interface MasterDef {
  key: string;
  label: string;
  perm: string;
  api: {
    list: (p: BoardingListParams) => Promise<{ rows: Row[]; meta?: unknown }>;
    create: (p: never) => Promise<unknown>;
    update: (id: number, p: never) => Promise<unknown>;
    remove: (id: number) => Promise<void>;
  };
  columns: Array<{ key: string; label: string; render?: (row: Row) => ReactNode }>;
  fields: (deps: Record<string, unknown>) => FormFieldConfig[];
  filterFields?: FormFieldConfig[];
}

function MasterTab({ def }: { def: MasterDef }) {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [filterVals, setFilterVals] = useState<FormValues>({});
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<Row | null>(null);
  const [values, setValues] = useState<FormValues>({});
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<Row | null>(null);

  const filter = def.filterFields
    ? Object.fromEntries(
        Object.entries(filterVals).filter(([, v]) => v !== '' && v !== undefined),
      )
    : {};

  const listQuery = useQuery({
    queryKey: ['boarding', 'masters', def.key, page, search, filter],
    queryFn: () => def.api.list({ page, per_page: 15, search: search || undefined, filter }),
  });

  const fields = def.fields(filterVals);

  const invalidate = () => qc.invalidateQueries({ queryKey: ['boarding'] });

  const save = useMutation({
    mutationFn: (payload: FormValues) =>
      editing ? def.api.update(editing.id, payload as never) : def.api.create(payload as never),
    onSuccess: () => {
      toast.success(editing ? 'Data diperbarui' : 'Data dibuat');
      setFormOpen(false);
      setEditing(null);
      setServerErrors({});
      invalidate();
    },
    onError: (err) => {
      setServerErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const remove = useMutation({
    mutationFn: (id: number) => def.api.remove(id),
    onSuccess: () => {
      toast.success('Data dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta as
    | { current_page: number; last_page: number; total: number }
    | undefined;

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex flex-wrap items-center gap-2">
          {def.filterFields?.map((f) => {
            if (f.type === 'search-select' && f.options) {
              return (
                <div key={f.name} className="w-56">
                  <ConfigSelect
                    field={{ ...f, type: 'select' }}
                    value={String(filterVals[f.name] ?? '')}
                    onChange={(v) => {
                      setFilterVals((s) => ({ ...s, [f.name]: v, ...(f.name === 'dormitory_id' ? { building_id: '', floor_id: '' } : {}) }));
                      setPage(1);
                    }}
                  />
                </div>
              );
            }
            return null;
          })}
          <div className="w-56">
            <Input
              value={search}
              placeholder="Cari..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
        </div>
        <Can perm={def.perm}>
          <Button
            onClick={() => {
              setEditing(null);
              setServerErrors({});
              setValues(defaultValues(fields));
              setFormOpen(true);
            }}
          >
            <Plus className="h-4 w-4" /> Tambah {def.label}
          </Button>
        </Can>
      </div>

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-14 text-slate-400">
            <Spinner /> Memuat data...
          </div>
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState title={`Belum ada ${def.label.toLowerCase()}`} />
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  {def.columns.map((c) => (
                    <th key={c.key} className="px-4 py-2 font-medium">{c.label}</th>
                  ))}
                  <th className="w-24 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id} className="border-b border-slate-50 last:border-0">
                    {def.columns.map((c) => (
                      <td key={c.key} className="px-4 py-2 text-slate-700">
                        {c.render ? c.render(row) : String(row[c.key] ?? '—')}
                      </td>
                    ))}
                    <td className="px-4 py-2">
                      <div className="flex gap-1">
                        <Can perm={def.perm}>
                          <Button
                            variant="ghost"
                            size="icon"
                            title="Edit"
                            onClick={() => {
                              setEditing(row);
                              setServerErrors({});
                              setValues(defaultValues(fields, row));
                              setFormOpen(true);
                            }}
                          >
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button variant="ghost" size="icon" title="Hapus" onClick={() => setDeleting(row)}>
                            <Trash2 className="h-3.5 w-3.5 text-red-500" />
                          </Button>
                        </Can>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {meta && meta.last_page > 1 && (
            <div className="flex items-center justify-between px-4 py-3 text-xs text-slate-500">
              <span>Total {meta.total} data</span>
              <div className="flex gap-1">
                <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                  Sebelumnya
                </Button>
                <span className="px-2 py-1">
                  {meta.current_page}/{meta.last_page}
                </span>
                <Button
                  size="sm"
                  variant="outline"
                  disabled={meta.current_page >= meta.last_page}
                  onClick={() => setPage(page + 1)}
                >
                  Berikutnya
                </Button>
              </div>
            </div>
          )}
        </Card>
      )}

      <ConfigFormModal
        open={formOpen}
        title={editing ? `Edit ${def.label}` : `Tambah ${def.label}`}
        fields={fields}
        values={values}
        submitting={save.isPending}
        serverErrors={serverErrors}
        onChange={setValues}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={() => save.mutate(values)}
      />

      <ConfirmDialog
        open={!!deleting}
        title={`Hapus ${def.label}`}
        message={`Hapus data ini? Tindakan tidak bisa dibatalkan.`}
        loading={remove.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
      />
    </div>
  );
}

function ConfigSelect({
  field,
  value,
  onChange,
}: {
  field: FormFieldConfig;
  value: string;
  onChange: (v: string) => void;
}) {
  return (
    <select
      className="h-9 w-full rounded-md border border-slate-200 bg-white px-2 text-sm text-slate-700"
      value={value}
      onChange={(e) => onChange(e.target.value)}
    >
      <option value="">{field.placeholder ?? `-- Semua ${field.label} --`}</option>
      {(field.options ?? []).map((o) => (
        <option key={String(o.value)} value={String(o.value)}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

const statusBadge = (row: Row, map = STRUCTURE_STATUS_LABELS) => {
  const s = String(row.status ?? row.is_active ?? '');
  return <Badge tone={row.is_active === false || s === 'inactive' ? 'gray' : 'green'}>{labelOf(map, (row.status_label as string) ?? s)}</Badge>;
};

export function BoardingStructurePage() {
  const [tab, setTab] = useState('dormitories');

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-lg font-semibold text-slate-900">Struktur Asrama</h1>
        <p className="text-sm text-slate-500">
          Asrama → Gedung → Lantai → Kamar → Tempat tidur.
        </p>
      </div>
      <StructureTabs tab={tab} onTab={setTab} />
    </div>
  );
}

function StructureTabs({ tab, onTab }: { tab: string; onTab: (t: string) => void }) {
  // hook options di level ini agar bisa di-share ke definisi master
  const { options: dormitoryOptions } = useDormitoryOptions();
  const { options: buildingOptions } = useBuildingOptions();
  const { options: floorOptions } = useFloorOptions();
  const { options: roomOptions } = useRoomOptions();

  const defs: Record<string, MasterDef> = {
    dormitories: {
      key: 'dormitories',
      label: 'Asrama',
      perm: 'boarding.dorm.manage',
      api: dormitoriesApi as never,
      columns: [
        { key: 'code', label: 'Kode' },
        { key: 'name', label: 'Nama' },
        { key: 'gender_label', label: 'Jenis' },
        { key: 'rooms_count', label: 'Kamar' },
        { key: 'current_occupancy', label: 'Terisi' },
        { key: 'capacity', label: 'Kapasitas' },
        { key: 'location', label: 'Lokasi' },
        { key: 'status', label: 'Status', render: (r) => statusBadge(r) },
      ],
      fields: () => [
        { name: 'code', label: 'Kode', required: true },
        { name: 'name', label: 'Nama', required: true },
        { name: 'gender', label: 'Jenis Kelamin', type: 'select', options: toOptions(GENDER_LABELS), required: true },
        { name: 'capacity', label: 'Kapasitas', type: 'number' },
        { name: 'location', label: 'Lokasi' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
        { name: 'is_active', label: 'Aktif', type: 'checkbox' },
      ],
    },
    buildings: {
      key: 'buildings',
      label: 'Gedung',
      perm: 'boarding.building.manage',
      api: buildingsApi as never,
      columns: [
        { key: 'code', label: 'Kode' },
        { key: 'name', label: 'Nama' },
        { key: 'dormitory', label: 'Asrama', render: (r) => (r.dormitory as { name: string })?.name ?? '—' },
        { key: 'floors_count', label: 'Lantai' },
        { key: 'current_occupancy', label: 'Terisi' },
        { key: 'status', label: 'Status', render: (r) => statusBadge(r) },
      ],
      fields: () => [
        { name: 'dormitory_id', label: 'Asrama', type: 'search-select', options: dormitoryOptions, required: true },
        { name: 'code', label: 'Kode', required: true },
        { name: 'name', label: 'Nama', required: true },
        { name: 'floor_count', label: 'Jumlah Lantai', type: 'number' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ],
      filterFields: [{ name: 'dormitory_id', label: 'Asrama', placeholder: '-- Semua Asrama --', type: 'select', options: dormitoryOptions }],
    },
    floors: {
      key: 'floors',
      label: 'Lantai',
      perm: 'boarding.floor.manage',
      api: floorsApi as never,
      columns: [
        { key: 'display_name', label: 'Lantai' },
        { key: 'building', label: 'Gedung', render: (r) => (r.building as { name: string })?.name ?? '—' },
        { key: 'rooms_count', label: 'Kamar' },
        { key: 'current_occupancy', label: 'Terisi' },
      ],
      fields: () => [
        { name: 'building_id', label: 'Gedung', type: 'search-select', options: buildingOptions, required: true },
        { name: 'floor_number', label: 'Nomor Lantai', type: 'number', required: true },
        { name: 'name', label: 'Nama (opsional)' },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ],
    },
    rooms: {
      key: 'rooms',
      label: 'Kamar',
      perm: 'boarding.room.manage',
      api: roomsApi as never,
      columns: [
        { key: 'code', label: 'Kode' },
        { key: 'name', label: 'Nama' },
        { key: 'dormitory', label: 'Asrama', render: (r) => (r.dormitory as { name: string })?.name ?? '—' },
        { key: 'floor', label: 'Lantai', render: (r) => (r.floor as { display_name: string })?.display_name ?? '—' },
        { key: 'capacity', label: 'Kapasitas' },
        { key: 'current_occupancy', label: 'Terisi' },
        {
          key: 'room_type',
          label: 'Tipe',
          render: (r) =>
            r.room_type === 'isolation' ? (
              <Badge tone="red">{labelOf(ROOM_TYPE_LABELS, String(r.room_type))}</Badge>
            ) : (
              labelOf(ROOM_TYPE_LABELS, String(r.room_type ?? 'regular'))
            ),
        },
        { key: 'status', label: 'Status', render: (r) => statusBadge(r) },
      ],
      fields: () => [
        { name: 'floor_id', label: 'Lantai', type: 'search-select', options: floorOptions, required: true },
        { name: 'code', label: 'Kode', required: true },
        { name: 'name', label: 'Nama' },
        { name: 'capacity', label: 'Kapasitas', type: 'number', required: true },
        { name: 'room_type', label: 'Tipe Kamar', type: 'select', options: toOptions(ROOM_TYPE_LABELS) },
        { name: 'description', label: 'Deskripsi', type: 'textarea' },
      ],
      filterFields: [{ name: 'dormitory_id', label: 'Asrama', placeholder: '-- Semua Asrama --', type: 'select', options: dormitoryOptions }],
    },
    beds: {
      key: 'beds',
      label: 'Tempat Tidur',
      perm: 'boarding.bed.manage',
      api: bedsApi as never,
      columns: [
        { key: 'display_name', label: 'Kode' },
        { key: 'room', label: 'Kamar', render: (r) => (r.room as { code: string })?.code ?? '—' },
        { key: 'status_label', label: 'Status' },
        {
          key: 'active_placement',
          label: 'Penghuni',
          render: (r) =>
            (r.active_placement as { student?: { full_name?: string } } | null)?.student?.full_name ?? '—',
        },
        { key: 'notes', label: 'Catatan' },
      ],
      fields: () => [
        { name: 'room_id', label: 'Kamar', type: 'search-select', options: roomOptions, required: true },
        { name: 'bed_number', label: 'Nomor', required: true, placeholder: 'mis. 01' },
        { name: 'label', label: 'Label (opsional)' },
        { name: 'status', label: 'Status', type: 'select', options: toOptions(BED_STATUS_LABELS) },
        { name: 'notes', label: 'Catatan', type: 'textarea' },
      ],
    },
  };

  const def = defs[tab];

  return (
    <>
      <Tabs
        tabs={[
          { key: 'dormitories', label: 'Asrama' },
          { key: 'buildings', label: 'Gedung' },
          { key: 'floors', label: 'Lantai' },
          { key: 'rooms', label: 'Kamar' },
          { key: 'beds', label: 'Tempat Tidur' },
          { key: 'occupancy', label: 'Papan Okupansi' },
        ]}
        active={tab}
        onChange={onTab}
      />
      {tab === 'occupancy' ? <BoardingOccupancyTab /> : def && <MasterTab key={def.key} def={def} />}
    </>
  );
}
