import React, { useEffect, useState } from 'react';
import { Linking, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, Segmented, T } from '@/components/ui';
import { Kv, Sheet, TextArea } from '@/components/forms';
import { Hero } from '@/components/Hero';
import {
  lmsJoinInfo, lmsRecordJoin, trackItemView, useLmsAssignment, useLmsCertificates, useLmsComplete, useLmsCourses, useLmsDashboard, useLmsGrades,
  useLmsItem, useLmsLive, useLmsPlayer, useLmsSubmit,
} from '@/api/student';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
type Tab = 'courses' | 'todo' | 'grades' | 'live' | 'certs';

export default function Kelas() {
  const [tab, setTab] = useState<Tab>('courses');
  const [course, setCourse] = useState<number | null>(null);
  const dash = useLmsDashboard();

  return (
    <Screen refreshing={dash.isRefetching} onRefresh={() => dash.refetch()} header={<Hero back subtitle="Kelas digital (LMS)" />}>
      <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'courses', label: 'Kelas saya' }, { key: 'todo', label: 'Tenggat' }, { key: 'grades', label: 'Nilai' }, { key: 'live', label: 'Siaran langsung' }, { key: 'certs', label: 'Sertifikat' }]} />
      {tab === 'courses' ? <Courses onOpen={setCourse} /> : null}
      {tab === 'todo' ? <Todo dash={dash.data} loading={dash.isLoading} error={dash.error} onOpen={setCourse} /> : null}
      {tab === 'grades' ? <Grades /> : null}
      {tab === 'live' ? <Live /> : null}
      {tab === 'certs' ? <Certs /> : null}
      {course !== null ? <Player courseId={course} onClose={() => setCourse(null)} /> : null}
    </Screen>
  );
}

function Bar({ value }: { value: number }) {
  return (
    <View style={{ height: 7, borderRadius: 4, backgroundColor: colors.primarySoft }}>
      <View style={{ height: 7, borderRadius: 4, width: `${Math.max(0, Math.min(100, value))}%`, backgroundColor: colors.primary }} />
    </View>
  );
}

function Courses({ onOpen }: { onOpen: (id: number) => void }) {
  const q = useLmsCourses();
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  if (!q.data?.courses.length) return <EmptyState icon="laptop-outline" title="Belum ada kelas" hint="Anda belum terdaftar pada kelas digital mana pun." />;
  return (
    <>
      <Card style={{ flexDirection: 'row', justifyContent: 'space-around' }}>
        <Stat label="Kelas" value={q.data.summary.total} />
        <Stat label="Selesai" value={q.data.summary.completed} />
        <Stat label="Rata-rata" value={`${Math.round(q.data.summary.average_progress ?? 0)}%`} />
      </Card>
      {q.data.courses.map((c) => (
        <Card key={c.enrollment_id} onPress={() => onOpen(c.course.id)}>
          <Row left={<Text style={T.title} numberOfLines={2}>{c.course.name}</Text>} sub={<Text style={T.small}>{c.course.code}</Text>} right={<Pill label={c.status === 'COMPLETED' ? 'Selesai' : 'Aktif'} color={c.status === 'COMPLETED' ? colors.success : colors.info} />} />
          <Bar value={Number(c.progress_percent)} />
          <Text style={T.small}>{c.completed_items}/{c.required_items} materi · {Math.round(Number(c.progress_percent))}%</Text>
        </Card>
      ))}
    </>
  );
}

function Stat({ label, value }: { label: string; value: string | number }) {
  return (
    <View style={{ alignItems: 'center' }}>
      <Text style={{ fontSize: 22, fontWeight: '800', color: colors.primary }}>{value}</Text>
      <Text style={T.small}>{label}</Text>
    </View>
  );
}

function Todo({ dash, loading, error, onOpen }: { dash?: R; loading: boolean; error: unknown; onOpen: (id: number) => void }) {
  if (loading) return <Loading />;
  if (error) return <ErrorNote message={errorMessage(error)} />;
  if (!dash) return null;
  const due: R[] = dash.due_soon ?? [];
  const graded: R[] = dash.recently_graded ?? [];
  const news: R[] = dash.announcements ?? [];
  return (
    <>
      <SectionTitle title="Segera berakhir" />
      {due.length === 0 ? <Card><Text style={T.body}>Tidak ada aktivitas yang mendekati tenggat.</Text></Card> : null}
      {due.map((d) => (
        <Card key={d.id} onPress={() => onOpen(d.course_id)}>
          <Row left={<Text style={T.title}>{d.title}</Text>} sub={<Text style={T.small}>{d.course_name} · {d.activity_type_label}</Text>} right={<Pill label={d.is_past_due ? 'Lewat' : d.due_at ? dayjs(d.due_at).format('D MMM HH:mm') : '-'} color={d.is_past_due ? colors.danger : colors.warning} />} />
        </Card>
      ))}
      {graded.length > 0 ? <SectionTitle title="Baru dinilai" /> : null}
      {graded.map((g, i) => (
        <Card key={i}><Row left={<Text style={T.title}>{g.title}</Text>} sub={<Text style={T.small}>{g.course_name}</Text>} right={<Text style={{ fontSize: 18, fontWeight: '800', color: g.passed === false ? colors.danger : colors.primary }}>{Math.round(Number(g.percent ?? 0))}%</Text>} /></Card>
      ))}
      {news.length > 0 ? <SectionTitle title="Pengumuman kelas" /> : null}
      {news.map((n) => (
        <Card key={n.id}><Text style={T.title}>{n.title}</Text><Text style={T.small}>{n.course_name} · {n.published_at ? dateShort(n.published_at) : ''}</Text><Text style={T.body}>{n.excerpt}</Text></Card>
      ))}
    </>
  );
}

function Grades() {
  const q = useLmsGrades();
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  if (!q.data?.courses.length) return <EmptyState icon="ribbon-outline" title="Belum ada nilai" />;
  return (
    <>
      {q.data.courses.map((c) => (
        <Card key={c.course_id}>
          <Row left={<Text style={T.title}>{c.course_title}</Text>} sub={<Text style={T.small}>{c.is_released ? (c.predicate ?? '') : 'Belum dirilis guru'}</Text>} right={<Text style={{ fontSize: 22, fontWeight: '800', color: c.passed === false ? colors.danger : colors.primary }}>{c.display?.text ?? '–'}</Text>} />
          {(c.items ?? []).slice(0, 8).map((it: R, i: number) => (
            <Row key={i} left={<Text style={T.body}>{it.name ?? it.title}</Text>} right={<Text style={{ fontWeight: '700', color: colors.ink }}>{it.final_score ?? it.score ?? '–'}</Text>} />
          ))}
          {(c.feedback ?? []).map((f: R, i: number) => <Text key={i} style={[T.small, { fontStyle: 'italic' }]}>“{f.feedback}” — {f.name}</Text>)}
        </Card>
      ))}
    </>
  );
}

function Live() {
  const q = useLmsLive();
  const [busy, setBusy] = useState<number | null>(null);
  const join = async (id: number) => {
    setBusy(id);
    try {
      const info = await lmsJoinInfo(id);
      if (!info.joinable || !info.url) return Alert.alert('Belum dapat bergabung', info.reason ?? 'Tautan belum tersedia.');
      lmsRecordJoin(id);
      await Linking.openURL(info.url);
    } catch (e) { Alert.alert('Gagal', errorMessage(e)); } finally { setBusy(null); }
  };
  if (q.isLoading) return <Loading />;
  const all: R[] = [...(q.data?.live_now ?? []), ...(q.data?.sessions ?? [])].filter((s, i, a) => a.findIndex((x) => x.id === s.id) === i);
  if (all.length === 0) return <EmptyState icon="videocam-outline" title="Tidak ada sesi langsung" />;
  return (
    <>
      {all.map((s) => (
        <Card key={s.id}>
          <Row left={<Text style={T.title}>{s.title}</Text>} sub={<Text style={T.small}>{s.start_at ? dayjs(s.start_at).format('dddd, D MMM HH:mm') : ''} · {s.duration_minutes ?? '-'} menit · {s.provider_label}</Text>} right={<Pill label={s.status_label} color={s.status === 'LIVE' ? colors.danger : colors.info} />} />
          <Button label="Gabung" icon="videocam-outline" variant={s.status === 'LIVE' ? 'primary' : 'ghost'} onPress={() => join(s.id)} loading={busy === s.id} />
        </Card>
      ))}
    </>
  );
}

function Certs() {
  const q = useLmsCertificates();
  if (q.isLoading) return <Loading />;
  if (!q.data?.certificates.length) return <EmptyState icon="medal-outline" title="Belum ada sertifikat" />;
  return (
    <>
      {q.data.certificates.map((c) => (
        <Card key={c.id}>
          <Row left={<Text style={T.title}>{c.course_name}</Text>} sub={<Text style={T.small}>{c.certificate_number} · terbit {c.issued_at ? dateShort(c.issued_at) : '-'}</Text>} right={<Pill label={c.status_label} color={c.status === 'ISSUED' ? colors.success : colors.muted} />} />
        </Card>
      ))}
    </>
  );
}

// ---------------------------------------------------------------- pemutar
function Player({ courseId, onClose }: { courseId: number; onClose: () => void }) {
  const q = useLmsPlayer(courseId);
  const [item, setItem] = useState<R | null>(null);
  const d = q.data;

  return (
    <Sheet visible title={d?.course?.name ?? 'Kelas'} onClose={onClose}>
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {d ? (
        <>
          <Card>
            <Bar value={Number(d.progress?.progress_percent ?? 0)} />
            <Text style={T.small}>{d.progress?.completed_items ?? 0}/{d.progress?.required_items ?? 0} materi wajib selesai · {(d.course?.teachers ?? []).join(', ')}</Text>
          </Card>
          {(d.announcements?.data ?? d.announcements ?? []).slice?.(0, 2).map((a: R) => <Card key={a.id} style={{ backgroundColor: colors.goldSoft }}><Text style={T.title}>{a.title}</Text></Card>)}
          {(d.structure ?? []).map((sec: R) => (
            <View key={sec.id} style={{ gap: 8 }}>
              <SectionTitle title={sec.title} />
              {sec.locked ? <Text style={T.small}>🔒 {sec.lock_reason}</Text> : null}
              {(sec.items ?? []).map((it: R) => (
                <Card key={it.id} onPress={it.locked || sec.locked ? () => Alert.alert('Terkunci', it.lock_reason ?? sec.lock_reason ?? 'Materi belum dibuka.') : () => setItem(it)}>
                  <Row
                    left={<Text style={[T.title, it.locked && { color: colors.muted }]}>{it.title}</Text>}
                    sub={<Text style={T.small}>{it.activity_type ?? it.resource_type ?? it.item_type}{it.estimated_minutes ? ` · ${it.estimated_minutes} mnt` : ''}{it.is_required ? ' · wajib' : ''}</Text>}
                    right={it.completed ? <Ionicons name="checkmark-circle" size={22} color={colors.success} /> : it.locked ? <Ionicons name="lock-closed" size={18} color={colors.muted} /> : <Ionicons name="chevron-forward" size={18} color={colors.muted} />}
                  />
                </Card>
              ))}
            </View>
          ))}
        </>
      ) : null}
      {item ? <ItemSheet courseId={courseId} summary={item} onClose={() => setItem(null)} /> : null}
    </Sheet>
  );
}

function ItemSheet({ courseId, summary, onClose }: { courseId: number; summary: R; onClose: () => void }) {
  const q = useLmsItem(courseId, summary.id);
  const complete = useLmsComplete();
  const d = q.data;
  const it: R = d?.item ?? summary;

  useEffect(() => { trackItemView(courseId, summary.id); }, [courseId, summary.id]);

  const toggle = () => complete.mutate({ courseId, itemId: summary.id, done: !d?.completed }, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) });
  const url: string | undefined = it.resource?.external_url;

  return (
    <Sheet
      visible
      title={summary.title}
      onClose={onClose}
      footer={d?.completion?.can_mark_complete ? <Button label={d.completed ? 'Tandai belum selesai' : 'Tandai selesai'} icon={d.completed ? 'refresh-outline' : 'checkmark-circle-outline'} variant={d.completed ? 'ghost' : 'primary'} onPress={toggle} loading={complete.isPending} /> : undefined}
    >
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      <Card>
        <Kv k="Jenis" v={it.activity_type_label ?? it.resource_type_label} />
        <Kv k="Status" v={d?.completed ? 'Selesai' : 'Belum selesai'} />
        {it.description ? <Text style={T.body}>{it.description}</Text> : null}
      </Card>
      {url ? <Button label="Buka materi" icon="open-outline" onPress={() => Linking.openURL(url).catch(() => Alert.alert('Tidak dapat membuka tautan', url))} /> : null}
      {(it.chapters ?? []).map((c: R) => <Card key={c.id}><Text style={T.title}>{c.title}</Text>{c.content ? <Text style={T.body}>{c.content}</Text> : null}</Card>)}
      {summary.activity_id ? <ActivityBlock activityId={summary.activity_id} type={summary.activity_type} /> : null}
      {it.resource?.file_name && !url ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Berkas “{it.resource.file_name}” dapat diunduh dari versi web.</Text></Card> : null}
    </Sheet>
  );
}

function ActivityBlock({ activityId, type }: { activityId: number; type?: string }) {
  if (type === 'ASSIGNMENT') return <AssignmentBlock activityId={activityId} />;
  return (
    <Card style={{ backgroundColor: colors.goldSoft }}>
      <Text style={T.body}>{type === 'QUIZ' ? 'Kuis dikerjakan di versi web / pada modul Ujian (segera hadir di aplikasi).' : 'Aktivitas ini dibuka dari versi web.'}</Text>
    </Card>
  );
}

function AssignmentBlock({ activityId }: { activityId: number }) {
  const q = useLmsAssignment(activityId);
  const send = useLmsSubmit();
  const [text, setText] = useState('');
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  const d = q.data as R;
  if (d.locked) return <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>{d.state?.reason_label ?? 'Tugas terkunci.'}</Text></Card>;
  const sub = d.submission as R | null;

  const go = () => {
    if (!text.trim()) return Alert.alert('Jawaban kosong', 'Tuliskan jawaban Anda.');
    send.mutate({ activityId, text: text.trim() }, { onSuccess: () => { setText(''); Alert.alert('Terkirim', 'Tugas berhasil dikirim.'); }, onError: (e) => Alert.alert('Gagal', errorMessage(e)) });
  };

  return (
    <>
      <Card>
        <Row left={<Text style={T.title}>Tugas</Text>} right={<Pill label={d.status_label ?? d.status} color={d.status === 'GRADED' ? colors.success : colors.info} />} />
        <Kv k="Sisa percobaan" v={d.attempts_remaining} />
        <Kv k="Nilai maks" v={d.grades?.maximum} />
        {sub?.grade !== null && sub?.grade !== undefined ? <Text style={{ fontSize: 20, fontWeight: '800', color: colors.primary }}>Nilai {sub.grade}</Text> : null}
        {sub?.feedback ? <Text style={T.body}>Umpan balik: {sub.feedback}</Text> : null}
      </Card>
      {d.can_submit ? (
        <>
          <TextArea label="Jawaban teks" value={text} onChangeText={setText} style={{ minHeight: 140 }} hint="Unggah berkas tersedia di versi web." />
          <Button label="Kirim tugas" icon="send-outline" onPress={go} loading={send.isPending} />
        </>
      ) : null}
    </>
  );
}
