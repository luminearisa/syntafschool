import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { ChildHeader, ChildPicker } from '@/components/ChildPicker';
import { Hero } from '@/components/Hero';
import {
  useChildAttendance, useChildReportCards, useChildSchedule, useChildScores, useChildren,
} from '@/api/hooks';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { DAYS, dateShort, monthLabel, shiftMonth, timeHM } from '@/lib/format';
import dayjs from 'dayjs';

type Tab = 'attendance' | 'scores' | 'schedule' | 'reports';
const statusColor = (code: string) => ({ H: colors.success, S: colors.info, I: colors.warning, A: colors.danger, T: colors.warning } as Record<string, string>)[code] ?? colors.muted;

export default function Akademik() {
  const { children, child, isLoading, error, refetch, isRefetching } = useChildren();
  const [tab, setTab] = useState<Tab>('attendance');

  return (
    <Screen refreshing={isRefetching} onRefresh={() => refetch()} header={<Hero back subtitle="Absensi, nilai, jadwal & rapor" />}>
      {isLoading ? <Loading /> : error ? <ErrorNote message={errorMessage(error)} onRetry={() => refetch()} /> : null}
      {!isLoading && !child ? <EmptyState icon="school-outline" title="Belum ada data siswa" /> : null}
      {child ? (
        <>
          <ChildPicker children={children} current={child} />
          <ChildHeader child={child} />
          <Segmented<Tab>
            value={tab}
            onChange={setTab}
            options={[{ key: 'attendance', label: 'Absensi' }, { key: 'scores', label: 'Nilai' }, { key: 'schedule', label: 'Jadwal' }, { key: 'reports', label: 'Rapor' }]}
          />
          {tab === 'attendance' ? <AttendanceTab id={child.id} /> : null}
          {tab === 'scores' ? <ScoresTab id={child.id} /> : null}
          {tab === 'schedule' ? <ScheduleTab id={child.id} /> : null}
          {tab === 'reports' ? <ReportsTab id={child.id} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

function AttendanceTab({ id }: { id: number }) {
  const [month, setMonth] = useState(dayjs().format('YYYY-MM'));
  const q = useChildAttendance(id, month);
  const sum = q.data?.summary ?? {};
  return (
    <>
      <Row
        left={<Text style={T.title}>{monthLabel(month)}</Text>}
        right={
          <View style={{ flexDirection: 'row', gap: 14 }}>
            <Ionicons name="chevron-back-circle" size={30} color={colors.primary} onPress={() => setMonth(shiftMonth(month, -1))} />
            <Ionicons name="chevron-forward-circle" size={30} color={colors.primary} onPress={() => setMonth(shiftMonth(month, 1))} />
          </View>
        }
      />
      <View style={{ flexDirection: 'row', gap: 8 }}>
        {(['H', 'S', 'I', 'A'] as const).map((c) => (
          <Card key={c} style={{ flex: 1, alignItems: 'center', padding: 12 }}>
            <Text style={{ fontSize: 22, fontWeight: '800', color: statusColor(c) }}>{sum[c] ?? 0}</Text>
            <Text style={T.small}>{{ H: 'Hadir', S: 'Sakit', I: 'Izin', A: 'Alpa' }[c]}</Text>
          </Card>
        ))}
      </View>
      {q.isLoading ? <Loading /> : (q.data?.records ?? []).length === 0 ? <EmptyState icon="calendar-outline" title="Belum ada catatan" /> : (
        <Card style={{ gap: 0, padding: 0 }}>
          {q.data!.records.map((r, i) => (
            <View key={i} style={{ padding: 14, borderTopWidth: i ? 1 : 0, borderColor: colors.line }}>
              <Row
                left={<Text style={T.title}>{dateShort(r.date)}</Text>}
                sub={<Text style={T.small}>{r.subject ?? 'Harian'}{r.time_in ? ` · masuk ${timeHM(r.time_in)}` : ''}</Text>}
                right={<Pill label={r.status} color={statusColor(r.code)} />}
              />
            </View>
          ))}
        </Card>
      )}
    </>
  );
}

function ScoresTab({ id }: { id: number }) {
  const q = useChildScores(id);
  if (q.isLoading) return <Loading />;
  if (!q.data || q.data.scores.length === 0) return <EmptyState icon="ribbon-outline" title="Belum ada nilai" hint="Nilai tampil setelah diverifikasi guru." />;
  return (
    <>
      <Card>
        <Text style={T.title}>Rata-rata per mata pelajaran</Text>
        {q.data.subjects.map((s) => (
          <View key={s.subject} style={{ gap: 4, marginTop: 6 }}>
            <Row left={<Text style={T.body}>{s.subject}</Text>} right={<Text style={{ fontWeight: '800', color: colors.primary }}>{s.average ?? '–'}</Text>} />
            <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.primarySoft }}>
              <View style={{ height: 6, borderRadius: 3, width: `${Math.min(100, s.average ?? 0)}%`, backgroundColor: colors.primary }} />
            </View>
          </View>
        ))}
      </Card>
      <Text style={T.title}>Riwayat penilaian</Text>
      {q.data.scores.slice(0, 40).map((s, i) => (
        <Card key={i}>
          <Row left={<Text style={T.title}>{s.name}</Text>} sub={<Text style={T.small}>{s.subject} · {s.type} · {dateShort(s.date)}</Text>}
            right={<Text style={{ fontSize: 20, fontWeight: '800', color: colors.primary }}>{Number(s.score)}<Text style={T.small}>/{Number(s.max_score)}</Text></Text>} />
        </Card>
      ))}
    </>
  );
}

function ScheduleTab({ id }: { id: number }) {
  const q = useChildSchedule(id);
  if (q.isLoading) return <Loading />;
  const rows = q.data ?? [];
  if (rows.length === 0) return <EmptyState icon="time-outline" title="Jadwal belum tersedia" />;
  const days = [...new Set(rows.map((r) => r.day_id))];
  return (
    <>
      {days.map((d) => (
        <Card key={d}>
          <Text style={[T.title, { color: colors.primary }]}>{DAYS[d]}</Text>
          {rows.filter((r) => r.day_id === d).map((r, i) => (
            <Row key={i} left={<Text style={T.body}>{r.subject}</Text>} sub={<Text style={T.small}>{r.teacher ?? '-'}{r.room ? ` · ${r.room}` : ''}</Text>} right={<Pill label={`${timeHM(r.start_time)}–${timeHM(r.end_time)}`} />} />
          ))}
        </Card>
      ))}
    </>
  );
}

function ReportsTab({ id }: { id: number }) {
  const q = useChildReportCards(id);
  if (q.isLoading) return <Loading />;
  if (!q.data || q.data.length === 0) return <EmptyState icon="document-text-outline" title="Belum ada rapor terbit" />;
  return (
    <>
      {q.data.map((r) => (
        <Card key={r.id}>
          <Row left={<Text style={T.title}>Rapor {r.semester} · {r.academic_year}</Text>} sub={<Text style={T.small}>Rata-rata {r.average_score ?? '–'}{r.rank ? ` · Peringkat ${r.rank}` : ''}</Text>} right={<Pill label="Terbit" color={colors.success} />} />
          {r.items.map((it, i) => (
            <Row key={i} left={<Text style={T.body}>{it.subject}</Text>} right={<Text style={{ fontWeight: '800', color: colors.ink }}>{it.score ? Number(it.score) : '–'} {it.grade ?? ''}</Text>} />
          ))}
          {r.homeroom_note ? <Text style={[T.body, { fontStyle: 'italic', marginTop: 6 }]}>“{r.homeroom_note}”</Text> : null}
        </Card>
      ))}
    </>
  );
}
