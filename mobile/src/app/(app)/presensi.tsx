import React, { useState } from 'react';
import { Alert, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import dayjs from 'dayjs';
import { Button, Card, EmptyState, Loading, Pill, Row, Screen, SectionTitle, StatTile, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { useCheck, useEmployeeAttendance, useEmployeeHome } from '@/api/hooks';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort, monthLabel, shiftMonth, timeHM } from '@/lib/format';

export default function Presensi() {
  const [month, setMonth] = useState(dayjs().format('YYYY-MM'));
  const home = useEmployeeHome();
  const list = useEmployeeAttendance(month);
  const check = useCheck();
  const today = home.data?.attendance;
  const next: 'check-in' | 'check-out' | null = !today?.check_in ? 'check-in' : !today.check_out ? 'check-out' : null;

  const run = () => {
    if (!next) return;
    check.mutate(next, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) });
  };

  return (
    <Screen refreshing={list.isRefetching} onRefresh={() => { home.refetch(); list.refetch(); }} header={<Hero back subtitle="Catat kehadiran harian Anda" />}>
      <Card style={{ alignItems: 'center', gap: 12, paddingVertical: 22 }}>
        <View style={{ width: 84, height: 84, borderRadius: 42, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center' }}>
          <Ionicons name={next === 'check-out' ? 'log-out' : next ? 'log-in' : 'checkmark-done'} size={40} color={colors.primary} />
        </View>
        <Text style={{ fontSize: 15, color: colors.inkSoft }}>
          {next === 'check-in' ? 'Anda belum check-in hari ini' : next === 'check-out' ? `Masuk pukul ${timeHM(today?.check_in)}` : 'Kehadiran hari ini lengkap'}
        </Text>
        {next ? (
          <View style={{ alignSelf: 'stretch' }}>
            <Button label={next === 'check-in' ? 'Check-in sekarang' : 'Check-out sekarang'} icon={next === 'check-in' ? 'log-in-outline' : 'log-out-outline'} variant={next === 'check-in' ? 'primary' : 'gold'} onPress={run} loading={check.isPending} />
          </View>
        ) : <Pill label={`${timeHM(today?.check_in)} – ${timeHM(today?.check_out)}`} color={colors.success} />}
      </Card>

      <Row
        left={<SectionTitle title={monthLabel(month)} />}
        right={
          <View style={{ flexDirection: 'row', gap: 14 }}>
            <Ionicons name="chevron-back-circle" size={30} color={colors.primary} onPress={() => setMonth(shiftMonth(month, -1))} />
            <Ionicons name="chevron-forward-circle" size={30} color={colors.primary} onPress={() => setMonth(shiftMonth(month, 1))} />
          </View>
        }
      />
      <View style={{ flexDirection: 'row', gap: 10 }}>
        {Object.entries(list.data?.summary ?? {}).slice(0, 3).map(([k, v]) => <StatTile key={k} icon="calendar" label={k} value={String(v)} />)}
      </View>
      {list.isLoading ? <Loading /> : (list.data?.records ?? []).length === 0 ? <EmptyState icon="calendar-outline" title="Belum ada catatan bulan ini" /> : (
        <Card style={{ padding: 0, gap: 0 }}>
          {list.data!.records.map((r, i) => (
            <View key={i} style={{ padding: 14, borderTopWidth: i ? 1 : 0, borderColor: colors.line }}>
              <Row left={<Text style={T.title}>{dateShort(r.date)}</Text>} sub={<Text style={T.small}>{timeHM(r.check_in)} – {timeHM(r.check_out)}</Text>} right={<Pill label={r.status ?? '-'} color={r.code === 'H' ? colors.success : colors.warning} />} />
            </View>
          ))}
        </Card>
      )}
    </Screen>
  );
}
