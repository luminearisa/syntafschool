import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { useQuery } from '@tanstack/react-query';
import { Card, EmptyState, ErrorNote, Loading, Row, Screen, SectionTitle, Segmented, StatTile, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { get, errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { rupiah } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
const MONTH = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
const monthName = (period: string) => MONTH[Number(period.slice(5, 7)) - 1] ?? period;

/** Ringkasan manajemen. Setiap bagian muncul hanya bila server mengizinkan peran aktif membacanya. */
export default function Dasbor() {
  const [days, setDays] = useState<'7' | '14' | '30'>('14');
  const q = useQuery({ queryKey: ['dashboard', days], queryFn: () => get<R>('/dashboard/overview', { days }) });
  const d = q.data;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Ringkasan sekolah" />}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {d && !d.students && !d.academic && !d.finance ? <EmptyState icon="stats-chart-outline" title="Tidak ada data untuk ditampilkan" hint="Peran Anda belum memiliki izin membaca ringkasan." /> : null}

      {d?.students || d?.academic ? (
        <View style={{ flexDirection: 'row', gap: 10 }}>
          {d.students ? <StatTile icon="people" tint={colors.primary} label="Siswa aktif" value={String(d.students.active_total)} hint={`+${d.students.admitted_this_month} bulan ini`} /> : null}
          {d.academic ? <StatTile icon="school" tint={colors.info} label="Kelas aktif" value={String(d.academic.active_classes)} hint={d.academic.academic_year} /> : null}
        </View>
      ) : null}

      {d?.attendance ? (
        <>
          <SectionTitle title="Kehadiran siswa" />
          <Segmented value={days} onChange={setDays} options={[{ key: '7', label: '7 hari' }, { key: '14', label: '14 hari' }, { key: '30', label: '30 hari' }]} />
          <Card>
            <Row left={<Text style={T.body}>Hari ini</Text>} right={<Text style={{ fontWeight: '800', color: colors.primary }}>{d.attendance.today.rate ?? '–'}%</Text>} />
            <Row left={<Text style={T.body}>Rata-rata {days} hari</Text>} right={<Text style={{ fontWeight: '800', color: colors.primary }}>{d.attendance.summary.rate ?? '–'}%</Text>} />
            <Bars items={(d.attendance.daily as R[]).map((x) => ({ label: x.date.slice(8), value: Number(x.rate ?? 0) }))} max={100} color={colors.primary} />
          </Card>
          <Card>
            {(d.attendance.summary.statuses as R[]).filter((s) => s.total > 0).map((s) => <Row key={s.code} left={<Text style={T.body}>{s.name}</Text>} right={<Text style={{ fontWeight: '800', color: colors.ink }}>{s.total}</Text>} />)}
          </Card>
        </>
      ) : null}

      {d?.finance ? (
        <>
          <SectionTitle title="Keuangan" />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            {d.finance.received_this_month !== null ? <StatTile icon="cash" tint={colors.success} label="Diterima bulan ini" value={rupiah(d.finance.received_this_month)} /> : null}
            {d.finance.billed_this_month !== null ? <StatTile icon="receipt" tint={colors.gold} label="Ditagihkan bulan ini" value={rupiah(d.finance.billed_this_month)} /> : null}
          </View>
          <Card>
            <Text style={T.title}>Penerimaan 6 bulan</Text>
            <Bars items={(d.finance.series as R[]).map((x) => ({ label: monthName(x.period), value: Number(x.received ?? 0) }))} color={colors.success} />
          </Card>
        </>
      ) : null}
    </Screen>
  );
}

function Bars({ items, max, color }: { items: { label: string; value: number }[]; max?: number; color: string }) {
  const top = max ?? Math.max(1, ...items.map((i) => i.value));
  return (
    <View style={{ flexDirection: 'row', alignItems: 'flex-end', gap: 4, height: 110, marginTop: 8 }}>
      {items.map((i, n) => (
        <View key={n} style={{ flex: 1, alignItems: 'center', gap: 4, justifyContent: 'flex-end', height: '100%' }}>
          <View style={{ width: '100%', borderRadius: 4, backgroundColor: color, height: `${Math.max(3, (i.value / top) * 80)}%`, opacity: 0.9 }} />
          <Text style={{ fontSize: 9, color: colors.muted }}>{i.label}</Text>
        </View>
      ))}
    </View>
  );
}
