import React from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { useActivityConsents, useActivityParent, useDecideActivityConsent } from '@/api/family';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export default function Ekskul() {
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
