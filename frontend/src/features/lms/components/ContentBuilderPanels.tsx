import { useEffect, useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ChevronDown, ChevronUp, Copy, Eye, EyeOff, GripVertical, Lock, Plus, Trash2, Upload } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Modal } from '@/components/ui/Modal';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { cn } from '@/lib/utils';
import { lmsApi } from '../api/lmsApi';
import { ContentEditor } from './ContentEditor';
import { ItemIcon } from './ItemIcon';
import type {
  ActivityTypeValue,
  CompletionTypeValue,
  ItemTypeValue,
  LearningItemPayload,
  LmsLearningItem,
  LmsLookups,
  LmsSection,
  ResourceTypeValue,
} from '../types';

/**
 * Penyusun struktur course (§10–§14, §40): daftar bagian, penyuntingan bagian,
 * dan modal materi/aktivitas dengan editor konten.
 *
 * Urutan diatur dengan tombol naik/turun yang juga berfungsi sebagai
 * drag-and-drop dasar (HTML5) — cukup untuk guru dan tetap dapat dipakai pada
 * perangkat sentuh.
 */
export function ContentBuilder({
  courseId,
  sections,
  itemsBySection,
  lookups,
  canEdit,
  onChanged,
}: {
  courseId: number;
  sections: LmsSection[];
  itemsBySection: Record<number, LmsLearningItem[]>;
  lookups: LmsLookups;
  canEdit: boolean;
  onChanged: () => void;
}) {
  const queryClient = useQueryClient();
  const [sectionModal, setSectionModal] = useState<{ open: boolean; section?: LmsSection }>({ open: false });
  const [itemModal, setItemModal] = useState<{ open: boolean; sectionId: number | null; item?: LmsLearningItem }>({
    open: false,
    sectionId: null,
  });

  const refresh = () => {
    onChanged();
    queryClient.invalidateQueries({ queryKey: ['lms', 'course', courseId] });
  };

  const removeSection = useMutation({
    mutationFn: (sectionId: number) => lmsApi.deleteSection(sectionId),
    onSuccess: () => {
      toast.success('Bagian dihapus');
      refresh();
    },
    onError: () => toast.error('Bagian gagal dihapus'),
  });

  const duplicateSection = useMutation({
    mutationFn: (sectionId: number) => lmsApi.duplicateSection(sectionId),
    onSuccess: () => {
      toast.success('Bagian diduplikasi');
      refresh();
    },
  });

  const removeItem = useMutation({
    mutationFn: (itemId: number) => lmsApi.deleteItem(itemId),
    onSuccess: () => {
      toast.success('Materi dihapus');
      refresh();
    },
  });

  const duplicateItem = useMutation({
    mutationFn: (itemId: number) => lmsApi.duplicateItem(itemId),
    onSuccess: () => {
      toast.success('Materi diduplikasi');
      refresh();
    },
  });

  const toggleVisibility = useMutation({
    mutationFn: (item: LmsLearningItem) =>
      lmsApi.updateItem(item.id, { visibility: item.visibility === 'VISIBLE' ? 'HIDDEN' : 'VISIBLE' }),
    onSuccess: () => {
      toast.success('Visibilitas materi diperbarui');
      refresh();
    },
  });

  const reorderSections = useMutation({
    mutationFn: (ids: number[]) => lmsApi.reorderSections(courseId, ids),
    onSuccess: () => refresh(),
  });

  const reorderItems = useMutation({
    mutationFn: ({ sectionId, ids }: { sectionId: number; ids: number[] }) => lmsApi.reorderItems(sectionId, ids),
    onSuccess: () => refresh(),
  });

  const moveSection = (index: number, direction: -1 | 1) => {
    const next = [...sections];
    const target = index + direction;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    reorderSections.mutate(next.map((section) => section.id));
  };

  const moveItem = (sectionId: number, index: number, direction: -1 | 1) => {
    const list = [...(itemsBySection[sectionId] ?? [])];
    const target = index + direction;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
    reorderItems.mutate({ sectionId, ids: list.map((item) => item.id) });
  };

  return (
    <div className="space-y-4">
      {sections.length === 0 && (
        <EmptyState
          title="Course belum memiliki bagian"
          description="Mulai dengan membuat bagian pertama (mis. “Pengenalan Aljabar”), lalu tambahkan materi atau aktivitas."
        />
      )}

      {sections.map((section, sectionIndex) => {
        const items = itemsBySection[section.id] ?? [];

        return (
          <section key={section.id} className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <header className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-3">
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-brand-50 text-xs font-semibold text-brand-700">
                {sectionIndex + 1}
              </span>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-slate-900">{section.title}</p>
                <p className="text-[11px] text-slate-500">
                  {items.length} materi • {section.visibility === 'HIDDEN' ? 'disembunyikan' : 'tampil'}
                </p>
              </div>
              {canEdit && (
                <>
                  <Button size="icon" variant="ghost" title="Naikkan" onClick={() => moveSection(sectionIndex, -1)}>
                    <ChevronUp className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button size="icon" variant="ghost" title="Turunkan" onClick={() => moveSection(sectionIndex, 1)}>
                    <ChevronDown className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button size="icon" variant="ghost" title="Duplikasi bagian" onClick={() => duplicateSection.mutate(section.id)}>
                    <Copy className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    title="Sunting bagian"
                    onClick={() => setSectionModal({ open: true, section })}
                  >
                    <GripVertical className="h-4 w-4" aria-hidden="true" />
                  </Button>
                  <Button
                    size="icon"
                    variant="ghost"
                    title="Hapus bagian"
                    onClick={() => {
                      if (confirm(`Hapus bagian "${section.title}" beserta isinya?`)) removeSection.mutate(section.id);
                    }}
                  >
                    <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                  </Button>
                </>
              )}
            </header>

            <ul className="divide-y divide-slate-100">
              {items.length === 0 && (
                <li className="px-4 py-3 text-xs text-slate-500">Belum ada materi pada bagian ini.</li>
              )}
              {items.map((item, itemIndex) => (
                <li key={item.id} className="flex items-center gap-3 px-4 py-2.5">
                  <ItemIcon icon={item.icon ?? item.resource_type ?? item.activity_type ?? 'file'} tone={item.item_type === 'ACTIVITY' ? 'violet' : 'brand'} />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-slate-800">{item.title}</p>
                    <p className="flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                      <span>{item.resource_type_label ?? item.activity_type_label ?? 'Materi'}</span>
                      <span>{item.completion_type_label ?? item.completion_type}</span>
                      {item.is_required ? <Badge tone="gray">wajib</Badge> : <Badge tone="gray">opsional</Badge>}
                      {item.is_preview && <Badge tone="blue">pratinjau</Badge>}
                      {!item.is_required && <Lock className="h-3 w-3" aria-hidden="true" />}
                    </p>
                  </div>
                  {canEdit && (
                    <>
                      <Button size="icon" variant="ghost" title="Naikkan" onClick={() => moveItem(section.id, itemIndex, -1)}>
                        <ChevronUp className="h-4 w-4" aria-hidden="true" />
                      </Button>
                      <Button size="icon" variant="ghost" title="Turunkan" onClick={() => moveItem(section.id, itemIndex, 1)}>
                        <ChevronDown className="h-4 w-4" aria-hidden="true" />
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        title={item.visibility === 'VISIBLE' ? 'Sembunyikan' : 'Tampilkan'}
                        onClick={() => toggleVisibility.mutate(item)}
                      >
                        {item.visibility === 'VISIBLE' ? (
                          <Eye className="h-4 w-4" aria-hidden="true" />
                        ) : (
                          <EyeOff className="h-4 w-4" aria-hidden="true" />
                        )}
                      </Button>
                      <Button size="icon" variant="ghost" title="Duplikasi" onClick={() => duplicateItem.mutate(item.id)}>
                        <Copy className="h-4 w-4" aria-hidden="true" />
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setItemModal({ open: true, sectionId: section.id, item })}
                      >
                        Sunting
                      </Button>
                      <Button
                        size="icon"
                        variant="ghost"
                        title="Hapus"
                        onClick={() => {
                          if (confirm(`Hapus "${item.title}"?`)) removeItem.mutate(item.id);
                        }}
                      >
                        <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                      </Button>
                    </>
                  )}
                </li>
              ))}
            </ul>

            {canEdit && (
              <footer className="flex flex-wrap gap-2 border-t border-slate-100 px-4 py-3">
                <Button size="sm" variant="outline" onClick={() => setItemModal({ open: true, sectionId: section.id })}>
                  <Plus className="h-4 w-4" aria-hidden="true" /> Tambah materi
                </Button>
              </footer>
            )}
          </section>
        );
      })}

      {canEdit && (
        <Button variant="outline" onClick={() => setSectionModal({ open: true })}>
          <Plus className="h-4 w-4" aria-hidden="true" /> Tambah bagian
        </Button>
      )}

      <SectionModal
        courseId={courseId}
        open={sectionModal.open}
        section={sectionModal.section}
        onClose={() => setSectionModal({ open: false })}
        onSaved={refresh}
      />

      <ItemModal
        open={itemModal.open}
        sectionId={itemModal.sectionId}
        item={itemModal.item}
        lookups={lookups}
        onClose={() => setItemModal({ open: false, sectionId: null })}
        onSaved={refresh}
      />
    </div>
  );
}

function SectionModal({
  courseId,
  open,
  section,
  onClose,
  onSaved,
}: {
  courseId: number;
  open: boolean;
  section?: LmsSection;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [title, setTitle] = useState(section?.title ?? '');
  const [summary, setSummary] = useState(section?.summary ?? '');
  const [visibility, setVisibility] = useState<'VISIBLE' | 'HIDDEN'>(section?.visibility ?? 'VISIBLE');
  const [availableFrom, setAvailableFrom] = useState(section?.available_from?.slice(0, 16) ?? '');

  useEffect(() => {
    setTitle(section?.title ?? '');
    setSummary(section?.summary ?? '');
    setVisibility(section?.visibility ?? 'VISIBLE');
    setAvailableFrom(section?.available_from?.slice(0, 16) ?? '');
  }, [section, open]);

  const save = useMutation({
    mutationFn: () =>
      section
        ? lmsApi.updateSection(section.id, {
            title,
            summary,
            visibility,
            available_from: availableFrom || null,
          })
        : lmsApi.createSection(courseId, {
            title,
            summary,
            visibility,
            available_from: availableFrom || null,
          }),
    onSuccess: () => {
      toast.success(section ? 'Bagian diperbarui' : 'Bagian ditambahkan');
      onSaved();
      onClose();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Gagal menyimpan bagian'),
  });

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={section ? 'Sunting bagian' : 'Bagian baru'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button loading={save.isPending} disabled={title.trim().length < 2} onClick={() => save.mutate()}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        <div className="space-y-1.5">
          <Label htmlFor="lms-section-title">Judul bagian *</Label>
          <Input id="lms-section-title" value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Pengenalan Aljabar" />
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="lms-section-summary">Ringkasan</Label>
          <Textarea id="lms-section-summary" rows={2} value={summary ?? ''} onChange={(event) => setSummary(event.target.value)} />
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="lms-section-visibility">Visibilitas</Label>
            <Select
              id="lms-section-visibility"
              value={visibility}
              onChange={(event) => setVisibility(event.target.value as 'VISIBLE' | 'HIDDEN')}
            >
              <option value="VISIBLE">Tampil</option>
              <option value="HIDDEN">Disembunyikan</option>
            </Select>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-section-available">Dibuka pada</Label>
            <Input
              id="lms-section-available"
              type="datetime-local"
              value={availableFrom}
              onChange={(event) => setAvailableFrom(event.target.value)}
            />
          </div>
        </div>
      </div>
    </Modal>
  );
}

function ItemModal({
  open,
  sectionId,
  item,
  lookups,
  onClose,
  onSaved,
}: {
  open: boolean;
  sectionId: number | null;
  item?: LmsLearningItem;
  lookups: LmsLookups;
  onClose: () => void;
  onSaved: () => void;
}) {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<LearningItemPayload>({
    title: '',
    item_type: 'RESOURCE',
    resource_type: 'PAGE',
    activity_type: null,
    description: '',
    content: '',
    external_url: '',
    open_in: 'EMBED',
    visibility: 'VISIBLE',
    is_required: true,
    completion_type: 'VIEW',
    is_preview: false,
    require_previous_item: false,
    chapters: [],
  });
  const [file, setFile] = useState<File | null>(null);

  useEffect(() => {
    if (!open) return;
    if (item) {
      setForm({
        title: item.title,
        item_type: item.item_type,
        resource_type: item.resource_type,
        activity_type: item.activity_type,
        description: item.description ?? '',
        content: item.resource?.content ?? '',
        external_url: item.resource?.external_url ?? '',
        open_in: item.resource?.open_in ?? 'EMBED',
        visibility: item.visibility,
        is_required: item.is_required,
        completion_type: item.completion_type,
        completion_min_grade: item.completion_min_grade,
        is_preview: item.is_preview,
        estimated_minutes: item.estimated_minutes,
        require_previous_item: Boolean(item.availability?.require_previous_item),
        chapters: item.chapters?.map((chapter) => ({ id: chapter.id, title: chapter.title, content: chapter.content })) ?? [],
      });
    } else {
      setForm((current) => ({ ...current, title: '', content: '', description: '', external_url: '', chapters: [] }));
    }
    setFile(null);
  }, [item, open]);

  const update = <K extends keyof LearningItemPayload>(key: K, value: LearningItemPayload[K]) =>
    setForm((current) => ({ ...current, [key]: value }));

  const save = useMutation({
    mutationFn: async () => {
      const payload: LearningItemPayload = { ...form };
      if (payload.item_type === 'RESOURCE' && payload.resource_type !== 'BOOK') {
        payload.chapters = undefined;
      }
      const saved = item ? await lmsApi.updateItem(item.id, payload) : await lmsApi.createItem(sectionId as number, payload);
      if (file) await lmsApi.uploadItemFile(saved.id, file);
      return saved;
    },
    onSuccess: () => {
      toast.success(item ? 'Materi diperbarui' : 'Materi ditambahkan');
      queryClient.invalidateQueries({ queryKey: ['lms'] });
      onSaved();
      onClose();
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Gagal menyimpan materi'),
  });

  const isResource = form.item_type === 'RESOURCE';

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="xl"
      title={item ? 'Sunting materi' : 'Materi / aktivitas baru'}
      footer={
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>
            Batal
          </Button>
          <Button loading={save.isPending} disabled={(form.title ?? '').trim().length < 2} onClick={() => save.mutate()}>
            Simpan
          </Button>
        </div>
      }
    >
      <div className="space-y-4">
        <div className="grid gap-4 sm:grid-cols-3">
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="lms-item-title">Judul *</Label>
            <Input id="lms-item-title" value={form.title} onChange={(event) => update('title', event.target.value)} />
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="lms-item-type">Jenis</Label>
            <Select
              id="lms-item-type"
              value={form.item_type}
              onChange={(event) => {
                const value = event.target.value as ItemTypeValue;
                update('item_type', value);
                update('completion_type', value === 'ACTIVITY' ? 'SUBMIT' : 'VIEW');
              }}
            >
              {lookups.enums.item_type.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>

          {isResource ? (
            <div className="space-y-1.5">
              <Label htmlFor="lms-item-resource">Tipe materi</Label>
              <Select
                id="lms-item-resource"
                value={form.resource_type ?? 'PAGE'}
                onChange={(event) => update('resource_type', event.target.value as ResourceTypeValue)}
              >
                {lookups.enums.resource_type.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </div>
          ) : (
            <div className="space-y-1.5">
              <Label htmlFor="lms-item-activity">Jenis aktivitas</Label>
              <Select
                id="lms-item-activity"
                value={form.activity_type ?? 'ASSIGNMENT'}
                onChange={(event) => update('activity_type', event.target.value as ActivityTypeValue)}
              >
                {lookups.enums.activity_type.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </Select>
            </div>
          )}

          <div className="space-y-1.5">
            <Label htmlFor="lms-item-completion">Aturan penyelesaian</Label>
            <Select
              id="lms-item-completion"
              value={form.completion_type ?? 'VIEW'}
              onChange={(event) => update('completion_type', event.target.value as CompletionTypeValue)}
            >
              {lookups.enums.completion_type.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="lms-item-visibility">Visibilitas</Label>
            <Select
              id="lms-item-visibility"
              value={form.visibility}
              onChange={(event) => update('visibility', event.target.value as 'VISIBLE' | 'HIDDEN')}
            >
              <option value="VISIBLE">Tampil</option>
              <option value="HIDDEN">Disembunyikan</option>
            </Select>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="lms-item-duration">Estimasi menit</Label>
            <Input
              id="lms-item-duration"
              type="number"
              min={1}
              value={form.estimated_minutes ?? ''}
              onChange={(event) => update('estimated_minutes', event.target.value ? Number(event.target.value) : null)}
            />
          </div>

          {form.completion_type === 'PASS' && (
            <div className="space-y-1.5">
              <Label htmlFor="lms-item-grade">Nilai minimum</Label>
              <Input
                id="lms-item-grade"
                type="number"
                min={0}
                max={100}
                value={form.completion_min_grade ?? ''}
                onChange={(event) =>
                  update('completion_min_grade', event.target.value ? Number(event.target.value) : null)
                }
              />
            </div>
          )}
        </div>

        <div className="flex flex-wrap gap-4 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={Boolean(form.is_required)}
              onChange={(event) => update('is_required', event.target.checked)}
            />
            Wajib (dihitung dalam progress)
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={Boolean(form.is_preview)}
              onChange={(event) => update('is_preview', event.target.checked)}
            />
            Boleh dipratinjau tamu
          </label>
          <label className="flex items-center gap-2">
            <input
              type="checkbox"
              className="h-4 w-4 rounded border-slate-300"
              checked={Boolean(form.require_previous_item)}
              onChange={(event) => update('require_previous_item', event.target.checked)}
            />
            Terkunci sampai materi sebelumnya selesai
          </label>
        </div>

        {isResource && (form.resource_type === 'PAGE' || form.resource_type === 'TEXT') && (
          <ContentEditor value={form.content ?? ''} onChange={(value) => update('content', value)} />
        )}

        {isResource && (form.resource_type === 'URL' || form.resource_type === 'VIDEO' || form.resource_type === 'AUDIO') && (
          <div className="space-y-1.5">
            <Label htmlFor="lms-item-url">URL eksternal</Label>
            <Input
              id="lms-item-url"
              value={form.external_url ?? ''}
              onChange={(event) => update('external_url', event.target.value)}
              placeholder="https://www.youtube.com/watch?v=…"
            />
            <p className="text-[11px] text-slate-500">
              Video YouTube/Vimeo/Drive otomatis diubah menjadi embed; berkas besar sebaiknya dititipkan ke CDN.
            </p>
          </div>
        )}

        {isResource && form.resource_type === 'FILE' && (
          <div className="space-y-1.5">
            <Label htmlFor="lms-item-file">Berkas materi</Label>
            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-slate-300 px-3 py-3 text-sm text-slate-600 hover:border-brand-400">
              <Upload className="h-4 w-4" aria-hidden="true" />
              {file ? file.name : 'Pilih berkas (PDF, DOCX, PPTX, gambar, audio, video…)'}
              <input
                id="lms-item-file"
                type="file"
                className="hidden"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
              />
            </label>
            {item?.resource?.file_name && (
              <p className="text-[11px] text-slate-500">Berkas tersimpan: {item.resource.file_name}</p>
            )}
          </div>
        )}

        {isResource && form.resource_type === 'BOOK' && (
          <div className="space-y-2">
            <Label>Bab buku</Label>
            {(form.chapters ?? []).map((chapter, index) => (
              <div key={index} className="flex items-center gap-2">
                <Input
                  value={chapter.title}
                  placeholder={`Bab ${index + 1}`}
                  onChange={(event) => {
                    const chapters = [...(form.chapters ?? [])];
                    chapters[index] = { ...chapters[index], title: event.target.value };
                    update('chapters', chapters);
                  }}
                />
                <Button
                  size="icon"
                  variant="ghost"
                  title="Hapus bab"
                  onClick={() => update('chapters', (form.chapters ?? []).filter((_, position) => position !== index))}
                >
                  <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                </Button>
              </div>
            ))}
            <Button
              size="sm"
              variant="outline"
              onClick={() => update('chapters', [...(form.chapters ?? []), { title: '', content: '' }])}
            >
              <Plus className="h-4 w-4" aria-hidden="true" /> Tambah bab
            </Button>
          </div>
        )}

        {form.activity_type && (
          <p className={cn('rounded-xl border px-3 py-2 text-xs', 'border-violet-200 bg-violet-50 text-violet-800')}>
            Aktivitas {form.activity_type} tersimpan sebagai bagian struktur course. Implementasi pengumpulan, kuis, dan
            penilaiannya dilanjutkan pada phase berikutnya; tautan ke aktivitas ERP dapat dipasang lewat
            <code className="mx-1 rounded bg-white/70 px-1">external_activity_id</code>.
          </p>
        )}
      </div>
    </Modal>
  );
}
