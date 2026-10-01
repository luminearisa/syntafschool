import React, { useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Button, Card, Segmented, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { useActivityConsents, useActivityParent, useDecideActivityConsent } from '@/api/family';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { Kv, Sheet, TextArea } from '@/components/forms';
import { useActivityStudent, useCancelRegistration, useDiscoverPrograms, useMyRegistrations, useOwnStudentId, useRegisterProgram } from '@/api/student';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export default function Ekskul() {
  return useAuth((s) => s.active) === 'student' ? <EkskulStudent /> : <EkskulGuardian />;
}

const REG: Record<string, string> = {
  DRAFT: 'Draf', SUBMITTED: 'Diajukan', APPROVED: 'Disetujui', WAITLISTED: 'Daftar tunggu', REJECTED: 'Ditolak', CANCELLED: 'Dibatalkan',
  PENDING_PARENT_CONSENT: 'Menunggu orang tua', UNDER_SELECTION: 'Seleksi',
};
const lbl = (v: unknown) => REG[String(v).toUpperCase()] ?? String(v ?? '').replace(/_/g, ' ').toLowerCase();

function EkskulStudent() {
  const [tab, setTab] = useState<'mine' | 'find'>('mine');
  const studentId = useOwnStudentId();
  const mine = useMyRegistrations();
  const prog = useDiscoverPrograms();
  const info = useActivityStudent();
  const register = useRegisterProgram();
  const cancel = useCancelRegistration();
  const [pick, setPick] = useState<R | null>(null);
  const [why, setWhy] = useState('');
  const key = useRef<string>('');
  const kid: R | undefined = info.data?.children?.[0] ?? info.data;

  const open = (p: R) => { key.current = `${studentId}-${p.id}-${Date.now()}`; setWhy(''); setPick(p); };
  const go = () => {
    if (!pick || !studentId) return;
    register.mutate({ programId: pick.id, studentId, motivation: why.trim() || undefined, key: key.current }, {
      onSuccess: (r) => { Alert.alert('Pendaftaran dicatat', `Status: ${lbl(r?.status)}`); setPick(null); },
      onError: (e) => Alert.alert('Tidak dapat mendaftar', errorMessage(e)),
    });
  };
  const askCancel = (r: R) => Alert.alert('Batalkan pendaftaran?', r.program?.name ?? '', [
    { text: 'Tidak', style: 'cancel' },
    { text: 'Batalkan', onPress: () => cancel.mutate(r.id, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) },
  ]);

  return (
    <Screen refreshing={mine.isRefetching} onRefresh={() => { mine.refetch(); prog.refetch(); info.refetch(); }} header={<Hero back subtitle="Ekstrakurikuler" />}>
      <Segmented value={tab} onChange={setTab} options={[{ key: 'mine', label: 'Kegiatan saya' }, { key: 'find', label: 'Cari kegiatan' }]} />
      {mine.isLoading || prog.isLoading ? <Loading /> : null}
      {mine.error ? <ErrorNote message={errorMessage(mine.error)} onRetry={() => mine.refetch()} /> : null}

      {tab === 'mine' ? (
        <>
          {(kid?.my_activities ?? []).map((a: R) => (
            <Card key={a.id}>
              <Row left={<Text style={T.title}>{a.program}</Text>} sub={<Text style={T.small}>{a.role ?? 'Anggota'}</Text>} right={a.attendance_percentage !== null && a.attendance_percentage !== undefined ? <Pill label={`Hadir ${Math.round(a.attendance_percentage)}%`} color={colors.success} /> : undefined} />
            </Card>
          ))}
          {(kid?.upcoming_sessions ?? []).length > 0 ? (
            <Card>
              <Text style={T.title}>Jadwal berikutnya</Text>
              {kid!.upcoming_sessions.map((s: R, i: number) => <Row key={i} left={<Text style={T.body}>{s.program}</Text>} right={<Text style={T.small}>{dateShort(s.date)} {s.start_time}</Text>} />)}
            </Card>
          ) : null}
          <SectionTitle title="Pendaftaran" />
          {!mine.isLoading && (mine.data ?? []).length === 0 ? <EmptyState icon="trophy-outline" title="Belum ada pendaftaran" hint="Buka tab Cari kegiatan untuk mendaftar." /> : null}
          {(mine.data ?? []).map((r: R) => {
            const live = ['SUBMITTED', 'DRAFT', 'WAITLISTED', 'PENDING_PARENT_CONSENT', 'UNDER_SELECTION'].includes(String(r.status).toUpperCase());
            return (
              <Card key={r.id}>
                <Row left={<Text style={T.title}>{r.program?.name}</Text>} sub={<Text style={T.small}>{r.period?.name ?? ''}</Text>} right={<Pill label={lbl(r.status)} color={String(r.status).toUpperCase() === 'APPROVED' ? colors.success : colors.warning} />} />
                {live ? <Button label="Batalkan" variant="ghost" onPress={() => askCancel(r)} /> : null}
              </Card>
            );
          })}
          {kid ? (
            <Card style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
              <Count label="Prestasi" value={kid.achievements} />
              <Count label="Sertifikat" value={kid.certificates} />
              <Count label="Selesai" value={kid.completed_activities} />
            </Card>
          ) : null}
        </>
      ) : (
        <>
          {!prog.isLoading && (prog.data ?? []).length === 0 ? <EmptyState icon="search-outline" title="Belum ada pendaftaran dibuka" /> : null}
          {(prog.data ?? []).map((p: R) => (
            <Card key={p.id} onPress={() => open(p)}>
              <Row left={<Text style={T.title}>{p.name}</Text>} sub={<Text style={T.small}>{p.category?.name ?? ''}{p.coach?.full_name ? ` · ${p.coach.full_name}` : ''}</Text>} right={p.requires_parent_consent ? <Pill label="Perlu izin ortu" color={colors.warning} /> : undefined} />
              {p.registration_closes ? <Text style={T.small}>Pendaftaran ditutup {dateShort(p.registration_closes)}</Text> : null}
            </Card>
          ))}
        </>
      )}

      {pick ? (
        <Sheet visible title={pick.name} onClose={() => setPick(null)} footer={<Button label="Daftar" icon="checkmark-circle-outline" onPress={go} loading={register.isPending} />}>
          <Card>
            <Kv k="Kategori" v={pick.category?.name} />
            <Kv k="Pembina" v={pick.coach?.full_name} />
            <Kv k="Kuota" v={pick.maximum_capacity} />
            <Kv k="Seleksi" v={pick.selection_method} />
            {pick.description ? <Text style={T.body}>{pick.description}</Text> : null}
          </Card>
          <TextArea label="Motivasi (opsional)" value={why} onChangeText={setWhy} />
          <Text style={T.small}>Status akhir (disetujui, seleksi, daftar tunggu, atau menunggu izin orang tua) ditentukan oleh aturan program.</Text>
        </Sheet>
      ) : null}
    </Screen>
  );
}

function EkskulGuardian() {
  const role = useAuth((s) => s.active);
  const q = useActivityParent();
  const consents = useActivityConsents();
  const decide = useDecideActivityConsent();
  const kids = q.data?.children ?? [];

  const choose = (c: R, decision: 'APPROVED' | 'DECLINED') =>
    Alert.alert(decision === 'APPROVED' ? 'Setujui keikutsertaan?' : 'Tolak keikutsertaan?', `${c.student_name} — ${c.program_name}`, [
      { text: 'Batal', style: 'cancel' },
      { text: decision === 'APPROVED' ? 'Setujui' : 'Tolak', onPress: () => decide.mutate({ id: c.id, decision }, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) },
    ]);

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => { q.refetch(); consents.refetch(); }} header={<Hero back subtitle="Ekstrakurikuler & prestasi" />}>
      {q.isLoading || consents.isLoading ? <Loading /> : null}
      {q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}

      {(consents.data ?? []).length > 0 ? (
        <>
          <SectionTitle title="Menunggu persetujuan Anda" />
          {consents.data!.map((c: R) => (
            <Card key={c.id} style={{ borderColor: colors.gold, borderWidth: 1.5 }}>
              <Row left={<Text style={T.title}>{c.program_name}</Text>} sub={<Text style={T.small}>{c.student_name} · {c.scope_label}</Text>} right={<Pill label="Perlu keputusan" color={colors.warning} />} />
              {c.expires_at ? <Text style={T.small}>Berlaku hingga {dateShort(c.expires_at)}</Text> : null}
              <View style={{ flexDirection: 'row', gap: 10 }}>
                <View style={{ flex: 1 }}><Button label="Setujui" icon="checkmark-circle-outline" onPress={() => choose(c, 'APPROVED')} disabled={decide.isPending || role !== 'guardian'} /></View>
                <View style={{ flex: 1 }}><Button label="Tolak" variant="ghost" onPress={() => choose(c, 'DECLINED')} disabled={decide.isPending || role !== 'guardian'} /></View>
              </View>
            </Card>
          ))}
        </>
      ) : null}

      {!q.isLoading && kids.length === 0 ? <EmptyState icon="trophy-outline" title="Belum ada kegiatan" /> : null}
      {kids.map((k: R) => (
        <View key={k.student_id} style={{ gap: 10 }}>
          <SectionTitle title={k.student_name ?? `Anak #${k.student_id}`} />
          {(k.my_activities ?? []).length === 0 ? <Card><Text style={T.body}>Belum mengikuti ekstrakurikuler.</Text></Card> : null}
          {(k.my_activities ?? []).map((a: R) => (
            <Card key={a.id}>
              <Row left={<Text style={T.title}>{a.program}</Text>} sub={<Text style={T.small}>{a.role ?? 'Anggota'}</Text>} right={a.attendance_percentage !== null ? <Pill label={`Hadir ${Math.round(a.attendance_percentage)}%`} color={colors.success} /> : undefined} />
              {a.average_score !== null ? <Text style={T.small}>Rata-rata penilaian {a.average_score}</Text> : null}
            </Card>
          ))}
          {(k.upcoming_sessions ?? []).length > 0 ? (
            <Card>
              <Text style={T.title}>Jadwal berikutnya</Text>
              {k.upcoming_sessions.map((s: R, i: number) => <Row key={i} left={<Text style={T.body}>{s.program}</Text>} right={<Text style={T.small}>{dateShort(s.date)} {s.start_time}</Text>} />)}
            </Card>
          ) : null}
          <Card style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
            <Count label="Prestasi" value={k.achievements} />
            <Count label="Sertifikat" value={k.certificates} />
            <Count label="Selesai" value={k.completed_activities} />
          </Card>
        </View>
      ))}
    </Screen>
  );
}

function Count({ label, value }: { label: string; value?: number }) {
  return (
    <View style={{ alignItems: 'center' }}>
      <Text style={{ fontSize: 22, fontWeight: '800', color: colors.primary }}>{value ?? 0}</Text>
      <Text style={T.small}>{label}</Text>
    </View>
  );
}
