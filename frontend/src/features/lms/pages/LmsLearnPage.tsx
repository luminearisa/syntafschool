import { useCallback, useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  Check,
  ChevronLeft,
  Download,
  ExternalLink,
  List,
  Lock,
  Menu,
  PlayCircle,
  X,
} from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/utils';
import { lmsApi } from '../api/lmsApi';
import { ItemIcon } from '../components/ItemIcon';
import { ProgressBar } from '../components/ProgressBar';
import { sanitizeHtml } from '../lib/sanitizeHtml';
import { formatDate } from '../lib/lmsLabels';

/**
 * Pengalaman belajar (§39): sidebar materi, isi materi, Previous/Next,
 * Mark as Complete, dan alasan terkunci yang jelas.
 *
 * Tata letak: desktop = sidebar + konten; tablet = sidebar dapat dilipat;
 * mobile = navigasi atas + bilah aksi melekat di bawah (§41).
 */
export default function LmsLearnPage() {
  const { courseId, itemId } = useParams<{ courseId: string; itemId?: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [sidebarOpen, setSidebarOpen] = useState(false);

  const numericCourseId = Number(courseId);

  const player = useQuery({
    queryKey: ['lms', 'player', courseId],
    queryFn: () => lmsApi.player(numericCourseId),
    enabled: Boolean(courseId),
  });

  const flatItems = useMemo(() => player.data?.structure.flatMap((section) => section.items) ?? [], [player.data]);

  const activeItemId = useMemo(() => {
    if (itemId) return Number(itemId);
    const next = player.data?.resume.next_item_id;
    if (next) return next;
    return flatItems.find((item) => !item.locked)?.id ?? null;
  }, [itemId, player.data, flatItems]);

  const itemQuery = useQuery({
    queryKey: ['lms', 'item', courseId, activeItemId],
    queryFn: () => lmsApi.learningItem(numericCourseId, activeItemId as number),
    enabled: Boolean(activeItemId) && Boolean(courseId),
  });

  // Catat materi dibuka; tipe VIEW otomatis tuntas dan progress ikut diperbarui.
  const trackView = useMutation({
    mutationFn: (id: number) => lmsApi.trackView(numericCourseId, id),
    onSuccess: (result) => {
      if (result.auto_completed) {
        queryClient.invalidateQueries({ queryKey: ['lms', 'player', courseId] });
      }
    },
  });

  useEffect(() => {
    if (itemQuery.data && activeItemId) {
      trackView.mutate(activeItemId);
    }
    setSidebarOpen(false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeItemId]);

  const complete = useMutation({
    mutationFn: ({ id, grade }: { id: number; grade?: number }) => lmsApi.completeItem(numericCourseId, id, grade),
    onSuccess: (result) => {
      toast.success('Materi ditandai selesai');
      queryClient.invalidateQueries({ queryKey: ['lms', 'player', courseId] });
      queryClient.invalidateQueries({ queryKey: ['lms', 'item', courseId] });
      if (result.next) {
        navigate(`/lms/courses/${courseId}/learn/${result.next.id}`);
      }
    },
    onError: (error: { response?: { data?: { message?: string } } }) =>
      toast.error(error.response?.data?.message ?? 'Gagal menandai selesai'),
  });

  const uncomplete = useMutation({
    mutationFn: (id: number) => lmsApi.uncompleteItem(numericCourseId, id),
    onSuccess: () => {
      toast.success('Tanda selesai dibatalkan');
      queryClient.invalidateQueries({ queryKey: ['lms', 'player', courseId] });
      queryClient.invalidateQueries({ queryKey: ['lms', 'item', courseId] });
    },
  });

  const selectItem = useCallback(
    (id: number) => {
      navigate(`/lms/courses/${courseId}/learn/${id}`);
    },
    [courseId, navigate],
  );

  if (player.isLoading) {
    return (
      <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
        <Spinner className="h-6 w-6" /> Menyiapkan ruang belajar…
      </div>
    );
  }

  if (player.isError || !player.data) {
    return (
      <EmptyState
        title="Course ini belum dapat dipelajari"
        description="Pastikan Anda terdaftar dan course sudah diterbitkan."
        action={
          <Link to={`/lms/courses/${courseId}`}>
            <Button size="sm">Lihat halaman course</Button>
          </Link>
        }
      />
    );
  }

  const { course, structure, progress, capabilities } = player.data;
  const item = itemQuery.data?.item;

  return (
    <div className="lms-learn -mx-4 -my-6 flex min-h-[calc(100vh-4rem)] flex-col lg:mx-0 lg:my-0 lg:flex-row lg:gap-6">
      {/* Sidebar materi */}
      <aside
        className={cn(
          'lms-learn-sidebar border-b border-slate-200 bg-white lg:sticky lg:top-0 lg:h-[calc(100vh-4rem)] lg:w-80 lg:shrink-0 lg:overflow-y-auto lg:border-b-0 lg:border-r',
          sidebarOpen ? 'block' : 'hidden lg:block',
        )}
      >
        <div className="space-y-4 p-4">
          <div>
            <Link to={`/lms/courses/${course.id}`} className="text-xs font-medium text-brand-700 hover:underline">
              ← {course.code}
            </Link>
            <h2 className="mt-1 text-sm font-semibold text-slate-900">{course.name}</h2>
            <p className="text-[11px] text-slate-500">
              {course.teachers?.join(', ') || 'LMS'}
              {course.start_date ? ` • ${formatDate(course.start_date)}` : ''}
            </p>
          </div>
          <div>
            <ProgressBar value={progress.progress_percent} showLabel />
            <p className="mt-1 text-[11px] text-slate-500">
              {progress.completed_items}/{progress.required_items} materi wajib
            </p>
          </div>

          <nav className="space-y-3">
            {structure.map((section, index) => (
              <div key={section.id}>
                <p className="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                  <span className="inline-flex h-5 w-5 items-center justify-center rounded bg-slate-100 text-[10px]">
                    {index + 1}
                  </span>
                  <span className="truncate">{section.title}</span>
                </p>
                <ul className="mt-1 space-y-0.5">
                  {section.items.map((row) => (
                    <li key={row.id}>
                      <button
                        type="button"
                        disabled={row.locked && !capabilities.manage}
                        onClick={() => selectItem(row.id)}
                        className={cn(
                          'flex w-full items-center gap-2 rounded-lg px-2 py-2 text-left text-xs transition',
                          row.id === activeItemId ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-50',
                          row.locked && !capabilities.manage && 'cursor-not-allowed opacity-60',
                        )}
                        title={row.lock_reason ?? undefined}
                      >
                        <ItemIcon icon={row.icon} tone={row.completed ? 'brand' : row.locked ? 'amber' : 'slate'} className="h-6 w-6" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate font-medium">{row.title}</span>
                          {row.locked && row.lock_reason && (
                            <span className="block truncate text-[10px] text-amber-700">🔒 {row.lock_reason}</span>
                          )}
                        </span>
                        {row.completed && <Check className="h-3.5 w-3.5 text-emerald-500" aria-hidden="true" />}
                      </button>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </nav>
        </div>
      </aside>

      {/* Konten */}
      <main className="min-w-0 flex-1 pb-24 lg:pb-6">
        <header className="flex items-center gap-2 border-b border-slate-200 bg-white px-4 py-2.5 lg:hidden">
          <Button variant="ghost" size="icon" aria-label="Daftar materi" onClick={() => setSidebarOpen((open) => !open)}>
            {sidebarOpen ? <X className="h-5 w-5" aria-hidden="true" /> : <Menu className="h-5 w-5" aria-hidden="true" />}
          </Button>
          <span className="min-w-0 flex-1 truncate text-sm font-medium text-slate-800">{item?.title ?? 'Materi'}</span>
          <Badge tone="brand">{Math.round(progress.progress_percent)}%</Badge>
        </header>

        <div className="px-4 py-5 lg:px-0">
          {itemQuery.isLoading || !item ? (
            <div className="flex flex-col items-center gap-3 py-16 text-sm text-slate-500">
              <Spinner className="h-6 w-6" /> Memuat materi…
            </div>
          ) : (
            <article className="space-y-4">
              <header className="space-y-2">
                <div className="flex flex-wrap items-center gap-2">
                  <ItemIcon icon={item.resource_type ?? item.activity_type ?? 'file'} tone="brand" />
                  <h1 className="text-lg font-semibold text-slate-900">{item.title}</h1>
                  {item.completion_type !== 'NONE' && (
                    <Badge tone={itemQuery.data?.completed ? 'green' : 'gray'}>
                      {itemQuery.data?.completed ? 'Selesai' : (item.completion_type_label ?? item.completion_type)}
                    </Badge>
                  )}
                  {item.estimated_minutes && <Badge tone="gray">{item.estimated_minutes} menit</Badge>}
                </div>
                {item.description && (
                  <div
                    className="lms-rich-text text-sm text-slate-600"
                    dangerouslySetInnerHTML={{ __html: sanitizeHtml(item.description) }}
                  />
                )}
              </header>

              <section className="lms-item-content rounded-2xl border border-slate-200 bg-white p-4 lg:p-6">
                <ItemContent item={item} />
              </section>

              <footer className="flex flex-wrap items-center justify-between gap-2">
                <Button
                  variant="outline"
                  disabled={!itemQuery.data?.navigation.previous}
                  onClick={() =>
                    itemQuery.data?.navigation.previous &&
                    selectItem(itemQuery.data.navigation.previous.id)
                  }
                >
                  <ChevronLeft className="h-4 w-4" aria-hidden="true" /> Sebelumnya
                </Button>

                <div className="flex items-center gap-2">
                  {capabilities.read_only ? (
                    <Badge tone="blue">Mode baca wali</Badge>
                  ) : itemQuery.data?.completed ? (
                    <Button variant="ghost" loading={uncomplete.isPending} onClick={() => uncomplete.mutate(item.id)}>
                      Batalkan tanda selesai
                    </Button>
                  ) : (
                    itemQuery.data?.completion.can_mark_complete && (
                      <Button loading={complete.isPending} onClick={() => complete.mutate({ id: item.id })}>
                        <Check className="h-4 w-4" aria-hidden="true" /> Tandai selesai
                      </Button>
                    )
                  )}
                </div>

                <Button
                  disabled={!itemQuery.data?.navigation.next}
                  onClick={() => itemQuery.data?.navigation.next && selectItem(itemQuery.data.navigation.next.id)}
                >
                  Selanjutnya <ArrowRight className="h-4 w-4" aria-hidden="true" />
                </Button>
              </footer>
            </article>
          )}
        </div>

        {/* Bilah progress melekat di mobile (§41) */}
        <div className="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white/95 px-4 py-2 backdrop-blur lg:hidden">
          <div className="flex items-center gap-3">
            <List className="h-4 w-4 text-slate-400" aria-hidden="true" />
            <ProgressBar value={progress.progress_percent} size="sm" className="flex-1" />
            <span className="text-[11px] font-medium text-slate-600">
              {progress.completed_items}/{progress.required_items}
            </span>
          </div>
        </div>
      </main>
    </div>
  );
}

function ItemContent({ item }: { item: NonNullable<Awaited<ReturnType<typeof lmsApi.learningItem>>['item']> }) {
  const resource = item.resource;

  if (item.item_type === 'ACTIVITY') {
    return (
      <div className="space-y-3 text-sm text-slate-700">
        <p className="rounded-xl border border-violet-200 bg-violet-50 px-3 py-2 text-xs text-violet-800">
          Aktivitas <strong>{item.activity_type_label ?? item.activity_type}</strong> — pengumpulan, kuis, dan penilaian
          interaktif hadir pada phase berikutnya. Instruksi dari guru tetap dapat dibaca di sini.
        </p>
        {item.description ? (
          <div className="lms-rich-text" dangerouslySetInnerHTML={{ __html: sanitizeHtml(item.description) }} />
        ) : (
          <p className="text-slate-500">Belum ada instruksi.</p>
        )}
      </div>
    );
  }

  if (!resource) {
    return <p className="text-sm text-slate-500">Materi ini belum memiliki isi.</p>;
  }

  switch (item.resource_type) {
    case 'VIDEO':
    case 'AUDIO':
      if (resource.embed_url) {
        return (
          <div className="aspect-video w-full overflow-hidden rounded-xl bg-slate-900">
            <iframe
              src={resource.embed_url}
              title={item.title}
              className="h-full w-full"
              allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
              allowFullScreen
            />
          </div>
        );
      }
      if (resource.has_file && resource.download_url) {
        return (
          <div className="space-y-3">
            {item.resource_type === 'VIDEO' ? (
              <video controls className="w-full rounded-xl" src={resource.download_url} />
            ) : (
              <audio controls className="w-full" src={resource.download_url} />
            )}
            <a href={resource.download_url} className="inline-flex items-center gap-2 text-xs font-medium text-brand-700 hover:underline">
              <Download className="h-3.5 w-3.5" aria-hidden="true" /> Unduh berkas
            </a>
          </div>
        );
      }
      return <p className="text-sm text-slate-500">Media belum tersedia.</p>;

    case 'URL':
      return (
        <div className="space-y-2">
          <a
            href={resource.external_url ?? '#'}
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-2 text-sm font-medium text-brand-700 hover:underline"
          >
            <ExternalLink className="h-4 w-4" aria-hidden="true" /> {resource.external_url}
          </a>
          {resource.content && (
            <div className="lms-rich-text text-sm text-slate-700" dangerouslySetInnerHTML={{ __html: sanitizeHtml(resource.content) }} />
          )}
        </div>
      );

    case 'FILE':
      return (
        <div className="space-y-3 text-sm text-slate-700">
          {resource.content && <div className="lms-rich-text" dangerouslySetInnerHTML={{ __html: sanitizeHtml(resource.content) }} />}
          {resource.has_file && resource.download_url ? (
            <a
              href={resource.download_url}
              className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-brand-700 hover:bg-brand-50"
            >
              <Download className="h-4 w-4" aria-hidden="true" /> {resource.file_name ?? 'Unduh berkas'}
            </a>
          ) : (
            <p className="text-slate-500">Berkas belum diunggah.</p>
          )}
        </div>
      );

    case 'BOOK':
      return (
        <div className="space-y-4">
          {resource.content && <div className="lms-rich-text text-sm" dangerouslySetInnerHTML={{ __html: sanitizeHtml(resource.content) }} />}
          {(item.chapters ?? []).length === 0 ? (
            <p className="text-sm text-slate-500">Buku ini belum memiliki bab.</p>
          ) : (
            <ol className="space-y-3">
              {(item.chapters ?? []).map((chapter, index) => (
                <li key={chapter.id} className="rounded-xl border border-slate-200 p-3">
                  <p className="flex items-center gap-2 text-sm font-semibold text-slate-800">
                    <span className="inline-flex h-6 w-6 items-center justify-center rounded bg-brand-50 text-[11px] text-brand-700">
                      {index + 1}
                    </span>
                    {chapter.title}
                  </p>
                  {chapter.content && (
                    <div
                      className="lms-rich-text mt-2 text-sm text-slate-700"
                      dangerouslySetInnerHTML={{ __html: sanitizeHtml(chapter.content) }}
                    />
                  )}
                </li>
              ))}
            </ol>
          )}
        </div>
      );

    case 'FOLDER':
      return (
        <p className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-3 py-6 text-center text-sm text-slate-500">
          <BookOpen className="mx-auto mb-2 h-5 w-5 text-slate-400" aria-hidden="true" />
          Folder ini mengelompokkan materi turunannya.
        </p>
      );

    default:
      return resource.content ? (
        <div className="lms-rich-text text-sm leading-relaxed text-slate-700" dangerouslySetInnerHTML={{ __html: sanitizeHtml(resource.content) }} />
      ) : (
        <p className="text-sm text-slate-500">
          <PlayCircle className="mr-1 inline h-4 w-4" aria-hidden="true" />
          Materi ini belum memiliki isi.
        </p>
      );
  }
}

/** Dipakai halaman lain untuk menampilkan penanda terkunci yang konsisten. */
export function LockedNotice({ message }: { message: string }) {
  return (
    <p className="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
      <Lock className="h-4 w-4" aria-hidden="true" /> {message}
    </p>
  );
}

export { ArrowLeft };
