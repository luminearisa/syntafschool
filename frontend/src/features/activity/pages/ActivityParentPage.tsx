import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { activityApi } from '../api/activityApi';

/**
 * Portal Aktivitas Anak (orang tua).
 *
 * Orang tua hanya dapat **menyetujui atau menolak** \\u2014 tidak mengubah kehadiran,
 * penilaian, atau keanggotaan. Itu bukan pembatasan teknis, melainkan aturan
 * yang ditegakkan backend dan ditampilkan apa adanya di layar ini.
 */
export default function ActivityParentPage() {
  const queryClient = useQueryClient();
  const [notes, setNotes] = useState<Record<number, string>>({});
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const dashboardQuery = useQuery({ queryKey: ['activity', 'parent'], queryFn: () => activityApi.parentDashboard().then((r) => r.data.data) });
  const consentsQuery = useQuery({ queryKey: ['activity', 'consents'], queryFn: () => activityApi.pendingConsents().then((r) => r.data.data) });

  const decide = useMutation({
    mutationFn: ({ consentId, decision }: { consentId: number; decision: 'APPROVED' | 'DECLINED' }) =>
      activityApi.decideConsent(consentId, decision, notes[consentId]).then((r) => r.data.data),
    onSuccess: (consent) => {
      setNotice({ tone: 'success', text: `Persetujuan ${consent.program_name ?? ''} tercatat sebagai ${consent.decision_label ?? consent.decision}.` });
      void queryClient.invalidateQueries({ queryKey: ['activity'] });
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  if (dashboardQuery.isLoading) {
    return (
      <div className="flex justify-center py-12">
        <Spinner />
      </div>
    );
  }

  const children = dashboardQuery.data?.parent?.children ?? [];
  const consents = consentsQuery.data ?? [];

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Aktivitas Anak</h1>
        <p className="mt-1 text-sm text-slate-500">
          Anda dapat memantau kegiatan anak dan memberi persetujuan bila diperlukan. Kehadiran dan penilaian diisi oleh pembina.
        </p>
      </div>

      {notice && (
        <div
          role="status"
          className={
            notice.tone === 'success'
              ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
              : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'
          }
        >
          {notice.text}
        </div>
      )}

      <Card>
        <CardHeader>
          <CardTitle className="text-sm">Persetujuan menunggu keputusan Anda</CardTitle>
        </CardHeader>
        <CardBody className="space-y-3">
          {consents.length === 0 ? (
            <EmptyState title="Tidak ada persetujuan menunggu" description="Persetujuan baru muncul bila anak Anda mendaftar aktivitas yang memerlukannya." />
          ) : (
            consents.map((consent) => (
              <div key={consent.id} className="rounded-lg border border-slate-200 p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div>
                    <p className="font-medium text-slate-800">{consent.program_name}</p>
                    <p className="text-xs text-slate-500">
                      {consent.student_name} · {consent.scope_label ?? consent.scope} · permintaan {consent.requested_at?.slice(0, 10)}
                      {consent.expires_at ? ` · berlaku s.d. ${consent.expires_at.slice(0, 10)}` : ''}
                    </p>
                  </div>
                  <Badge tone="warning">{consent.decision_label ?? consent.decision}</Badge>
                </div>

                <div className="mt-3 space-y-2">
                  <Textarea
                    rows={2}
                    placeholder="Catatan (opsional) — mis. menyetujui dengan catatan pulang sendiri."
                    value={notes[consent.id] ?? ''}
                    onChange={(event) => setNotes((current) => ({ ...current, [consent.id]: event.target.value }))}
                  />
                  <div className="flex gap-2">
                    <Button size="sm" loading={decide.isPending} onClick={() => decide.mutate({ consentId: consent.id, decision: 'APPROVED' })}>
                      Setujui
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => decide.mutate({ consentId: consent.id, decision: 'DECLINED' })}>
                      Tolak
                    </Button>
                  </div>
                </div>
              </div>
            ))
          )}
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        {children.length === 0 ? (
          <EmptyState title="Belum ada data anak pada akun ini" description="Minta tata usaha menautkan akun Anda dengan data wali siswa." />
        ) : (
          children.map((child) => (
            <Card key={child.student_id}>
              <CardHeader>
                <CardTitle className="text-sm">Anak #{child.student_id}</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3 text-sm">
                <div className="flex flex-wrap gap-2">
                  <Badge tone="info">{child.my_activities.length} aktivitas</Badge>
                  <Badge tone="success">{child.certificates} sertifikat</Badge>
                  <Badge tone="brand">{child.achievements} prestasi</Badge>
                  {child.pending_consents > 0 && <Badge tone="warning">{child.pending_consents} persetujuan menunggu</Badge>}
                </div>

                {child.my_activities.length === 0 ? (
                  <p className="text-slate-500">Belum mengikuti aktivitas.</p>
                ) : (
                  child.my_activities.map((activity) => (
                    <div key={activity.id} className="flex items-center justify-between border-b border-slate-100 pb-2 last:border-0">
                      <span className="text-slate-700">{activity.program}</span>
                      <span className="text-xs text-slate-500">{activity.role}</span>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>
          ))
        )}
      </div>
    </div>
  );
}
