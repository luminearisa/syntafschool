import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Bookmark, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/Button';
import { Card, CardBody } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { ItemIcon } from '../components/ItemIcon';
import { lmsApi } from '../api/lmsApi';

/** Bookmark course & materi (§32). */
export default function LmsBookmarksPage() {
  const queryClient = useQueryClient();
  const bookmarks = useQuery({ queryKey: ['lms', 'bookmarks'], queryFn: () => lmsApi.bookmarks() });

  const remove = useMutation({
    mutationFn: (id: number) => lmsApi.deleteBookmark(id),
    onSuccess: () => {
      toast.success('Bookmark dihapus');
      queryClient.invalidateQueries({ queryKey: ['lms', 'bookmarks'] });
    },
  });

  const rows = bookmarks.data?.data ?? [];

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <Bookmark className="h-5 w-5 text-brand-600" aria-hidden="true" /> Bookmark saya
        </h1>
        <p className="text-sm text-slate-500">Simpan course dan materi yang ingin Anda buka lagi.</p>
      </header>

      {bookmarks.isLoading ? (
        <div className="flex justify-center py-10">
          <Spinner />
        </div>
      ) : rows.length === 0 ? (
        <EmptyState
          title="Belum ada bookmark"
          description="Tekan tombol Simpan pada halaman course atau materi untuk menandainya."
          action={
            <Link to="/lms/courses">
              <Button size="sm">Jelajahi course</Button>
            </Link>
          }
        />
      ) : (
        <div className="grid gap-3 md:grid-cols-2">
          {rows.map((bookmark) => (
            <Card key={bookmark.id}>
              <CardBody className="flex items-start gap-3">
                <ItemIcon icon={bookmark.item ? (bookmark.item.resource_type ?? bookmark.item.activity_type ?? 'file') : 'book-open'} tone="brand" />
                <div className="min-w-0 flex-1">
                  <Link
                    to={
                      bookmark.learning_item_id
                        ? `/lms/courses/${bookmark.course_id}/learn/${bookmark.learning_item_id}`
                        : `/lms/courses/${bookmark.course_id}`
                    }
                    className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700"
                  >
                    {bookmark.item?.title ?? bookmark.course?.name}
                  </Link>
                  <p className="truncate text-xs text-slate-500">
                    {bookmark.course?.name} {bookmark.item?.section_name ? `• ${bookmark.item.section_name}` : ''}
                  </p>
                </div>
                <Button size="icon" variant="ghost" title="Hapus bookmark" onClick={() => remove.mutate(bookmark.id)}>
                  <Trash2 className="h-4 w-4 text-red-500" aria-hidden="true" />
                </Button>
              </CardBody>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
