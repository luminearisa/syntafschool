import React, { useState } from 'react';
import { Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { Kv, Sheet, TextArea } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { useChildren } from '@/api/hooks';
import { ChildPicker } from '@/components/ChildPicker';
import { useStudentAssignments, useSubmitAssignment, type AssignmentItem } from '@/api/student';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type Tab = 'todo' | 'done' | 'graded';
type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const ST: Record<string, { label: string; color: string }> = {
  pending: { label: 'Belum dikumpulkan', color: colors.warning }, submitted: { label: 'Dikumpulkan', color: colors.info }, late: { label: 'Terlambat', color: colors.danger },
  graded: { label: 'Dinilai', color: colors.success }, returned: { label: 'Dikembalikan', color: colors.warning },
};

/** Siswa: kerjakan & kumpulkan. Wali: hanya memantau tugas anak (tanpa tombol kumpul). */
export default function Tugas() {
  const role = useAuth((s) => s.active);
  const { children, child } = useChildren();
  const q = useStudentAssignments(child?.id);
  const [tab, setTab] = useState<Tab>('todo');
  const [open, setOpen] = useState<AssignmentItem | null>(null);
  const items = q.data ?? [];

  const filtered = items.filter((i) => tab === 'todo' ? ['pending', 'returned'].includes(i.status) : tab === 'done' ? ['submitted', 'late'].includes(i.status) : i.status === 'graded');
  const count = (f: (i: AssignmentItem) => boolean) => items.filter(f).length;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle={role === 'guardian' ? 'Tugas anak' : 'Tugas & pengumpulan'} />}>
      {role === 'guardian' ? <ChildPicker children={children} current={child} /> : null}
      <Segmented<Tab> value={tab} onChange={setTab} options={[
        { key: 'todo', label: `Belum (${count((i) => ['pending', 'returned'].includes(i.status))})` },
        { key: 'done', label: `Dikumpulkan (${count((i) => ['submitted', 'late'].includes(i.status))})` },
        { key: 'graded', label: `Dinilai (${count((i) => i.status === 'graded')})` },
      ]} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {!q.isLoading && filtered.length === 0 ? <EmptyState icon="checkmark-done-outline" title={tab === 'todo' ? 'Tidak ada tugas tertunda' : 'Belum ada'} /> : null}
      {filtered.map((it) => {
        const a = it.assignment as R;
        const st = ST[it.status] ?? ST.pending!;
        const overdue = a.is_overdue && it.status === 'pending';
        return (
          <Card key={a.id} onPress={() => setOpen(it)}>
            <Row left={<Text style={T.title} numberOfLines={2}>{a.title}</Text>} sub={<Text style={T.small}>{a.subject?.name} · {a.teacher?.full_name ?? ''}</Text>} right={<Pill label={overdue ? 'Lewat tenggat' : st.label} color={overdue ? colors.danger : st.color} />} />
            <Text style={T.small}>Tenggat {dateShort(a.due_date)}{it.submission?.score !== null && it.submission?.score !== undefined ? ` · nilai ${it.submission.score}/${a.max_score}` : ''}</Text>
          </Card>
        );
      })}
      {open ? <Detail item={open} canSubmit={role === 'student'} onClose={() => setOpen(null)} /> : null}
    </Screen>
  );
}

function Detail({ item, canSubmit, onClose }: { item: AssignmentItem; canSubmit: boolean; onClose: () => void }) {
  const a = item.assignment as R;
  const sub = item.submission as R | null;
  const send = useSubmitAssignment();
  const [text, setText] = useState(sub?.content ?? '');
  const closed = a.status !== 'published';
  const locked = item.status === 'graded' || closed || (a.is_overdue && !a.allow_late_submission && item.status === 'pending');

  const go = () => {
    if (text.trim().length < 1) return Alert.alert('Jawaban kosong', 'Tuliskan jawaban atau catatan pengumpulan.');
    send.mutate({ assignmentId: a.id, content: text.trim() }, {
      onSuccess: () => { Alert.alert('Terkumpul', 'Tugas berhasil dikumpulkan.'); onClose(); },
      onError: (e) => Alert.alert('Tidak dapat mengumpulkan', errorMessage(e)),
    });
  };

  return (
    <Sheet visible title={a.title} onClose={onClose} footer={canSubmit && !locked ? <Button label={sub ? 'Kirim ulang' : 'Kumpulkan'} icon="send-outline" onPress={go} loading={send.isPending} /> : undefined}>
      <Card>
        <Kv k="Mata pelajaran" v={a.subject?.name} />
        <Kv k="Guru" v={a.teacher?.full_name} />
        <Kv k="Jenis" v={a.type} />
        <Kv k="Tenggat" v={dateShort(a.due_date)} />
        <Kv k="Nilai maks" v={a.max_score} />
        <Text style={T.body}>{a.description}</Text>
        {a.instructions ? <Text style={[T.body, { fontStyle: 'italic' }]}>{a.instructions}</Text> : null}
      </Card>
      {sub ? (
        <Card>
          <Row left={<Text style={T.title}>Pengumpulan Anda</Text>} right={<Pill label={ST[item.status]?.label ?? item.status} color={ST[item.status]?.color} />} />
          <Text style={T.small}>{sub.submitted_at ? dayjs(sub.submitted_at).format('D MMM YYYY HH:mm') : ''}</Text>
          {sub.score !== null && sub.score !== undefined ? <Text style={{ fontSize: 22, fontWeight: '800', color: colors.primary }}>{sub.score} / {a.max_score}</Text> : null}
          {sub.feedback ? <Text style={T.body}>Umpan balik: {sub.feedback}</Text> : null}
        </Card>
      ) : null}
      {canSubmit && !locked ? <TextArea label="Jawaban / catatan pengumpulan" value={text} onChangeText={setText} style={{ minHeight: 160 }} hint="Lampiran berkas belum tersedia di aplikasi — tuliskan jawaban atau tautan berkas." /> : null}
      {canSubmit && locked && item.status !== 'graded' ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>{closed ? 'Tugas ini sudah ditutup.' : 'Batas pengumpulan sudah lewat.'}</Text></Card> : null}
    </Sheet>
  );
}
