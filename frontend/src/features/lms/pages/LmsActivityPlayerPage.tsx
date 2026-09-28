import { useCallback, useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { ActivityHeader } from '@/features/lms/components/activities/ActivityHeader';
import { AssignmentPlayer } from '@/features/lms/components/activities/AssignmentPlayer';
import { ChoicePlayer } from '@/features/lms/components/activities/ChoicePlayer';
import { ForumPlayer } from '@/features/lms/components/activities/ForumPlayer';
import { LessonPlayer } from '@/features/lms/components/activities/LessonPlayer';
import { QuizPlayer } from '@/features/lms/components/activities/QuizPlayer';
import { SurveyPlayer } from '@/features/lms/components/activities/SurveyPlayer';
import { activityApi } from '@/features/lms/api/activityApi';
import type {
  AssignmentStudentView,
  ChoiceView,
  ForumView,
  LessonView,
  LmsActivityShowPayload,
  QuizStudentView,
  SurveyView,
} from '@/features/lms/types.phase2';
import { Settings } from 'lucide-react';

/**
 * Halaman pengerjaan aktivitas (§10, §12, §33, §37–§39).
 *
 * Satu halaman menangani seluruh tipe: kepala aktivitas (tenggat, status,
 * navigasi Previous/Next) + pemain sesuai tipenya. Guru yang membuka aktivitas
 * tanpa enrollment diberi tautan ke halaman kelola, bukan formulir palsu.
 */
export function LmsActivityPlayerPage() {
  const { id } = useParams();
  const activityId = Number(id);
  const navigate = useNavigate();

  const [payload, setPayload] = useState<LmsActivityShowPayload | null>(null);
  const [view, setView] = useState<unknown>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const show = await activityApi.show(activityId);
      setPayload(show);

      const type = show.activity.activity_type;
      const canPlay = !show.state.locked;

      if (!canPlay) {
        setView(null);
        return;
      }

      if (type === 'ASSIGNMENT') setView(await activityApi.assignment(activityId));
      else if (type === 'QUIZ') setView(await activityApi.quiz(activityId));
      else if (type === 'FORUM') setView(await activityApi.forum(activityId));
      else if (type === 'LESSON') setView(await activityApi.lesson(activityId));
      else if (type === 'CHOICE') setView(await activityApi.choice(activityId));
      else if (type === 'SURVEY') setView(await activityApi.survey(activityId));
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

  if (loading) {
    return (
      <div className="flex justify-center py-16">
        <Spinner />
      </div>
    );
  }

  if (error || !payload) {
    return <EmptyState title="Aktivitas tidak ditemukan" description={error ?? 'Periksa kembali tautan aktivitas ini.'} />;
  }

  const { activity, state, neighbours, can_manage: canManage } = payload;

  const manageButton = canManage && (
    <Button type="button" variant="outline" size="sm" onClick={() => navigate(`/lms/activities/${activity.id}/manage`)}>
      <Settings className="mr-1 h-4 w-4" /> Kelola aktivitas
    </Button>
  );

  return (
    <div className="space-y-5">
      <ActivityHeader
        activity={activity}
        state={state}
        neighbours={neighbours}
        canManage={canManage && view === null}
        onNavigate={(nextId) => navigate(`/lms/activities/${nextId}`)}
        actions={manageButton}
      />

      {state.locked && (
        <Card className="p-6 text-center text-sm text-slate-600">
          <p className="font-medium text-slate-800">Aktivitas belum dapat dibuka</p>
          <p className="mt-1">{state.reason_label ?? 'Selesaikan prasyarat terlebih dahulu.'}</p>
        </Card>
      )}

      {!state.locked && view === null && (
        <Card className="space-y-3 p-6 text-center text-sm text-slate-600">
          <p>Anda membuka aktivitas ini sebagai pengajar, sehingga tidak ada formulir pengerjaan.</p>
          <div className="flex justify-center">
            <Button type="button" onClick={() => navigate(`/lms/activities/${activity.id}/manage`)}>
              Buka halaman kelola aktivitas
            </Button>
          </div>
        </Card>
      )}

      {!state.locked && view !== null && (
        <>
          {activity.activity_type === 'ASSIGNMENT' && (
            <AssignmentPlayer activityId={activityId} view={view as AssignmentStudentView} onChanged={() => void load()} />
          )}
          {activity.activity_type === 'QUIZ' && (
            <QuizPlayer activityId={activityId} view={view as QuizStudentView} onChanged={() => void load()} />
          )}
          {activity.activity_type === 'FORUM' && (
            <ForumPlayer activityId={activityId} view={view as ForumView} canManage={canManage} onChanged={() => void load()} />
          )}
          {activity.activity_type === 'LESSON' && (
            <LessonPlayer activityId={activityId} view={view as LessonView} onChanged={() => void load()} />
          )}
          {activity.activity_type === 'CHOICE' && (
            <ChoicePlayer activityId={activityId} view={view as ChoiceView} onChanged={() => void load()} />
          )}
          {activity.activity_type === 'SURVEY' && (
            <SurveyPlayer activityId={activityId} view={view as SurveyView} onChanged={() => void load()} />
          )}

          {canManage && (
            <p className="text-center text-xs text-slate-500">
              Anda melihat tampilan siswa. <Badge tone="gray">Mode pengajar</Badge>
            </p>
          )}
        </>
      )}
    </div>
  );
}
