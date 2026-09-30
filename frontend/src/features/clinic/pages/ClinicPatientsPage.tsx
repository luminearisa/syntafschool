import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { AlertTriangle, ClipboardPlus, Search, ShieldAlert, UserPlus } from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';
import type { ClinicAllergy, ClinicPatientProfile, ClinicSpecialCondition } from '../types';

/**
 * Pencarian & pendaftaran pasien.
 *
 * Petugas mencari siswa pada master ERP (bukan tabel pasien), lalu:
 * - bila rekam medis sudah ada → langsung membuka kunjungan baru;
 * - bila belum → rekam medis dibuatkan nomor (MRN) yang tidak berubah lagi.
 *
 * Panel kanan menampilkan peringatan alergi/kondisi khusus pasien terpilih,
 * karena dua hal itu yang paling sering menentukan keputusan klinis.
 */
export default function ClinicPatientsPage() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [term, setTerm] = useState('');
  const [selected, setSelected] = useState<number | null>(null);
  const [visitType, setVisitType] = useState('GENERAL');
  const [complaint, setComplaint] = useState('');

  const [allergyForm, setAllergyForm] = useState({ allergen: '', category: 'MEDICATION', severity: 'MODERATE', reaction: '' });
  const [conditionForm, setConditionForm] = useState({ category: 'DIETARY_RESTRICTION', condition: '', care_instruction: '', visible_to_kitchen: false });

  const list = useQuery({
    queryKey: ['clinic', 'patients', term],
    queryFn: () => clinicApi.patients({ search: term, limit: 30 }),
  });

  const studentResults = useQuery({
    queryKey: ['clinic', 'students', term],
    queryFn: () => clinicApi.searchStudents(term),
    enabled: term.trim().length >= 3,
  });

  const profile = useQuery({
    queryKey: ['clinic', 'patient', selected],
    queryFn: () => clinicApi.patient(selected as number),
    enabled: selected !== null,
  });

  const metadata = useQuery({
    queryKey: ['clinic', 'metadata'],
    queryFn: () => clinicApi.metadata(),
  });

  const registerPatient = useMutation({
    mutationFn: (studentId: number) => clinicApi.registerPatient({ student_id: studentId }),
    onSuccess: (data: ClinicPatientProfile) => {
      toast.success(`Rekam medis ${data.patient.medical_record_number} dibuat.`);
      setSelected(data.patient.id);
      void queryClient.invalidateQueries({ queryKey: ['clinic'] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const createVisit = useMutation({
    mutationFn: () =>
      clinicApi.createVisit({
        patient_id: selected,
        visit_type: visitType,
        chief_complaint: complaint || null,
      }),
    onSuccess: (workspace) => {
      toast.success(`Kunjungan ${workspace.visit.visit_number} terdaftar.`);
      navigate(`/clinic/visits/${workspace.visit.id}`);
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const addAllergy = useMutation({
    mutationFn: () => clinicApi.addAllergy(selected as number, allergyForm),
    onSuccess: () => {
      toast.success('Alergi dicatat — peringatan akan muncul saat peresepan.');
      setAllergyForm({ allergen: '', category: 'MEDICATION', severity: 'MODERATE', reaction: '' });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'patient', selected] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const addCondition = useMutation({
    mutationFn: () => clinicApi.addCondition(selected as number, conditionForm),
    onSuccess: () => {
      toast.success('Kondisi khusus dicatat.');
      setConditionForm({ category: 'DIETARY_RESTRICTION', condition: '', care_instruction: '', visible_to_kitchen: false });
      void queryClient.invalidateQueries({ queryKey: ['clinic', 'patient', selected] });
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const rows = list.data?.rows ?? [];

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900">Pasien & Rekam Medis</h1>
          <p className="text-sm text-slate-500">
            Cari siswa pada master sekolah, lalu buka kunjungan. Nomor rekam medis tidak berubah meski kelas atau tahun
            ajaran berganti.
          </p>
        </div>
        <div className="w-full max-w-sm">
          <Label htmlFor="clinic-search">Cari nama / NIS / NISN / nomor rekam medis</Label>
          <div className="relative">
            <Search className="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-slate-400" />
            <Input
              id="clinic-search"
              className="pl-8"
              value={term}
              placeholder="mis. Ahmad, NIS12345, MR-2026-000001"
              onChange={(event) => setTerm(event.target.value)}
            />
          </div>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>Daftar pasien dalam cakupan Anda</CardTitle>
          </CardHeader>
          <CardBody className="p-0">
            {list.isLoading ? (
              <div className="flex justify-center p-6">
                <Spinner />
              </div>
            ) : rows.length === 0 ? (
              <div className="p-4">
                <EmptyState
                  title="Belum ada pasien yang cocok"
                  description="Cari siswa pada kotak di atas. Bila rekam medisnya belum ada, daftarkan dari hasil pencarian siswa."
                />
              </div>
            ) : (
              <div className="divide-y divide-slate-100">
                {rows.map((row) => (
                  <button
                    key={row.patient_id}
                    type="button"
                    onClick={() => setSelected(row.patient_id)}
                    className={`flex w-full flex-wrap items-center gap-3 p-3 text-left hover:bg-slate-50 ${
                      selected === row.patient_id ? 'bg-sky-50' : ''
                    }`}
                  >
                    <div className="min-w-[10rem] flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-slate-900">{row.student?.name ?? 'Pasien'}</span>
                        <Badge tone="neutral">{row.medical_record_number}</Badge>
                        {row.alerts.allergy ? <Badge tone="danger">Alergi</Badge> : null}
                        {row.alerts.special_care ? <Badge tone="warning">Kondisi khusus</Badge> : null}
                      </div>
                      <p className="text-xs text-slate-500">
                        NIS {row.student?.nis ?? '—'} · kunjungan terakhir{' '}
                        {row.last_visit_at ? new Date(row.last_visit_at).toLocaleDateString('id-ID') : 'belum ada'}
                      </p>
                    </div>
                  </button>
                ))}
              </div>
            )}
          </CardBody>
        </Card>

        <div className="space-y-4">
          {studentResults.data && studentResults.data.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle>Hasil pencarian siswa (master ERP)</CardTitle>
              </CardHeader>
              <CardBody className="space-y-2">
                {studentResults.data.map((student) => (
                  <div key={student.id} className="flex items-center justify-between gap-2 rounded-md border p-2">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{student.name}</p>
                      <p className="text-xs text-slate-500">
                        {student.nis ?? '—'} · {student.class_name ?? 'tanpa kelas'}
                        {student.has_medical_record ? ` · ${student.medical_record_number}` : ''}
                      </p>
                    </div>
                    {student.has_medical_record ? (
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                          const found = rows.find((row) => row.student?.id === student.id);
                          if (found) setSelected(found.patient_id);
                          else void list.refetch();
                        }}
                      >
                        Pilih
                      </Button>
                    ) : (
                      <Button
                        size="sm"
                        onClick={() => registerPatient.mutate(student.id)}
                        disabled={registerPatient.isPending}
                      >
                        <UserPlus className="mr-1 h-4 w-4" /> Daftarkan
                      </Button>
                    )}
                  </div>
                ))}
              </CardBody>
            </Card>
          ) : null}

          {selected !== null && profile.data ? (
            <>
              <Card>
                <CardHeader>
                  <CardTitle>Mulai kunjungan</CardTitle>
                </CardHeader>
                <CardBody className="space-y-3">
                  <div>
                    <Label>Jenis kunjungan</Label>
                    <Select value={visitType} onChange={(event) => setVisitType(event.target.value)}>
                      {(metadata.data?.options.visit_types ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label>Keluhan utama</Label>
                    <Input
                      value={complaint}
                      onChange={(event) => setComplaint(event.target.value)}
                      placeholder="mis. Demam sejak pagi"
                    />
                  </div>
                  <Button
                    className="w-full"
                    onClick={() => createVisit.mutate()}
                    disabled={createVisit.isPending || !profile.data.patient.id}
                  >
                    <ClipboardPlus className="mr-1 h-4 w-4" /> Daftarkan Kunjungan
                  </Button>
                </CardBody>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Peringatan klinis</CardTitle>
                </CardHeader>
                <CardBody className="space-y-3">
                  {(profile.data.allergies ?? []).length === 0 ? (
                    <p className="text-sm text-slate-500">Belum ada alergi tercatat.</p>
                  ) : (
                    profile.data.allergies.map((allergy: ClinicAllergy) => (
                      <div
                        key={allergy.id}
                        className={`rounded-md p-2 text-sm ${allergy.is_critical ? 'bg-rose-50' : 'bg-amber-50'}`}
                      >
                        <div className="flex items-center gap-2">
                          <ShieldAlert className={`h-4 w-4 ${allergy.is_critical ? 'text-rose-600' : 'text-amber-600'}`} />
                          <span className="font-medium text-slate-800">{allergy.allergen}</span>
                          <Badge tone={allergy.is_critical ? 'danger' : 'warning'}>{allergy.category_label}</Badge>
                        </div>
                        {allergy.reaction ? <p className="text-xs text-slate-600">Reaksi: {allergy.reaction}</p> : null}
                      </div>
                    ))
                  )}

                  {(profile.data.special_conditions ?? []).map((condition: ClinicSpecialCondition) => (
                    <div key={condition.id} className="rounded-md bg-slate-50 p-2 text-sm">
                      <div className="flex items-center gap-2">
                        <AlertTriangle className="h-4 w-4 text-slate-500" />
                        <span className="font-medium text-slate-800">{condition.condition}</span>
                        <Badge tone="neutral">{condition.category_label}</Badge>
                      </div>
                      {condition.care_instruction ? (
                        <p className="text-xs text-slate-600">{condition.care_instruction}</p>
                      ) : null}
                    </div>
                  ))}
                </CardBody>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Catat alergi baru</CardTitle>
                </CardHeader>
                <CardBody className="space-y-2">
                  <Input
                    placeholder="Zat pemicu (mis. Amoksisilin)"
                    value={allergyForm.allergen}
                    onChange={(event) => setAllergyForm({ ...allergyForm, allergen: event.target.value })}
                  />
                  <div className="grid grid-cols-2 gap-2">
                    <Select
                      value={allergyForm.category}
                      onChange={(event) => setAllergyForm({ ...allergyForm, category: event.target.value })}
                    >
                      {(metadata.data?.options.allergy_categories ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </Select>
                    <Select
                      value={allergyForm.severity}
                      onChange={(event) => setAllergyForm({ ...allergyForm, severity: event.target.value })}
                    >
                      {(metadata.data?.options.allergy_severities ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <Input
                    placeholder="Reaksi (mis. ruam, sesak)"
                    value={allergyForm.reaction}
                    onChange={(event) => setAllergyForm({ ...allergyForm, reaction: event.target.value })}
                  />
                  <Button
                    variant="secondary"
                    className="w-full"
                    onClick={() => addAllergy.mutate()}
                    disabled={allergyForm.allergen.trim() === '' || addAllergy.isPending}
                  >
                    Simpan alergi
                  </Button>
                </CardBody>
              </Card>

              <Card>
                <CardHeader>
                  <CardTitle>Catat kondisi khusus</CardTitle>
                </CardHeader>
                <CardBody className="space-y-2">
                  <Select
                    value={conditionForm.category}
                    onChange={(event) => setConditionForm({ ...conditionForm, category: event.target.value })}
                  >
                    {(metadata.data?.options.special_conditions ?? []).map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </Select>
                  <Input
                    placeholder="Kondisi (mis. Pantang makanan pedas)"
                    value={conditionForm.condition}
                    onChange={(event) => setConditionForm({ ...conditionForm, condition: event.target.value })}
                  />
                  <Input
                    placeholder="Instruksi perawatan"
                    value={conditionForm.care_instruction}
                    onChange={(event) => setConditionForm({ ...conditionForm, care_instruction: event.target.value })}
                  />
                  <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input
                      type="checkbox"
                      checked={conditionForm.visible_to_kitchen}
                      onChange={(event) =>
                        setConditionForm({ ...conditionForm, visible_to_kitchen: event.target.checked })
                      }
                    />
                    Bagikan instruksi ke dapur (tanpa diagnosis)
                  </label>
                  <Button
                    variant="secondary"
                    className="w-full"
                    onClick={() => addCondition.mutate()}
                    disabled={conditionForm.condition.trim() === '' || addCondition.isPending}
                  >
                    Simpan kondisi
                  </Button>
                </CardBody>
              </Card>
            </>
          ) : (
            <Card>
              <CardBody>
                <EmptyState
                  title="Belum ada pasien dipilih"
                  description="Pilih pasien dari daftar, atau daftarkan siswa baru dari hasil pencarian."
                />
              </CardBody>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
