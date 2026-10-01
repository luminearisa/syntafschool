import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { getErrorMessage } from '@/lib/axios';
import { useAuthStore } from '@/stores/authStore';
import { bkApi } from '../api/bkApi';
import type { BkReferralRow } from '../types';

const STATUS_TONE: Record<string, 'neutral' | 'warning' | 'success' | 'danger'> = {
  SUBMITTED: 'warning', RECEIVED: 'warning', SCREENING: 'warning',
  ASSIGNED: 'success', IN_PROGRESS: 'success', REFERRED: 'neutral',
  RESOLVED: 'success', REJECTED: 'danger', CANCELLED: 'neutral',
};

const CATEGORIES = ['ACADEMIC', 'BEHAVIORAL', 'SOCIAL', 'EMOTIONAL', 'FAMILY', 'ATTENDANCE', 'DISCIPLINE', 'BULLYING', 'PEER_CONFLICT', 'CAREER', 'BOARDING', 'HEALTH', 'SAFEGUARDING', 'CRISIS', 'OTHER'];
const SOURCES = ['STUDENT', 'PARENT', 'TEACHER', 'HOMEROOM', 'BOARDING', 'ACADEMIC', 'DISCIPLINE', 'CLINIC', 'STUDENT_ACTIVITY', 'SELF_REFERRAL', 'SYSTEM', 'MANAGEMENT'];

/**
 * Antrean referral BK.
 *
 * Guru/siswa/wali mengisi formulir yang ringan (apa yang diamati, bukan detail
 * sensitif); koordinator menerima, menyaring, lalu membuka kasus. Tombol yang
 * tampil mengikuti izin, tetapi server tetap penentu akhir.
 */
export default function BkReferralsPage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();
  const [status, setStatus] = useState('');
  const [queueOnly, setQueueOnly] = useState(true);
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [form, setForm] = useState({ student_id: '', source_type: 'HOMEROOM', category: 'ACADEMIC', priority: 'NORMAL', description: '' });
  const [screening, setScreening] = useState<{ id: number | null; concern: string; outcome: string }>({ id: null, concern: '', outcome: 'COUNSELING' });

  const referrals = useQuery({
    queryKey: ['bk', 'referrals', status, queueOnly],
    queryFn: () => bkApi.referrals({ status: status || undefined, queue: queueOnly ? 1 : undefined, per_page: 25 }).then((r) => r.data.data),
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['bk', 'referrals'] });
  const onError = (error: unknown) => setNotice({ tone: 'danger', text: getErrorMessage(error) });

  const createReferral = useMutation({
    mutationFn: () => bkApi.createReferral({ ...form, student_id: Number(form.student_id) }).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Referral terkirim. Tim BK akan memeriksanya.' });
      setForm({ student_id: '', source_type: 'HOMEROOM', category: 'ACADEMIC', priority: 'NORMAL', description: '' });
      invalidate();
    },
    onError,
  });

  const receive = useMutation({
    mutationFn: (id: number) => bkApi.receiveReferral(id).then((r) => r.data.data),
    onSuccess: () => { setNotice({ tone: 'success', text: 'Referral diterima BK.' }); invalidate(); },
    onError,
  });

  const screen = useMutation({
    mutationFn: () => bkApi.screenReferral(screening.id as number, {
      presenting_concern: screening.concern,
      recommended_outcome: screening.outcome,
    }).then((r) => r.data.data),
    onSuccess: () => { setNotice({ tone: 'success', text: 'Screening tersimpan; rekomendasi tercatat di timeline.' }); setScreening({ id: null, concern: '', outcome: 'COUNSELING' }); invalidate(); },
    onError,
  });

  const openCase = useMutation({
    mutationFn: (row: BkReferralRow) => bkApi.openCase(row.id, { case_type: 'INDIVIDUAL_COUNSELING' }).then((r) => r.data.data),
    onSuccess: (data) => { setNotice({ tone: 'success', text: `Kasus ${String((data as { case_number?: string }).case_number)} dibuka.` }); invalidate(); },
    onError,
  });

  const rows = referrals.data ?? [];

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-slate-800">Referral BK</h1>
          <p className="mt-1 text-sm text-slate-500">Pintu masuk layanan: apa yang diamati, siapa yang melaporkan, dan seberapa mendesak.</p>
        </div>
        <div className="flex items-end gap-2">
          <div className="w-40">
            <Select value={status} onChange={(event) => setStatus(event.target.value)} aria-label="Saring status">
              <option value="">Semua status</option>
              {Object.keys(STATUS_TONE).map((key) => <option key={key} value={key}>{key}</option>)}
            </Select>
          </div>
          <label className="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" checked={queueOnly} onChange={(event) => setQueueOnly(event.target.checked)} />
            Hanya antrean
          </label>
        </div>
      </div>

      {notice && (
        <div role="status" className={notice.tone === 'success'
          ? 'rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800'
          : 'rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800'}>
          {notice.text}
        </div>
      )}

      {can('bk.referral.create') && (
        <Card>
          <CardHeader><CardTitle className="text-sm">Ajukan referral</CardTitle></CardHeader>
          <CardBody className="grid gap-3 md:grid-cols-4">
            <Input placeholder="ID siswa" value={form.student_id} onChange={(event) => setForm({ ...form, student_id: event.target.value })} />
            <Select value={form.source_type} onChange={(event) => setForm({ ...form, source_type: event.target.value })} aria-label="Sumber">
              {SOURCES.map((source) => <option key={source} value={source}>{source}</option>)}
            </Select>
            <Select value={form.category} onChange={(event) => setForm({ ...form, category: event.target.value })} aria-label="Kategori">
              {CATEGORIES.map((category) => <option key={category} value={category}>{category}</option>)}
            </Select>
            <Select value={form.priority} onChange={(event) => setForm({ ...form, priority: event.target.value })} aria-label="Prioritas">
              {['LOW', 'NORMAL', 'HIGH', 'URGENT'].map((priority) => <option key={priority} value={priority}>{priority}</option>)}
            </Select>
            <div className="md:col-span-4">
              <Textarea rows={3} placeholder="Apa yang Anda amati? (tidak perlu menuliskan hal yang bersifat pribadi)"
                value={form.description} onChange={(event) => setForm({ ...form, description: event.target.value })} />
            </div>
            <div className="md:col-span-4">
              <Button loading={createReferral.isPending} disabled={form.student_id === '' || form.description.trim().length < 10}
                onClick={() => createReferral.mutate()}>
                Kirim referral
              </Button>
            </div>
          </CardBody>
        </Card>
      )}

      <Card>
        <CardHeader><CardTitle className="text-sm">Antrean referral</CardTitle></CardHeader>
        <CardBody>
          {referrals.isLoading ? <Spinner /> : rows.length === 0 ? (
            <EmptyState title="Tidak ada referral" description="Antrean bersih untuk penyaring ini." />
          ) : (
            <div className="space-y-2">
              {rows.map((row) => (
                <div key={row.id} className="rounded-lg border border-slate-200 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">
                        {row.referral_number} · {row.student?.full_name ?? `#${row.student_id}`}
                      </p>
                      <p className="text-xs text-slate-500">
                        {row.source_type} · {row.category} · {row.priority}
                        {row.case?.case_number ? ` · kasus ${row.case.case_number}` : ''}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <Badge tone={STATUS_TONE[row.status] ?? 'neutral'}>{row.status}</Badge>
                      {can('bk.referral.receive') && ['SUBMITTED', 'RECEIVED'].includes(row.status) && (
                        <Button size="sm" variant="outline" loading={receive.isPending} onClick={() => receive.mutate(row.id)}>Terima</Button>
                      )}
                      {can('bk.screening.create') && !row.case_id && (
                        <Button size="sm" variant="ghost" onClick={() => setScreening({ id: row.id, concern: '', outcome: 'COUNSELING' })}>Screening</Button>
                      )}
                      {can('bk.case.create') && !row.case_id && (
                        <Button size="sm" loading={openCase.isPending} onClick={() => openCase.mutate(row)}>Buka kasus</Button>
                      )}
                    </div>
                  </div>
                  <p className="mt-2 line-clamp-2 text-sm text-slate-600">{row.description}</p>

                  {screening.id === row.id && (
                    <div className="mt-3 space-y-2 rounded-md bg-slate-50 p-3">
                      <Textarea rows={2} placeholder="Keluhan utama (presenting concern)" value={screening.concern}
                        onChange={(event) => setScreening({ ...screening, concern: event.target.value })} />
                      <Select value={screening.outcome} onChange={(event) => setScreening({ ...screening, outcome: event.target.value })} aria-label="Rekomendasi">
                        {['NO_ACTION', 'MONITOR', 'COUNSELING', 'INTERVENTION', 'PARENT_MEETING', 'TEAM_REVIEW', 'REFERRAL', 'URGENT_ESCALATION'].map((outcome) => (
                          <option key={outcome} value={outcome}>{outcome}</option>
                        ))}
                      </Select>
                      <div className="flex gap-2">
                        <Button size="sm" loading={screen.isPending} disabled={screening.concern.trim().length < 5} onClick={() => screen.mutate()}>Simpan screening</Button>
                        <Button size="sm" variant="ghost" onClick={() => setScreening({ id: null, concern: '', outcome: 'COUNSELING' })}>Batal</Button>
                      </div>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
