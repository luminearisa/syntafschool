import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Pencil, Plus, Trash2, Trophy } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Modal } from '@/components/ui/Modal';
import { SearchSelect } from '@/components/ui/SearchSelect';
import { Pagination } from '@/components/common/Pagination';
import { Can } from '@/components/common/Can';
import { ConfirmDialog } from '@/components/layout/ConfirmDialog';
import { useStudentOptions } from '@/hooks/useLookupOptions';
import { FormField, StatCard, fmtDate } from '../components/BoardingUi';
import { achievementsApi } from '../api/boardingApi';
import {
  ACHIEVEMENT_CATEGORY_LABELS,
  ACHIEVEMENT_LEVEL_LABELS,
  labelOf,
  toOptions,
} from '../lib/labels';
import { getErrorMessage, getValidationErrors } from '@/lib/axios';
import type { BoardingAchievement } from '@/types/boarding';

const toId = (v: string | number | ''): number | '' => (v === '' ? '' : Number(v));
const today = () => new Date().toISOString().slice(0, 10);

function AchievementModal({
  open,
  onClose,
  editing,
}: {
  open: boolean;
  onClose: () => void;
  editing: BoardingAchievement | null;
}) {
  const qc = useQueryClient();
  const { options: studentOptions } = useStudentOptions();
  const { data: options } = useQuery({
    queryKey: ['boarding', 'achievements', 'options'],
    queryFn: () => achievementsApi.options(),
    staleTime: 10 * 60 * 1000,
  });
  const [studentId, setStudentId] = useState<number | ''>('');
  const [title, setTitle] = useState('');
  const [category, setCategory] = useState('tahfidz');
  const [level, setLevel] = useState('sekolah');
  const [date, setDate] = useState(today());
  const [description, setDescription] = useState('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  useEffect(() => {
    if (!open) return;
    setStudentId(editing?.student_id ?? '');
    setTitle(editing?.title ?? '');
    setCategory(editing?.category ?? 'tahfidz');
    setLevel(editing?.level ?? 'sekolah');
    setDate(editing?.date ?? today());
    setDescription(editing?.description ?? '');
    setErrors({});
  }, [open, editing]);

  const save = useMutation({
    mutationFn: () =>
      editing
        ? achievementsApi.update(editing.id, {
            student_id: editing.student_id,
            title,
            category,
            level,
            date,
            description,
          })
        : achievementsApi.create({ student_id: studentId, title, category, level, date, description }),
    onSuccess: () => {
      toast.success(editing ? 'Prestasi diperbarui' : 'Prestasi dicatat');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      onClose();
    },
    onError: (err) => {
      setErrors(getValidationErrors(err) ?? {});
      toast.error(getErrorMessage(err));
    },
  });

  const categoryOpts = options?.categories ? toOptions(options.categories) : toOptions(ACHIEVEMENT_CATEGORY_LABELS);
  const levelOpts = options?.levels ? toOptions(options.levels) : toOptions(ACHIEVEMENT_LEVEL_LABELS);

  return (
    <Modal open={open} onClose={onClose} title={editing ? 'Koreksi Prestasi' : 'Catat Prestasi (§41)'} size="lg">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <FormField label="Santri" required error={errors.student_id?.[0]}>
          <SearchSelect
            options={studentOptions}
            value={editing ? editing.student_id : studentId}
            onChange={(v) => setStudentId(toId(v))}
            disabled={!!editing}
            placeholder="Cari santri..."
          />
        </FormField>
        <FormField label="Judul prestasi" required error={errors.title?.[0]}>
          <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="mis. Juara 1 MHQ tingkat kota" />
        </FormField>
        <FormField label="Kategori" required error={errors.category?.[0]}>
          <Select value={category} onChange={(e) => setCategory(e.target.value)}>
            {categoryOpts.map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Jenjang" error={errors.level?.[0]}>
          <Select value={level} onChange={(e) => setLevel(e.target.value)}>
            {levelOpts.map((o) => (
              <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
            ))}
          </Select>
        </FormField>
        <FormField label="Tanggal" required error={errors.date?.[0]}>
          <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </FormField>
      </div>
      <FormField label="Deskripsi" className="mt-3" error={errors.description?.[0]}>
        <Textarea rows={3} value={description} onChange={(e) => setDescription(e.target.value)} />
      </FormField>
      <div className="mt-4 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>Batal</Button>
        <Button onClick={() => save.mutate()} disabled={save.isPending || (!editing && studentId === '') || !title || !date}>
          {save.isPending ? <Spinner /> : <Plus className="h-4 w-4" />} Simpan
        </Button>
      </div>
    </Modal>
  );
}

export function BoardingAchievementsPage() {
  const qc = useQueryClient();
  const [page, setPage] = useState(1);
  const [category, setCategory] = useState('');
  const [level, setLevel] = useState('');
  const [keyword, setKeyword] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<BoardingAchievement | null>(null);
  const [deleting, setDeleting] = useState<BoardingAchievement | null>(null);

  const listQuery = useQuery({
    queryKey: ['boarding', 'achievements', page, category, level, keyword],
    queryFn: () =>
      achievementsApi.list({
        page,
        per_page: 15,
        filter: {
          ...(category ? { category } : {}),
          ...(level ? { level } : {}),
          ...(keyword ? { keyword } : {}),
        },
      }),
  });

  const summaryQuery = useQuery({
    queryKey: ['boarding', 'achievements', 'summary', category, level],
    queryFn: () =>
      achievementsApi.summary({
        ...(category ? { category } : {}),
        ...(level ? { level } : {}),
      }),
  });

  const remove = useMutation({
    mutationFn: (id: number) => achievementsApi.remove(id),
    onSuccess: () => {
      toast.success('Prestasi dihapus');
      qc.invalidateQueries({ queryKey: ['boarding'] });
      setDeleting(null);
    },
    onError: (err) => toast.error(getErrorMessage(err)),
  });

  const rows = listQuery.data?.rows ?? [];
  const meta = listQuery.data?.meta;
  const summary = summaryQuery.data;

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">Prestasi Santri</h1>
          <p className="text-sm text-slate-500">Rekaman pencapaian tahfidz, akademik, olahraga, bahasa, dan kepemimpinan (§41).</p>
        </div>
        <Can perm="boarding.achievement.manage">
          <Button onClick={() => { setEditing(null); setModalOpen(true); }}>
            <Trophy className="h-4 w-4" /> Catat Prestasi
          </Button>
        </Can>
      </div>

      {summary && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard label="Total prestasi" value={summary.total} tone="brand" />
          <StatCard
            label="Tahfidz"
            value={summary.by_category['tahfidz'] ?? 0}
          />
          <StatCard
            label="Akademik"
            value={summary.by_category['academic'] ?? 0}
          />
          <StatCard
            label="Tingkat nasional"
            value={summary.by_level['nasional'] ?? 0}
            tone="green"
          />
        </div>
      )}

      <Card className="p-3">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <FormField label="Kategori">
            <Select value={category} onChange={(e) => { setCategory(e.target.value); setPage(1); }}>
              <option value="">Semua kategori</option>
              {toOptions(ACHIEVEMENT_CATEGORY_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Jenjang">
            <Select value={level} onChange={(e) => { setLevel(e.target.value); setPage(1); }}>
              <option value="">Semua jenjang</option>
              {toOptions(ACHIEVEMENT_LEVEL_LABELS).map((o) => (
                <option key={String(o.value)} value={String(o.value)}>{o.label}</option>
              ))}
            </Select>
          </FormField>
          <FormField label="Cari judul">
            <Input value={keyword} onChange={(e) => { setKeyword(e.target.value); setPage(1); }} placeholder="kata kunci judul..." />
          </FormField>
        </div>
      </Card>

      {listQuery.isLoading ? (
        <Card><div className="flex justify-center py-14"><Spinner /></div></Card>
      ) : rows.length === 0 ? (
        <Card><EmptyState title="Belum ada prestasi" /></Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-slate-100 text-left text-xs uppercase tracking-wide text-slate-400">
                  <th className="px-4 py-2 font-medium">Santri</th>
                  <th className="px-4 py-2 font-medium">Prestasi</th>
                  <th className="px-4 py-2 font-medium">Kategori</th>
                  <th className="px-4 py-2 font-medium">Jenjang</th>
                  <th className="px-4 py-2 font-medium">Tanggal</th>
                  <th className="w-28 px-4 py-2 font-medium">Aksi</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.id} className="border-b border-slate-50 last:border-0">
                    <td className="px-4 py-2">
                      <p className="font-medium text-slate-800">{a.student?.full_name ?? `Santri #${a.student_id}`}</p>
                      <p className="text-xs text-slate-400">{a.student?.nis ?? ''}</p>
                    </td>
                    <td className="px-4 py-2 text-slate-700">
                      {a.title}
                      {a.description && <p className="max-w-md truncate text-xs text-slate-400" title={a.description}>{a.description}</p>}
                    </td>
                    <td className="px-4 py-2"><Badge tone="brand">{a.category_label || labelOf(ACHIEVEMENT_CATEGORY_LABELS, a.category)}</Badge></td>
                    <td className="px-4 py-2 text-slate-600">{a.level_label || (a.level ? labelOf(ACHIEVEMENT_LEVEL_LABELS, a.level) : '-')}</td>
                    <td className="px-4 py-2 text-slate-600">{fmtDate(a.date)}</td>
                    <td className="px-4 py-2">
                      <Can perm="boarding.achievement.manage">
                        <div className="flex gap-1">
                          <Button size="sm" variant="ghost" title="Edit" onClick={() => { setEditing(a); setModalOpen(true); }}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button size="sm" variant="ghost" title="Hapus" onClick={() => setDeleting(a)}>
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
          {meta && <Pagination meta={meta} onPageChange={setPage} />}
        </Card>
      )}

      <AchievementModal open={modalOpen} editing={editing} onClose={() => { setModalOpen(false); setEditing(null); }} />
      <ConfirmDialog
        open={!!deleting}
        title="Hapus prestasi"
        message={`Hapus "${deleting?.title}" untuk ${deleting?.student?.full_name ?? ''}?`}
        loading={remove.isPending}
        onConfirm={() => deleting && remove.mutate(deleting.id)}
        onCancel={() => setDeleting(null)}
      />
    </div>
  );
}
