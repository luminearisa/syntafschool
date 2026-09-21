import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { CalendarClock, CreditCard, Pencil, Plus, RefreshCw, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { libraryMastersApi, libraryMemberCardsApi, libraryMembersApi } from '../api/libraryApi';
import { MemberFormModal } from '../components/MemberFormModal';
import { MemberRenewModal } from '../components/MemberRenewModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { LINKED_TYPE_LABELS, MEMBER_STATUS_LABELS, memberStatusTone } from '../lib/catalogLabels';
import { orDash } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { Pagination } from '@/components/common/Pagination';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type {
  LibraryMember,
  LibraryMemberPayload,
  MemberLinkedType,
  MemberStatus,
} from '@/types/libraryCatalog';

const LINKED_TYPE_OPTIONS = Object.keys(LINKED_TYPE_LABELS) as MemberLinkedType[];
const STATUS_OPTIONS = Object.keys(MEMBER_STATUS_LABELS) as MemberStatus[];

function SummaryTile({ label, value, tone }: { label: string; value: number; tone?: string }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
      <div className="text-xs text-slate-500">{label}</div>
      <div className={`mt-0.5 text-lg font-semibold ${tone ?? 'text-slate-900'}`}>{value}</div>
    </div>
  );
}

/**
 * Daftar anggota (keanggotaan) perpustakaan.
 *
 * Satu orang hanya boleh punya satu keanggotaan aktif per perpustakaan.
 * Kedaluwarsa bersifat tidak merusak — hanya mengubah status, bukan menghapus.
 */
export function LibraryMembersPage() {
  const queryClient = useQueryClient();

  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [membershipTypeId, setMembershipTypeId] = useState<number | ''>('');
  const [status, setStatus] = useState<MemberStatus | ''>('');
  const [linkedType, setLinkedType] = useState<MemberLinkedType | ''>('');

  const [selected, setSelected] = useState<number[]>([]);
  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryMember | null>(null);
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [renewing, setRenewing] = useState<LibraryMember | null>(null);
  const [renewErrors, setRenewErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryMember | null>(null);

  const { options: libraryOptions } = useLibraryOptions();

  const membershipTypeQuery = useQuery({
    queryKey: ['library', 'masters', 'membership-types', 'options'],
    staleTime: 1000 * 60 * 10,
    queryFn: () => libraryMastersApi.list('membership-types', { all: true }),
  });

  const membershipTypeOptions = useMemo(
    () =>
      (membershipTypeQuery.data?.data ?? []).map((row) => ({
        value: row.id,
        label: row.code ? `${row.code} — ${row.name}` : row.name,
      })),
    [membershipTypeQuery.data],
  );

  const filters = useMemo(() => {
    const out: Record<string, string | number> = {};
    if (libraryId !== '') out.library_id = Number(libraryId);
    if (membershipTypeId !== '') out.membership_type_id = Number(membershipTypeId);
    if (status) out.status = status;
    return out;
  }, [libraryId, membershipTypeId, status]);

  const listQuery = useQuery({
    queryKey: ['library', 'members', page, search, filters, linkedType],
    queryFn: () =>
      libraryMembersApi.list({
        page,
        per_page: 15,
        search: search || undefined,
        filter: Object.keys(filters).length ? filters : undefined,
        linked_type: linkedType || undefined,
      }),
  });

  const summaryQuery = useQuery({
    queryKey: ['library', 'members', 'summary', libraryId],
    queryFn: () => libraryMembersApi.summary(libraryId),
  });

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library', 'members'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryMemberPayload) =>
      editing ? libraryMembersApi.update(editing.id, payload) : libraryMembersApi.create(payload),
    onSuccess: () => {
      toast.success(editing ? 'Anggota diperbarui' : 'Anggota ditambahkan');
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

  const renewMutation = useMutation({
    mutationFn: ({ id, validUntil, validFrom }: { id: number; validUntil: string; validFrom?: string }) =>
      libraryMembersApi.renew(id, validUntil, validFrom),
    onSuccess: (res) => {
      toast.success(res.message);
      setRenewing(null);
      setRenewErrors({});
      invalidate();
    },
    onError: (err) => {
      setRenewErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => libraryMembersApi.remove(id),
    onSuccess: () => {
      toast.success('Anggota dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => {
      // 422 → anggota masih punya kartu; tampilkan alasan validasi pertama.
      const errors = getValidationErrors(err);
      const first = errors ? Object.values(errors)[0]?.[0] : undefined;
      toast.error(first ?? getErrorMessage(err));
    },
  });

  const evaluateMutation = useMutation({
    mutationFn: () => libraryMembersApi.evaluateStatuses(libraryId),
    onSuccess: (res) => {
      toast.success(`${res.message} (${res.data.expired} anggota)`);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const batchCardMutation = useMutation({
    mutationFn: (ids: number[]) => libraryMemberCardsApi.batch(ids),
    onSuccess: (res) => {
      const { generated, skipped } = res.data;
      toast.success(
        skipped.length > 0
          ? `${generated} kartu diterbitkan, ${skipped.length} dilewati`
          : res.message,
      );
      setSelected([]);
      queryClient.invalidateQueries({ queryKey: ['library', 'member-cards'] });
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.data ?? [];
  const meta = listQuery.data?.meta;
  const summary = summaryQuery.data;

  const allSelected = rows.length > 0 && rows.every((row) => selected.includes(row.id));

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

  const openEdit = (row: LibraryMember) => {
    setEditing(row);
    setServerErrors({});
    setFormOpen(true);
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Anggota Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Keanggotaan per orang dengan masa berlaku dan siklus status yang tidak merusak data.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Can perm="library.membership.update">
            <Button
              variant="outline"
              loading={evaluateMutation.isPending}
              onClick={() => evaluateMutation.mutate()}
            >
              <CalendarClock className="h-4 w-4" /> Sinkronkan Kedaluwarsa
            </Button>
          </Can>
          <Button variant="ghost" size="icon" title="Muat ulang" onClick={() => listQuery.refetch()}>
            <RefreshCw className={listQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.member.create">
            <Button onClick={openCreate}>
              <Plus className="h-4 w-4" /> Tambah Anggota
            </Button>
          </Can>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
        <SummaryTile label="Total" value={summary?.total ?? 0} />
        <SummaryTile label="Aktif" value={summary?.active ?? 0} tone="text-green-600" />
        <SummaryTile label="Kedaluwarsa" value={summary?.expired ?? 0} tone="text-amber-600" />
        <SummaryTile label="Menunggu" value={summary?.pending ?? 0} tone="text-blue-600" />
        <SummaryTile label="Ditangguhkan" value={summary?.suspended ?? 0} tone="text-brand-600" />
      </div>

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Cari</label>
            <Input
              value={search}
              placeholder="No. anggota / nama / email / telepon..."
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
            <SearchSelect
              options={libraryOptions}
              value={libraryId}
              placeholder="-- Semua --"
              onChange={(v) => {
                setLibraryId(v === '' ? '' : Number(v));
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Jenis Keanggotaan</label>
            <SearchSelect
              options={membershipTypeOptions}
              value={membershipTypeId}
              placeholder="-- Semua --"
              onChange={(v) => {
                setMembershipTypeId(v === '' ? '' : Number(v));
                setPage(1);
              }}
            />
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Status</label>
            <Select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value as MemberStatus | '');
                setPage(1);
              }}
            >
              <option value="">Semua status</option>
              {STATUS_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {MEMBER_STATUS_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
          <div>
            <label className="mb-1 block text-xs font-medium text-slate-600">Tipe Tautan</label>
            <Select
              value={linkedType}
              onChange={(e) => {
                setLinkedType(e.target.value as MemberLinkedType | '');
                setPage(1);
              }}
            >
              <option value="">Semua tipe</option>
              {LINKED_TYPE_OPTIONS.map((key) => (
                <option key={key} value={key}>
                  {LINKED_TYPE_LABELS[key]}
                </option>
              ))}
            </Select>
          </div>
        </div>
      </Card>

      {selected.length > 0 && (
        <Can perm="library.member_card.generate">
          <Card className="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
            <span className="text-sm text-slate-600">{selected.length} anggota dipilih</span>
            <Button
              size="sm"
              loading={batchCardMutation.isPending}
              onClick={() => batchCardMutation.mutate(selected)}
            >
              <CreditCard className="h-3.5 w-3.5" /> Terbitkan Kartu
            </Button>
          </Card>
        </Can>
      )}

      {listQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat anggota...
          </div>
        </Card>
      ) : listQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat anggota"
            description={getErrorMessage(listQuery.error)}
            action={
              <Button size="sm" variant="outline" className="mt-2" onClick={() => listQuery.refetch()}>
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : rows.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada anggota"
            description="Tambahkan anggota pertama untuk perpustakaan ini."
            action={
              <Can perm="library.member.create">
                <Button size="sm" className="mt-2" onClick={openCreate}>
                  <Plus className="h-4 w-4" /> Tambah Anggota
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
                  <th className="px-4 py-2 font-medium">No. Anggota</th>
                  <th className="px-4 py-2 font-medium">Nama</th>
                  <th className="px-4 py-2 font-medium">Tipe</th>
                  <th className="px-4 py-2 font-medium">Jenis</th>
                  <th className="px-4 py-2 font-medium">Masa Berlaku</th>
                  <th className="px-4 py-2 font-medium">Status</th>
                  <th className="w-44 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
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
                      {row.member_number}
                    </td>
                    <td className="px-4 py-2">
                      <Link
                        to={`/library/members/${row.id}`}
                        className="font-medium text-brand-700 hover:underline"
                      >
                        {row.display_name ?? row.name ?? '—'}
                      </Link>
                      {row.email && <div className="mt-0.5 text-xs text-slate-400">{row.email}</div>}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone="gray">{LINKED_TYPE_LABELS[row.linked_type] ?? row.linked_type}</Badge>
                    </td>
                    <td className="px-4 py-2 text-slate-600">
                      {row.membership_type?.name ?? '—'}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-600">
                      {orDash(row.valid_from)} – {orDash(row.valid_until)}
                    </td>
                    <td className="px-4 py-2">
                      <Badge tone={memberStatusTone(row.status)}>
                        {MEMBER_STATUS_LABELS[row.status] ?? row.status}
                      </Badge>
                      {row.is_expired && (
                        <div className="mt-0.5 text-xs text-amber-600">lewat masa berlaku</div>
                      )}
                    </td>
                    <td className="px-4 py-2">
                      <div className="flex flex-wrap items-center gap-1">
                        <Link
                          to={`/library/members/${row.id}`}
                          className="rounded-lg px-2 py-1 text-xs font-medium text-brand-700 hover:bg-slate-100"
                        >
                          Detail
                        </Link>
                        <Can perm="library.member.update">
                          <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(row)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                        </Can>
                        <Can perm="library.membership.update">
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                              setRenewErrors({});
                              setRenewing(row);
                            }}
                          >
                            Perpanjang
                          </Button>
                        </Can>
                        <Can perm="library.member.delete">
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

          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <MemberFormModal
        open={formOpen}
        editing={editing}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <MemberRenewModal
        open={!!renewing}
        member={renewing}
        submitting={renewMutation.isPending}
        serverErrors={renewErrors}
        onClose={() => setRenewing(null)}
        onSubmit={(validUntil, validFrom) => {
          if (renewing) renewMutation.mutate({ id: renewing.id, validUntil, validFrom });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Anggota"
        message={`Hapus anggota "${deleting?.display_name ?? deleting?.name ?? ''}" (${deleting?.member_number ?? ''})? Anggota yang masih memiliki kartu tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}