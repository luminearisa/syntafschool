import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Power, PowerOff, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryMastersApi } from '../api/libraryApi';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { orDash, toOptions } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import { cn } from '@/lib/utils';
import type {
  LibraryMasterField,
  LibraryMasterGroup,
  LibraryMasterMeta,
  LibraryMasterPayload,
  LibraryMasterRecord,
} from '@/types/libraryMaster';

/**
 * Pesan paling informatif dari sebuah error API.
 *
 * Proteksi hapus mengembalikan alasan spesifik pada `errors.master`
 * (mis. "sudah dipakai oleh 8 nomor klasifikasi"), sedangkan `message`
 * hanya berisi pesan validasi umum — jadi error validasi diutamakan.
 */
function describeError(err: unknown): string {
  const errors = getValidationErrors(err);
  const first = errors ? Object.values(errors)[0] : undefined;

  if (first && first.length > 0) {
    return first[0];
  }

  return getErrorMessage(err);
}

type SortDir = 'asc' | 'desc';
type FieldValue = string | number | boolean;
type FormState = Record<string, FieldValue>;
type ListParams = Parameters<typeof libraryMastersApi.list>[1] & {
  sort_by?: string;
  sort_dir?: SortDir;
};

const STATUS_LABELS = { active: 'Aktif', inactive: 'Tidak Aktif' } as const;
const statusOptions = toOptions(STATUS_LABELS);

const GROUP_LABELS: Record<LibraryMasterGroup, string> = {
  core: 'Master Inti',
  supporting: 'Master Pendukung',
};

const SORT_OPTIONS = [
  { value: 'name', label: 'Nama' },
  { value: 'code', label: 'Kode' },
  { value: 'updated_at', label: 'Diperbarui' },
];

const dateFormatter = new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' });

function formatDate(value?: string | null): string {
  if (!value) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : dateFormatter.format(date);
}

/** Nama relasi tambahan (negara/sistem/penerbit) bila backend mengirimkannya. */
function relationName(row: LibraryMasterRecord): string | null {
  const rel = row.relations;
  if (!rel) return null;
  return rel.country?.name ?? rel.system?.name ?? rel.publisher?.name ?? null;
}

function buildInitialForm(
  meta: LibraryMasterMeta,
  editing: LibraryMasterRecord | null,
): FormState {
  const out: FormState = {};
  for (const field of meta.fields) {
    const raw = editing ? (editing[field.name] as unknown) : undefined;
    switch (field.type) {
      case 'boolean':
        out[field.name] = Boolean(raw ?? false);
        break;
      case 'status':
        out[field.name] = editing ? editing.status : 'active';
        break;
      case 'number':
      case 'relation':
        out[field.name] = raw === null || raw === undefined || raw === '' ? '' : Number(raw);
        break;
      default:
        out[field.name] = raw === null || raw === undefined ? '' : String(raw);
    }
  }
  return out;
}

/* ============================ FIELD RELASI ============================ */

function LibraryRelationSelect({
  value,
  error,
  onChange,
}: {
  value: number | '';
  error?: string;
  onChange: (value: number | '') => void;
}) {
  const { options } = useLibraryOptions();
  return (
    <SearchSelect
      options={options}
      value={value}
      error={error}
      onChange={(v) => onChange(v === '' ? '' : Number(v))}
    />
  );
}

function MasterRelationSelect({
  field,
  value,
  currentKey,
  editingId,
  error,
  onChange,
}: {
  field: LibraryMasterField;
  value: number | '';
  currentKey: string;
  editingId: number | null;
  error?: string;
  onChange: (value: number | '') => void;
}) {
  const relation = field.relation ?? '';
  const { data } = useQuery({
    queryKey: ['library', 'masters', relation, 'options'],
    enabled: !!relation,
    staleTime: 1000 * 60 * 5,
    queryFn: () => libraryMastersApi.list(relation, { all: true }),
  });

  const options = useMemo(
    () =>
      (data?.data ?? [])
        .filter(
          (row) => !(relation === currentKey && editingId !== null && row.id === editingId),
        )
        .map((row) => ({
          value: row.id,
          label: row.code ? `${row.code} — ${row.name}` : row.name,
        })),
    [data, relation, currentKey, editingId],
  );

  return (
    <SearchSelect
      options={options}
      value={value}
      error={error}
      onChange={(v) => onChange(v === '' ? '' : Number(v))}
    />
  );
}

function RelationSelect(props: {
  field: LibraryMasterField;
  value: number | '';
  currentKey: string;
  editingId: number | null;
  error?: string;
  onChange: (value: number | '') => void;
}) {
  if (props.field.relation === 'libraries') {
    return (
      <LibraryRelationSelect value={props.value} error={props.error} onChange={props.onChange} />
    );
  }
  return <MasterRelationSelect {...props} />;
}

/* ============================ FORM MODAL ============================ */

function MasterFormModal({
  open,
  meta,
  editing,
  submitting,
  serverErrors,
  onClose,
  onSubmit,
}: {
  open: boolean;
  meta: LibraryMasterMeta;
  editing: LibraryMasterRecord | null;
  submitting: boolean;
  serverErrors: Record<string, string[]>;
  onClose: () => void;
  onSubmit: (payload: LibraryMasterPayload) => void;
}) {
  const [form, setForm] = useState<FormState>(() => buildInitialForm(meta, editing));

  useEffect(() => {
    if (open) setForm(buildInitialForm(meta, editing));
  }, [open, editing, meta]);

  const setField = (name: string, value: FieldValue) =>
    setForm((prev) => ({ ...prev, [name]: value }));

  const buildPayload = (): LibraryMasterPayload => {
    const payload: LibraryMasterPayload = {
      code: String(form.code ?? ''),
      name: String(form.name ?? ''),
    };
    for (const field of meta.fields) {
      if (field.name === 'code' || field.name === 'name') continue;
      const value = form[field.name];
      switch (field.type) {
        case 'number':
        case 'relation':
          payload[field.name] = value === '' ? '' : Number(value);
          break;
        case 'boolean':
          payload[field.name] = Boolean(value);
          break;
        case 'status':
          payload[field.name] = value as 'active' | 'inactive';
          break;
        default:
          payload[field.name] = value ?? '';
      }
    }
    return payload;
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    onSubmit(buildPayload());
  };

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={editing ? `Ubah ${meta.label}` : `Tambah ${meta.label}`}
      size="lg"
      footer={
        <>
          <Button variant="outline" type="button" onClick={onClose} disabled={submitting}>
            Batal
          </Button>
          <Button type="submit" form="library-master-form" loading={submitting}>
            Simpan
          </Button>
        </>
      }
    >
      <form id="library-master-form" onSubmit={handleSubmit} className="space-y-3">
        {meta.fields.map((field) => {
          const error = serverErrors[field.name]?.[0];
          return (
            <div key={field.name}>
              <Label required={field.required}>{field.label}</Label>
              {field.type === 'textarea' ? (
                <Textarea
                  value={String(form[field.name] ?? '')}
                  error={error}
                  onChange={(e) => setField(field.name, e.target.value)}
                />
              ) : field.type === 'number' ? (
                <Input
                  type="number"
                  value={form[field.name] === '' ? '' : String(form[field.name] ?? '')}
                  error={error}
                  onChange={(e) =>
                    setField(field.name, e.target.value === '' ? '' : Number(e.target.value))
                  }
                />
              ) : field.type === 'boolean' ? (
                <Select
                  value={form[field.name] ? 'true' : 'false'}
                  error={error}
                  onChange={(e) => setField(field.name, e.target.value === 'true')}
                >
                  <option value="true">Ya</option>
                  <option value="false">Tidak</option>
                </Select>
              ) : field.type === 'status' ? (
                <Select
                  value={String(form[field.name] ?? 'active')}
                  error={error}
                  onChange={(e) => setField(field.name, e.target.value)}
                >
                  {statusOptions.map((opt) => (
                    <option key={opt.value} value={opt.value}>
                      {opt.label}
                    </option>
                  ))}
                </Select>
              ) : field.type === 'relation' ? (
                <RelationSelect
                  field={field}
                  value={(form[field.name] ?? '') as number | ''}
                  currentKey={meta.key}
                  editingId={editing?.id ?? null}
                  error={error}
                  onChange={(v) => setField(field.name, v)}
                />
              ) : (
                <Input
                  value={String(form[field.name] ?? '')}
                  error={error}
                  onChange={(e) => setField(field.name, e.target.value)}
                />
              )}
              {field.help && <p className="mt-1 text-xs text-slate-400">{field.help}</p>}
              {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
            </div>
          );
        })}
      </form>
    </Modal>
  );
}

/* ============================ HALAMAN ============================ */

/**
 * Master data Library (18 master) dalam satu antarmuka generik.
 *
 * Definisi kolom & form datang dari `GET library/master-meta`, sehingga master
 * baru cukup ditambah di backend. Master aktif dipilih lewat path
 * `/library/masters/{key}` supaya dapat di-bookmark.
 */
export function LibraryMastersPage() {
  const { master } = useParams<{ master?: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'' | 'active' | 'inactive'>('');
  const [libraryFilter, setLibraryFilter] = useState<number | ''>('');
  const [sortBy, setSortBy] = useState('name');
  const [sortDir, setSortDir] = useState<SortDir>('asc');

  const [selected, setSelected] = useState<number[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryMasterRecord | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryMasterRecord | null>(null);

  const metaQuery = useQuery({
    queryKey: ['library', 'masters', 'meta'],
    queryFn: () => libraryMastersApi.meta(),
  });

  const mastersMeta = metaQuery.data ?? [];
  const activeKey = master ?? mastersMeta[0]?.key ?? '';
  const activeMeta = mastersMeta.find((m) => m.key === activeKey) ?? null;
  const permission = activeMeta?.permission ?? '';

  const { options: libraryOptions } = useLibraryOptions();

  useEffect(() => {
    setPage(1);
    setSearch('');
    setStatusFilter('');
    setLibraryFilter('');
    setSelected([]);
  }, [activeKey]);

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (statusFilter) out.status = statusFilter;
    if (activeMeta?.scoped && libraryFilter !== '') out.library_id = Number(libraryFilter);
    return out;
  }, [statusFilter, libraryFilter, activeMeta?.scoped]);

  const listQuery = useQuery({
    queryKey: ['library', 'masters', activeKey, page, search, filters, sortBy, sortDir],
    enabled: !!activeKey,
    queryFn: () => {
      const params: ListParams = {
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
        sort_by: sortBy,
        sort_dir: sortDir,
      };
      return libraryMastersApi.list(activeKey, params);
    },
  });

  const invalidate = () =>
    queryClient.invalidateQueries({ queryKey: ['library', 'masters'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryMasterPayload) =>
      editing
        ? libraryMastersApi.update(activeKey, editing.id, payload)
        : libraryMastersApi.create(activeKey, payload),
    onSuccess: () => {
      toast.success(
        editing ? `${activeMeta?.label ?? 'Data'} diperbarui` : `${activeMeta?.label ?? 'Data'} dibuat`,
      );
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

  const statusMutation = useMutation({
    mutationFn: ({ row, status }: { row: LibraryMasterRecord; status: 'active' | 'inactive' }) =>
      libraryMastersApi.update(activeKey, row.id, {
        code: row.code,
        name: row.name,
        status,
      }),
    onSuccess: () => {
      toast.success('Status diperbarui');
      invalidate();
    },
    onError: (err) => toast.error(describeError(err)),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => libraryMastersApi.remove(activeKey, id),
    onSuccess: (_res, id) => {
      toast.success('Data dihapus');
      setDeleting(null);
      setSelected((prev) => prev.filter((x) => x !== id));
      invalidate();
    },
    onError: (err) => {
      // Alasan proteksi hapus ditampilkan apa adanya, bukan pesan umum.
      toast.error(describeError(err));
      setDeleting(null);
    },
  });

  const bulkMutation = useMutation({
    mutationFn: ({ ids, status }: { ids: number[]; status: 'active' | 'inactive' }) =>
      libraryMastersApi.bulkStatus(activeKey, { ids, status }),
    onSuccess: (res) => {
      toast.success(res.message);
      setSelected([]);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const listMeta = listQuery.data?.meta;
  const hasRelationColumn = rows.some((row) => relationName(row) !== null);
  const allSelected = rows.length > 0 && rows.every((row) => selected.includes(row.id));

  const grouped = useMemo(() => {
    const groups: { key: LibraryMasterGroup; items: LibraryMasterMeta[] }[] = [
      { key: 'core', items: [] },
      { key: 'supporting', items: [] },
    ];
    for (const m of mastersMeta) {
      const group = groups.find((g) => g.key === m.group);
      if (group) group.items.push(m);
    }
    return groups.filter((g) => g.items.length > 0);
  }, [mastersMeta]);

  const toggleAll = () => {
    if (allSelected) {
      setSelected((prev) => prev.filter((id) => !rows.some((r) => r.id === id)));
    } else {
      setSelected((prev) => [...new Set([...prev, ...rows.map((r) => r.id)])]);
    }
  };

  const toggleRow = (id: number) =>
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));

  const openCreate = () => {
    setEditing(null);
    setServerErrors({});
    setFormOpen(true);
  };

  const openEdit = (row: LibraryMasterRecord) => {
    setEditing(row);
    setServerErrors({});
    setFormOpen(true);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Master Data Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Referensi katalog, koleksi, dan keanggotaan. Master dipilih lewat tautan agar dapat
            di-bookmark.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Link
            to="/library/libraries"
            className="text-xs font-medium text-brand-600 hover:underline"
          >
            Kelola Perpustakaan
          </Link>
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            onClick={() => listQuery.refetch()}
          >
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          {activeMeta && (
            <Can perm={`library.${permission}.create`}>
              <Button onClick={openCreate}>
                <Plus className="h-4 w-4" /> Tambah
              </Button>
            </Can>
          )}
        </div>
      </div>

      {metaQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat master data...
          </div>
        </Card>
      ) : metaQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat master data"
            description={getErrorMessage(metaQuery.error)}
            action={
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => metaQuery.refetch()}
              >
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : mastersMeta.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada master data"
            description="Master data belum diatur di backend."
          />
        </Card>
      ) : !activeMeta ? (
        <Card>
          <EmptyState
            title="Master tidak ditemukan"
            description={`Master "${master ?? ''}" tidak dikenal.`}
            action={
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => navigate('/library/masters')}
              >
                Kembali
              </Button>
            }
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[220px_1fr]">
          <Card className="h-fit p-2">
            <nav className="space-y-3">
              {grouped.map((group) => (
                <div key={group.key}>
                  <p className="px-3 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {GROUP_LABELS[group.key]}
                  </p>
                  <div className="space-y-0.5">
                    {group.items.map((m) => (
                      <button
                        key={m.key}
                        type="button"
                        onClick={() => navigate(`/library/masters/${m.key}`)}
                        className={cn(
                          'w-full rounded-lg px-3 py-2 text-left text-sm transition-colors',
                          m.key === activeKey
                            ? 'bg-brand-50 font-medium text-brand-700'
                            : 'text-slate-600 hover:bg-slate-50',
                        )}
                      >
                        {m.label}
                      </button>
                    ))}
                  </div>
                </div>
              ))}
            </nav>
          </Card>

          <div className="space-y-3">
            <Card className="p-3">
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
                  <Input
                    value={search}
                    placeholder="Cari kode atau nama..."
                    onChange={(e) => {
                      setSearch(e.target.value);
                      setPage(1);
                    }}
                  />
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
                  <Select
                    value={statusFilter}
                    onChange={(e) => {
                      setStatusFilter(e.target.value as '' | 'active' | 'inactive');
                      setPage(1);
                    }}
                  >
                    <option value="">Semua status</option>
                    {statusOptions.map((opt) => (
                      <option key={opt.value} value={opt.value}>
                        {opt.label}
                      </option>
                    ))}
                  </Select>
                </div>
                {activeMeta.scoped && (
                  <div>
                    <label className="mb-1 block text-xs font-medium text-slate-600">
                      Perpustakaan
                    </label>
                    <SearchSelect
                      options={libraryOptions}
                      value={libraryFilter}
                      placeholder="-- Semua --"
                      onChange={(v) => {
                        setLibraryFilter(v === '' ? '' : Number(v));
                        setPage(1);
                      }}
                    />
                  </div>
                )}
                <div>
                  <label className="mb-1 block text-xs font-medium text-slate-600">Urutkan</label>
                  <div className="flex gap-2">
                    <Select
                      value={sortBy}
                      onChange={(e) => {
                        setSortBy(e.target.value);
                        setPage(1);
                      }}
                    >
                      {SORT_OPTIONS.map((opt) => (
                        <option key={opt.value} value={opt.value}>
                          {opt.label}
                        </option>
                      ))}
                    </Select>
                    <Button
                      variant="outline"
                      size="sm"
                      className="shrink-0"
                      title={sortDir === 'asc' ? 'Naik' : 'Turun'}
                      onClick={() => {
                        setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
                        setPage(1);
                      }}
                    >
                      {sortDir === 'asc' ? 'A–Z' : 'Z–A'}
                    </Button>
                  </div>
                </div>
              </div>
            </Card>

            {selected.length > 0 && (
              <Can perm={`library.${permission}.update`}>
                <Card className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                  <span className="text-sm text-slate-600">
                    {selected.length} data dipilih
                  </span>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      loading={bulkMutation.isPending}
                      onClick={() => bulkMutation.mutate({ ids: selected, status: 'active' })}
                    >
                      <Power className="h-3.5 w-3.5" /> Aktifkan
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      loading={bulkMutation.isPending}
                      onClick={() => bulkMutation.mutate({ ids: selected, status: 'inactive' })}
                    >
                      <PowerOff className="h-3.5 w-3.5" /> Nonaktifkan
                    </Button>
                  </div>
                </Card>
              </Can>
            )}

            {listQuery.isLoading ? (
              <Card>
                <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
                  <Spinner /> Memuat data...
                </div>
              </Card>
            ) : listQuery.isError ? (
              <Card>
                <EmptyState
                  title="Gagal memuat data"
                  description={getErrorMessage(listQuery.error)}
                  action={
                    <Button
                      size="sm"
                      variant="outline"
                      className="mt-2"
                      onClick={() => listQuery.refetch()}
                    >
                      Coba lagi
                    </Button>
                  }
                />
              </Card>
            ) : rows.length === 0 ? (
              <Card>
                <EmptyState
                  title="Belum ada data"
                  description={`Tambahkan ${activeMeta.label} pertama.`}
                  action={
                    <Can perm={`library.${permission}.create`}>
                      <Button size="sm" className="mt-2" onClick={openCreate}>
                        <Plus className="h-4 w-4" /> Tambah
                      </Button>
                    </Can>
                  }
                />
              </Card>
            ) : (
              <Card>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                        <th className="w-10 px-4 py-2">
                          <input
                            type="checkbox"
                            className="h-4 w-4 rounded border-slate-300"
                            checked={allSelected}
                            onChange={toggleAll}
                          />
                        </th>
                        <th className="px-4 py-2 font-medium">Kode</th>
                        <th className="px-4 py-2 font-medium">Nama</th>
                        {activeMeta.hierarchical && (
                          <th className="px-4 py-2 font-medium">Induk</th>
                        )}
                        {hasRelationColumn && <th className="px-4 py-2 font-medium">Relasi</th>}
                        <th className="px-4 py-2 font-medium">Status</th>
                        <th className="px-4 py-2 font-medium">Pemakaian</th>
                        <th className="px-4 py-2 font-medium">Diperbarui</th>
                        <th className="w-40 px-4 py-2 font-medium">Aksi</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rows.map((row) => {
                        const usage = row.usage_count ?? 0;
                        const rel = relationName(row);
                        return (
                          <tr key={row.id} className="border-b border-slate-50 last:border-0 align-top">
                            <td className="px-4 py-2">
                              <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-slate-300"
                                checked={selected.includes(row.id)}
                                onChange={() => toggleRow(row.id)}
                              />
                            </td>
                            <td className="px-4 py-2 font-mono text-xs text-slate-600">
                              {orDash(row.code)}
                            </td>
                            <td className="px-4 py-2">
                              <div className="font-medium text-slate-800">{row.name}</div>
                              {row.description && (
                                <div className="mt-0.5 text-xs text-slate-400">
                                  {row.description}
                                </div>
                              )}
                            </td>
                            {activeMeta.hierarchical && (
                              <td className="px-4 py-2 text-slate-600">
                                {orDash(row.parent?.name)}
                              </td>
                            )}
                            {hasRelationColumn && (
                              <td className="px-4 py-2 text-slate-600">{orDash(rel)}</td>
                            )}
                            <td className="px-4 py-2">
                              <Badge tone={row.status === 'active' ? 'green' : 'gray'}>
                                {row.status === 'active' ? 'Aktif' : 'Tidak Aktif'}
                              </Badge>
                            </td>
                            <td className="px-4 py-2 text-slate-700">
                              {usage > 0 ? (
                                <div>
                                  {usage}
                                  <div className="text-xs text-slate-400">dipakai {usage}</div>
                                </div>
                              ) : (
                                <span className="text-slate-400">—</span>
                              )}
                            </td>
                            <td className="px-4 py-2 text-xs text-slate-600">
                              {formatDate(row.updated_at)}
                            </td>
                            <td className="px-4 py-2">
                              <div className="flex flex-wrap items-center gap-1">
                                <Can perm={`library.${permission}.update`}>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Edit"
                                    onClick={() => openEdit(row)}
                                  >
                                    <Pencil className="h-3.5 w-3.5" />
                                  </Button>
                                  {row.status === 'active' && (
                                    <Button
                                      variant="ghost"
                                      size="sm"
                                      title="Nonaktifkan"
                                      loading={statusMutation.isPending}
                                      onClick={() =>
                                        statusMutation.mutate({ row, status: 'inactive' })
                                      }
                                    >
                                      <PowerOff className="h-3.5 w-3.5" /> Nonaktifkan
                                    </Button>
                                  )}
                                </Can>
                                <Can perm={`library.${permission}.delete`}>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Hapus"
                                    onClick={() => setDeleting(row)}
                                  >
                                    <Trash2 className="h-3.5 w-3.5 text-red-500" />
                                  </Button>
                                </Can>
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>

                {listMeta && <Pagination meta={listMeta} onPageChange={setPage} />}
              </Card>
            )}
          </div>
        </div>
      )}

      {activeMeta && (
        <MasterFormModal
          open={formOpen}
          meta={activeMeta}
          editing={editing}
          submitting={saveMutation.isPending}
          serverErrors={serverErrors}
          onClose={() => {
            setFormOpen(false);
            setEditing(null);
          }}
          onSubmit={(payload) => saveMutation.mutate(payload)}
        />
      )}

      <ConfirmDialog
        open={!!deleting}
        title={`Hapus ${activeMeta?.label ?? 'data'}`}
        message={`Hapus "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Tindakan ini tidak bisa dibatalkan.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}