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
import { activityApi } from '../api/activityApi';

type Tab = 'audit' | 'consents' | 'finance' | 'resources';

const EQUIPMENT_STATUS = ['ISSUED', 'PARTIAL', 'RETURNED', 'REJECTED', 'LOST'];

/**
 * Tata Kelola Aktivitas.
 *
 * Empat hal yang tidak terlihat dari layar operasional, tetapi justru dicari
 * ketika ada pertanyaan "atas dasar apa keputusan ini diambil?": jejak audit,
 * persetujuan orang tua yang masih menggantung, permintaan tagihan ke Keuangan,
 * serta permintaan perlengkapan dan pemesanan ruang.
 *
 * Tab yang tampil mengikuti izin: petugas keuangan melihat permintaan tagihan,
 * petugas inventaris melihat perlengkapan, kepala sekolah dan auditor melihat
 * jejak audit. Tidak ada tombol yang pasti ditolak backend.
 */
export default function ActivityGovernancePage() {
  const can = useAuthStore((state) => state.can);
  const queryClient = useQueryClient();

  const [tab, setTab] = useState<Tab>('audit');
  const [action, setAction] = useState('');
  const [studentId, setStudentId] = useState('');
  const [notice, setNotice] = useState<{ tone: 'success' | 'danger'; text: string } | null>(null);
  const [reason, setReason] = useState<{ kind: 'charge' | 'booking'; id: number | null; text: string }>({
    kind: 'charge',
    id: null,
    text: '',
  });
  const [equipment, setEquipment] = useState<{ id: number | null; status: string; notes: string }>({
    id: null,
    status: 'ISSUED',
    notes: '',
  });

  const invalidate = () => void queryClient.invalidateQueries({ queryKey: ['activity', 'governance'] });

  const auditQuery = useQuery({
    queryKey: ['activity', 'governance', 'audit', action, studentId],
    queryFn: () =>
      activityApi
        .auditLog({ action: action || undefined, student_id: studentId || undefined, per_page: 30 })
        .then((r) => r.data.data),
    enabled: tab === 'audit' && can('activity.audit.view'),
  });

  const consentsQuery = useQuery({
    queryKey: ['activity', 'governance', 'consents'],
    queryFn: () => activityApi.staffConsents({ pending: 1, per_page: 30 }).then((r) => r.data.data),
    enabled: tab === 'consents' && can('activity.consent.view'),
  });

  const chargesQuery = useQuery({
    queryKey: ['activity', 'governance', 'charges'],
    queryFn: () => activityApi.charges({ per_page: 30 }).then((r) => r.data.data),
    enabled: tab === 'finance' && can('activity.finance.view'),
  });

  const equipmentQuery = useQuery({
    queryKey: ['activity', 'governance', 'equipment'],
    queryFn: () => activityApi.equipmentRequests({ per_page: 30 }).then((r) => r.data.data),
    enabled: tab === 'resources' && can('activity.inventory.view'),
  });

  const bookingsQuery = useQuery({
    queryKey: ['activity', 'governance', 'bookings'],
    queryFn: () => activityApi.facilityBookings({ per_page: 30 }).then((r) => r.data.data),
    enabled: tab === 'resources' && can('activity.facility.view'),
  });

  const cancelCharge = useMutation({
    mutationFn: () => activityApi.cancelCharge(reason.id as number, reason.text).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Permintaan tagihan dibatalkan dan tercatat di jejak audit.' });
      setReason({ kind: 'charge', id: null, text: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const cancelBooking = useMutation({
    mutationFn: () => activityApi.cancelFacilityBooking(reason.id as number, reason.text).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Pemesanan ruang dibatalkan; pembatalannya diteruskan ke modul Sarpras.' });
      setReason({ kind: 'booking', id: null, text: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const updateEquipment = useMutation({
    mutationFn: () => activityApi.updateEquipmentRequest(equipment.id as number, equipment.status, equipment.notes).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Status permintaan perlengkapan diselaraskan.' });
      setEquipment({ id: null, status: 'ISSUED', notes: '' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const decideConsent = useMutation({
    mutationFn: ({ id, decision }: { id: number; decision: 'APPROVED' | 'DECLINED' }) =>
      activityApi.decideConsent(id, decision).then((r) => r.data.data),
    onSuccess: () => {
      setNotice({ tone: 'success', text: 'Keputusan persetujuan dicatat.' });
      invalidate();
    },
    onError: (error) => setNotice({ tone: 'danger', text: getErrorMessage(error) }),
  });

  const tabs: { key: Tab; label: string; allowed: boolean }[] = [
    { key: 'audit', label: 'Jejak audit', allowed: can('activity.audit.view') },
    { key: 'consents', label: 'Persetujuan menggantung', allowed: can('activity.consent.view') },
    { key: 'finance', label: 'Permintaan tagihan', allowed: can('activity.finance.view') },
    {
      key: 'resources',
      label: 'Perlengkapan & ruang',
      allowed: can('activity.inventory.view') || can('activity.facility.view'),
    },
  ];

  const allowedTabs = tabs.filter((entry) => entry.allowed);
  const activeTab = allowedTabs.some((entry) => entry.key === tab) ? tab : allowedTabs[0]?.key;

  const audits = auditQuery.data ?? [];
  const consents = consentsQuery.data ?? [];
  const charges = chargesQuery.data ?? [];
  const equipmentRows = equipmentQuery.data ?? [];
  const bookings = bookingsQuery.data ?? [];

  return (
    <div className="space-y-5">
      <div>
        <h1 className="text-xl font-semibold text-slate-800">Tata Kelola Aktivitas</h1>
        <p className="mt-1 text-sm text-slate-500">
          Jejak audit, persetujuan yang menunggu, dan permintaan modul aktivitas ke Keuangan, Inventaris, serta Sarpras.
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

      {allowedTabs.length === 0 ? (
        <EmptyState
          title="Tidak ada bagian yang dapat Anda buka"
          description="Peran Anda belum memiliki izin audit, persetujuan, maupun integrasi."
        />
      ) : (
        <>
          <div className="flex flex-wrap gap-2">
            {allowedTabs.map((entry) => (
              <button
                key={entry.key}
                type="button"
                onClick={() => setTab(entry.key)}
                className={`rounded-lg border px-3 py-2 text-sm ${
                  entry.key === activeTab
                    ? 'border-brand-500 bg-brand-50 text-brand-800'
                    : 'border-slate-200 text-slate-700 hover:bg-slate-50'
                }`}
              >
                {entry.label}
              </button>
            ))}
          </div>

          {activeTab === 'audit' && (
            <Card>
              <CardHeader>
                <div className="flex flex-wrap items-end justify-between gap-3">
                  <CardTitle className="text-sm">Jejak audit (tidak dapat disunting)</CardTitle>
                  <div className="flex gap-2">
                    <div className="w-56">
                      <Input
                        placeholder="Nama aksi (mis. REGISTRATION_APPROVED)"
                        value={action}
                        onChange={(event) => setAction(event.target.value.toUpperCase())}
                      />
                    </div>
                    <div className="w-40">
                      <Input placeholder="ID siswa" value={studentId} onChange={(event) => setStudentId(event.target.value)} />
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardBody>
                {auditQuery.isLoading ? (
                  <Spinner />
                ) : audits.length === 0 ? (
                  <EmptyState title="Belum ada jejak" description="Jejak muncul begitu ada keputusan yang dicatat." />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                      <thead>
                        <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                          <th className="px-3 py-2">Waktu</th>
                          <th className="px-3 py-2">Aksi</th>
                          <th className="px-3 py-2">Pelaku</th>
                          <th className="px-3 py-2">Sasaran</th>
                          <th className="px-3 py-2">Siswa</th>
                          <th className="px-3 py-2">Sebelum &rarr; Sesudah</th>
                        </tr>
                      </thead>
                      <tbody>
                        {audits.map((row) => (
                          <tr key={row.id} className="border-b border-slate-100">
                            <td className="px-3 py-2 text-xs text-slate-600">{row.created_at?.slice(0, 19).replace('T', ' ')}</td>
                            <td className="px-3 py-2 text-slate-800">{row.action_label ?? row.action}</td>
                            <td className="px-3 py-2 text-slate-600">
                              {row.actor?.name ?? row.actor_user_id}
                              {row.actor_role ? ` (${row.actor_role})` : ''}
                            </td>
                            <td className="px-3 py-2 text-xs text-slate-600">
                              {row.subject_type ? `${row.subject_type.split('\\').pop()}#${row.subject_id}` : '-'}
                            </td>
                            <td className="px-3 py-2 text-slate-600">{row.student_id ?? '-'}</td>
                            <td className="px-3 py-2 text-xs text-slate-600">
                              {row.before ? JSON.stringify(row.before) : '-'} &rarr; {row.after ? JSON.stringify(row.after) : '-'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </CardBody>
            </Card>
          )}

          {activeTab === 'consents' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Persetujuan orang tua yang menunggu</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2">
                {consentsQuery.isLoading ? (
                  <Spinner />
                ) : consents.length === 0 ? (
                  <EmptyState title="Tidak ada persetujuan menggantung" description="Semua permintaan sudah dijawab atau kedaluwarsa." />
                ) : (
                  consents.map((consent) => (
                    <div key={consent.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                      <div>
                        <p className="text-sm text-slate-800">
                          {consent.student_name} &middot; {consent.program_name}
                        </p>
                        <p className="text-xs text-slate-500">
                          {consent.scope_label ?? consent.scope} &middot; diminta {consent.requested_at?.slice(0, 10)} &middot; berlaku s.d.{' '}
                          {consent.expires_at?.slice(0, 10) ?? '-'}
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        <Badge tone="warning">{consent.decision_label ?? consent.decision}</Badge>
                        {can('activity.consent.decide') && (
                          <>
                            <Button size="sm" variant="outline" onClick={() => decideConsent.mutate({ id: consent.id, decision: 'APPROVED' })}>
                              Setujui
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => decideConsent.mutate({ id: consent.id, decision: 'DECLINED' })}>
                              Tolak
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  ))
                )}
              </CardBody>
            </Card>
          )}

          {activeTab === 'finance' && (
            <Card>
              <CardHeader>
                <CardTitle className="text-sm">Permintaan tagihan ke modul Keuangan</CardTitle>
              </CardHeader>
              <CardBody className="space-y-3">
                {chargesQuery.isLoading ? (
                  <Spinner />
                ) : charges.length === 0 ? (
                  <EmptyState title="Belum ada permintaan tagihan" description="Permintaan muncul saat pendaftaran program berbiaya." />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                      <thead>
                        <tr className="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-600">
                          <th className="px-3 py-2">Program</th>
                          <th className="px-3 py-2">Siswa</th>
                          <th className="px-3 py-2">Jenis</th>
                          <th className="px-3 py-2">Nilai</th>
                          <th className="px-3 py-2">Status</th>
                          <th className="px-3 py-2">Rujukan keuangan</th>
                          <th className="px-3 py-2" />
                        </tr>
                      </thead>
                      <tbody>
                        {charges.map((charge) => (
                          <tr key={charge.id} className="border-b border-slate-100">
                            <td className="px-3 py-2 text-slate-800">{charge.program?.name}</td>
                            <td className="px-3 py-2 text-slate-600">{charge.student?.full_name}</td>
                            <td className="px-3 py-2 text-slate-600">{charge.type}</td>
                            <td className="px-3 py-2 text-slate-700">{Number(charge.amount ?? 0).toLocaleString('id-ID')}</td>
                            <td className="px-3 py-2">
                              <Badge tone={charge.status === 'REQUESTED' ? 'warning' : charge.status === 'CANCELLED' ? 'neutral' : 'success'}>
                                {charge.status}
                              </Badge>
                            </td>
                            <td className="px-3 py-2 text-xs text-slate-500">{charge.external_reference ?? 'belum ditagihkan'}</td>
                            <td className="px-3 py-2 text-right">
                              {can('activity.finance.manage') && charge.status === 'REQUESTED' && (
                                <Button size="sm" variant="ghost" onClick={() => setReason({ kind: 'charge', id: charge.id, text: '' })}>
                                  Batalkan
                                </Button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}

                {reason.id !== null && reason.kind === 'charge' && (
                  <div className="space-y-2 rounded-md bg-amber-50 p-3">
                    <p className="text-xs text-amber-900">
                      Membatalkan permintaan tagihan #{reason.id}. Setelah Keuangan menerbitkan tagihan, pembatalan menjadi urusan modul
                      Keuangan.
                    </p>
                    <Textarea
                      rows={2}
                      placeholder="Alasan pembatalan"
                      value={reason.text}
                      onChange={(event) => setReason({ ...reason, text: event.target.value })}
                    />
                    <div className="flex gap-2">
                      <Button
                        size="sm"
                        variant="danger"
                        loading={cancelCharge.isPending}
                        disabled={reason.text.trim().length < 3}
                        onClick={() => cancelCharge.mutate()}
                      >
                        Batalkan permintaan
                      </Button>
                      <Button size="sm" variant="ghost" onClick={() => setReason({ kind: 'charge', id: null, text: '' })}>
                        Batal
                      </Button>
                    </div>
                  </div>
                )}
              </CardBody>
            </Card>
          )}

          {activeTab === 'resources' && (
            <div className="grid gap-4 lg:grid-cols-2">
              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Permintaan perlengkapan</CardTitle>
                </CardHeader>
                <CardBody className="space-y-2 text-sm">
                  {equipmentQuery.isLoading ? (
                    <Spinner />
                  ) : equipmentRows.length === 0 ? (
                    <p className="text-slate-500">Belum ada permintaan perlengkapan.</p>
                  ) : (
                    equipmentRows.map((row) => (
                      <div key={row.id} className="border-b border-slate-100 pb-2 last:border-0">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                          <div>
                            <p className="text-slate-800">{row.description}</p>
                            <p className="text-xs text-slate-500">
                              {row.program?.name} &middot; {row.quantity} unit &middot; dibutuhkan {row.due_on ?? '-'}
                            </p>
                          </div>
                          <div className="flex items-center gap-2">
                            <Badge tone={row.status === 'ISSUED' ? 'success' : row.status === 'REJECTED' ? 'danger' : 'neutral'}>
                              {row.status}
                            </Badge>
                            {can('activity.inventory.manage') && (
                              <Button size="sm" variant="ghost" onClick={() => setEquipment({ id: row.id, status: 'ISSUED', notes: '' })}>
                                Selaraskan
                              </Button>
                            )}
                          </div>
                        </div>

                        {equipment.id === row.id && (
                          <div className="mt-2 space-y-2 rounded-md bg-slate-50 p-3">
                            <Select
                              value={equipment.status}
                              onChange={(event) => setEquipment({ ...equipment, status: event.target.value })}
                              aria-label="Status perlengkapan"
                            >
                              {EQUIPMENT_STATUS.map((status) => (
                                <option key={status} value={status}>
                                  {status}
                                </option>
                              ))}
                            </Select>
                            <Input
                              placeholder="Catatan (mis. diserahkan 4 dari 4)"
                              value={equipment.notes}
                              onChange={(event) => setEquipment({ ...equipment, notes: event.target.value })}
                            />
                            <div className="flex gap-2">
                              <Button size="sm" loading={updateEquipment.isPending} onClick={() => updateEquipment.mutate()}>
                                Simpan
                              </Button>
                              <Button size="sm" variant="ghost" onClick={() => setEquipment({ id: null, status: 'ISSUED', notes: '' })}>
                                Batal
                              </Button>
                            </div>
                          </div>
                        )}
                      </div>
                    ))
                  )}
                </CardBody>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle className="text-sm">Pemesanan ruang aktivitas</CardTitle>
                </CardHeader>
                <CardBody className="space-y-2 text-sm">
                  {bookingsQuery.isLoading ? (
                    <Spinner />
                  ) : bookings.length === 0 ? (
                    <p className="text-slate-500">Belum ada pemesanan ruang.</p>
                  ) : (
                    bookings.map((booking) => (
                      <div key={booking.id} className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2 last:border-0">
                        <div>
                          <p className="text-slate-800">
                            {booking.program?.name} &middot; {booking.date} {booking.start_time?.slice(0, 5)}&ndash;{booking.end_time?.slice(0, 5)}
                          </p>
                          <p className="text-xs text-slate-500">
                            {booking.external_reference ?? 'belum terhubung Sarpras'}
                            {booking.conflict_reason ? ` - ${booking.conflict_reason}` : ''}
                          </p>
                        </div>
                        <div className="flex items-center gap-2">
                          <Badge tone={booking.status === 'CONFIRMED' ? 'success' : booking.status === 'CONFLICT' ? 'danger' : 'neutral'}>
                            {booking.status}
                          </Badge>
                          {can('activity.facility.manage') && booking.status !== 'CANCELLED' && (
                            <Button size="sm" variant="ghost" onClick={() => setReason({ kind: 'booking', id: booking.id, text: '' })}>
                              Batalkan
                            </Button>
                          )}
                        </div>
                      </div>
                    ))
                  )}

                  {reason.id !== null && reason.kind === 'booking' && (
                    <div className="space-y-2 rounded-md bg-amber-50 p-3">
                      <Textarea
                        rows={2}
                        placeholder="Alasan pembatalan pemesanan"
                        value={reason.text}
                        onChange={(event) => setReason({ ...reason, text: event.target.value })}
                      />
                      <div className="flex gap-2">
                        <Button
                          size="sm"
                          variant="danger"
                          loading={cancelBooking.isPending}
                          disabled={reason.text.trim().length < 3}
                          onClick={() => cancelBooking.mutate()}
                        >
                          Batalkan pemesanan
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setReason({ kind: 'booking', id: null, text: '' })}>
                          Batal
                        </Button>
                      </div>
                    </div>
                  )}
                </CardBody>
              </Card>
            </div>
          )}
        </>
      )}
    </div>
  );
}
