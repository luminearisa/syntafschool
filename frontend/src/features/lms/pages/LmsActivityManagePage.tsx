import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Button } from '@/components/ui/Button';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { ActivityHeader } from '@/features/lms/components/activities/ActivityHeader';
import { AssignmentManage } from '@/features/lms/components/activities/manage/AssignmentManage';
import { ChoiceManage, ForumManage, LessonManage, SurveyManage } from '@/features/lms/components/activities/manage/EngagementManage';
import { QuizManage } from '@/features/lms/components/activities/manage/QuizManage';
import { activityApi } from '@/features/lms/api/activityApi';
import type { LmsActivityShowPayload } from '@/features/lms/types.phase2';
import { Archive, Eye, Rocket, Undo2 } from 'lucide-react';

/**
 * Halaman kelola aktivitas untuk guru (§11, §43).
 *
 * Tab konfigurasi & konten sesuai tipe aktivitas; aksi terbitkan/arsipkan dan
 * pratinjau sebagai siswa tersedia di kepala halaman.
 */
export function LmsActivityManagePage() {
  const { id } = useParams();
  const activityId = Number(id);
  const navigate = useNavigate();

  const [payload, setPayload] = useState<LmsActivityShowPayload | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setPayload(await activityApi.show(activityId));
      setError(null);
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Aktivitas tidak dapat dimuat.');
    } finally {
      setLoading(false);
    }
  }, [activityId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function run(action: () => Promise<unknown>) {
    setBusy(true);
    try {
      await action();
      await load();
    } catch (err) {
      const detail = (err as { response?: { data?: { message?: string } } }).response?.data?.message;
      setError(detail ?? 'Aksi gagal dijalankan.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (!payload || error) {
    return <EmptyState title="Tidak dapat membuka aktivitas" description={error ?? 'Coba muat ulang halaman.'} />;
  }

  const { activity, can_manage: canManage } = payload;

  if (!canManage) {
    return <EmptyState title="Akses ditolak" description="Anda tidak berhak mengelola aktivitas ini." />;
  }

  return (
    <div className="space-y-5">
      <ActivityHeader
        activity={activity}
        state={payload.state}
        neighbours={payload.neighbours}
        canManage
        onNavigate={(nextId) => navigate(`/lms/activities/${nextId}/manage`)}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            <Button type="button" variant="outline" size="sm" onClick={() => navigate(`/lms/activities/${activity.id}`)}>
              <Eye className="mr-1 h-4 w-4" /> Pratinjau siswa
            </Button>

            {activity.status !== 'PUBLISHED' ? (
              <Button type="button" size="sm" disabled={busy} onClick={() => void run(() => activityApi.publish(activity.id))}>
                <Rocket className="mr-1 h-4 w-4" /> Terbitkan
              </Button>
            ) : (
              <Button type="button" variant="outline" size="sm" disabled={busy} onClick={() => void run(() => activityApi.unpublish(activity.id))}>
                <Undo2 className="mr-1 h-4 w-4" /> Kembalikan ke draf
              </Button>
            )}

            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={busy}
              onClick={() => void run(() => activityApi.duplicate(activity.id, `${activity.title} (salinan)`))}
            >
              Duplikasi
            </Button>

            <Button type="button" variant="ghost" size="sm" disabled={busy} onClick={() => void run(() => activityApi.archive(activity.id))}>
              <Archive className="mr-1 h-4 w-4" /> Arsipkan
            </Button>
          </div>
        }
      />

      {error && <p className="text-sm text-red-600">{error}</p>}

      {activity.activity_type === 'ASSIGNMENT' && <AssignmentManage activity={activity} />}
      {activity.activity_type === 'QUIZ' && <QuizManage activity={activity} />}
      {activity.activity_type === 'FORUM' && <ForumManage activity={activity} />}
      {activity.activity_type === 'LESSON' && <LessonManage activity={activity} />}
      {activity.activity_type === 'CHOICE' && <ChoiceManage activity={activity} />}
      {activity.activity_type === 'SURVEY' && <SurveyManage activity={activity} />}
    </div>
  );
}
