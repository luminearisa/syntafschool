import React, { useState } from 'react';
import { Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { Chips, Kv } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { useClinicCertificates, useClinicChild, useClinicChildren, useClinicVisits } from '@/api/family';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
type Tab = 'summary' | 'visits' | 'certs';

export default function Kesehatan() {
  const q = useClinicChildren();
  const kids = q.data?.registered ?? [];
  const [pick, setPick] = useState<number | null>(null);
  const patientId = pick ?? kids[0]?.patient_id;
  const [tab, setTab] = useState<Tab>('summary');

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Kesehatan anak (klinik sekolah)" />}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {(q.data?.unregistered ?? []).map((u: R) => (
        <Card key={u.id ?? u.student_id}><Text style={T.title}>{u.full_name ?? u.name}</Text><Text style={T.small}>Belum terdaftar di klinik sekolah.</Text></Card>
      ))}
      {!q.isLoading && kids.length === 0 && (q.data?.unregistered ?? []).length === 0 ? <EmptyState icon="medkit-outline" title="Belum ada data kesehatan" /> : null}
      {kids.length > 1 ? <Chips label="Anak" value={patientId ?? null} options={kids.map((k: R) => ({ key: k.patient_id as number, label: k.name as string }))} onChange={setPick} /> : null}
      {patientId ? (
        <>
          <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'summary', label: 'Ringkasan' }, { key: 'visits', label: 'Kunjungan' }, { key: 'certs', label: 'Surat' }]} />
          {tab === 'summary' ? <Summary id={patientId} /> : null}
          {tab === 'visits' ? <Visits id={patientId} /> : null}
          {tab === 'certs' ? <Certs id={patientId} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

function Summary({ id }: { id: number }) {
  const q = useClinicChild(id);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  const d = q.data as R;
  const v = d.vitals_latest ?? {};
  return (
    <>
      <Card>
        <Text style={T.title}>{d.identity?.name ?? d.patient?.medical_record_number}</Text>
        <Kv k="No. rekam medis" v={d.patient?.medical_record_number} />
        <Kv k="Golongan darah" v={d.patient?.blood_type ?? d.identity?.blood_type} />
      </Card>
      {(d.allergies ?? []).length > 0 ? (
        <Card style={{ borderColor: colors.danger, borderWidth: 1 }}>
          <Text style={[T.title, { color: colors.danger }]}>Alergi</Text>
          {d.allergies.map((a: R, i: number) => <Text key={i} style={T.body}>• {a.allergen ?? a.name}{a.reaction ? ` — ${a.reaction}` : ''}{a.severity ? ` (${a.severity})` : ''}</Text>)}
        </Card>
      ) : null}
      {(d.special_conditions ?? []).length > 0 ? (
        <Card>
          <Text style={T.title}>Kondisi khusus</Text>
          {d.special_conditions.map((c: R, i: number) => <Text key={i} style={T.body}>• {c.condition ?? c.name ?? c.title}</Text>)}
        </Card>
      ) : null}
      {Object.keys(v).length > 0 ? (
        <Card>
          <Text style={T.title}>Tanda vital terakhir</Text>
          <Kv k="Tinggi / berat" v={v.height_cm || v.weight_kg ? `${v.height_cm ?? '-'} cm / ${v.weight_kg ?? '-'} kg` : null} />
          <Kv k="Suhu" v={v.temperature ? `${v.temperature} °C` : null} />
          <Kv k="Tekanan darah" v={v.systolic ? `${v.systolic}/${v.diastolic}` : null} />
          <Kv k="Diukur" v={v.measured_at ? dayjs(v.measured_at).format('D MMM YYYY') : null} />
        </Card>
      ) : null}
    </>
  );
}

function Visits({ id }: { id: number }) {
  const q = useClinicVisits(id);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="pulse-outline" title="Belum ada kunjungan" />;
  return (
    <>
      {q.data.map((v: R) => (
        <Card key={v.visit_number}>
          <Row left={<Text style={T.title}>{v.chief_complaint ?? v.visit_type_label}</Text>} sub={<Text style={T.small}>{dayjs(v.visit_at).format('D MMM YYYY HH:mm')} · {v.visit_type_label}</Text>} right={<Pill label={v.status_label} />} />
          {v.diagnosis ? <Text style={T.body}>Diagnosis: {v.diagnosis}</Text> : null}
          {(v.medications ?? []).map((m: R, i: number) => <Text key={i} style={T.small}>💊 {m.medication_name}{m.dose ? ` · ${m.dose}` : ''}</Text>)}
          {v.discharge?.instructions ? <Text style={T.body}>Anjuran: {v.discharge.instructions}</Text> : null}
          {v.discharge?.follow_up_required ? <Pill label={`Kontrol ulang${v.discharge.follow_up_date ? ` ${dateShort(v.discharge.follow_up_date)}` : ''}`} color={colors.warning} /> : null}
        </Card>
      ))}
    </>
  );
}

function Certs({ id }: { id: number }) {
  const q = useClinicCertificates(id);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="document-text-outline" title="Belum ada surat keterangan" />;
  return (
    <View style={{ gap: 12 }}>
      {q.data.map((c: R) => (
        <Card key={c.certificate_number}>
          <Row left={<Text style={T.title}>{c.type_label}</Text>} sub={<Text style={T.small}>{c.certificate_number}</Text>} right={<Pill label={c.is_valid ? 'Berlaku' : 'Tidak berlaku'} color={c.is_valid ? colors.success : colors.muted} />} />
          <Text style={T.small}>Terbit {c.issued_at ? dateShort(c.issued_at) : '-'}{c.valid_until ? ` · s/d ${dateShort(c.valid_until)}` : ''}</Text>
        </Card>
      ))}
    </View>
  );
}
