import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { SEVERITY_TONE, STATUS_TONE } from '../types';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { Spinner } from '@/components/ui/Spinner';
import { Textarea } from '@/components/ui/Textarea';
import { Can } from '@/components/common/Can';
import { getErrorMessage } from '@/lib/axios';

const OPEN_STATUSES = ['MENUNGGU', 'DITANGANI', 'ISTIRAHAT'];

/** Detail kunjungan: penanganan petugas, obat, rujukan, dan persetujuan orang tua. */
export default function UksVisitDetailPage() {
  const { visitId } = useParams();
  const id = Number(visitId);
  const queryClient = useQueryClient();

  const visit = useQuery({
    queryKey: ['uks', 'visit', id],
    queryFn: () => uksApi.visit(id),
    enabled: Number.isFinite(id),
  });

  const [treatment, setTreatment] = useState('');
  const [advice, setAdvice] = useState('');
  const [status, setStatus] = useState('DITANGANI');
  const [medicineId, setMedicineId] = useState('');
  const [quantity, setQuantity] = useState('1');
  const [dosage, setDosage] = useState('');
  const [facility, setFacility] = useState('');
  const [reason, setReason] = useState('');

  const medicines = useQuery({
    queryKey: ['uks', 'medicines', 'picker'],
    queryFn: () => uksApi.medicines({ per_page: 100 }),
  });

  const refresh = () => {
    queryClient.invalidateQueries({ queryKey: ['uks', 'visit', id] });
    queryClient.invalidateQueries({ queryKey: ['uks', 'visits'] });
  };

  const handle = useMutation({
    mutationFn: () => uksApi.handleVisit(id, { treatment, advice, status, sent_home: status === 'PULANG' }),
    onSuccess: () => { toast.success('Penanganan disimpan'); refresh(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const medicate = useMutation({
    mutationFn: () => uksApi.giveMedication(id, { medicine_id: Number(medicineId), quantity: Number(quantity), dosage }),
    onSuccess: () => { toast.success('Obat diberikan, stok berkurang'); setMedicineId(''); refresh(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const refer = useMutation({
    mutationFn: () => uksApi.referVisit(id, { facility_name: facility, reason }),
    onSuccess: () => { toast.success('Rujukan dicatat dan orang tua dikabari'); refresh(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const consent = useMutation({
    mutationFn: (decision: 'DISETUJUI' | 'DITOLAK') => uksApi.consentVisit(id, { decision }),
    onSuccess: () => { toast.success('Persetujuan dicatat'); refresh(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const close = useMutation({
    mutationFn: () => uksApi.closeVisit(id),
    onSuccess: () => { toast.success('Kunjungan ditutup'); refresh(); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  if (visit.isLoading) return <Spinner />;
  const data = visit.data;
  if (!data) return <p className="text-sm text-slate-500">Kunjungan tidak ditemukan.</p>;

  const isOpen = OPEN_STATUSES.includes(data.status);

  return (
    <div className="space-y-4">
      <Link to="/uks/visits" className="text-xs text-slate-500 hover:underline">← Semua kunjungan</Link>

      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h1 className="text-lg font-semibold text-slate-900">
            {data.student?.full_name} <span className="text-sm text-slate-400">{data.code}</span>
          </h1>
          <p className="text-sm text-slate-500">
            {data.visit_type_label} · {data.class_name ?? 'tanpa kelas'} · tiba {data.arrived_at}
          </p>
        </div>
        <div className="flex gap-1">
          <Badge tone={SEVERITY_TONE[data.severity] ?? 'gray'}>{data.severity_label}</Badge>
          <Badge tone={STATUS_TONE[data.status] ?? 'gray'}>{data.status_label}</Badge>
        </div>
      </div>

      <Card>
        <CardHeader><CardTitle>Keluhan</CardTitle></CardHeader>
        <CardBody className="space-y-1 text-sm">
          <p className="font-medium">{data.complaint}</p>
          {data.symptoms ? <p className="text-slate-600">{data.symptoms}</p> : null}
          {data.treatment ? <p><span className="text-slate-400">Tindakan:</span> {data.treatment}</p> : null}
          {data.advice ? <p><span className="text-slate-400">Anjuran:</span> {data.advice}</p> : null}
          <p className="text-xs text-slate-400">
            Dilaporkan {data.reported_by ?? '-'} · Ditangani {data.handled_by ?? '-'} ·{' '}
            Orang tua {data.parent_notified ? `dikabari ${data.parent_notified_at ?? ''}` : 'belum dikabari'}
          </p>
        </CardBody>
      </Card>

      {data.medications && data.medications.length > 0 ? (
        <Card>
          <CardHeader><CardTitle>Obat yang diberikan</CardTitle></CardHeader>
          <CardBody>
            <ul className="space-y-1 text-sm">
              {data.medications.map((med) => (
                <li key={med.id}>{med.medicine_name} — {med.quantity} {med.unit} {med.dosage ? `(${med.dosage})` : ''}</li>
              ))}
            </ul>
          </CardBody>
        </Card>
      ) : null}

      {data.consents && data.consents.length > 0 ? (
        <Card>
          <CardHeader><CardTitle>Persetujuan orang tua</CardTitle></CardHeader>
          <CardBody>
            <ul className="space-y-1 text-sm">
              {data.consents.map((consent) => (
                <li key={consent.id}>
                  <Badge tone={consent.decision === 'DISETUJUI' ? 'green' : 'red'}>{consent.decision}</Badge>
                  <span className="ml-2 text-slate-500">via {consent.channel} · {consent.decided_at}</span>
                  {consent.note ? <span className="ml-2">{consent.note}</span> : null}
                </li>
              ))}
            </ul>
          </CardBody>
        </Card>
      ) : null}

      {data.referral ? (
        <Card>
          <CardHeader><CardTitle>Rujukan</CardTitle></CardHeader>
          <CardBody className="text-sm">
            <p>{data.referral.facility_name} — {data.referral.reason}</p>
            <p className="text-xs text-slate-400">Status: {data.referral.status}{data.referral.outcome ? ` · ${data.referral.outcome}` : ''}</p>
          </CardBody>
        </Card>
      ) : null}

      <Can perm="uks.consent.create">
        {isOpen ? (
          <Card>
            <CardHeader><CardTitle>Persetujuan orang tua/wali</CardTitle></CardHeader>
            <CardBody className="flex gap-2">
              <Button onClick={() => consent.mutate('DISETUJUI')} loading={consent.isPending}>Setujui penanganan</Button>
              <Button variant="danger" onClick={() => consent.mutate('DITOLAK')} loading={consent.isPending}>Tolak</Button>
            </CardBody>
          </Card>
        ) : null}
      </Can>

      <Can perm="uks.visit.manage">
        {isOpen ? (
          <div className="grid gap-3 md:grid-cols-2">
            <Card>
              <CardHeader><CardTitle>Penanganan petugas</CardTitle></CardHeader>
              <CardBody className="space-y-2">
                <Textarea placeholder="Tindakan yang dilakukan" value={treatment} onChange={(e) => setTreatment(e.target.value)} rows={2} />
                <Textarea placeholder="Anjuran untuk siswa/orang tua" value={advice} onChange={(e) => setAdvice(e.target.value)} rows={2} />
                <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                  <option value="DITANGANI">Ditangani</option>
                  <option value="ISTIRAHAT">Istirahat di UKS</option>
                  <option value="PULANG">Dipulangkan</option>
                  <option value="SELESAI">Selesai</option>
                </Select>
                <Button onClick={() => handle.mutate()} loading={handle.isPending}>Simpan penanganan</Button>
              </CardBody>
            </Card>

            <Card>
              <CardHeader><CardTitle>Beri obat</CardTitle></CardHeader>
              <CardBody className="space-y-2">
                <Select value={medicineId} onChange={(e) => setMedicineId(e.target.value)}>
                  <option value="">Pilih obat</option>
                  {medicines.data?.data.map((medicine) => (
                    <option key={medicine.id} value={medicine.id}>
                      {medicine.name} (stok {medicine.stock} {medicine.unit})
                    </option>
                  ))}
                </Select>
                <div className="grid grid-cols-2 gap-2">
                  <Input type="number" min="0.5" step="0.5" value={quantity} onChange={(e) => setQuantity(e.target.value)} />
                  <Input placeholder="Dosis, mis. 1 tablet" value={dosage} onChange={(e) => setDosage(e.target.value)} />
                </div>
                <Button disabled={medicineId === ''} onClick={() => medicate.mutate()} loading={medicate.isPending}>Berikan</Button>
                <p className="text-xs text-slate-400">Stok berkurang otomatis. Obat kedaluwarsa ditolak server.</p>
              </CardBody>
            </Card>

            {!data.referral ? (
              <Card>
                <CardHeader><CardTitle>Rujuk ke faskes</CardTitle></CardHeader>
                <CardBody className="space-y-2">
                  <div>
                    <Label>Fasilitas tujuan</Label>
                    <Input value={facility} onChange={(e) => setFacility(e.target.value)} placeholder="Puskesmas / klinik / RS" />
                  </div>
                  <Textarea placeholder="Alasan rujukan" value={reason} onChange={(e) => setReason(e.target.value)} rows={2} />
                  <Button disabled={facility.trim() === '' || reason.trim() === ''} onClick={() => refer.mutate()} loading={refer.isPending}>
                    Buat rujukan
                  </Button>
                </CardBody>
              </Card>
            ) : null}

            <Card>
              <CardHeader><CardTitle>Tutup kunjungan</CardTitle></CardHeader>
              <CardBody>
                <Button variant="ghost" onClick={() => close.mutate()} loading={close.isPending}>Tandai selesai</Button>
                <p className="mt-2 text-xs text-slate-400">Kunjungan yang ditutup tidak dapat diubah lagi.</p>
              </CardBody>
            </Card>
          </div>
        ) : (
          <p className="text-xs text-slate-400">Kunjungan sudah {data.status_label.toLowerCase()} dan terkunci.</p>
        )}
      </Can>
    </div>
  );
}
