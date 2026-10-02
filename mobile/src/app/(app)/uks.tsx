import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { Kv } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { ChildPicker } from '@/components/ChildPicker';
import { useChildren } from '@/api/hooks';
import { errorMessage, get, post } from '@/api/client';
import { downloadAuthed } from '@/lib/download';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
type Tab = 'visits' | 'checks' | 'vax' | 'profile' | 'letters';

const SEV: Record<string, string> = { RINGAN: colors.success, SEDANG: colors.warning, BERAT: colors.danger, DARURAT: colors.danger };

/** UKS sekolah: kabar kunjungan UKS anak/diri sendiri, pemeriksaan berkala, imunisasi, profil kesehatan, surat. */
export default function Uks() {
  const role = useAuth((s) => s.active);
  const { children, child } = useChildren();
  const q = useQuery({ queryKey: ['uks', child?.id], queryFn: () => get<R>(`/mobile/children/${child!.id}/uks`), enabled: !!child });
  const [tab, setTab] = useState<Tab>('visits');
  const d = q.data;
  const pending = (d?.visits ?? []).filter((v: R) => v.needs_consent);

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="UKS sekolah" />}>
      {role === 'guardian' ? <ChildPicker children={children} current={child} /> : null}
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {role === 'guardian' ? pending.map((v: R) => <ConsentCard key={v.id} visit={v} childId={child!.id} />) : null}
      {d ? (
        <>
          <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'visits', label: 'Kunjungan' }, { key: 'checks', label: 'Pemeriksaan' }, { key: 'vax', label: 'Imunisasi' }, { key: 'profile', label: 'Profil' }, { key: 'letters', label: 'Surat' }]} />
          {tab === 'visits' ? <Visits d={d} /> : null}
          {tab === 'checks' ? <Checks d={d} /> : null}
          {tab === 'vax' ? <Vax d={d} /> : null}
          {tab === 'profile' ? <Profile d={d} /> : null}
          {tab === 'letters' ? <Letters d={d} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

function ConsentCard({ visit, childId }: { visit: R; childId: number }) {
  const qc = useQueryClient();
  const m = useMutation({ mutationFn: (decision: 'DISETUJUI' | 'DITOLAK') => post(`/mobile/uks/visits/${visit.id}/consent`, { decision }), onSuccess: () => qc.invalidateQueries({ queryKey: ['uks', childId] }) });
  const choose = (decision: 'DISETUJUI' | 'DITOLAK') => Alert.alert(decision === 'DISETUJUI' ? 'Setujui rujukan?' : 'Tolak rujukan?', visit.referral?.facility_name ?? '', [
    { text: 'Batal', style: 'cancel' },
    { text: decision === 'DISETUJUI' ? 'Setujui' : 'Tolak', onPress: () => m.mutate(decision, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) },
  ]);
  return (
    <Card style={{ borderColor: colors.gold, borderWidth: 1.5 }}>
      <Row left={<Text style={T.title}>Persetujuan rujukan</Text>} sub={<Text style={T.small}>{dateShort(visit.arrived_at)} · {visit.complaint}</Text>} right={<Pill label="Perlu keputusan" color={colors.warning} />} />
      <Text style={T.body}>Dirujuk ke {visit.referral?.facility_name}. {visit.referral?.reason}</Text>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <View style={{ flex: 1 }}><Button label="Setujui" icon="checkmark-circle-outline" onPress={() => choose('DISETUJUI')} loading={m.isPending} /></View>
        <View style={{ flex: 1 }}><Button label="Tolak" variant="ghost" onPress={() => choose('DITOLAK')} disabled={m.isPending} /></View>
      </View>
    </Card>
  );
}

function Visits({ d }: { d: R }) {
  if ((d.visits ?? []).length === 0) return <EmptyState icon="medkit-outline" title="Belum ada kunjungan UKS" />;
  return (
    <>
      {d.visits.map((v: R) => (
        <Card key={v.id}>
          <Row left={<Text style={T.title}>{v.complaint}</Text>} sub={<Text style={T.small}>{dateShort(v.arrived_at)} · {v.visit_type}</Text>} right={<Pill label={v.severity_label} color={SEV[v.severity] ?? colors.info} />} />
          <Kv k="Status" v={v.status_label} />
          {v.treatment ? <Kv k="Penanganan" v={v.treatment} /> : null}
          {v.advice ? <Kv k="Saran" v={v.advice} /> : null}
          {v.needs_rest ? <Text style={T.small}>Dianjurkan istirahat.</Text> : null}
          {v.sent_home ? <Text style={T.small}>Dipulangkan.</Text> : null}
          {v.medications.length > 0 ? <Text style={T.small}>Obat: {v.medications.map((m: R) => `${m.name}${m.dosage ? ` (${m.dosage})` : ''}`).join(', ')}</Text> : null}
          {v.referral ? <Text style={T.small}>Rujukan: {v.referral.facility_name} · {v.referral.status_label}</Text> : null}
          {v.consent ? <Pill label={v.consent === 'DISETUJUI' ? 'Rujukan disetujui' : 'Rujukan ditolak'} color={v.consent === 'DISETUJUI' ? colors.success : colors.danger} /> : null}
        </Card>
      ))}
    </>
  );
}

function Checks({ d }: { d: R }) {
  if ((d.screenings ?? []).length === 0) return <EmptyState icon="body-outline" title="Belum ada pemeriksaan berkala" />;
  return (
    <>
      {d.screenings.map((s: R, i: number) => (
        <Card key={i}>
          <Row left={<Text style={T.title}>{s.type}</Text>} sub={<Text style={T.small}>{dateShort(s.screened_at)}</Text>} right={<Pill label={String(s.result).replace('_', ' ')} color={s.result === 'NORMAL' ? colors.success : colors.warning} />} />
          {s.height_cm || s.weight_kg ? <Kv k="TB / BB" v={`${s.height_cm ?? '–'} cm / ${s.weight_kg ?? '–'} kg${s.bmi ? ` (IMT ${s.bmi})` : ''}`} /> : null}
          {s.vision_left || s.vision_right ? <Kv k="Penglihatan (kiri/kanan)" v={`${s.vision_left ?? '–'} / ${s.vision_right ?? '–'}`} /> : null}
          {s.dental_status ? <Kv k="Gigi" v={s.dental_status} /> : null}
          {s.findings ? <Text style={T.body}>{s.findings}</Text> : null}
          {s.recommendation ? <Text style={[T.body, { fontStyle: 'italic' }]}>{s.recommendation}</Text> : null}
        </Card>
      ))}
    </>
  );
}

function Vax({ d }: { d: R }) {
  if ((d.immunizations ?? []).length === 0) return <EmptyState icon="shield-checkmark-outline" title="Belum ada catatan imunisasi" />;
  return (
    <Card>
      {d.immunizations.map((i: R, n: number) => (
        <Row key={n} left={<Text style={T.body}>{i.vaccine_name} · dosis {i.dose_number}</Text>} sub={<Text style={T.small}>{dateShort(i.given_at)}</Text>} right={i.next_due_at ? <Pill label={`Berikut ${dateShort(i.next_due_at)}`} color={colors.info} /> : undefined} />
      ))}
    </Card>
  );
}

function Profile({ d }: { d: R }) {
  const p = d.profile;
  if (!p) return <EmptyState icon="id-card-outline" title="Profil kesehatan belum diisi" hint="Petugas UKS mengisinya saat pemeriksaan." />;
  return (
    <Card>
      <Kv k="Golongan darah" v={p.blood_type} />
      <Kv k="Alergi" v={p.allergies} />
      <Kv k="Penyakit kronis" v={p.chronic_conditions} />
      <Kv k="Obat rutin" v={p.regular_medications} />
      <Kv k="Kebutuhan khusus" v={p.special_needs} />
      <Kv k="Kontak darurat" v={p.emergency_contact_name ? `${p.emergency_contact_name}${p.emergency_contact_relation ? ` (${p.emergency_contact_relation})` : ''} ${p.emergency_contact_phone ?? ''}` : null} />
    </Card>
  );
}

function Letters({ d }: { d: R }) {
  const [busy, setBusy] = useState<number | null>(null);
  if ((d.letters ?? []).length === 0) return <EmptyState icon="document-text-outline" title="Belum ada surat keterangan" />;
  return (
    <>
      {d.letters.map((l: R) => (
        <Card key={l.id}>
          <Row left={<Text style={T.title}>{l.letter_type}</Text>} sub={<Text style={T.small}>{l.number} · {dateShort(l.date_from)}{l.date_to !== l.date_from ? ` – ${dateShort(l.date_to)}` : ''}</Text>} />
          <Button label="Unduh PDF" variant="ghost" icon="download-outline" loading={busy === l.id}
            onPress={() => { setBusy(l.id); downloadAuthed(`/mobile/uks/letters/${l.id}/pdf`, `surat-uks-${l.number}.pdf`).catch((e) => Alert.alert('Gagal mengunduh', errorMessage(e))).finally(() => setBusy(null)); }} />
        </Card>
      ))}
    </>
  );
}
