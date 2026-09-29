import { useQuery } from '@tanstack/react-query';
import { Flame, Medal, Trophy } from 'lucide-react';
import { Badge } from '@/components/ui/Badge';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { phase4Api } from '../api/phase4Api';

/**
 * Capaian belajar: badge, poin XP, dan rentetan belajar (§26–§30).
 *
 * Label kesehatan/capaian hanya untuk memantau kegiatan belajar — bukan
 * penilaian kemampuan pribadi siswa.
 */
export default function LmsAchievementsPage() {
  const achievements = useQuery({
    queryKey: ['lms', 'gamification'],
    queryFn: () => phase4Api.myGamification(),
  });

  if (achievements.isLoading) {
    return (
      <div className="flex justify-center py-10">
        <Spinner />
      </div>
    );
  }

  if (achievements.isError) {
    return (
      <EmptyState
        title="Capaian belum dapat dimuat"
        description="Modul gamifikasi mungkin belum diaktifkan sekolah Anda."
      />
    );
  }

  const data = achievements.data;
  const badges = data?.badges ?? [];
  const enabled = data?.enabled ?? false;
  const streak = (data?.streak ?? {}) as { current?: number; longest?: number; today?: boolean };

  if (!enabled) {
    return (
      <EmptyState
        title="Gamifikasi belum diaktifkan"
        description="Badge, poin XP, dan rentetan belajar akan tampil setelah sekolah mengaktifkannya."
      />
    );
  }

  return (
    <div className="space-y-5">
      <header>
        <h1 className="flex items-center gap-2 text-lg font-semibold text-slate-900">
          <Trophy className="h-5 w-5 text-amber-500" aria-hidden="true" /> Capaian belajar
        </h1>
        <p className="text-sm text-slate-500">Poin, badge, dan kebiasaan belajar Anda.</p>
      </header>

      <div className="grid gap-3 sm:grid-cols-3">
        <Card>
          <CardBody>
            <p className="text-xs text-slate-500">Total poin</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">
              {String((data?.summary as { total?: number } | undefined)?.total ?? 0)}
            </p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="flex items-center gap-1 text-xs text-slate-500">
              <Flame className="h-3.5 w-3.5 text-orange-500" aria-hidden="true" /> Rentetan belajar
            </p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{streak.current ?? 0} hari</p>
            <p className="text-xs text-slate-500">Terpanjang: {streak.longest ?? 0} hari</p>
          </CardBody>
        </Card>
        <Card>
          <CardBody>
            <p className="text-xs text-slate-500">Badge diperoleh</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">{badges.length}</p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Medal className="h-4 w-4 text-amber-500" aria-hidden="true" /> Badge
          </CardTitle>
        </CardHeader>
        <CardBody>
          {badges.length === 0 ? (
            <EmptyState title="Belum ada badge" description="Selesaikan aktivitas untuk memperoleh badge pertama Anda." />
          ) : (
            <ul className="grid gap-2 sm:grid-cols-2">
              {badges.map((badge) => (
                <li key={badge.id} className="flex items-start gap-3 rounded-lg border border-slate-200 p-3">
                  <Medal className="mt-0.5 h-5 w-5 text-amber-500" aria-hidden="true" />
                  <div className="min-w-0">
                    <p className="truncate text-sm font-medium text-slate-800">{badge.name}</p>
                    {badge.description && <p className="text-xs text-slate-500">{badge.description}</p>}
                    <p className="mt-1 text-xs text-slate-400">
                      {badge.awarded_at ? new Date(badge.awarded_at).toLocaleDateString('id-ID') : ''}
                      {badge.points ? ` · +${badge.points} poin` : ''}
                    </p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Aktivitas poin terakhir</CardTitle>
        </CardHeader>
        <CardBody>
          {(data?.recent ?? []).length === 0 ? (
            <p className="text-sm text-slate-500">Belum ada aktivitas poin.</p>
          ) : (
            <ul className="divide-y divide-slate-100">
              {(data?.recent ?? []).map((entry, index) => {
                const row = entry as { event_type?: string; points?: number; occurred_at?: string };
                return (
                  <li key={index} className="flex items-center justify-between py-2 text-sm">
                    <span className="text-slate-700">{row.event_type ?? 'Aktivitas'}</span>
                    <span className="flex items-center gap-2">
                      <Badge tone="green">+{row.points ?? 0}</Badge>
                      <span className="text-xs text-slate-400">
                        {row.occurred_at ? new Date(row.occurred_at).toLocaleDateString('id-ID') : ''}
                      </span>
                    </span>
                  </li>
                );
              })}
            </ul>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
