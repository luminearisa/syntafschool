import React, { useMemo, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Hero } from '@/components/Hero';
import { Sheet, TextArea, Kv } from '@/components/forms';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, T } from '@/components/ui';
import {
  newIdempotencyKey, useApprovalCounter, useApprovals, useCancelRequest, useComment, useDecide, useInstance, useMyRequests, useResubmit,
  type ApprovalRow, type Bucket, type Decision, type RequestRow,
} from '@/api/workflow';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort, rupiah } from '@/lib/format';
import dayjs from 'dayjs';

type Tab = 'inbox' | 'mine';

/** Kunci konteks workflow → label tampilan; kunci tak dikenal ditampilkan apa adanya. */
const CONTEXT_LABELS: Record<string, string> = {
  amount: 'Nominal', department: 'Unit', currency: 'Mata uang', cost_center_id: 'Pusat biaya', cost_center: 'Pusat biaya',
  leave_type: 'Jenis cuti', start_date: 'Mulai', end_date: 'Selesai', days: 'Lama (hari)', reason: 'Alasan', requester_name: 'Pemohon',
};

const STATUS: Record<string, { label: string; color: string }> = {
  APPROVED: { label: 'Disetujui', color: colors.success },
  REJECTED: { label: 'Ditolak', color: colors.danger },
  RETURNED: { label: 'Dikembalikan', color: colors.warning },
  CANCELLED: { label: 'Dibatalkan', color: colors.muted },
  EXPIRED: { label: 'Kedaluwarsa', color: colors.muted },
  DRAFT: { label: 'Draf', color: colors.muted },
};
const statusOf = (s: string, label?: string) => STATUS[s] ?? { label: label ?? 'Diproses', color: colors.info };

export default function Persetujuan() {
  const [tab, setTab] = useState<Tab>('inbox');
  const [bucket, setBucket] = useState<Bucket>('pending');
  const counter = useApprovalCounter();
  const approvals = useApprovals(bucket);
  const mine = useMyRequests();
  const [approval, setApproval] = useState<ApprovalRow | null>(null);
  const [request, setRequest] = useState<RequestRow | null>(null);

  const refreshing = approvals.isRefetching || mine.isRefetching;
  const refetch = () => { counter.refetch(); approvals.refetch(); mine.refetch(); };

  return (
    <Screen refreshing={refreshing} onRefresh={refetch} header={<Hero back subtitle="Persetujuan & pengajuan Anda" />}>
      <Segmented<Tab>
        value={tab}
        onChange={setTab}
        options={[
          { key: 'inbox', label: `Perlu keputusan${counter.data?.pending ? ` (${counter.data.pending})` : ''}` },
          { key: 'mine', label: `Pengajuan saya${counter.data?.my_requests_returned ? ` · ${counter.data.my_requests_returned} kembali` : ''}` },
        ]}
      />

      {tab === 'inbox' ? (
        <>
          <Segmented<Bucket>
            value={bucket}
            onChange={setBucket}
            options={[
              { key: 'pending', label: 'Semua' },
              { key: 'overdue', label: `Terlambat${counter.data?.overdue ? ` (${counter.data.overdue})` : ''}` },
              { key: 'due_soon', label: '< 24 jam' },
              { key: 'today', label: 'Hari ini' },
            ]}
          />
          {approvals.isLoading ? <Loading /> : approvals.error ? <ErrorNote message={errorMessage(approvals.error)} onRetry={() => approvals.refetch()} /> : null}
          {approvals.data?.length === 0 ? <EmptyState icon="checkmark-done-circle-outline" title="Tidak ada yang menunggu" hint="Penugasan persetujuan akan muncul di sini." /> : null}
          {approvals.data?.map((a) => <ApprovalCard key={a.id} a={a} onPress={() => setApproval(a)} />)}
        </>
      ) : (
        <>
          {mine.isLoading ? <Loading /> : mine.error ? <ErrorNote message={errorMessage(mine.error)} onRetry={() => mine.refetch()} /> : null}
          {mine.data?.length === 0 ? <EmptyState icon="paper-plane-outline" title="Belum ada pengajuan" hint="Pengajuan Anda (cuti, pengadaan, dll.) tampil di sini beserta statusnya." /> : null}
          {mine.data?.map((r) => <RequestCard key={r.id} r={r} onPress={() => setRequest(r)} />)}
        </>
      )}

      <DecisionSheet approval={approval} onClose={() => setApproval(null)} />
      <RequestSheet request={request} onClose={() => setRequest(null)} />
    </Screen>
  );
}

function ApprovalCard({ a, onPress }: { a: ApprovalRow; onPress: () => void }) {
  const overdue = a.sla_status === 'OVERDUE' || (a.remaining_minutes !== null && a.remaining_minutes !== undefined && a.remaining_minutes < 0);
  return (
    <Card onPress={onPress}>
      <Row
        left={<Text style={T.title} numberOfLines={2}>{a.title ?? a.instance_code}</Text>}
        sub={<Text style={T.small}>{a.definition_name ?? a.module} · {a.requester ?? '-'}{a.department ? ` · ${a.department}` : ''}</Text>}
        right={a.priority && a.priority !== 'NORMAL' ? <Pill label={a.priority} color={colors.danger} /> : undefined}
      />
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
        {a.step ? <Pill label={a.step} /> : null}
        {a.is_delegate ? <Pill label={`Delegasi${a.original_approver ? ` dari ${a.original_approver}` : ''}`} color={colors.info} /> : null}
        {overdue ? <Pill label="Terlambat" color={colors.danger} /> : a.due_at ? <Pill label={`Tenggat ${dayjs(a.due_at).format('D MMM HH:mm')}`} color={colors.warning} /> : null}
        {a.amount ? <Text style={{ marginLeft: 'auto', fontWeight: '800', color: colors.ink }}>{rupiah(a.amount)}</Text> : null}
      </View>
    </Card>
  );
}

function RequestCard({ r, onPress }: { r: RequestRow; onPress: () => void }) {
  const st = statusOf(r.status, r.status_label);
  return (
    <Card onPress={onPress}>
      <Row left={<Text style={T.title} numberOfLines={2}>{r.title}</Text>} sub={<Text style={T.small}>{r.code} · {r.definition_name ?? r.module}</Text>} right={<Pill label={st.label} color={st.color} />} />
      {r.progress && r.progress.total > 0 ? (
        <View style={{ gap: 4 }}>
          <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.primarySoft }}>
            <View style={{ height: 6, borderRadius: 3, width: `${r.progress.percent}%`, backgroundColor: colors.primary }} />
          </View>
          <Text style={T.small}>{r.progress.completed}/{r.progress.total} tahap selesai{r.submitted_at ? ` · diajukan ${dateShort(r.submitted_at)}` : ''}</Text>
        </View>
      ) : null}
      {r.status === 'RETURNED' ? <Text style={{ color: colors.warning, fontWeight: '700', fontSize: 12.5 }}>Dikembalikan untuk revisi — ketuk untuk mengirim ulang</Text> : null}
    </Card>
  );
}

/** Keputusan atas satu penugasan. Reject/return wajib berkomentar (aturan engine). */
function DecisionSheet({ approval, onClose }: { approval: ApprovalRow | null; onClose: () => void }) {
  const detail = useInstance(approval?.instance_id ?? null);
  const decide = useDecide();
  const [comment, setComment] = useState('');
  // Satu kunci per percobaan: ketuk ganda / ulang jaringan → engine mengembalikan keputusan yang sama.
  const keys = useRef<Record<string, string>>({});

  const keyFor = (d: Decision) => (keys.current[`${approval?.id}-${d}`] ??= newIdempotencyKey('wf'));

  const needsComment = (d: Decision) => d !== 'approve';
  const run = (d: Decision) => {
    if (!approval) return;
    if (needsComment(d) && comment.trim().length < 3) {
      Alert.alert('Komentar wajib', 'Isi alasan untuk menolak atau mengembalikan pengajuan.');
      return;
    }
    decide.mutate(
      { approvalId: approval.id, decision: d, comment: comment.trim(), key: keyFor(d) },
      {
        onSuccess: () => { setComment(''); keys.current = {}; onClose(); },
        onError: (e) => Alert.alert('Gagal', errorMessage(e)),
      },
    );
  };

  const confirmApprove = () => Alert.alert('Setujui pengajuan?', approval?.title ?? '', [{ text: 'Batal', style: 'cancel' }, { text: 'Setujui', onPress: () => run('approve') }]);

  return (
    <Sheet
      visible={!!approval}
      title="Keputusan"
      onClose={onClose}
      footer={
        <>
          <Button label="Setujui" icon="checkmark-circle-outline" onPress={confirmApprove} loading={decide.isPending && decide.variables?.decision === 'approve'} disabled={decide.isPending} />
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <View style={{ flex: 1 }}><Button label="Kembalikan" variant="ghost" onPress={() => run('return')} disabled={decide.isPending} /></View>
            <View style={{ flex: 1 }}><Button label="Tolak" variant="ghost" onPress={() => run('reject')} disabled={decide.isPending} /></View>
          </View>
        </>
      }
    >
      {approval ? (
        <>
          <Card>
            <Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{approval.title}</Text>
            <Kv k="Kode" v={approval.instance_code} />
            <Kv k="Jenis" v={approval.definition_name ?? approval.module} />
            <Kv k="Pemohon" v={approval.requester} />
            <Kv k="Unit" v={approval.department} />
            <Kv k="Nominal" v={approval.amount ? rupiah(approval.amount) : null} />
            <Kv k="Tahap" v={approval.step} />
            <Kv k="Tenggat" v={approval.due_at ? dayjs(approval.due_at).format('D MMM YYYY HH:mm') : null} />
          </Card>

          {detail.isLoading ? <Loading /> : null}
          {detail.data ? <ContextAndHistory d={detail.data} /> : null}

          <TextArea label="Komentar" value={comment} onChangeText={setComment} placeholder="Wajib untuk menolak / mengembalikan" />
        </>
      ) : null}
    </Sheet>
  );
}

function ContextAndHistory({ d }: { d: NonNullable<ReturnType<typeof useInstance>['data']> }) {
  const ctx = useMemo(() => {
    const c = d.context_summary;
    if (!c || typeof c === 'string') return c ? [['Ringkasan', c] as [string, string]] : [];
    return Object.entries(c)
      .filter(([, v]) => v !== null && v !== '' && typeof v !== 'object')
      .map(([k, v]) => [CONTEXT_LABELS[k] ?? k.replace(/_/g, ' '), k === 'amount' ? rupiah(Number(v)) : String(v)] as [string, string]);
  }, [d.context_summary]);

  return (
    <>
      {ctx.length > 0 ? (
        <Card>
          <Text style={T.title}>Rincian</Text>
          {ctx.map(([k, v]) => <Kv key={k} k={k} v={v} />)}
        </Card>
      ) : null}
      {d.decisions.length > 0 ? (
        <Card>
          <Text style={T.title}>Riwayat keputusan</Text>
          {d.decisions.map((x) => (
            <View key={x.id} style={{ gap: 2 }}>
              <Text style={{ fontWeight: '700', color: colors.ink }}>{x.actor ?? '-'} · {x.decision}</Text>
              {x.comment ? <Text style={T.body}>{x.comment}</Text> : null}
              <Text style={T.small}>{x.decided_at ? dayjs(x.decided_at).format('D MMM YYYY HH:mm') : ''}</Text>
            </View>
          ))}
        </Card>
      ) : null}
      {d.comments.length > 0 ? (
        <Card>
          <Text style={T.title}>Komentar</Text>
          {d.comments.map((c) => (
            <View key={c.id}>
              <Text style={{ fontWeight: '700', color: colors.ink }}>{c.author ?? '-'}</Text>
              <Text style={T.body}>{c.body}</Text>
            </View>
          ))}
        </Card>
      ) : null}
    </>
  );
}

/** Detail pengajuan sendiri: lihat status, komentar, batalkan, atau kirim ulang setelah dikembalikan. */
function RequestSheet({ request, onClose }: { request: RequestRow | null; onClose: () => void }) {
  const detail = useInstance(request?.id ?? null);
  const cancel = useCancelRequest();
  const resubmit = useResubmit();
  const comment = useComment();
  const [text, setText] = useState('');
  const [reason, setReason] = useState('');

  if (!request) return <Sheet visible={false} title="" onClose={onClose}><View /></Sheet>;
  const st = statusOf(request.status, request.status_label);

  const fail = (e: unknown) => Alert.alert('Gagal', errorMessage(e));
  const doCancel = () => {
    if (reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Isi alasan pembatalan.');
    cancel.mutate({ id: request.id, reason: reason.trim() }, { onSuccess: () => { setReason(''); onClose(); }, onError: fail });
  };
  const doResubmit = () => {
    if (reason.trim().length < 3) return Alert.alert('Alasan wajib', 'Jelaskan revisi yang Anda lakukan.');
    resubmit.mutate({ id: request.id, reason: reason.trim() }, { onSuccess: () => { setReason(''); onClose(); }, onError: fail });
  };

  return (
    <Sheet
      visible
      title={request.code}
      onClose={onClose}
      footer={
        request.can_resubmit || request.can_cancel ? (
          <>
            {request.can_resubmit ? <Button label="Kirim ulang" icon="send-outline" onPress={doResubmit} loading={resubmit.isPending} /> : null}
            {request.can_cancel ? <Button label="Batalkan pengajuan" variant="ghost" onPress={doCancel} loading={cancel.isPending} /> : null}
          </>
        ) : undefined
      }
    >
      <Card>
        <Row left={<Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{request.title}</Text>} right={<Pill label={st.label} color={st.color} />} />
        <Kv k="Jenis" v={request.definition_name ?? request.module} />
        <Kv k="Diajukan" v={request.submitted_at ? dateShort(request.submitted_at) : null} />
        <Kv k="Nominal" v={request.amount ? rupiah(request.amount) : null} />
      </Card>
      {detail.isLoading ? <Loading /> : null}
      {detail.data ? <ContextAndHistory d={detail.data} /> : null}

      {request.can_resubmit || request.can_cancel ? <TextArea label="Alasan / catatan revisi" value={reason} onChangeText={setReason} placeholder="Wajib untuk membatalkan atau mengirim ulang" /> : null}

      <TextArea label="Tambah komentar" value={text} onChangeText={setText} multiline />
      <Button
        label="Kirim komentar"
        variant="ghost"
        loading={comment.isPending}
        onPress={() => text.trim() && comment.mutate({ id: request.id, body: text.trim() }, { onSuccess: () => setText(''), onError: fail })}
      />
    </Sheet>
  );
}
