import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { HeartPulse, Syringe } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';

/**
 * Skrining kesehatan berkala & imunisasi.
 *
 * Skrining dikerjakan massal per kelas, sehingga layar ini menampilkan daftar
 * sasaran beserta progresnya. Mengirim hasil yang sama dua kali tidak
 * menggandakan baris — backend memperbarui hasil yang sudah ada.
 */
export default function ClinicScreeningPage() {
  const queryClient = useQueryClient();
  const [selectedProgram, setSelectedProgram] = useState('');
  const [classId, setClassId] = useState('');
  const [programForm, setProgramForm] = useState({ code: '', name: '', type: 'GENERAL' });
  const [resultForm, setResultForm] = useState({
    student_id: '',
    height_cm: '',
    weight_kg: '',
    vision_left: '',
    vision_right: '',
    dental_status: '',
    result: 'NORMAL',
    recommendation: '',
  });
  const [vaccineForm, setVaccineForm] = useState({
    student_id: '',
    vaccine: '',
    dose: '1',
    given_at: new Date().toISOString().slice(0, 10),
    provider: '',
    consent_obtained: true,
  });

  const programs = useQuery({ queryKey: ['clinic', 'screening-programs'], queryFn: () => clinicApi.screeningPrograms() });

  const targets = useQuery({
    queryKey: ['clinic', 'screening-targets', selectedProgram, classId],
    queryFn: () => clinicApi.screeningTargets(Number(selectedProgram), { school_class_id: classId || undefined }),
    enabled: selectedProgram !== '',
  });

  const screenings = useQuery({
    queryKey: ['clinic', 'screenings', selectedProgram],
    queryFn: () => clinicApi.screenings({ program_id: selectedProgram || undefined }),
  });

  const vaccinations = useQuery({ queryKey: ['clinic', 'vaccinations'], queryFn: () => clinicApi.vaccinations({}) });

  const createProgram = useMutation({
    mutationFn: () => clinicApi.createScreeningProgram(programForm),
    onSuccess: () => {
      toast.success('Program skrining dibuat.');
      setProgramForm({ code: '', name: '', type: 'GENERAL' });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'screening-programs'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const recordScreening = useMutation({
    mutationFn: () =>
      clinicApi.recordScreening(Number(selectedProgram), {
        student_id: Number(resultForm.student_id),
        height_cm: resultForm.height_cm === '' ? null : Number(resultForm.height_cm),
        weight_kg: resultForm.weight_kg === '' ? null : Number(resultForm.weight_kg),
        vision_left: resultForm.vision_left || null,
        vision_right: resultForm.vision_right || null,
        dental_status: resultForm.dental_status || null,
        result: resultForm.result,
        recommendation: resultForm.recommendation || null,
        follow_up_required: resultForm.result !== 'NORMAL',
      }),
    onSuccess: () => {
      toast.success('Hasil skrining tersimpan.');
      setResultForm({ student_id: '', height_cm: '', weight_kg: '', vision_left: '', vision_right: '', dental_status: '', result: 'NORMAL', recommendation: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const recordVaccination = useMutation({
    mutationFn: () =>
      clinicApi.recordVaccination({
        student_id: Number(vaccineForm.student_id),
        vaccine: vaccineForm.vaccine,
        dose: Number(vaccineForm.dose),
        given_at: vaccineForm.given_at,
        provider: vaccineForm.provider || null,
        consent_obtained: vaccineForm.consent_obtained,
      }),
    onSuccess: () => {
      toast.success('Imunisasi dicatat.');
      setVaccineForm({ ...vaccineForm, student_id: '', vaccine: '', provider: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'vaccinations'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const studentOptions = targets.data?.students ?? [];

  return (
    <div className="space-y-4">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">Skrining & Imunisasi</h1>
        <p className="text-sm text-slate-500">
          Pemeriksaan berkala per kelas: tinggi/berat, IMT otomatis, penglihatan, gigi — beserta tindak lanjutnya.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader>
            <CardTitle>Program skrining</CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            <div>
              <Label>Pilih program aktif</Label>
              <Select value={selectedProgram} onChange={(event) => setSelectedProgram(event.target.value)}>
                <option value="">— pilih program —</option>
                {(programs.data ?? []).map((program) => (
                  <option key={program.id} value={program.id}>
                    {program.name} ({program.status})
                  </option>
                ))}
              </Select>
            </div>

            {selectedProgram !== '' && targets.data ? (
              <div className="rounded-md bg-slate-50 p-2 text-sm">
                <p className="font-medium text-slate-800">
                  Sasaran: {targets.data.total} siswa
                </p>
                <p className="text-xs text-slate-600">
                  Sudah diskrining {targets.data.progress.screened} · perlu perhatian{' '}
                  {targets.data.progress.needs_follow_up} · rujukan {targets.data.progress.referrals}
                </p>
              </div>
            ) : null}

            <div>
              <Label>Filter kelas (opsional)</Label>
              <Input
                placeholder="ID kelas — dikosongkan berarti seluruh sekolah"
                value={classId}
                onChange={(event) => setClassId(event.target.value)}
              />
            </div>

            <hr className="my-2" />

            <p className="text-sm font-medium text-slate-700">Buat program baru</p>
            <Input
              placeholder="Kode (mis. SKR-2027-01)"
              value={programForm.code}
              onChange={(event) => setProgramForm({ ...programForm, code: event.target.value })}
            />
            <Input
              placeholder="Nama program"
              value={programForm.name}
              onChange={(event) => setProgramForm({ ...programForm, name: event.target.value })}
            />
            <Select value={programForm.type} onChange={(event) => setProgramForm({ ...programForm, type: event.target.value })}>
              <option value="GENERAL">Umum</option>
              <option value="VISION">Penglihatan</option>
              <option value="HEARING">Pendengaran</option>
              <option value="DENTAL">Gigi</option>
              <option value="NUTRITION">Gizi</option>
              <option value="GROWTH">Tumbuh kembang</option>
            </Select>
            <Button
              variant="secondary"
              className="w-full"
              onClick={() => createProgram.mutate()}
              disabled={programForm.code.trim() === '' || programForm.name.trim() === '' || createProgram.isPending}
            >
              Buat program
            </Button>
          </CardBody>
        </Card>

        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <HeartPulse className="h-4 w-4" /> Catat hasil skrining
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-2">
            {selectedProgram === '' ? (
              <EmptyState title="Pilih program lebih dahulu" description="Daftar siswa sasaran muncul setelah program dipilih." />
            ) : (
              <>
                <div>
                  <Label>Siswa</Label>
                  <Select
                    value={resultForm.student_id}
                    onChange={(event) => setResultForm({ ...resultForm, student_id: event.target.value })}
                  >
                    <option value="">— pilih siswa —</option>
                    {studentOptions.map((student) => (
                      <option key={student.id} value={student.id}>
                        {student.name} · {student.nis ?? '—'} · {student.class_name ?? 'tanpa kelas'}
                      </option>
                    ))}
                  </Select>
                </div>

                <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                  <Input
                    type="number"
                    placeholder="Tinggi (cm)"
                    value={resultForm.height_cm}
                    onChange={(event) => setResultForm({ ...resultForm, height_cm: event.target.value })}
                  />
                  <Input
                    type="number"
                    placeholder="Berat (kg)"
                    value={resultForm.weight_kg}
                    onChange={(event) => setResultForm({ ...resultForm, weight_kg: event.target.value })}
                  />
                  <Input
                    placeholder="Visus kiri"
                    value={resultForm.vision_left}
                    onChange={(event) => setResultForm({ ...resultForm, vision_left: event.target.value })}
                  />
                  <Input
                    placeholder="Visus kanan"
                    value={resultForm.vision_right}
                    onChange={(event) => setResultForm({ ...resultForm, vision_right: event.target.value })}
                  />
                  <Input
                    placeholder="Status gigi"
                    value={resultForm.dental_status}
                    onChange={(event) => setResultForm({ ...resultForm, dental_status: event.target.value })}
                  />
                  <Select
                    value={resultForm.result}
                    onChange={(event) => setResultForm({ ...resultForm, result: event.target.value })}
                  >
                    <option value="NORMAL">Normal</option>
                    <option value="NEEDS_ATTENTION">Perlu perhatian</option>
                    <option value="REFERRAL">Perlu rujukan</option>
                  </Select>
                  <div className="col-span-2">
                    <Input
                      placeholder="Rekomendasi"
                      value={resultForm.recommendation}
                      onChange={(event) => setResultForm({ ...resultForm, recommendation: event.target.value })}
                    />
                  </div>
                </div>

                <Button
                  onClick={() => recordScreening.mutate()}
                  disabled={resultForm.student_id === '' || recordScreening.isPending}
                >
                  Simpan hasil skrining
                </Button>
                <p className="text-xs text-slate-500">
                  IMT dihitung server-side. Mengirim ulang hasil untuk siswa & tanggal yang sama akan memperbarui baris
                  yang ada, bukan menggandakannya.
                </p>
              </>
            )}
          </CardBody>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Riwayat skrining</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            {(screenings.data ?? []).length === 0 ? (
              <div className="p-4">
                <EmptyState title="Belum ada hasil skrining" description="Hasil yang dicatat akan tampil di sini." />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {(screenings.data ?? []).slice(0, 30).map((row) => (
                  <div key={row.id} className="flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                    <div>
                      <p className="font-medium text-slate-900">{row.patient?.student?.full_name ?? 'Siswa'}</p>
                      <p className="text-xs text-slate-500">
                        {row.type} · {new Date(row.screened_at).toLocaleDateString('id-ID')} · IMT {row.bmi ?? '—'}
                        {row.bmi_category ? ` (${row.bmi_category})` : ''}
                      </p>
                    </div>
                    <Badge
                      tone={row.result === 'NORMAL' ? 'success' : row.result === 'REFERRAL' ? 'danger' : 'warning'}
                    >
                      {row.result}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Syringe className="h-4 w-4" /> Imunisasi
            </CardTitle>
          </CardHeader>
          <CardBody className="space-y-3">
            <div className="grid gap-2 md:grid-cols-2">
              <div className="md:col-span-2">
                <Label>Siswa</Label>
                <Select
                  value={vaccineForm.student_id}
                  onChange={(event) => setVaccineForm({ ...vaccineForm, student_id: event.target.value })}
                >
                  <option value="">— pilih siswa —</option>
                  {studentOptions.map((student) => (
                    <option key={student.id} value={student.id}>
                      {student.name} · {student.class_name ?? '—'}
                    </option>
                  ))}
                </Select>
              </div>
              <Input
                placeholder="Vaksin"
                value={vaccineForm.vaccine}
                onChange={(event) => setVaccineForm({ ...vaccineForm, vaccine: event.target.value })}
              />
              <Input
                type="number"
                placeholder="Dosis ke-"
                value={vaccineForm.dose}
                onChange={(event) => setVaccineForm({ ...vaccineForm, dose: event.target.value })}
              />
              <Input
                type="date"
                value={vaccineForm.given_at}
                onChange={(event) => setVaccineForm({ ...vaccineForm, given_at: event.target.value })}
              />
              <Input
                placeholder="Pemberi (mis. Puskesmas)"
                value={vaccineForm.provider}
                onChange={(event) => setVaccineForm({ ...vaccineForm, provider: event.target.value })}
              />
            </div>
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input
                type="checkbox"
                checked={vaccineForm.consent_obtained}
                onChange={(event) => setVaccineForm({ ...vaccineForm, consent_obtained: event.target.checked })}
              />
              Persetujuan orang tua sudah diperoleh
            </label>
            <Button
              variant="secondary"
              onClick={() => recordVaccination.mutate()}
              disabled={vaccineForm.student_id === '' || vaccineForm.vaccine.trim() === '' || recordVaccination.isPending}
            >
              Catat imunisasi
            </Button>
            <p className="text-xs text-slate-500">
              Catatan ini melengkapi riwayat sekolah, bukan menggantikan registry imunisasi resmi puskesmas.
            </p>

            {(vaccinations.data ?? []).length > 0 ? (
              <ul className="space-y-1 text-sm text-slate-700">
                {(vaccinations.data ?? []).slice(0, 10).map((row) => (
                  <li key={row.id}>
                    {row.patient?.student?.full_name ?? 'Siswa'} · {row.vaccine} dosis {row.dose} ·{' '}
                    {new Date(row.given_at).toLocaleDateString('id-ID')}
                  </li>
                ))}
              </ul>
            ) : null}
          </CardBody>
        </Card>
      </div>
    </div>
  );
}
