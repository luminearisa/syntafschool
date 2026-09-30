import { useEffect, useMemo, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import {
  ActivitySquare,
  AlertTriangle,
  Ambulance,
  BedDouble,
  ClipboardList,
  FileText,
  HeartPulse,
  Pill,
  Send,
  ShieldAlert,
  Stethoscope,
  Syringe,
} from 'lucide-react';
import { Card, CardBody, CardHeader, CardTitle } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Textarea } from '@/components/ui/Textarea';
import { Label } from '@/components/ui/Label';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { getErrorMessage } from '@/lib/axios';
import { clinicApi } from '../api/clinicApi';

/**
 * Workspace kunjungan klinis.
 *
 * Tata letak mengikuti cara petugas bekerja, bukan cara data disimpan:
 *
 *   header pasien (identitas + peringatan alergi/kondisi khusus)
 *   ├── triase & tanda vital
 *   ├── asesmen SOAP & diagnosis
 *   ├── tindakan
 *   ├── resep & pemberian obat
 *   ├── observasi
 *   └── pemulangan / rujukan
 *
 * Setiap langkah menyegarkan workspace sehingga status kunjungan, antrean, dan
 * seluruh catatan selalu konsisten dengan server.
 */
export default function ClinicVisitWorkspacePage() {
  const { visitId } = useParams<{ visitId: string }>();
  const id = Number(visitId);
  const queryClient = useQueryClient();

  const workspace = useQuery({
    queryKey: ['clinic', 'visit', id],
    queryFn: () => clinicApi.workspace(id),
    enabled: Number.isFinite(id) && id > 0,
  });

  const metadata = useQuery({ queryKey: ['clinic', 'metadata'], queryFn: () => clinicApi.metadata() });

  const medications = useQuery({
    queryKey: ['clinic', 'medications'],
    queryFn: () => clinicApi.medications({ status: 'ACTIVE' }),
  });

  const refresh = () => queryClient.invalidateQueries({ queryKey: ['clinic', 'visit', id] });

  const data = workspace.data;
  const writable = data?.visit.can_be_written ?? false;
  const patientId = data?.patient_header.patient_id;

  /**
   * Isi formulir triase dari data yang sudah tersimpan.
   *
   * Tanpa ini, membuka kunjungan yang sudah ditriase lalu menekan "Simpan
   * triase" akan menimpa prioritas dengan nilai bawaan formulir — penurunan
   * prioritas yang tidak disengaja dan berbahaya secara klinis.
   */
  useEffect(() => {
    const saved = data?.triage;
    if (!saved) return;

    setTriage({
      priority: saved.priority,
      chief_complaint: saved.chief_complaint,
      pain_score: saved.pain_score === null || saved.pain_score === undefined ? '' : String(saved.pain_score),
      consciousness: saved.consciousness,
      general_condition: saved.general_condition,
      duration: saved.duration ?? '',
      onset: saved.onset ?? 'UNKNOWN',
    });
  }, [data?.triage]);

  // ------------------------------------------------------------- formulir
  const [triage, setTriage] = useState({ priority: 'NON_URGENT', chief_complaint: '', pain_score: '', consciousness: 'ALERT', general_condition: 'GOOD', duration: '', onset: 'UNKNOWN' });
  const [vitals, setVitals] = useState({ temperature: '', systolic: '', diastolic: '', heart_rate: '', respiratory_rate: '', oxygen_saturation: '', weight_kg: '', height_cm: '' });
  const [soap, setSoap] = useState({ subjective: '', objective: '', assessment: '', plan: '', reason: '' });
  const [diagnosis, setDiagnosis] = useState({ diagnosis_code: '', type: 'PRIMARY', notes: '' });
  const [procedure, setProcedure] = useState({ type_id: '', procedure: '', notes: '' });
  const [observation, setObservation] = useState({ reason: '', bed: '' });
  const [prescription, setPrescription] = useState({ medication_id: '', dose: '1 tablet', frequency: '3x sehari', duration: '3 hari', quantity: '3', override_reason: '' });
  const [administration, setAdministration] = useState({ medication_id: '', dose: '', status: 'GIVEN', notes: '' });
  const [discharge, setDischarge] = useState({ status: 'RETURN_TO_CLASS', condition: 'IMPROVING', instructions: '', follow_up_required: false, follow_up_date: '' });
  const [referral, setReferral] = useState({ facility: '', urgency: 'ROUTINE', reason: '', transport: '' });

  const numeric = (value: string): number | null => {
    const parsed = Number(value);
    return value.trim() === '' || Number.isNaN(parsed) ? null : parsed;
  };

  // ------------------------------------------------------------- mutasi
  const saveTriage = useMutation({
    mutationFn: () =>
      clinicApi.triage(id, {
        priority: triage.priority,
        chief_complaint: triage.chief_complaint || data?.visit.chief_complaint || 'Keluhan tidak dicatat',
        pain_score: numeric(triage.pain_score),
        consciousness: triage.consciousness,
        general_condition: triage.general_condition,
        duration: triage.duration || null,
        onset: triage.onset,
      }),
    onSuccess: () => {
      toast.success('Triase tersimpan — prioritas kunjungan diperbarui.');
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveVitals = useMutation({
    mutationFn: () =>
      clinicApi.recordVitals(id, {
        temperature: numeric(vitals.temperature),
        systolic: numeric(vitals.systolic),
        diastolic: numeric(vitals.diastolic),
        heart_rate: numeric(vitals.heart_rate),
        respiratory_rate: numeric(vitals.respiratory_rate),
        oxygen_saturation: numeric(vitals.oxygen_saturation),
        weight_kg: numeric(vitals.weight_kg),
        height_cm: numeric(vitals.height_cm),
      }),
    onSuccess: (result) => {
      toast.success('Tanda vital tersimpan.');
      result.warnings.forEach((warning: string) => toast.warning(warning, { duration: 6000 }));
      setVitals({ temperature: '', systolic: '', diastolic: '', heart_rate: '', respiratory_rate: '', oxygen_saturation: '', weight_kg: '', height_cm: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveAssessment = useMutation({
    mutationFn: () => clinicApi.saveAssessment(id, { ...soap, reason: soap.reason || null }),
    onSuccess: (response) => {
      const version = (response?.data as { version?: number } | undefined)?.version;
      toast.success(`Asesmen tersimpan${version ? ` (versi ${version})` : ''}.`);
      setSoap({ subjective: '', objective: '', assessment: '', plan: '', reason: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveDiagnosis = useMutation({
    mutationFn: () => clinicApi.addDiagnosis(id, {
      diagnosis_code: diagnosis.diagnosis_code || null,
      diagnosis_name: diagnosis.diagnosis_code ? null : diagnosis.notes || 'Diagnosis klinis',
      type: diagnosis.type,
      notes: diagnosis.notes || null,
    }),
    onSuccess: () => {
      toast.success('Diagnosis ditambahkan.');
      setDiagnosis({ diagnosis_code: '', type: 'PRIMARY', notes: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const saveProcedure = useMutation({
    mutationFn: () => clinicApi.addProcedure(id, {
      type_id: procedure.type_id ? Number(procedure.type_id) : null,
      procedure: procedure.procedure || null,
      notes: procedure.notes || null,
    }),
    onSuccess: () => {
      toast.success('Tindakan dicatat.');
      setProcedure({ type_id: '', procedure: '', notes: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const startObservation = useMutation({
    mutationFn: () => clinicApi.startObservation(id, { reason: observation.reason, bed: observation.bed || null }),
    onSuccess: () => {
      toast.success('Observasi dimulai.');
      setObservation({ reason: '', bed: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const createPrescription = useMutation({
    mutationFn: () =>
      clinicApi.createPrescription(id, {
        items: [
          {
            medication_id: prescription.medication_id ? Number(prescription.medication_id) : null,
            medication_name: medicationName(prescription.medication_id),
            dose: prescription.dose,
            frequency: prescription.frequency,
            duration: prescription.duration,
            quantity: numeric(prescription.quantity) ?? 1,
          },
        ],
        allergy_override: prescription.override_reason.trim() !== '',
        override_reason: prescription.override_reason || null,
      }),
    onSuccess: (response) => {
      const warnings = response.data?.allergy_warnings ?? [];
      toast.success(`Resep ${response.data?.prescription.prescription_number ?? ''} diterbitkan.`);
      if (warnings.length > 0) {
        toast.warning('Resep diterbitkan dengan override alergi — alasan tersimpan di rekam medis.');
      }
      setPrescription({ medication_id: '', dose: '1 tablet', frequency: '3x sehari', duration: '3 hari', quantity: '3', override_reason: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const dispense = useMutation({
    mutationFn: (prescriptionId: number) => clinicApi.dispense(prescriptionId, {}),
    onSuccess: (result) => {
      const results = result.results as { inventory_result: string; medication_name: string | null; notes: string | null }[];
      const failed = results.filter((row) => row.inventory_result !== 'SUCCESS');
      toast.success(`Penyerahan ${result.dispensing.dispensing_number} diproses (${result.prescription_status}).`);
      failed.forEach((row) => toast.error(`${row.medication_name ?? 'Obat'}: ${row.notes ?? row.inventory_result}`));
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const administer = useMutation({
    mutationFn: () => clinicApi.administer(id, {
      medication_id: administration.medication_id ? Number(administration.medication_id) : null,
      medication_name: medicationName(administration.medication_id),
      dose: administration.dose || null,
      status: administration.status,
      notes: administration.notes || null,
    }),
    onSuccess: () => {
      toast.success('Pemberian obat dicatat (MAR).');
      setAdministration({ medication_id: '', dose: '', status: 'GIVEN', notes: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const refer = useMutation({
    mutationFn: () => clinicApi.createReferral(id, referral),
    onSuccess: (created) => {
      toast.success(`Rujukan ${created.referral_number} dibuat — orang tua diberi kabar.`);
      setReferral({ facility: '', urgency: 'ROUTINE', reason: '', transport: '' });
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const finish = useMutation({
    mutationFn: () => clinicApi.discharge(id, {
      status: discharge.status,
      condition: discharge.condition,
      instructions: discharge.instructions || null,
      follow_up_required: discharge.follow_up_required,
      follow_up_date: discharge.follow_up_required ? discharge.follow_up_date || null : null,
    }),
    onSuccess: (result) => {
      toast.success('Pasien dipulangkan.');
      toast.info(result.attendance?.message ?? '');
      if (result.boarding?.allowed) toast.info(result.boarding.note);
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const cancel = useMutation({
    mutationFn: () => clinicApi.updateVisitStatus(id, 'CANCELLED', 'Dibatalkan petugas klinik'),
    onSuccess: () => {
      toast.success('Kunjungan dibatalkan.');
      void refresh();
    },
    onError: (error) => toast.error(getErrorMessage(error)),
  });

  const medicationOptions = medications.data ?? [];
  const medicationName = (value: string): string | null => {
    if (!value) return null;
    const found = medicationOptions.find((row) => row.id === Number(value));
    return found?.name ?? null;
  };

  const header = data?.patient_header;
  const activeObservation = useMemo(
    () => (data?.observations ?? []).find((row) => row.status === 'ACTIVE') ?? null,
    [data?.observations],
  );

  if (workspace.isLoading) {
    return (
      <div className="flex justify-center py-20">
        <Spinner />
      </div>
    );
  }

  if (workspace.isError) {
    return (
      <EmptyState
        title="Kunjungan tidak dapat dibuka"
        description={getErrorMessage(workspace.error)}
        action={
          <Link to="/clinic">
            <Button variant="secondary">Kembali ke dasbor</Button>
          </Link>
        }
      />
    );
  }

  if (!data) return null;

  return (
    <div className="space-y-4">
      {/* ------------------------------------------------ header pasien */}
      <Card>
        <CardBody className="space-y-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="flex flex-wrap items-center gap-2">
                <h1 className="text-xl font-semibold text-slate-900">
                  {header?.identity?.name ?? 'Pasien'}
                </h1>
                <Badge tone="neutral">{header?.medical_record_number}</Badge>
                <Badge tone={data.visit.is_emergency ? 'danger' : 'info'}>{data.visit.priority_label}</Badge>
                <Badge tone={data.visit.can_be_written ? 'success' : 'neutral'}>{data.visit.status_label}</Badge>
              </div>
              <p className="text-sm text-slate-500">
                {data.visit.visit_number} · {new Date(data.visit.visit_at).toLocaleString('id-ID')} ·{' '}
                {data.visit.clinic?.name ?? '—'} · sumber: {data.visit.source_label}
              </p>
              <p className="text-sm text-slate-600">
                {header?.identity?.class_name ?? 'tanpa kelas'} · {header?.identity?.gender ?? '—'} ·{' '}
                {header?.identity?.age_years !== null && header?.identity?.age_years !== undefined
                  ? `${header.identity.age_years} tahun`
                  : 'usia belum tercatat'}{' '}
                · gol. darah {header?.blood_type ?? '—'}
                {header?.boarding?.is_boarder
                  ? ` · asrama ${header.boarding.dormitory ?? '—'}/${header.boarding.room ?? '—'}`
                  : ''}
              </p>
            </div>

            <div className="flex flex-wrap gap-2">
              {writable ? (
                <>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => cancel.mutate()}
                    disabled={cancel.isPending}
                  >
                    Batalkan kunjungan
                  </Button>
                  <Link to={`/clinic/referrals?visit=${data.visit.id}`}>
                    <Button size="sm" variant="secondary">
                      <Send className="mr-1 h-4 w-4" /> Rujuk
                    </Button>
                  </Link>
                </>
              ) : (
                <Badge tone="neutral">Kunjungan sudah ditutup — catatan tidak dapat diubah</Badge>
              )}
            </div>
          </div>

          {(header?.allergies ?? []).length > 0 || (header?.special_conditions ?? []).length > 0 ? (
            <div className="grid gap-2 md:grid-cols-2">
              {(header?.allergies ?? []).map((allergy) => (
                <div
                  key={`allergy-${allergy.id}`}
                  className={`flex items-start gap-2 rounded-md p-2 text-sm ${
                    allergy.is_critical ? 'bg-rose-100' : 'bg-amber-50'
                  }`}
                >
                  <ShieldAlert className={`mt-0.5 h-4 w-4 ${allergy.is_critical ? 'text-rose-700' : 'text-amber-600'}`} />
                  <div>
                    <p className="font-semibold text-slate-900">
                      ALERGI {allergy.severity}: {allergy.allergen}
                    </p>
                    {allergy.reaction ? <p className="text-xs text-slate-700">Reaksi: {allergy.reaction}</p> : null}
                  </div>
                </div>
              ))}
              {(header?.special_conditions ?? []).map((condition) => (
                <div key={`condition-${condition.id}`} className="flex items-start gap-2 rounded-md bg-slate-100 p-2 text-sm">
                  <AlertTriangle className="mt-0.5 h-4 w-4 text-slate-600" />
                  <div>
                    <p className="font-medium text-slate-900">{condition.condition}</p>
                    {condition.care_instruction ? (
                      <p className="text-xs text-slate-700">{condition.care_instruction}</p>
                    ) : null}
                  </div>
                </div>
              ))}
            </div>
          ) : (
            <p className="text-sm text-slate-500">Tidak ada alergi atau kondisi khusus tercatat.</p>
          )}

          <div className="flex flex-wrap gap-3 text-xs text-slate-600">
            <span>
              Kontak darurat: {header?.emergency_contact.name ?? '—'} ({header?.emergency_contact.phone ?? '—'})
            </span>
            {(header?.guardians ?? []).map((guardian) => (
              <span key={guardian.guardian_id}>
                Wali: {guardian.name} {guardian.phone ? `· ${guardian.phone}` : ''}{' '}
                {guardian.has_account ? '' : '(belum punya akun portal — hubungi manual)'}
              </span>
            ))}
          </div>
        </CardBody>
      </Card>

      <div className="grid gap-4 lg:grid-cols-3">
        {/* ------------------------------------------------ kolom kiri */}
        <div className="space-y-4 lg:col-span-2">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ClipboardList className="h-4 w-4" /> Triase
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.triage ? (
                <div className="rounded-md bg-slate-50 p-3 text-sm">
                  <p className="font-medium text-slate-900">{data.triage.chief_complaint}</p>
                  <p className="text-xs text-slate-600">
                    Prioritas {data.triage.priority} · nyeri {data.triage.pain_score ?? '—'}/10 · kesadaran{' '}
                    {data.triage.consciousness} · kondisi {data.triage.general_condition}
                    {data.triage.duration ? ` · ${data.triage.duration}` : ''}
                  </p>
                  <p className="text-xs text-slate-500">
                    Diketahui {new Date(data.triage.triaged_at).toLocaleString('id-ID')}
                  </p>
                </div>
              ) : null}

              {writable ? (
                <div className="grid gap-2 md:grid-cols-2">
                  <div className="md:col-span-2">
                    <Label>Keluhan utama</Label>
                    <Input
                      value={triage.chief_complaint}
                      placeholder={data.visit.chief_complaint ?? 'mis. Demam sejak semalam'}
                      onChange={(event) => setTriage({ ...triage, chief_complaint: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Prioritas</Label>
                    <Select value={triage.priority} onChange={(event) => setTriage({ ...triage, priority: event.target.value })}>
                      {(metadata.data?.options.priorities ?? []).map((option) => (
                        <option key={option.value} value={option.value}>
                          {option.label}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label>Skala nyeri (0-10)</Label>
                    <Input
                      type="number"
                      min={0}
                      max={10}
                      value={triage.pain_score}
                      onChange={(event) => setTriage({ ...triage, pain_score: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Kesadaran</Label>
                    <Select
                      value={triage.consciousness}
                      onChange={(event) => setTriage({ ...triage, consciousness: event.target.value })}
                    >
                      <option value="ALERT">Sadar penuh</option>
                      <option value="VOICE">Respons suara</option>
                      <option value="PAIN">Respons nyeri</option>
                      <option value="UNRESPONSIVE">Tidak responsif</option>
                    </Select>
                  </div>
                  <div>
                    <Label>Kondisi umum</Label>
                    <Select
                      value={triage.general_condition}
                      onChange={(event) => setTriage({ ...triage, general_condition: event.target.value })}
                    >
                      <option value="GOOD">Baik</option>
                      <option value="FAIR">Cukup</option>
                      <option value="WEAK">Lemah</option>
                      <option value="CRITICAL">Kritis</option>
                    </Select>
                  </div>
                  <Button onClick={() => saveTriage.mutate()} disabled={saveTriage.isPending}>
                    Simpan triase
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <HeartPulse className="h-4 w-4" /> Tanda vital
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.vitals.length > 0 ? (
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead>
                      <tr className="text-left text-xs uppercase text-slate-600">
                        <th className="py-1">Waktu</th>
                        <th>Suhu</th>
                        <th>TD</th>
                        <th>Nadi</th>
                        <th>Napas</th>
                        <th>SpO₂</th>
                        <th>BB/TB</th>
                        <th>IMT</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.vitals.map((vital) => (
                        <tr key={vital.id} className="border-t border-slate-100">
                          <td className="py-1 text-xs text-slate-600">
                            {new Date(vital.measured_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                          </td>
                          <td>{vital.temperature ?? '—'}</td>
                          <td>{vital.systolic && vital.diastolic ? `${vital.systolic}/${vital.diastolic}` : '—'}</td>
                          <td>{vital.heart_rate ?? '—'}</td>
                          <td>{vital.respiratory_rate ?? '—'}</td>
                          <td>{vital.oxygen_saturation ?? '—'}</td>
                          <td>{vital.weight_kg && vital.height_cm ? `${vital.weight_kg}/${vital.height_cm}` : '—'}</td>
                          <td>
                            {vital.bmi ?? '—'}
                            {vital.bmi_category ? (
                              <span className="ml-1 text-xs text-slate-500">({vital.bmi_category})</span>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-slate-500">Belum ada tanda vital tercatat.</p>
              )}

              {writable ? (
                <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                  {([
                    ['temperature', 'Suhu (°C)'],
                    ['systolic', 'TD sistolik'],
                    ['diastolic', 'TD diastolik'],
                    ['heart_rate', 'Nadi'],
                    ['respiratory_rate', 'Napas'],
                    ['oxygen_saturation', 'SpO₂ (%)'],
                    ['weight_kg', 'Berat (kg)'],
                    ['height_cm', 'Tinggi (cm)'],
                  ] as const).map(([field, label]) => (
                    <div key={field}>
                      <Label>{label}</Label>
                      <Input
                        type="number"
                        step="0.1"
                        value={vitals[field]}
                        onChange={(event) => setVitals({ ...vitals, [field]: event.target.value })}
                      />
                    </div>
                  ))}
                  <div className="col-span-2 flex items-end md:col-span-4">
                    <Button onClick={() => saveVitals.mutate()} disabled={saveVitals.isPending}>
                      Simpan tanda vital
                    </Button>
                    <p className="ml-3 text-xs text-slate-500">
                      IMT dihitung otomatis; peringatan ambang klinis muncul setelah disimpan.
                    </p>
                  </div>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Stethoscope className="h-4 w-4" /> Asesmen klinis (SOAP)
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.assessments.map((assessment) => (
                <div
                  key={assessment.id}
                  className={`rounded-md p-3 text-sm ${assessment.is_current ? 'bg-emerald-50' : 'bg-slate-50'}`}
                >
                  <div className="flex items-center gap-2">
                    <Badge tone={assessment.is_current ? 'success' : 'neutral'}>Versi {assessment.version}</Badge>
                    <span className="text-xs text-slate-500">
                      {new Date(assessment.assessment_at).toLocaleString('id-ID')}
                    </span>
                  </div>
                  <dl className="mt-2 space-y-1 text-slate-700">
                    {assessment.subjective ? <div><dt className="inline font-medium">S: </dt><dd className="inline">{assessment.subjective}</dd></div> : null}
                    {assessment.objective ? <div><dt className="inline font-medium">O: </dt><dd className="inline">{assessment.objective}</dd></div> : null}
                    {assessment.assessment ? <div><dt className="inline font-medium">A: </dt><dd className="inline">{assessment.assessment}</dd></div> : null}
                    {assessment.plan ? <div><dt className="inline font-medium">P: </dt><dd className="inline">{assessment.plan}</dd></div> : null}
                  </dl>
                </div>
              ))}

              {writable ? (
                <div className="space-y-2">
                  <Textarea
                    placeholder="S — keluhan & riwayat yang disampaikan pasien"
                    value={soap.subjective}
                    onChange={(event) => setSoap({ ...soap, subjective: event.target.value })}
                  />
                  <Textarea
                    placeholder="O — temuan pemeriksaan (tanda vital, fisik)"
                    value={soap.objective}
                    onChange={(event) => setSoap({ ...soap, objective: event.target.value })}
                  />
                  <Textarea
                    placeholder="A — penilaian klinis"
                    value={soap.assessment}
                    onChange={(event) => setSoap({ ...soap, assessment: event.target.value })}
                  />
                  <Textarea
                    placeholder="P — rencana: obat, istirahat, kontrol, rujukan"
                    value={soap.plan}
                    onChange={(event) => setSoap({ ...soap, plan: event.target.value })}
                  />
                  {data.current_assessment ? (
                    <div>
                      <Label>Alasan koreksi (wajib bila asesmen sudah ada)</Label>
                      <Input
                        value={soap.reason}
                        placeholder="mis. salah mengetik hasil pemeriksaan"
                        onChange={(event) => setSoap({ ...soap, reason: event.target.value })}
                      />
                      <p className="mt-1 text-xs text-slate-500">
                        Asesmen lama tidak dihapus: versi baru dibuat dan versi sebelumnya tetap tersimpan.
                      </p>
                    </div>
                  ) : null}
                  <Button onClick={() => saveAssessment.mutate()} disabled={saveAssessment.isPending}>
                    Simpan asesmen
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Diagnosis</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.diagnoses.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada diagnosis.</p>
              ) : (
                <ul className="space-y-1 text-sm">
                  {data.diagnoses.map((row) => (
                    <li key={row.id} className="flex items-center gap-2">
                      <Badge tone={row.type === 'PRIMARY' ? 'info' : 'neutral'}>{row.type}</Badge>
                      <span className="text-slate-800">
                        {row.diagnosis_code ? `${row.diagnosis_code} — ` : ''}
                        {row.diagnosis_name}
                      </span>
                      {!row.is_current ? <span className="text-xs text-slate-400">(versi lama)</span> : null}
                    </li>
                  ))}
                </ul>
              )}

              {writable ? (
                <div className="grid gap-2 md:grid-cols-3">
                  <div className="md:col-span-2">
                    <Label>Kode diagnosis ({metadata.data?.diagnosis_coding_system ?? 'INTERNAL'})</Label>
                    <Select
                      value={diagnosis.diagnosis_code}
                      onChange={(event) => setDiagnosis({ ...diagnosis, diagnosis_code: event.target.value })}
                    >
                      <option value="">— pilih dari master —</option>
                      {(metadata.data?.diagnosis_common ?? []).map((option) => (
                        <option key={option.code} value={option.code}>
                          {option.code} — {option.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label>Jenis</Label>
                    <Select value={diagnosis.type} onChange={(event) => setDiagnosis({ ...diagnosis, type: event.target.value })}>
                      <option value="PRIMARY">Diagnosis utama</option>
                      <option value="SECONDARY">Sekunder</option>
                      <option value="SYMPTOM">Gejala</option>
                      <option value="PROVISIONAL">Sementara</option>
                    </Select>
                  </div>
                  <div className="md:col-span-3">
                    <Input
                      placeholder="Catatan diagnosis"
                      value={diagnosis.notes}
                      onChange={(event) => setDiagnosis({ ...diagnosis, notes: event.target.value })}
                    />
                  </div>
                  <Button onClick={() => saveDiagnosis.mutate()} disabled={saveDiagnosis.isPending}>
                    Tambah diagnosis
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>Tindakan</CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.procedures.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada tindakan tercatat.</p>
              ) : (
                <ul className="space-y-1 text-sm text-slate-700">
                  {data.procedures.map((row) => (
                    <li key={row.id}>
                      {row.procedure}
                      <span className="ml-2 text-xs text-slate-400">
                        {new Date(row.performed_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                      </span>
                    </li>
                  ))}
                </ul>
              )}

              {writable ? (
                <div className="grid gap-2 md:grid-cols-3">
                  <div>
                    <Label>Jenis tindakan</Label>
                    <Select
                      value={procedure.type_id}
                      onChange={(event) => setProcedure({ ...procedure, type_id: event.target.value })}
                    >
                      <option value="">— pilih —</option>
                      {(metadata.data?.procedure_types ?? []).map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div className="md:col-span-2">
                    <Label>Catatan tindakan</Label>
                    <Input
                      value={procedure.notes}
                      onChange={(event) => setProcedure({ ...procedure, notes: event.target.value })}
                    />
                  </div>
                  <Button onClick={() => saveProcedure.mutate()} disabled={saveProcedure.isPending}>
                    Catat tindakan
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Pill className="h-4 w-4" /> Resep & pemberian obat
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.prescriptions.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada resep.</p>
              ) : (
                data.prescriptions.map((row) => (
                  <div key={row.id} className="rounded-md border p-3 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="font-medium text-slate-900">{row.prescription_number}</span>
                      <Badge tone={row.status === 'DISPENSED' ? 'success' : row.status === 'CANCELLED' ? 'neutral' : 'warning'}>
                        {row.status}
                      </Badge>
                      {row.allergy_override ? <Badge tone="danger">override alergi</Badge> : null}
                    </div>
                    <ul className="mt-1 space-y-0.5 text-slate-700">
                      {(row.items ?? []).map((item) => (
                        <li key={item.id}>
                          {item.medication_name} · {item.dose ?? '—'} · {item.frequency ?? '—'} · {item.duration ?? '—'} ·
                          jumlah {item.quantity} (terserahkan {item.dispensed_quantity})
                        </li>
                      ))}
                    </ul>
                    {row.status !== 'DISPENSED' && row.status !== 'CANCELLED' && writable ? (
                      <Button
                        size="sm"
                        className="mt-2"
                        variant="secondary"
                        onClick={() => dispense.mutate(row.id)}
                        disabled={dispense.isPending}
                      >
                        Serahkan obat (stok dari Inventaris)
                      </Button>
                    ) : null}
                  </div>
                ))
              )}

              {writable ? (
                <div className="grid gap-2 rounded-md bg-slate-50 p-3 md:grid-cols-3">
                  <div className="md:col-span-2">
                    <Label>Obat</Label>
                    <Select
                      value={prescription.medication_id}
                      onChange={(event) => setPrescription({ ...prescription, medication_id: event.target.value })}
                    >
                      <option value="">— pilih obat —</option>
                      {medicationOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name} {option.strength ? `(${option.strength})` : ''} · stok{' '}
                          {option.stock_available ?? 'belum dipetakan'}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label>Jumlah</Label>
                    <Input
                      type="number"
                      value={prescription.quantity}
                      onChange={(event) => setPrescription({ ...prescription, quantity: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Dosis</Label>
                    <Input
                      value={prescription.dose}
                      onChange={(event) => setPrescription({ ...prescription, dose: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Frekuensi</Label>
                    <Input
                      value={prescription.frequency}
                      onChange={(event) => setPrescription({ ...prescription, frequency: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Durasi</Label>
                    <Input
                      value={prescription.duration}
                      onChange={(event) => setPrescription({ ...prescription, duration: event.target.value })}
                    />
                  </div>
                  <div className="md:col-span-3">
                    <Label>Alasan klinis (hanya bila melanjutkan meski ada peringatan alergi)</Label>
                    <Input
                      value={prescription.override_reason}
                      onChange={(event) => setPrescription({ ...prescription, override_reason: event.target.value })}
                    />
                  </div>
                  <Button
                    onClick={() => createPrescription.mutate()}
                    disabled={createPrescription.isPending || prescription.medication_id === ''}
                  >
                    Terbitkan resep
                  </Button>
                </div>
              ) : null}

              {data.administrations.length > 0 ? (
                <div className="rounded-md bg-emerald-50 p-3 text-sm">
                  <p className="font-medium text-slate-800">Riwayat pemberian obat (MAR)</p>
                  <ul className="mt-1 space-y-0.5 text-slate-700">
                    {data.administrations.map((row) => (
                      <li key={row.id}>
                        {row.medication_name} · {row.dose ?? '—'} · {row.status}
                        {row.administered_at ? ` · ${new Date(row.administered_at).toLocaleString('id-ID')}` : ''}
                        {!row.is_current ? ' (versi lama — dikoreksi)' : ''}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {writable ? (
                <div className="grid gap-2 md:grid-cols-4">
                  <div className="md:col-span-2">
                    <Label>Obat diberikan</Label>
                    <Select
                      value={administration.medication_id}
                      onChange={(event) => setAdministration({ ...administration, medication_id: event.target.value })}
                    >
                      <option value="">— pilih obat —</option>
                      {medicationOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name}
                        </option>
                      ))}
                    </Select>
                  </div>
                  <div>
                    <Label>Dosis</Label>
                    <Input
                      value={administration.dose}
                      onChange={(event) => setAdministration({ ...administration, dose: event.target.value })}
                    />
                  </div>
                  <div>
                    <Label>Status</Label>
                    <Select
                      value={administration.status}
                      onChange={(event) => setAdministration({ ...administration, status: event.target.value })}
                    >
                      <option value="GIVEN">Diberikan</option>
                      <option value="REFUSED">Ditolak pasien</option>
                      <option value="HELD">Ditahan</option>
                      <option value="MISSED">Terlewat</option>
                    </Select>
                  </div>
                  <Button
                    className="md:col-span-4"
                    variant="secondary"
                    onClick={() => administer.mutate()}
                    disabled={administer.isPending || administration.medication_id === ''}
                  >
                    Catat pemberian obat
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>
        </div>

        {/* ----------------------------------------------- kolom kanan */}
        <div className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <BedDouble className="h-4 w-4" /> Observasi
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.observations.length === 0 ? (
                <p className="text-sm text-slate-500">Tidak ada observasi.</p>
              ) : (
                data.observations.map((row) => (
                  <div key={row.id} className="rounded-md bg-slate-50 p-2 text-sm">
                    <div className="flex items-center gap-2">
                      <Badge tone={row.status === 'ACTIVE' ? 'warning' : 'neutral'}>{row.status}</Badge>
                      <span className="text-slate-800">
                        {row.reason} {row.bed ? `· bed ${row.bed}` : ''}
                      </span>
                    </div>
                    <p className="text-xs text-slate-500">
                      Mulai {new Date(row.started_at).toLocaleString('id-ID')}
                      {row.ended_at ? ` · selesai ${new Date(row.ended_at).toLocaleString('id-ID')}` : ''}
                    </p>
                    {(row.records ?? []).length > 0 ? (
                      <ul className="mt-1 space-y-0.5 text-xs text-slate-600">
                        {(row.records ?? []).map((record) => (
                          <li key={record.id}>
                            {new Date(record.recorded_at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })} —{' '}
                            {record.condition}
                            {record.notes ? `: ${record.notes}` : ''}
                          </li>
                        ))}
                      </ul>
                    ) : null}
                  </div>
                ))
              )}

              {writable && activeObservation === null ? (
                <div className="space-y-2">
                  <Input
                    placeholder="Alasan observasi"
                    value={observation.reason}
                    onChange={(event) => setObservation({ ...observation, reason: event.target.value })}
                  />
                  <Input
                    placeholder="Bed (mis. A1)"
                    value={observation.bed}
                    onChange={(event) => setObservation({ ...observation, bed: event.target.value })}
                  />
                  <Button
                    variant="secondary"
                    className="w-full"
                    onClick={() => startObservation.mutate()}
                    disabled={observation.reason.trim() === '' || startObservation.isPending}
                  >
                    Mulai observasi
                  </Button>
                </div>
              ) : null}

              {writable && activeObservation !== null ? (
                <Button
                  variant="secondary"
                  className="w-full"
                  onClick={() =>
                    clinicApi
                      .endObservation(activeObservation.id, { status: 'COMPLETED', notes: 'Kondisi membaik' })
                      .then(() => {
                        toast.success('Observasi diakhiri.');
                        void refresh();
                      })
                      .catch((error) => toast.error(getErrorMessage(error)))
                  }
                >
                  Akhiri observasi
                </Button>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <Send className="h-4 w-4" /> Rujukan
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.referrals.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada rujukan.</p>
              ) : (
                data.referrals.map((row) => (
                  <div key={row.id} className="rounded-md bg-orange-50 p-2 text-sm">
                    <p className="font-medium text-slate-900">{row.facility}</p>
                    <p className="text-xs text-slate-600">
                      {row.referral_number} · {row.urgency} · {row.status}
                    </p>
                    <p className="text-xs text-slate-700">{row.reason}</p>
                  </div>
                ))
              )}

              {writable && data.referrals.length === 0 ? (
                <div className="space-y-2">
                  <Input
                    placeholder="Fasilitas tujuan"
                    value={referral.facility}
                    onChange={(event) => setReferral({ ...referral, facility: event.target.value })}
                  />
                  <Select
                    value={referral.urgency}
                    onChange={(event) => setReferral({ ...referral, urgency: event.target.value })}
                  >
                    <option value="ROUTINE">Rutin</option>
                    <option value="URGENT">Mendesak</option>
                    <option value="EMERGENCY">Gawat darurat</option>
                  </Select>
                  <Textarea
                    placeholder="Alasan rujukan (wajib)"
                    value={referral.reason}
                    onChange={(event) => setReferral({ ...referral, reason: event.target.value })}
                  />
                  <Button
                    variant="secondary"
                    className="w-full"
                    onClick={() => refer.mutate()}
                    disabled={referral.facility.trim() === '' || referral.reason.trim() === '' || refer.isPending}
                  >
                    Buat rujukan
                  </Button>
                </div>
              ) : null}
            </CardBody>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ActivitySquare className="h-4 w-4" /> Pemulangan
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-3">
              {data.discharge ? (
                <div className="rounded-md bg-emerald-50 p-3 text-sm">
                  <p className="font-medium text-slate-900">{data.discharge.status}</p>
                  <p className="text-xs text-slate-600">
                    Kondisi: {data.discharge.condition} ·{' '}
                    {new Date(data.discharge.discharge_time).toLocaleString('id-ID')}
                    {data.discharge.version > 1 ? ` · versi ${data.discharge.version}` : ''}
                  </p>
                  {data.discharge.instructions ? (
                    <p className="text-xs text-slate-700">{data.discharge.instructions}</p>
                  ) : null}
                  {data.discharge.follow_up_required ? (
                    <p className="text-xs text-slate-700">Kontrol: {data.discharge.follow_up_date}</p>
                  ) : null}
                </div>
              ) : writable ? (
                <>
                  <div>
                    <Label>Tujuan setelah layanan</Label>
                    <Select
                      value={discharge.status}
                      onChange={(event) => setDischarge({ ...discharge, status: event.target.value })}
                    >
                      <option value="RETURN_TO_CLASS">Kembali ke kelas</option>
                      <option value="RETURN_TO_DORM">Kembali ke asrama</option>
                      <option value="HOME">Dipulangkan ke rumah</option>
                      <option value="REFERRED">Dirujuk</option>
                      <option value="HOSPITALIZED">Rawat inap</option>
                      <option value="OTHER">Lainnya</option>
                    </Select>
                  </div>
                  <div>
                    <Label>Kondisi saat pulang</Label>
                    <Select
                      value={discharge.condition}
                      onChange={(event) => setDischarge({ ...discharge, condition: event.target.value })}
                    >
                      <option value="RECOVERED">Sembuh</option>
                      <option value="IMPROVING">Membaik</option>
                      <option value="STABLE">Stabil</option>
                      <option value="UNCHANGED">Tidak berubah</option>
                      <option value="WORSENED">Memburuk</option>
                    </Select>
                  </div>
                  <Textarea
                    placeholder="Instruksi untuk pasien/orang tua"
                    value={discharge.instructions}
                    onChange={(event) => setDischarge({ ...discharge, instructions: event.target.value })}
                  />
                  <label className="flex items-center gap-2 text-sm text-slate-600">
                    <input
                      type="checkbox"
                      checked={discharge.follow_up_required}
                      onChange={(event) =>
                        setDischarge({ ...discharge, follow_up_required: event.target.checked })
                      }
                    />
                    Perlu kontrol ulang
                  </label>
                  {discharge.follow_up_required ? (
                    <Input
                      type="date"
                      value={discharge.follow_up_date}
                      onChange={(event) => setDischarge({ ...discharge, follow_up_date: event.target.value })}
                    />
                  ) : null}
                  <Button
                    className="w-full"
                    onClick={() => finish.mutate()}
                    disabled={finish.isPending || (discharge.follow_up_required && discharge.follow_up_date === '')}
                  >
                    Pulangkan pasien
                  </Button>
                  <p className="text-xs text-slate-500">
                    Presensi kelas & status asrama hanya berubah bila kebijakan sekolah mengizinkannya — hasilnya
                    dilaporkan setelah pemulangan.
                  </p>
                </>
              ) : (
                <p className="text-sm text-slate-500">Kunjungan sudah ditutup tanpa catatan pemulangan.</p>
              )}
            </CardBody>
          </Card>

          {data.emergencies.length > 0 || data.injuries.length > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Ambulance className="h-4 w-4" /> Gawat darurat & cedera
                </CardTitle>
              </CardHeader>
              <CardBody className="space-y-2 text-sm">
                {data.emergencies.map((row) => (
                  <div key={`em-${row.id}`} className="rounded-md bg-rose-50 p-2">
                    <p className="font-medium text-slate-900">{row.incident_number}</p>
                    <p className="text-xs text-slate-600">
                      {row.severity} · {row.status} · orang tua {row.parent_contacted ? 'sudah dihubungi' : 'BELUM dihubungi'}
                    </p>
                    <p className="text-xs text-slate-700">{row.description}</p>
                  </div>
                ))}
                {data.injuries.map((row) => (
                  <div key={`inj-${row.id}`} className="rounded-md bg-amber-50 p-2">
                    <p className="font-medium text-slate-900">
                      {row.injury_type} — {row.body_part ?? 'lokasi tidak dicatat'}
                    </p>
                    <p className="text-xs text-slate-600">
                      {row.location ?? '—'} · {row.severity} · {row.status}
                    </p>
                  </div>
                ))}
              </CardBody>
            </Card>
          ) : null}

          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <FileText className="h-4 w-4" /> Riwayat & dokumen
              </CardTitle>
            </CardHeader>
            <CardBody className="space-y-2">
              {data.timeline.length === 0 ? (
                <p className="text-sm text-slate-500">Belum ada riwayat.</p>
              ) : (
                <ol className="space-y-1 text-sm">
                  {data.timeline.map((event, index) => (
                    <li key={`${event.type}-${index}`} className="flex gap-2">
                      <span className="w-24 shrink-0 text-xs text-slate-400">
                        {new Date(event.at).toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })}
                      </span>
                      <span className="text-slate-700">{event.label}</span>
                    </li>
                  ))}
                </ol>
              )}

              {data.certificates.length > 0 ? (
                <div className="rounded-md bg-slate-50 p-2 text-sm">
                  <p className="font-medium text-slate-800">Surat keterangan</p>
                  <ul className="text-xs text-slate-600">
                    {data.certificates.map((certificate) => (
                      <li key={certificate.id}>
                        {certificate.certificate_number} · {certificate.type} · {certificate.status}
                      </li>
                    ))}
                  </ul>
                </div>
              ) : null}

              {patientId ? (
                <Link to="/clinic/certificates">
                  <Button size="sm" variant="secondary">
                    <Syringe className="mr-1 h-4 w-4" /> Surat & dokumen pasien
                  </Button>
                </Link>
              ) : null}
            </CardBody>
          </Card>
        </div>
      </div>
    </div>
  );
}
