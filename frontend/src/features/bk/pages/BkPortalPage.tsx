import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { bkApi } from '../api/bkApi';

/**
 * Portal BK untuk siswa & orang tua.
 *
 * Formulirnya ringan: cukup topik dan waktu yang disukai — detail sensitif
 * dibicarakan langsung dengan konselor. Portal ini tidak pernah menampilkan
 * catatan konseling maupun konferensi kasus internal.
 */
export default function BkPortalPage() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState({ student_id: '', topic: '', preferred_time_slot: '', urgent: false });
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);

  const summary = useQuery({ queryKey: ['bk', 'portal', 'summary'], queryFn: () => bkApi.portalSummary().then((r) => r.data.data) });
  const requests = useQuery({ queryKey: ['bk', 'portal', 'requests'], queryFn: () => bkApi.portalRequests().then((r) => r.data.data) });
  const appointments = useQuery({ queryKey: ['bk', 'portal', 'appointments'], queryFn: () => bkApi.portalAppointments({ upcoming: 1 }).then((r) => r.data.data) });
  const consents = useQuery({ queryKey: ['bk', 'portal', 'consents'], queryFn: () => bkApi.portalConsents({ pending: 1 }).then((r) => r.data.data) });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['bk', 'portal'] });
  const onError = (error: unknown) => setNotice({ tone: 'danger', text: getErrorMessage(error) });

  const submit = useMutation({
    mutationFn: () => (form.student_id === ''
      ? bkApi.requestByStudent({ topic: form.topic, preferred_time_slot: form.preferred_time_slot || undefined, urgent: form.urgent })
      : bkApi.requestByGuardian({
          student_id: Number(form.student_id),
          topic: form.topic,
          preferred_time_slot: form.preferred_time_slot || undefined,
          urgent: form.urgent,
        })).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Permintaanmu sudah masuk ke antrean BK. Konselor akan menghubungi.' });
      setForm({ student_id: '', topic: '', preferred_time_slot: '', urgent: false });
      invalidate();
    },
    onError,
  });

  const decide = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: 'APPROVED' | 'DECLINED' }) => bkApi.decideConsent(id, decision).then((r) => r.data.data),
    onSuccess: () => { setNotice({ tone: 'success', text: 'Keputusan Anda tersimpan.' }); invalidate(); },
    onError,
  });

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">BK untuk Saya &amp; Anak</h1>
        <p className="mt-1 text-sm text-slate-500">
          Ajukan waktu bercerita dengan konselor, lihat jadwal, dan berikan persetujuan. Yang bersifat pribadi dibicarakan langsung — bukan lewat formulir.
        </p>
      </div>

      {notice && (
        <div role="status" className={notice.tone === 'success'
          ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
          : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'}>
          {notice.text}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader><CardTitle className="text-sm">Minta waktu dengan konselor</CardTitle></CardHeader>
          <CardBody className="grid gap-3 md:grid-cols-2">
            {summary.data?.is_guardian && (
              <Input placeholder="ID anak (untuk wali)" value={form.student_id} onChange={(event) => setForm({ ...form, student_id: event.target.value })} />
            )}
            <Input placeholder="Topik (mis. kesulitan belajar)" value={form.topic} onChange={(event) => setForm({ ...form, topic: event.target.value })} />
            <Input placeholder="Waktu yang disukai (mis. istirahat pertama)" value={form.preferred_time_slot} onChange={(event) => setForm({ ...form, preferred_time_slot: event.target.value })} />
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" checked={form.urgent} onChange={(event) => setForm({ ...form, urgent: event.target.checked })} />
              Ini mendesak
            </label>
            <div className="md:col-span-2">
              <Textarea rows={2} placeholder="Catatan tambahan (opsional)" value={''} onChange={() => undefined} aria-hidden className="hidden" />
              <Button loading={submit.isPending} disabled={form.topic.trim().length < 3} onClick={() => submit.mutate()}>Kirim permintaan</Button>
            </div>
          </CardBody>
        </Card>

        <Card>
          <CardHeader><CardTitle className="text-sm">Ringkasan</CardTitle></CardHeader>
          <CardBody className="space-y-1 text-sm text-slate-700">
            {summary.isLoading ? <Spinner /> : (
              <>
                <p>Permintaan menunggu: {summary.data?.queued_requests ?? 0}</p>
                <p>Persetujuan perlu keputusan: {summary.data?.pending_consents ?? 0}</p>
              </>
            )}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader><CardTitle className="text-sm">Janji temu mendatang</CardTitle></CardHeader>
        <CardBody className="space-y-2 text-sm">
          {appointments.isLoading ? <Spinner /> : (appointments.data ?? []).length === 0 ? (
            <EmptyState title="Belum ada jadwal" description="Jadwal konseling akan tampil di sini setelah konselor menetapkannya." />
          ) : (appointments.data ?? []).map((row) => (
            <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
              <div>
                <p className="text-slate-800">{row.scheduled_start?.slice(0, 16).replace('T', ' ')}</p>
                <p className="text-xs text-slate-500">{row.appointment_number} · {row.mode} · {row.location ?? 'lokasi menyusul'}</p>
              </div>
              <Badge tone="warning">{row.status}</Badge>
            </div>
          ))}
        </CardBody>
      </Card>

      <Card>
        <CardHeader><CardTitle className="text-sm">Persetujuan yang perlu keputusan Anda</CardTitle></CardHeader>
        <CardBody className="space-y-2 text-sm">
          {consents.isLoading ? <Spinner /> : (consents.data ?? []).length === 0 ? (
            <p className="text-slate-500">Tidak ada permintaan persetujuan yang menunggu.</p>
          ) : (consents.data ?? []).map((row) => (
            <div key={String(row.id)} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
              <div>
                <p className="text-slate-800">Persetujuan {String(row.scope)}</p>
                <p className="text-xs text-slate-500">{String(row.purpose ?? '')} · berlaku s.d. {String(row.expires_at ?? '—').slice(0, 10)}</p>
              </div>
              <div className="flex gap-2">
                <Button size="sm" variant="outline" onClick={() => decide.mutate({ id: Number(row.id), decision: 'APPROVED' })}>Setujui</Button>
                <Button size="sm" variant="ghost" onClick={() => decide.mutate({ id: Number(row.id), decision: 'DECLINED' })}>Tolak</Button>
              </div>
            </div>
          ))}
        </CardBody>
      </Card>

      <Card>
        <CardHeader><CardTitle className="text-sm">Permintaan saya</CardTitle></CardHeader>
        <CardBody className="space-y-2 text-sm">
          {requests.isLoading ? <Spinner /> : (requests.data ?? []).length === 0 ? (
            <p className="text-slate-500">Belum ada permintaan.</p>
          ) : (requests.data ?? []).map((row) => (
            <div key={String(row.id)} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
              <div>
                <p className="text-slate-800">{String(row.topic)}</p>
                <p className="text-xs text-slate-500">{String(row.request_number)} · {String(row.request_type)}</p>
              </div>
              <Badge tone={row.status === 'CLOSED' ? 'success' : 'warning'}>{String(row.status)}</Badge>
            </div>
          ))}
        </CardBody>
      </Card>
    </div>
  );
}
