import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ChevronRight, FolderTree, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { lmsApi } from '../api/lmsApi';
import type { LmsCategory } from '../types';

/** Pengelolaan kategori bertingkat (§4). */
export default function LmsCategoriesPage() {
  const queryClient = useQueryClient();
  const [modal, setModal] = useState<{ open: boolean; category?: LmsCategory }>({ open: false });
  const [name, setName] = useState('');
  const [parentId, setParentId] = useState('');
  const [description, setDescription] = useState('');
  const [status, setStatus] = useState('ACTIVE');

  const categories = useQuery({ queryKey: ['lms', 'categories', 'admin'], queryFn: () => lmsApi.categories() });

  const invalidate = () => {
    queryClient.invalidateQueries({ queryKey: ['lms', 'categories'] });
    queryClient.invalidateQueries({ queryKey: ['lms', 'lookups'] });
  };

  const save = useMutation({
    mutationFn: () =>
      modal.category
        ? lmsApi.updateCategory(modal.category.id, {
            name,
            parent_id: parentId ? Number(parentId) : null,
            description,
            status: status as LmsCategory['status'],
          })
        : lmsApi.createCategory({
            name,
            parent_id: parentId ? Number(parentId) : null,
            description,
            status: status as LmsCategory['status'],
          }),
    onSuccess: () => {
      toast.success(modal.category ? 'Kategori diperbarui' : 'Kategori dibuat');
      setModal({ open: false });
      invalidate();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Gagal menyimpan kategori'),
  });

  const remove = useMutation({
    mutationFn: (id: number) => lmsApi.deleteCategory(id),
    onSuccess: () => {
      toast.success('Kategori dihapus');
      invalidate();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Kategori tidak dapat dihapus'),
  });

  const openModal = (category?: LmsCategory, parent?: LmsCategory) => {
    setModal({ open: true, category });
    setName(category?.name ?? '');
    setParentId(String(category?.parent_id ?? parent?.id ?? ''));
    setDescription(category?.description ?? '');
    setStatus(category?.status ?? 'ACTIVE');
  };

  const tree = categories.data?.tree ?? [];
  const options = categories.data?.options ?? [];

  return (
    <div className="space-y-5">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
            <FolderTree className="h-5 w-5 text-brand-600" aria-hidden="true" /> Kategori LMS
          </h1>
          <p className="text-sm text-slate-500">
            Kategori dapat bertingkat: Matematika → SMA → Matematika X. Urutan menentukan tampilan katalog.
          </p>
        </div>
        <Button size="sm" onClick={() => openModal()}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Kategori baru
        </Button>
      </header>

      {categories.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : tree.length === 0 ? (
        <EmptyState title="Belum ada kategori" description="Mulai dengan kategori tingkat atas seperti “Matematika”." />
      ) : (
        <Card>
          <CardHeader>
            <CardTitle>Struktur kategori</CardTitle>
          </CardHeader>
          <CardBody>
            <ul className="space-y-1">
              {tree.map((category) => (
                <CategoryNode
                  key={category.id}
                  category={category}
                  depth={0}
                  onEdit={openModal}
                  onDelete={(id) => {
                    if (confirm('Hapus kategori ini?')) remove.mutate(id);
                  }}
                />
              ))}
            </ul>
          </CardBody>
        </Card>
      )}

      <Modal
        open={modal.open}
        onClose={() => setModal({ open: false })}
        title={modal.category ? 'Sunting kategori' : 'Kategori baru'}
        footer={
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setModal({ open: false })}>
              Batal
            </Button>
            <Button loading={save.isPending} disabled={name.trim().length < 2} onClick={() => save.mutate()}>
              Simpan
            </Button>
          </div>
        }
      >
        <div className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="lms-cat-name">Nama *</Label>
            <Input id="lms-cat-name" value={name} onChange={(event) => setName(event.target.value)} placeholder="Matematika" />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-cat-parent">Induk kategori</Label>
            <Select id="lms-cat-parent" value={parentId} onChange={(event) => setParentId(event.target.value)}>
              <option value="">— Kategori tingkat atas —</option>
              {options
                .filter((option) => option.id !== modal.category?.id)
                .map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.label}
                  </option>
                ))}
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-cat-description">Deskripsi</Label>
            <Input
              id="lms-cat-description"
              value={description ?? ''}
              onChange={(event) => setDescription(event.target.value)}
            />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-cat-status">Status</Label>
            <Select id="lms-cat-status" value={status} onChange={(event) => setStatus(event.target.value)}>
              <option value="ACTIVE">Aktif</option>
              <option value="INACTIVE">Nonaktif</option>
            </Select>
          </div>
        </div>
      </Modal>
    </div>
  );
}

function CategoryNode({
  category,
  depth,
  onEdit,
  onDelete,
}: {
  category: LmsCategory;
  depth: number;
  onEdit: (category?: LmsCategory, parent?: LmsCategory) => void;
  onDelete: (id: number) => void;
}) {
  const [open, setOpen] = useState(true);
  const children = category.children ?? [];

  return (
    <li>
      <div className="flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50" style={{ paddingLeft: depth * 16 + 8 }}>
        {children.length > 0 ? (
          <button type="button" aria-label={open ? 'Lipat' : 'Buka'} onClick={() => setOpen((value) => !value)} className="text-slate-400">
            {open ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
          </button>
        ) : (
          <span className="w-4" />
        )}
        <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800">{category.name}</span>
        <Badge tone={category.status === 'ACTIVE' ? 'green' : 'gray'}>{category.status === 'ACTIVE' ? 'aktif' : 'nonaktif'}</Badge>
        <Badge tone="gray">{category.courses_count ?? category.course_count ?? 0} course</Badge>
        <Button size="sm" variant="ghost" onClick={() => onEdit(undefined, category)}>
          + Sub
        </Button>
        <Button size="sm" variant="ghost" onClick={() => onEdit(category)}>
          Sunting
        </Button>
        <Button size="icon" variant="ghost" title="Hapus" onClick={() => onDelete(category.id)}>
          <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
        </Button>
      </div>
      {open && children.length > 0 && (
        <ul className="space-y-1">
          {children.map((child) => (
            <CategoryNode key={child.id} category={child} depth={depth + 1} onEdit={onEdit} onDelete={onDelete} />
          ))}
        </ul>
      )}
    </li>
  );
}
