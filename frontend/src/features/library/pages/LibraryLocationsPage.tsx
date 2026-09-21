import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowRightLeft,
  ChevronDown,
  ChevronRight,
  Pencil,
  Plus,
  RefreshCw,
  Trash2,
} from 'lucide-react';
import { toast } from 'sonner';
import { libraryLocationsApi } from '../api/libraryApi';
import { LocationFormModal } from '../components/LocationFormModal';
import { LocationMoveModal } from '../components/LocationMoveModal';
import { useLibraryOptions } from '../hooks/useLibraryOptions';
import { LOCATION_STATUS_LABELS, LOCATION_TYPE_LABELS } from '../lib/labels';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Spinner } from '@/components/ui/Spinner';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { LibraryLocation, LibraryLocationPayload } from '@/types/library';

const toId = (value: string | number | ''): number | '' => (value === '' ? '' : Number(value));

/**
 * Hierarki lokasi perpustakaan (gedung → lantai → ruang).
 *
 * Ditampilkan sebagai pohon yang bisa dibuka/tutup. Setiap simpul mendukung
 * tambah anak, ubah, pindah induk, ubah status, dan hapus.
 */
export function LibraryLocationsPage() {
  const queryClient = useQueryClient();
  const { options: libraryOptions, isLoading: librariesLoading } = useLibraryOptions();

  const [libraryId, setLibraryId] = useState<number | ''>('');
  const [expanded, setExpanded] = useState<Set<number>>(new Set());

  const [formOpen, setFormOpen] = useState(false);
  const [editing, setEditing] = useState<LibraryLocation | null>(null);
  const [formParentId, setFormParentId] = useState<number | ''>('');
  const [serverErrors, setServerErrors] = useState<Record<string, string[]>>({});
  const [moving, setMoving] = useState<LibraryLocation | null>(null);
  const [moveErrors, setMoveErrors] = useState<Record<string, string[]>>({});
  const [deleting, setDeleting] = useState<LibraryLocation | null>(null);

  const treeQuery = useQuery({
    queryKey: ['library', 'locations', libraryId, 'tree'],
    enabled: libraryId !== '',
    queryFn: () => libraryLocationsApi.list(Number(libraryId), { tree: true }),
  });

  const nodes = treeQuery.data?.data ?? [];

  const invalidate = () => queryClient.invalidateQueries({ queryKey: ['library'] });

  const saveMutation = useMutation({
    mutationFn: (payload: LibraryLocationPayload) =>
      editing
        ? libraryLocationsApi.update(Number(libraryId), editing.id, payload)
        : libraryLocationsApi.create(Number(libraryId), payload),
    onSuccess: () => {
      toast.success(editing ? 'Lokasi diperbarui' : 'Lokasi dibuat');
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

  const toggleMutation = useMutation({
    mutationFn: (node: LibraryLocation) =>
      libraryLocationsApi.update(Number(libraryId), node.id, {
        parent_id: node.parent_id ?? '',
        code: node.code,
        name: node.name,
        type: node.type,
        description: node.description ?? '',
        status: node.status === 'active' ? 'inactive' : 'active',
      }),
    onSuccess: () => {
      toast.success('Status lokasi diperbarui');
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const moveMutation = useMutation({
    mutationFn: ({ id, parentId }: { id: number; parentId: number | '' }) =>
      libraryLocationsApi.move(Number(libraryId), id, parentId),
    onSuccess: () => {
      toast.success('Lokasi berhasil dipindahkan');
      setMoving(null);
      setMoveErrors({});
      invalidate();
    },
    onError: (err) => {
      setMoveErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => libraryLocationsApi.remove(Number(libraryId), id),
    onSuccess: () => {
      toast.success('Lokasi dihapus');
      setDeleting(null);
      invalidate();
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const toggleExpand = (id: number) => {
    setExpanded((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const openCreateRoot = () => {
    setEditing(null);
    setFormParentId('');
    setServerErrors({});
    setFormOpen(true);
  };

  const openCreateChild = (parent: LibraryLocation) => {
    setEditing(null);
    setFormParentId(parent.id);
    setServerErrors({});
    setFormOpen(true);
    setExpanded((prev) => new Set(prev).add(parent.id));
  };

  const openEdit = (node: LibraryLocation) => {
    setEditing(node);
    setServerErrors({});
    setFormOpen(true);
  };

  const renderNode = (node: LibraryLocation, depth: number) => {
    const hasChildren = (node.children?.length ?? 0) > 0;
    const isOpen = expanded.has(node.id);

    return (
      <div key={node.id}>
        <div
          className="flex items-center gap-2 border-b border-slate-50 px-3 py-2 last:border-0 hover:bg-slate-50/60"
          style={{ paddingLeft: `${depth * 1.25 + 0.75}rem` }}
        >
          <button
            type="button"
            onClick={() => hasChildren && toggleExpand(node.id)}
            className={
              hasChildren
                ? 'rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600'
                : 'cursor-default p-0.5 text-transparent'
            }
            aria-label={hasChildren ? 'Buka/tutup' : undefined}
          >
            {isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
          </button>

          <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
            <span className="truncate text-sm font-medium text-slate-800">{node.name}</span>
            <span className="font-mono text-xs text-slate-500">{node.code}</span>
            <Badge tone="blue">{LOCATION_TYPE_LABELS[node.type] ?? node.type}</Badge>
            <Badge tone={node.status === 'active' ? 'green' : 'gray'}>
              {LOCATION_STATUS_LABELS[node.status] ?? node.status}
            </Badge>
            {hasChildren && (
              <span className="text-xs text-slate-400">
                {node.children?.length} sub-lokasi
              </span>
            )}
          </div>

          <div className="flex shrink-0 items-center gap-1">
            <Can perm="library.location.create">
              <Button
                variant="ghost"
                size="icon"
                title="Tambah sub-lokasi"
                onClick={() => openCreateChild(node)}
              >
                <Plus className="h-3.5 w-3.5" />
              </Button>
            </Can>
            <Can perm="library.location.update">
              <Button
                variant="ghost"
                size="icon"
                title={node.status === 'active' ? 'Nonaktifkan' : 'Aktifkan'}
                loading={toggleMutation.isPending}
                onClick={() => toggleMutation.mutate(node)}
              >
                <Badge tone={node.status === 'active' ? 'green' : 'gray'} className="px-1.5 py-0">
                  {node.status === 'active' ? 'ON' : 'OFF'}
                </Badge>
              </Button>
              <Button variant="ghost" size="icon" title="Edit" onClick={() => openEdit(node)}>
                <Pencil className="h-3.5 w-3.5" />
              </Button>
              <Button
                variant="ghost"
                size="icon"
                title="Pindahkan"
                onClick={() => {
                  setMoveErrors({});
                  setMoving(node);
                }}
              >
                <ArrowRightLeft className="h-3.5 w-3.5" />
              </Button>
            </Can>
            <Can perm="library.location.delete">
              <Button variant="ghost" size="icon" title="Hapus" onClick={() => setDeleting(node)}>
                <Trash2 className="h-3.5 w-3.5 text-red-500" />
              </Button>
            </Can>
          </div>
        </div>

        {hasChildren && isOpen && node.children?.map((child) => renderNode(child, depth + 1))}
      </div>
    );
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Lokasi Perpustakaan</h1>
          <p className="text-sm text-slate-500">
            Hierarki gedung, lantai, dan ruang untuk menata koleksi dan layanan.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            variant="ghost"
            size="icon"
            title="Muat ulang"
            disabled={libraryId === ''}
            onClick={() => treeQuery.refetch()}
          >
            <RefreshCw className={treeQuery.isFetching ? 'h-4 w-4 animate-spin' : 'h-4 w-4'} />
          </Button>
          <Can perm="library.location.create">
            <Button disabled={libraryId === ''} onClick={openCreateRoot}>
              <Plus className="h-4 w-4" /> Tambah Lokasi
            </Button>
          </Can>
        </div>
      </div>

      <Card className="p-3">
        <div className="max-w-md">
          <label className="mb-1 block text-xs font-medium text-slate-600">Perpustakaan</label>
          <SearchSelect
            options={libraryOptions}
            value={libraryId}
            disabled={librariesLoading}
            onChange={(v) => {
              setLibraryId(toId(v));
              setExpanded(new Set());
            }}
            placeholder="-- Pilih perpustakaan --"
          />
        </div>
      </Card>

      {libraryId === '' ? (
        <Card>
          <EmptyState
            title="Pilih perpustakaan"
            description="Pilih salah satu perpustakaan untuk mengelola lokasinya."
          />
        </Card>
      ) : treeQuery.isLoading ? (
        <Card>
          <div className="flex items-center justify-center gap-2 py-16 text-slate-400">
            <Spinner /> Memuat lokasi...
          </div>
        </Card>
      ) : treeQuery.isError ? (
        <Card>
          <EmptyState
            title="Gagal memuat lokasi"
            description={getErrorMessage(treeQuery.error)}
            action={
              <Button
                size="sm"
                variant="outline"
                className="mt-2"
                onClick={() => treeQuery.refetch()}
              >
                Coba lagi
              </Button>
            }
          />
        </Card>
      ) : nodes.length === 0 ? (
        <Card>
          <EmptyState
            title="Belum ada lokasi"
            description="Tambahkan lokasi akar terlebih dahulu, lalu bangun hierarkinya."
            action={
              <Can perm="library.location.create">
                <Button size="sm" className="mt-2" onClick={openCreateRoot}>
                  <Plus className="h-4 w-4" /> Tambah Lokasi
                </Button>
              </Can>
            }
          />
        </Card>
      ) : (
        <Card className="overflow-hidden">{nodes.map((node) => renderNode(node, 0))}</Card>
      )}

      <LocationFormModal
        open={formOpen}
        editing={editing}
        locations={nodes}
        defaultParentId={formParentId}
        submitting={saveMutation.isPending}
        serverErrors={serverErrors}
        onClose={() => {
          setFormOpen(false);
          setEditing(null);
        }}
        onSubmit={(payload) => saveMutation.mutate(payload)}
      />

      <LocationMoveModal
        open={!!moving}
        location={moving}
        locations={nodes}
        submitting={moveMutation.isPending}
        serverErrors={moveErrors}
        onClose={() => setMoving(null)}
        onSubmit={(parentId) => {
          if (moving) moveMutation.mutate({ id: moving.id, parentId });
        }}
      />

      <ConfirmDialog
        open={!!deleting}
        title="Hapus Lokasi"
        message={`Hapus lokasi "${deleting?.name ?? ''}" (${deleting?.code ?? ''})? Lokasi yang masih punya sub-lokasi tidak dapat dihapus.`}
        loading={deleteMutation.isPending}
        onCancel={() => setDeleting(null)}
        onConfirm={() => {
          if (deleting) deleteMutation.mutate(deleting.id);
        }}
      />
    </div>
  );
}