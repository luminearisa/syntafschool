import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { uksApi } from '../api/uksApi';
import { StudentPicker } from '../components/StudentPicker';
import { STATUS_TONE } from '../types';
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

/** Rekam kesehatan seorang siswa: profil, pemeriksaan berkala, imunisasi. */
export default function UksRecordsPage() {
  const queryClient = useQueryClient();
  const [studentId, setStudentId] = useState<number | null>(null);
  const [studentName, setStudentName] = useState('');

  const meta = useQuery({ queryKey: ['uks', 'meta'], queryFn: () => uksApi.metadata() });
  const record = useQuery({
    queryKey: ['uks', 'record', studentId],
    queryFn: () => uksApi.record(studentId as number),
    enabled: studentId !== null,
  });

  const [profile, setProfile] = useState({ blood_type: '', allergies: '', chronic_conditions: '', emergency_contact_name: '', emergency_contact_phone: '' });
  const [screening, setScreening] = useState({ screening_type: 'UMUM', height_cm: '', weight_kg: '', result: 'NORMAL', findings: '' });
  const [vaccine, setVaccine] = useState({ vaccine_name: '', dose_number: '1', given_at: '', next_due_at: '' });

  const saveProfile = useMutation({
    mutationFn: () => uksApi.saveProfile(studentId as number, profile),
    onSuccess: () => { toast.success('Profil disimpan'); queryClient.invalidateQueries({ queryKey: ['uks', 'record', studentId] }); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const addScreening = useMutation({
    mutationFn: () => uksApi.createScreening({
      student_id: studentId,
      ...screening,
      height_cm: screening.height_cm ? Number(screening.height_cm) : undefined,
      weight_kg: screening.weight_kg ? Number(screening.weight_kg) : undefined,
    }),
    onSuccess: () => { toast.success('Pemeriksaan dicatat (IMT dihitung otomatis)'); queryClient.invalidateQueries({ queryKey: ['uks', 'record', studentId] }); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const addVaccine = useMutation({
    mutationFn: () => uksApi.createImmunization({
      student_id: studentId,
      vaccine_name: vaccine.vaccine_name,
      dose_number: Number(vaccine.dose_number),
      given_at: vaccine.given_at || undefined,
      next_due_at: vaccine.next_due_at || undefined,
    }),
    onSuccess: () => { toast.success('Imunisasi dicatat'); setVaccine({ ...vaccine, vaccine_name: '' }); queryClient.invalidateQueries({ queryKey: ['uks', 'record', studentId] }); },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  return (
    <div className="space-y-4">
      <h1 className="text-lg font-semibold text-slate-900">Rekam Kesehatan Siswa</h1>
      <div className="max-w-md">
        <StudentPicker value={studentId} onChange={(id, label) => { setStudentId(id); setStudentName(label); }} />
      </div>

      {record.isLoading ? <Spinner /> : null}

      {record.data ? (
        <>
          <p className="text-sm text-slate-500">
            {studentName}: {record.data.visits_count} kunjungan · {record.data.incidents_count} insiden
          </p>

          <div className="grid gap-3 md:grid-cols-2">
            <Card>
              <CardHeader><CardTitle>Profil kesehatan</CardTitle></CardHeader>
              <CardBody className="space-y-2 text-sm">
                {record.data.profile ? (
                  <ul className="space-y-1">
                    <li>Golongan darah: {record.data.profile.blood_type ?? '-'}</li>
                    <li>Alergi: {record.data.profile.allergies ?? '-'}</li>
                    <li>Penyakit bawaan: {record.data.profile.chronic_conditions ?? '-'}</li>
                    <li>Kontak darurat: {record.data.profile.emergency_contact_name ?? '-'} ({record.data.profile.emergency_contact_phone ?? '-'})</li>
                  </ul>
                ) : <p className="text-slate-400">Belum ada profil.</p>}

                <Can perm="uks.record.manage">
                  <div className="space-y-2 border-t border-slate-100 pt-2">
                    <div className="grid grid-cols-2 gap-2">
                      <Input placeholder="Gol. darah" value={profile.blood_type} onChange={(e) => setProfile({ ...profile, blood_type: e.target.value })} />
                      <Input placeholder="Alergi" value={profile.allergies} onChange={(e) => setProfile({ ...profile, allergies: e.target.value })} />
                    </div>
                    <Input placeholder="Penyakit bawaan" value={profile.chronic_conditions} onChange={(e) => setProfile({ ...profile, chronic_conditions: e.target.value })} />
                    <div className="grid grid-cols-2 gap-2">
                      <Input placeholder="Nama kontak darurat" value={profile.emergency_contact_name} onChange={(e) => setProfile({ ...profile, emergency_contact_name: e.target.value })} />
                      <Input placeholder="No. HP" value={profile.emergency_contact_phone} onChange={(e) => setProfile({ ...profile, emergency_contact_phone: e.target.value })} />
                    </div>
                    <Button size="sm" onClick={() => saveProfile.mutate()} loading={saveProfile.isPending}>Simpan profil</Button>
                  </div>
                </Can>
              </CardBody>
            </Card>

            <Card>
              <CardHeader><CardTitle>Imunisasi</CardTitle></CardHeader>
              <CardBody className="space-y-2 text-sm">
                <ul className="space-y-1">
                  {record.data.immunizations.map((row) => (
                    <li key={row.id}>{row.vaccine_name} dosis {row.dose_number} · {row.given_at}{row.next_due_at ? ` (susulan ${row.next_due_at})` : ''}</li>
                  ))}
                  {record.data.immunizations.length === 0 ? <li className="text-slate-400">Belum ada catatan.</li> : null}
                </ul>
                <Can perm="uks.record.manage">
                  <div className="space-y-2 border-t border-slate-100 pt-2">
                    <Input placeholder="Nama vaksin" value={vaccine.vaccine_name} onChange={(e) => setVaccine({ ...vaccine, vaccine_name: e.target.value })} />
                    <div className="grid grid-cols-2 gap-2">
                      <Input type="date" value={vaccine.given_at} onChange={(e) => setVaccine({ ...vaccine, given_at: e.target.value })} />
                      <Input type="date" value={vaccine.next_due_at} onChange={(e) => setVaccine({ ...vaccine, next_due_at: e.target.value })} />
                    </div>
                    <Button size="sm" disabled={vaccine.vaccine_name.trim() === ''} onClick={() => addVaccine.mutate()} loading={addVaccine.isPending}>Catat imunisasi</Button>
                  </div>
                </Can>
              </CardBody>
            </Card>
          </div>

          <Card>
            <CardHeader><CardTitle>Pemeriksaan berkala</CardTitle></CardHeader>
            <CardBody className="space-y-2 text-sm">
              <ul className="space-y-1">
                {record.data.screenings.map((row) => (
                  <li key={row.id} className="flex items-center gap-2">
                    <Badge tone={STATUS_TONE[row.result] ?? 'gray'}>{row.result}</Badge>
                    {row.screening_type} · {row.screened_at}
                    {row.bmi ? ` · IMT ${row.bmi}` : ''} ({row.height_cm ?? '-'} cm / {row.weight_kg ?? '-'} kg)
                  </li>
                ))}
                {record.data.screenings.length === 0 ? <li className="text-slate-400">Belum ada pemeriksaan.</li> : null}
              </ul>
              <Can perm="uks.record.manage">
                <div className="grid grid-cols-2 gap-2 border-t border-slate-100 pt-2 md:grid-cols-4">
                  <Select value={screening.screening_type} onChange={(e) => setScreening({ ...screening, screening_type: e.target.value })}>
                    {meta.data?.screening_types.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </Select>
                  <Input type="number" placeholder="Tinggi (cm)" value={screening.height_cm} onChange={(e) => setScreening({ ...screening, height_cm: e.target.value })} />
                  <Input type="number" placeholder="Berat (kg)" value={screening.weight_kg} onChange={(e) => setScreening({ ...screening, weight_kg: e.target.value })} />
                  <Select value={screening.result} onChange={(e) => setScreening({ ...screening, result: e.target.value })}>
                    {meta.data?.screening_results.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                  </Select>
                  <Textarea className="col-span-2 md:col-span-4" rows={2} placeholder="Temuan" value={screening.findings} onChange={(e) => setScreening({ ...screening, findings: e.target.value })} />
                  <Button size="sm" onClick={() => addScreening.mutate()} loading={addScreening.isPending}>Catat pemeriksaan</Button>
                </div>
              </Can>
            </CardBody>
          </Card>
        </>
      ) : null}
    </div>
  );
}
