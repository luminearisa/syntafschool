import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, StatTile, T } from '@/components/ui';
import { Chips } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { useTahfizParent } from '@/api/family';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

export default function Tahfiz() {
  const base = useTahfizParent(null);
  const kids: R[] = base.data?.available_children ?? [];
  const [pick, setPick] = useState<number | null>(null);
  const participantId = pick ?? kids[0]?.id ?? null;
  const q = useTahfizParent(participantId);
  const child: R | undefined = q.data?.child;
  const p = child?.progress;
  const target = child?.target;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => { base.refetch(); q.refetch(); }} header={<Hero back subtitle="Hafalan & capaian Al-Qur'an" />}>
      {base.isLoading ? <Loading /> : base.error ? <ErrorNote message={errorMessage(base.error)} onRetry={() => base.refetch()} /> : null}
      {!base.isLoading && kids.length === 0 ? <EmptyState icon="moon-outline" title="Belum ada data Tahfiz" hint="Anak Anda belum terdaftar sebagai peserta Tahfiz." /> : null}
      {kids.length > 1 ? <Chips label="Peserta" value={participantId} options={kids.map((k) => ({ key: k.id as number, label: k.name as string }))} onChange={setPick} /> : null}
      {q.isLoading && participantId ? <Loading /> : null}
      {q.data?.error ? <ErrorNote message={q.data.error} /> : null}

      {child ? (
        <>
          <Card>
            <Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{child.participant?.name}</Text>
            <View style={{ gap: 4 }}>
              <Row left={<Text style={T.body}>Hafalan</Text>} right={<Text style={{ fontWeight: '800', color: colors.primary }}>{Number(p?.memorized_percent ?? 0).toFixed(1)}%</Text>} />
              <Bar value={Number(p?.memorized_percent ?? 0)} color={colors.primary} />
              <Row left={<Text style={T.body}>Mutqin (kuat)</Text>} right={<Text style={{ fontWeight: '800', color: colors.gold }}>{Number(p?.mutqin_percent ?? 0).toFixed(1)}%</Text>} />
              <Bar value={Number(p?.mutqin_percent ?? 0)} color={colors.gold} />
            </View>
          </Card>
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <StatTile icon="book" tint={colors.primary} label="Juz selesai" value={String(p?.juz_completed ?? 0)} />
            <StatTile icon="list" tint={colors.info} label="Surah disentuh" value={String(p?.surahs_touched ?? 0)} />
          </View>
          {target?.has_target ? (
            <Card>
              <Row left={<Text style={T.title}>Target {target.period}</Text>} right={<Pill label={`${Number(target.percent ?? 0).toFixed(0)}%`} color={Number(target.percent) >= 100 ? colors.success : colors.warning} />} />
              <Bar value={Number(target.percent ?? 0)} color={Number(target.percent) >= 100 ? colors.success : colors.warning} />
              <Text style={T.small}>{target.achieved} dari {target.target_value} ({target.target_type})</Text>
            </Card>
          ) : null}
          {child.pending_review > 0 ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>{child.pending_review} setoran menunggu penilaian ustadz.</Text></Card> : null}

          <SectionTitle title="Setoran terakhir" />
          {(child.recent_setoran ?? []).length === 0 ? <EmptyState icon="reader-outline" title="Belum ada setoran" /> : null}
          {(child.recent_setoran ?? []).map((s: R) => (
            <Card key={s.id}>
              <Row
                left={<Text style={T.title}>{s.surah?.name ?? 'Surah'} · ayat {s.ayah_start}–{s.ayah_end}</Text>}
                sub={<Text style={T.small}>{dateShort(s.date)} · {String(s.type).replace('_', ' ')}</Text>}
                right={s.score !== null && s.score !== undefined ? <Text style={{ fontSize: 20, fontWeight: '800', color: colors.primary }}>{Number(s.score)}</Text> : <Pill label={s.status} />}
              />
              {s.teacher_note ? <Text style={[T.body, { fontStyle: 'italic' }]}>“{s.teacher_note}”</Text> : null}
            </Card>
          ))}

          {(child.today_sessions ?? []).length > 0 ? (
            <>
              <SectionTitle title="Sesi hari ini" />
              {child.today_sessions.map((s: R, i: number) => <Card key={i}><Text style={T.title}>{s.halaqah_name ?? s.name ?? 'Halaqah'}</Text><Text style={T.small}>{s.start_time ?? ''} {s.end_time ? `– ${s.end_time}` : ''}</Text></Card>)}
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function Bar({ value, color }: { value: number; color: string }) {
  return (
    <View style={{ height: 8, borderRadius: 4, backgroundColor: colors.primarySoft }}>
      <View style={{ height: 8, borderRadius: 4, width: `${Math.max(0, Math.min(100, value))}%`, backgroundColor: color }} />
    </View>
  );
}
