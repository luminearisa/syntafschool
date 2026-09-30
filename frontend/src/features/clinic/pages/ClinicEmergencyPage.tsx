import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Ambulance, Bandage, Phone } from 'lucide-react';
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
 * Gawat darurat & cedera.
 *
 * Prioritas layar ini adalah kecepatan: petugas dapat membuka peristiwa darurat
 * dengan mengetik NIS siswa saja, dan sistem membuat kunjungan darurat tanpa
 * menunggu administrasi. Kontak orang tua ditampilkan langsung agar tidak perlu
 * mencari di menu lain.
 */
export default function ClinicEmergencyPage() {
  const queryClient = useQueryClient();
  const [studentTerm, setStudentTerm] = useState('');
  const [emergencyForm, setEmergencyForm] = useState({
    location: '',
    description: '',
    action_taken: '',
    severity: 'EMERGENCY',
    ambulance_required: false,
    hospital: '',
  });
  const [injuryForm, setInjuryForm] = useState({
    location: '',
    activity: '',
    injury_type: 'Fall',
    body_part: '',
    mechanism: '',
    severity: 'MODERATE',
    treatment: '',
    witnesses: '',
  });

  const studentResults = useQuery({
    queryKey: ['clinic', 'students', studentTerm],
    queryFn: () => clinicApi.searchStudents(studentTerm),
    enabled: studentTerm.trim().length >= 3,
  });

  const emergencies = useQuery({
    queryKey: ['clinic', 'emergencies'],
    queryFn: () => clinicApi.emergencies({ open_only: 1 }),
    refetchInterval: 20_000,
  });

  const injuries = useQuery({
    queryKey: ['clinic', 'injuries'],
    queryFn: () => clinicApi.injuries({ status: 'OPEN' }),
  });

  const openEmergency = useMutation({
    mutationFn: (studentId: number) =>
      clinicApi.openEmergency({ student_id: studentId, ...emergencyForm }),
    onSuccess: (result) => {
      toast.success(`Gawat darurat ${result.emergency.incident_number} dibuka. Orang tua dikabari.`);
      setEmergencyForm({ location: '', description: '', action_taken: '', severity: 'EMERGENCY', ambulance_required: false, hospital: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const recordInjury = useMutation({
    mutationFn: (studentId: number) => clinicApi.recordInjury({ student_id: studentId, ...injuryForm }),
    onSuccess: (injury) => {
      toast.success(`Cedera ${injury.incident_number} dicatat.`);
      setInjuryForm({ location: '', activity: '', injury_type: 'Fall', body_part: '', mechanism: '', severity: 'MODERATE', treatment: '', witnesses: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const contact = useMutation({
    mutationFn: (id: number) => clinicApi.updateEmergency(id, { parent_contacted: true }),
    onSuccess: () => {
      toast.success('Tercatat: orang tua sudah dihubungi.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'emergencies'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const closeEmergency = useMutation({
    mutationFn: ({ id, outcome }: { id: number; outcome: string }) => clinicApi.closeEmergency(id, outcome),
    onSuccess: () => {
      toast.success('Peristiwa ditutup.');
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'emergencies'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const students = studentResults.data ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Gawat Darurat & Cedera</h1>
        <p className="text-sm text-slate-500">
          Keadaan darurat melewati antrean biasa: kunjungan dibuat otomatis dan orang tua diberi kabar segera.
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Pasien</CardTitle>
        </CardHeader>
        <CardBody className="space-y-2">
          <Input
            placeholder="Ketik nama atau NIS siswa (min. 3 huruf)"
            value={studentTerm}
            onChange={(event) => setStudentTerm(event.target.value)}
          />
          {students.length > 0 ? (
            <div className="divide-y divide-slate-100 rounded-md border">
              {students.map((student) => (
                <div key={student.id} className="flex flex-wrap items-center justify-between gap-2 p-2">
                  <div>
                    <p className="text-sm font-medium text-slate-800">{student.name}</p>
                    <p className="text-xs text-slate-500">
                      {student.nis ?? '—'} · {student.class_name ?? 'tanpa kelas'}
                    </p>
                  </div>
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      variant="danger"
                      onClick={() => openEmergency.mutate(student.id)}
                      disabled={openEmergency.isPending}
                    >
                      <Ambulance className="mr-1 h-4 w-4" /> Gawat darurat
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => recordInjury.mutate(student.id)}
                      disabled={recordInjury.isPending}
                    >
                      <Bandage className="mr-1 h-4 w-4" /> Catat cedera
                    </Button>
                  </div>
                </div>
              ))}
            </div>
          ) : null}
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Formulir gawat darurat</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
              <div>
                <Label>Tingkat kegawatan</Label>
                <Select
                  value={emergencyForm.severity}
                  onChange={(event) => setEmergencyForm({ ...emergencyForm, severity: event.target.value })}
                >
                  <option value="EMERGENCY">Gawat darurat</option>
                  <option value="URGENT">Mendesak</option>
                  <option value="SEMI_URGENT">Semi mendesak</option>
                </Select>
              </div>
              <div>
                <Label>Lokasi kejadian</Label>
                <Input
                  value={emergencyForm.location}
                  onChange={(event) => setEmergencyForm({ ...emergencyForm, location: event.target.value })}
                />
              </div>
            </div>
            <Textarea
              placeholder="Kronologi singkat (wajib)"
              value={emergencyForm.description}
              onChange={(event) => setEmergencyForm({ ...emergencyForm, description: event.target.value })}
            />
            <Textarea
              placeholder="Tindakan segera yang dilakukan"
              value={emergencyForm.action_taken}
              onChange={(event) => setEmergencyForm({ ...emergencyForm, action_taken: event.target.value })}
            />
            <div className="grid grid-cols-2 gap-2">
              <Input
                placeholder="Rumah sakit tujuan (bila ada)"
                value={emergencyForm.hospital}
                onChange={(event) => setEmergencyForm({ ...emergencyForm, hospital: event.target.value })}
              />
              <label className="flex items-center gap-2 text-sm text-slate-600">
                <input
                  type="checkbox"
                  checked={emergencyForm.ambulance_required}
                  onChange={(event) =>
                    setEmergencyForm({ ...emergencyForm, ambulance_required: event.target.checked })
                  }
                />
                Butuh ambulans
              </label>
            </div>
            <p className="text-xs text-slate-500">
              Pilih siswa pada daftar di atas untuk membuka peristiwa dengan data ini.
            </p>
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Formulir cedera</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div className="grid grid-cols-2 gap-2">
              <Input
                placeholder="Lokasi (wajib)"
                value={injuryForm.location}
                onChange={(event) => setInjuryForm({ ...injuryForm, location: event.target.value })}
              />
              <Input
                placeholder="Aktivitas saat kejadian"
                value={injuryForm.activity}
                onChange={(event) => setInjuryForm({ ...injuryForm, activity: event.target.value })}
              />
              <Input
                placeholder="Jenis cedera (Fall/Cut/Burn/...)"
                value={injuryForm.injury_type}
                onChange={(event) => setInjuryForm({ ...injuryForm, injury_type: event.target.value })}
              />
              <Input
                placeholder="Bagian tubuh"
                value={injuryForm.body_part}
                onChange={(event) => setInjuryForm({ ...injuryForm, body_part: event.target.value })}
              />
              <Select
                value={injuryForm.severity}
                onChange={(event) => setInjuryForm({ ...injuryForm, severity: event.target.value })}
              >
                <option value="MINOR">Ringan</option>
                <option value="MODERATE">Sedang</option>
                <option value="SEVERE">Berat</option>
                <option value="CRITICAL">Kritis</option>
              </Select>
              <Input
                placeholder="Saksi"
                value={injuryForm.witnesses}
                onChange={(event) => setInjuryForm({ ...injuryForm, witnesses: event.target.value })}
              />
            </div>
            <Textarea
              placeholder="Mekanisme kejadian"
              value={injuryForm.mechanism}
              onChange={(event) => setInjuryForm({ ...injuryForm, mechanism: event.target.value })}
            />
            <Textarea
              placeholder="Tindakan awal"
              value={injuryForm.treatment}
              onChange={(event) => setInjuryForm({ ...injuryForm, treatment: event.target.value })}
            />
            <p className="text-xs text-slate-500">
              Cedera sedang/berat atau yang perlu rujukan otomatis mengabarkan orang tua. Cedera ringan tidak — supaya
              orang tua tidak khawatir berlebihan.
            </p>
          </CardBody>
        </Card>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Peristiwa gawat darurat berjalan</CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          {(emergencies.data ?? []).length === 0 ? (
            <div className="p-4">
              <EmptyState title="Tidak ada peristiwa darurat aktif" description="Semua peristiwa sudah ditutup." />
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {(emergencies.data ?? []).map((row) => (
                <div key={row.id} className="space-y-2 p-3">
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge tone="danger">{row.severity}</Badge>
                    <span className="font-medium text-slate-900">{row.incident_number}</span>
                    <span className="text-sm text-slate-700">{row.patient?.student?.full_name ?? 'Pasien'}</span>
                    {row.ambulance_required ? <Badge tone="warning">Butuh ambulans</Badge> : null}
                  </div>
                  <p className="text-sm text-slate-600">
                    {new Date(row.incident_at).toLocaleString('id-ID')} · {row.location ?? 'lokasi tidak dicatat'}
                  </p>
                  <p className="text-sm text-slate-800">{row.description}</p>
                  {row.action_taken ? <p className="text-xs text-slate-600">Tindakan: {row.action_taken}</p> : null}

                  <div className="flex flex-wrap items-center gap-2">
                    {row.parent_contacted ? (
                      <Badge tone="success">Orang tua sudah dihubungi</Badge>
                    ) : (
                      <Button size="sm" variant="danger" onClick={() => contact.mutate(row.id)} disabled={contact.isPending}>
                        <Phone className="mr-1 h-4 w-4" /> Tandai orang tua dihubungi
                      </Button>
                    )}

                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => {
                        const outcome = window.prompt('Hasil akhir peristiwa (wajib):');
                        if (outcome && outcome.trim() !== '') {
                          closeEmergency.mutate({ id: row.id, outcome });
                        }
                      }}
                    >
                      Tutup peristiwa
                    </Button>

                    {row.patient?.id ? (
                      <Link to="/clinic/referrals">
                        <Button size="sm" variant="secondary">
                          Buat rujukan
                        </Button>
                      </Link>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Cedera yang belum selesai</CardTitle>
        </CardHeader>
        <CardBody className="p-0">
          {(injuries.data ?? []).length === 0 ? (
            <div className="p-4">
              <EmptyState title="Tidak ada cedera terbuka" description="Seluruh cedera sudah ditangani." />
            </div>
          ) : (
            <div className="divide-y divide-slate-100">
              {(injuries.data ?? []).map((row) => (
                <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-3">
                  <div>
                    <p className="text-sm font-medium text-slate-900">
                      {row.patient?.student?.full_name ?? 'Pasien'} — {row.injury_type}
                    </p>
                    <p className="text-xs text-slate-500">
                      {row.incident_number} · {row.body_part ?? '—'} · {row.location ?? '—'} · {row.severity}
                    </p>
                    {row.treatment ? <p className="text-xs text-slate-600">Tindakan: {row.treatment}</p> : null}
                  </div>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() =>
                      clinicApi
                        .closeInjury(row.id, 'Ditangani, tidak perlu tindak lanjut')
                        .then(() => {
                          toast.success('Cedera ditutup.');
                          void queryClient.invalidateQueries({ queryKey: ['clinic', 'injuries'] });
                        })
                        .catch((error) => toast.error(getErrorMessage(error)))
                    }
                  >
                    Tutup cedera
                  </Button>
                </div>
              ))}
            </div>
          )}
        </CardBody>
      </Card>
    </div>
  );
}
