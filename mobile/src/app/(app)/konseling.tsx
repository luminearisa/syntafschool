import React, { useState } from 'react';
import { Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import { Chips, Sheet, TextArea } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { useChildren } from '@/api/hooks';
import { useBkAppointments, useBkCancelRequest, useBkConsents, useBkDecide, useBkRequest, useBkRequests, useBkSummary } from '@/api/family';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';
import { dateShort } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
type Tab = 'requests' | 'appointments' | 'consents';

const REQ_STATUS: Record<string, { label: string; color: string }> = {
  QUEUED: { label: 'Dalam antrean', color: colors.warning }, IN_REVIEW: { label: 'Ditinjau', color: colors.info }, ACCEPTED: { label: 'Diterima', color: colors.success },
  DECLINED: { label: 'Belum dapat diproses', color: colors.muted }, CANCELLED: { label: 'Dibatalkan', color: colors.muted },
};
const SCOPE: Record<string, string> = { COUNSELING: 'Layanan konseling', SHARING: 'Berbagi informasi', REFERRAL: 'Rujukan', ASSESSMENT: 'Asesmen' };

/**
 * Hanya kolom yang perlu bagi keluarga yang ditampilkan. API portal BK
 * mengembalikan model utuh (termasuk case_id/decline_reason internal) —
 * layar ini sengaja tidak menampilkannya.
 */
export default function Konseling() {
  const role = useAuth((s) => s.active);
  const summary = useBkSummary();
  const [tab, setTab] = useState<Tab>('requests');
  const pending = summary.data?.pending_consents ?? 0;

  return (
    <Screen refreshing={summary.isRefetching} onRefresh={() => summary.refetch()} header={<Hero back subtitle="Bimbingan & konseling" />}>
      <Card style={{ backgroundColor: colors.primarySoft }}>
        <Text style={T.body}>Layanan BK bersifat rahasia. Informasi hanya dibagikan atas persetujuan Anda dan sesuai kebijakan sekolah.</Text>
      </Card>
      <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'requests', label: 'Permintaan' }, { key: 'appointments', label: 'Janji temu' }, { key: 'consents', label: `Persetujuan${pending ? ` (${pending})` : ''}` }]} />
      {tab === 'requests' ? <Requests guardian={role === 'guardian'} /> : null}
      {tab === 'appointments' ? <Appointments /> : null}
      {tab === 'consents' ? <Consents canDecide={role === 'guardian'} /> : null}
    </Screen>
  );
}

function Requests({ guardian }: { guardian: boolean }) {
  const q = useBkRequests();
  const cancel = useBkCancelRequest();
  const me = useAuth((s) => s.user?.id);
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button label={guardian ? 'Ajukan konsultasi untuk anak' : 'Ajukan konsultasi'} icon="add-circle-outline" onPress={() => setOpen(true)} />
      {q.isLoading ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {q.data?.length === 0 ? <EmptyState icon="chatbubbles-outline" title="Belum ada permintaan" /> : null}
      {q.data?.map((r: R) => {
        const st = REQ_STATUS[r.status] ?? { label: r.status, color: colors.muted };
        return (
          <Card key={r.id}>
            <Row left={<Text style={T.title}>{r.topic}</Text>} sub={<Text style={T.small}>{r.request_number} · {dateShort(r.created_at)}{r.student?.full_name ? ` · ${r.student.full_name}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            {r.urgent ? <Pill label="Mendesak" color={colors.danger} /> : null}
            {Number(r.requested_by) === Number(me) && ['QUEUED', 'IN_REVIEW'].includes(r.status) ? (
              <Button label="Batalkan" variant="ghost" onPress={() => Alert.alert('Batalkan permintaan?', r.topic, [{ text: 'Tidak', style: 'cancel' }, { text: 'Batalkan', style: 'destructive', onPress: () => cancel.mutate(r.id, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) }])} disabled={cancel.isPending} />
            ) : null}
          </Card>
        );
      })}
      {open ? <RequestSheet guardian={guardian} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function RequestSheet({ guardian, onClose }: { guardian: boolean; onClose: () => void }) {
  const { children, child } = useChildren();
  const send = useBkRequest();
  const [studentId, setStudentId] = useState<number | null>(null);
  const [topic, setTopic] = useState('');
  const [message, setMessage] = useState('');
  const [slot, setSlot] = useState('');
  const [urgent, setUrgent] = useState(false);
  const sid = studentId ?? child?.id;

  const submit = () => {
    if (topic.trim().length < 3) return Alert.alert('Topik wajib', 'Tuliskan topik singkat (bukan detail sensitif).');
    if (guardian && !sid) return Alert.alert('Pilih anak', 'Pilih anak yang dimaksud.');
    send.mutate({ studentId: guardian ? sid : undefined, topic: topic.trim(), message: message.trim() || undefined, slot: slot.trim() || undefined, urgent }, {
      onSuccess: () => { Alert.alert('Terkirim', 'Permintaan masuk ke antrean BK. Anda akan dihubungi.'); onClose(); },
      onError: (e) => Alert.alert('Gagal', errorMessage(e)),
    });
  };

  return (
    <Sheet visible title="Ajukan konsultasi" onClose={onClose} footer={<Button label="Kirim permintaan" icon="send-outline" onPress={submit} loading={send.isPending} />}>
      {guardian && children.length > 1 ? <Chips label="Anak" value={sid ?? null} options={children.map((c) => ({ key: c.id, label: c.nick_name || c.full_name }))} onChange={setStudentId} /> : null}
      <TextArea label="Topik *" value={topic} onChangeText={setTopic} multiline={false} placeholder="mis. Kesulitan belajar, adaptasi asrama" hint="Cukup topik umum; detail dibahas langsung dengan konselor." />
      <TextArea label="Pesan (opsional)" value={message} onChangeText={setMessage} />
      <TextArea label="Waktu yang diinginkan" value={slot} onChangeText={setSlot} multiline={false} placeholder="mis. Senin pagi" />
      <Chips label="Urgensi" value={urgent ? 'y' : 'n'} options={[{ key: 'n', label: 'Biasa' }, { key: 'y', label: 'Mendesak' }]} onChange={(k) => setUrgent(k === 'y')} />
    </Sheet>
  );
}

function Appointments() {
  const q = useBkAppointments();
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="calendar-outline" title="Belum ada janji temu" />;
  return (
    <>
      {q.data.map((a: R) => (
        <Card key={a.id}>
          <Row left={<Text style={T.title}>{dayjs(a.scheduled_start).format('dddd, D MMM YYYY HH:mm')}</Text>} sub={<Text style={T.small}>{a.student ? `${a.student} · ` : ''}{a.mode}{a.location ? ` · ${a.location}` : ''}</Text>} right={<Pill label={String(a.status)} />} />
          {a.counselor ? <Text style={T.small}>Konselor: {a.counselor}</Text> : null}
        </Card>
      ))}
    </>
  );
}

function Consents({ canDecide }: { canDecide: boolean }) {
  const q = useBkConsents();
  const decide = useBkDecide();
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="shield-checkmark-outline" title="Tidak ada persetujuan" />;
  const go = (c: R, decision: 'APPROVED' | 'DECLINED') =>
    Alert.alert(decision === 'APPROVED' ? 'Setujui?' : 'Tolak?', SCOPE[c.scope] ?? c.scope, [{ text: 'Batal', style: 'cancel' }, { text: decision === 'APPROVED' ? 'Setujui' : 'Tolak', onPress: () => decide.mutate({ id: c.id, decision }, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) }]);
  return (
    <>
      {q.data.map((c: R) => (
        <Card key={c.id} style={c.status === 'PENDING' ? { borderColor: colors.gold, borderWidth: 1.5 } : undefined}>
          <Row left={<Text style={T.title}>{SCOPE[c.scope] ?? c.scope}</Text>} sub={<Text style={T.small}>{c.student?.full_name ?? ''} · diminta {dateShort(c.requested_at)}</Text>} right={<Pill label={c.status === 'PENDING' ? 'Perlu keputusan' : c.status === 'APPROVED' ? 'Disetujui' : c.status === 'DECLINED' ? 'Ditolak' : c.status} color={c.status === 'PENDING' ? colors.warning : c.status === 'APPROVED' ? colors.success : colors.muted} />} />
          {c.purpose ? <Text style={T.body}>{c.purpose}</Text> : null}
          {c.expires_at ? <Text style={T.small}>Berlaku hingga {dateShort(c.expires_at)}</Text> : null}
          {c.status === 'PENDING' && canDecide ? (
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <View style={{ flex: 1 }}><Button label="Setujui" onPress={() => go(c, 'APPROVED')} disabled={decide.isPending} /></View>
              <View style={{ flex: 1 }}><Button label="Tolak" variant="ghost" onPress={() => go(c, 'DECLINED')} disabled={decide.isPending} /></View>
            </View>
          ) : null}
        </Card>
      ))}
    </>
  );
}
