import React from 'react';
import { Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Hero } from '@/components/Hero';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, StatTile, T } from '@/components/ui';
import { ChildHeader } from '@/components/ChildPicker';
import { useEmployeeHome, useHome } from '@/api/hooks';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateLong, rupiah, timeHM } from '@/lib/format';
import { useRouter } from 'expo-router';
import { QuickLinks } from '@/components/QuickLinks';

export default function Home() {
  const active = useAuth((s) => s.active);
  return active === 'employee' ? <EmployeeHome /> : <FamilyHome />;
}

function FamilyHome() {
  const active = useAuth((s) => s.active);
  const select = useAuth((s) => s.selectChild);
  const router = useRouter();
  const q = useHome();
  const kids = q.data?.children ?? [];

  return (
    <Screen
      refreshing={q.isRefetching}
      onRefresh={() => q.refetch()}
      header={<Hero subtitle={active === 'guardian' ? 'Pantau perkembangan putra-putri Anda.' : 'Semangat belajar hari ini!'} />}
    >
      <QuickLinks />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data && kids.length === 0 ? <EmptyState icon="people-outline" title="Belum ada data anak" hint="Hubungi administrasi sekolah untuk menautkan data anak ke akun Anda." /> : null}

      {kids.map((c) => {
        const a = c.attendance ?? {};
        const hadir = a.H ?? 0;
        const total = Object.values(a).reduce((x, y) => x + y, 0);
        return (
          <Card key={c.id} onPress={() => { select(c.id); router.push('/akademik'); }}>
            <ChildHeader child={c} />
            <View style={{ flexDirection: 'row', gap: 10, marginTop: 6 }}>
              <StatTile icon="checkmark-done" tint={colors.success} label="Hadir bulan ini" value={total ? `${hadir}/${total}` : '–'} />
              {active === 'guardian' && c.finance ? (
                <StatTile
                  icon="wallet"
                  tint={c.finance.overdue > 0 ? colors.danger : c.finance.outstanding > 0 ? colors.warning : colors.success}
                  label="Tagihan berjalan"
                  value={c.finance.outstanding > 0 ? rupiah(c.finance.outstanding) : 'Lunas'}
                  hint={c.finance.overdue > 0 ? 'Ada yang jatuh tempo' : undefined}
                />
              ) : null}
            </View>
          </Card>
        );
      })}
    </Screen>
  );
}

function EmployeeHome() {
  const router = useRouter();
  const q = useEmployeeHome();
  const d = q.data;
  const att = d?.attendance;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero subtitle={d ? dateLong(d.today) : undefined} />}>
      <QuickLinks />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {d ? (
        <>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <StatTile icon="log-in" tint={colors.success} label="Masuk" value={timeHM(att?.check_in)} />
            <StatTile icon="log-out" tint={colors.info} label="Pulang" value={timeHM(att?.check_out)} />
          </View>

          {d.pending_approvals > 0 ? (
            <Card onPress={() => router.push('/persetujuan')} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, borderColor: colors.gold, borderWidth: 1.5 }}>
              <View style={{ width: 42, height: 42, borderRadius: 14, backgroundColor: colors.goldSoft, alignItems: 'center', justifyContent: 'center' }}>
                <Ionicons name="checkmark-done-circle" size={24} color={colors.gold} />
              </View>
              <View style={{ flex: 1 }}>
                <Text style={T.title}>{d.pending_approvals} persetujuan menunggu</Text>
                <Text style={T.small}>Ketuk untuk meninjau dan memutuskan.</Text>
              </View>
              <Ionicons name="chevron-forward" size={20} color={colors.muted} />
            </Card>
          ) : null}

          <SectionTitle title="Jadwal mengajar hari ini" action="Jurnal" onAction={() => router.push('/jurnal')} />
          {d.schedule.length === 0 ? (
            <Card><EmptyState icon="cafe-outline" title="Tidak ada jadwal hari ini" /></Card>
          ) : d.schedule.map((s, i) => (
            <Card key={i}>
              <Row
                left={<Text style={T.title}>{s.subject}</Text>}
                sub={<Text style={T.small}>{s.class}{s.room ? ` · ${s.room}` : ''}</Text>}
                right={<View style={{ alignItems: 'flex-end', gap: 4 }}><Pill label={`${timeHM(s.start_time)}–${timeHM(s.end_time)}`} /><Pill label={s.has_journal ? 'Jurnal terisi' : 'Belum dijurnal'} color={s.has_journal ? colors.success : colors.warning} /></View>}
              />
            </Card>
          ))}
          <Card style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: colors.goldSoft }}>
            <Ionicons name="information-circle" size={20} color={colors.gold} />
            <Text style={[T.body, { flex: 1 }]}>Catat kehadiran Anda di Layanan → Presensi.</Text>
          </Card>
        </>
      ) : null}
    </Screen>
  );
}
