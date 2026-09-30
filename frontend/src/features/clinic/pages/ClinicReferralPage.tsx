import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { CalendarCheck, Send } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';

/**
 * Rujukan & tindak lanjut.
 *
 * Satu alur yang sering terputus di sekolah: pasien dirujuk, lalu hasilnya tidak
 * pernah tercatat. Layar ini memaksa ketiga hal itu berurutan — rujukan punya
 * alasan, perjalanannya terpantau, dan setiap rujukan selesai melahirkan
 * tindak lanjut untuk membaca hasil.
 */
export default function ClinicReferralPage() {
  const queryClient = useQueryClient();
  const [visitId, setVisitId] = useState('');
  const [form, setForm] = useState({
    facility: '',
    facility_type: 'HOSPITAL',
    urgency: 'ROUTINE',
    reason: '',
    transport: '',
  });

  const referrals = useQuery({
    queryKey: ['clinic', 'referrals'],
    queryFn: () => clinicApi.referrals({}),
  });

  const followUps = useQuery({
    queryKey: ['clinic', 'follow-ups'],
    queryFn: () => clinicApi.followUps({}),
  });

  const openVisits = useQuery({
    queryKey: ['clinic', 'visits', 'open'],
    queryFn: () => clinicApi.visits({ open_only: true, per_page: 50 }),
  });

  const createReferral = useMutation({
    mutationFn: () => clinicApi.createReferral(Number(visitId), form),
    onSuccess: (created) => {
      toast.success(`Rujukan ${created.referral_number} dibuat — orang tua diberi kabar.`);
      setForm({ facility: '', facility_type: 'HOSPITAL', urgency: 'ROUTINE', reason: '', transport: '' });
      setVisitId('');
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const updateReferral = useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Record<string, unknown> }) =>
      clinicApi.updateReferral(id, payload),
    onSuccess: () => {
      toast.success('Rujukan diperbarui.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'referrals'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const completeFollowUp = useMutation({
    mutationFn: (id: number) => clinicApi.completeFollowUp(id, 'Ditindaklanjuti oleh petugas klinik'),
    onSuccess: () => {
      toast.success('Tindak lanjut diselesaikan.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'follow-ups'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = referrals.data ?? [];
  const followUpRows = followUps.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Rujukan & Tindak Lanjut</h1>
        <p className="text-sm text-slate-500">
          Rujukan wajib punya tujuan dan alasan; hasilnya dicatat agar tidak hilang setelah pasien kembali.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Send className="h-4 w-4" /> Buat rujukan
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div>
              <Label>Kunjungan aktif</Label>
              <Select value={visitId} onChange={(event) => setVisitId(event.target.value)}>
                <option value="">— pilih kunjungan —</option>
                {(openVisits.data?.data ?? []).map((visit) => (
                  <option key={visit.id} value={visit.id}>
                    {visit.visit_number} · {visit.patient.name ?? 'Pasien'} · {visit.status_label}
                  </option>
                ))}
              </Select>
            </div>
            <div>
              <Label>Fasilitas tujuan</Label>
              <Input
                value={form.facility}
                placeholder="mis. RSUD Kota / Puskesmas"
                onChange={(event) => setForm({ ...form, facility: event.target.value })}
              />
            </div>
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label>Jenis fasilitas</Label>
                <Select
                  value={form.facility_type}
                  onChange={(event) => setForm({ ...form, facility_type: event.target.value })}
                >
                  <option value="HOSPITAL">Rumah sakit</option>
                  <option value="PUSKESMAS">Puskesmas</option>
                  <option value="CLINIC">Klinik</option>
                  <option value="LAB">Laboratorium</option>
                  <option value="OTHER">Lainnya</option>
                </Select>
              </div>
              <div>
                <Label>Urgensi</Label>
                <Select value={form.urgency} onChange={(event) => setForm({ ...form, urgency: event.target.value })}>
                  <option value="ROUTINE">Rutin</option>
                  <option value="URGENT">Mendesak</option>
                  <option value="EMERGENCY">Gawat darurat</option>
                </Select>
              </div>
            </div>
            <Textarea
              placeholder="Alasan rujukan (wajib)"
              value={form.reason}
              onChange={(event) => setForm({ ...form, reason: event.target.value })}
            />
            <Input
              placeholder="Transportasi"
              value={form.transport}
              onChange={(event) => setForm({ ...form, transport: event.target.value })}
            />
            <Button
              className="w-full"
              onClick={() => createReferral.mutate()}
              disabled={visitId === '' || form.facility.trim() === '' || form.reason.trim() === '' || createReferral.isPending}
            >
              Buat rujukan
            </Button>
            <p className="text-xs text-slate-500">
              Modul ini tidak terhubung ke sistem rumah sakit: status perjalanan diisi manual oleh petugas.
            </p>
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Daftar rujukan</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            {rows.length === 0 ? (
              <div className="p-4">
                <EmptyState title="Belum ada rujukan" description="Rujukan yang dibuat akan tampil di sini beserta statusnya." />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <div key={row.id} className="space-y-2 p-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">{row.referral_number}</span>
                      <Badge tone={row.urgency === 'EMERGENCY' ? 'danger' : row.urgency === 'URGENT' ? 'warning' : 'neutral'}>
                        {row.urgency}
                      </Badge>
                      <Badge tone={row.status === 'COMPLETED' ? 'success' : 'info'}>{row.status}</Badge>
                      <span className="text-sm text-slate-700">{row.patient?.student?.full_name ?? 'Pasien'}</span>
                    </div>
                    <p className="text-sm text-slate-600">
                      {row.facility} · {new Date(row.referral_date).toLocaleString('id-ID')}
                    </p>
                    <p className="text-sm text-slate-800">{row.reason}</p>
                    {row.outcome ? <p className="text-xs text-emerald-700">Hasil: {row.outcome}</p> : null}

                    {row.status !== 'COMPLETED' && row.status !== 'CANCELLED' ? (
                      <div className="flex flex-wrap gap-2">
                        {(['IN_TRANSIT', 'ARRIVED'] as const).map((status) => (
                          <Button
                            key={status}
                            size="sm"
                            variant="secondary"
                            onClick={() => updateReferral.mutate({ id: row.id, payload: { status } })}
                            disabled={updateReferral.isPending}
                          >
                            {status === 'IN_TRANSIT' ? 'Dalam perjalanan' : 'Tiba di fasilitas'}
                          </Button>
                        ))}
                        <Button
                          size="sm"
                          onClick={() => {
                            const outcome = window.prompt('Hasil rujukan (wajib):');
                            if (outcome && outcome.trim() !== '') {
                              updateReferral.mutate({
                                id: row.id,
                                payload: { status: 'COMPLETED', outcome, follow_up_date: null },
                              });
                            }
                          }}
                          disabled={updateReferral.isPending}
                        >
                          Selesaikan
                        </Button>
                      </div>
                    ) : null}
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <CalendarCheck className="h-4 w-4" /> Tindak lanjut
          </CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          {followUpRows.length === 0 ? (
            <div className="p-4">
              <EmptyState title="Tidak ada tindak lanjut" description="Jadwal kontrol dan perawatan lanjutan akan tampil di sini." />
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {followUpRows.map((row) => (
                <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                  <div>
                    <p className="text-sm font-medium text-slate-900">
                      {row.patient?.student?.full_name ?? 'Pasien'} — {row.type}
                    </p>
                    <p className="text-xs text-slate-500">
                      Jatuh tempo {row.due_date} · {row.status}
                      {row.is_overdue ? ' · TERLAMBAT' : ''}
                    </p>
                    {row.instruction ? <p className="text-xs text-slate-600">{row.instruction}</p> : null}
                  </div>
                  {row.status !== 'COMPLETED' ? (
                    <Button size="sm" variant="secondary" onClick={() => completeFollowUp.mutate(row.id)}>
                      Tandai selesai
                    </Button>
                  ) : (
                    <Badge tone="success">Selesai</Badge>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      <p className="text-xs text-slate-500">
        Butuh membuka riwayat pasien? Gunakan <Link className="text-sky-600 underline" to="/clinic/patients">Pasien & Rekam Medis</Link>.
      </p>
    </div>
  );
}
