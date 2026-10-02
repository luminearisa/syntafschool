import React, { useRef, useState } from 'react';
import { Linking, Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, Segmented, StatTile, T, Button } from '@/components/ui';
import { Kv, Sheet, TextArea } from '@/components/forms';
import { ChildHeader, ChildPicker } from '@/components/ChildPicker';
import { Hero } from '@/components/Hero';
import { useChildren } from '@/api/hooks';
import {
  newIdempotencyKey, useFinInvoices, useFinPayments, useFinPlans, useFinReceipts, useFinReReg, useFinSummary, usePayInvoice,
} from '@/api/family';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort, rupiah } from '@/lib/format';

type Tab = 'bill' | 'paid' | 'receipt' | 'plan' | 'rereg';
type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const INV: Record<string, { label: string; color: string }> = {
  issued: { label: 'Belum bayar', color: colors.warning }, partial: { label: 'Sebagian', color: colors.info },
  paid: { label: 'Lunas', color: colors.success }, overdue: { label: 'Jatuh tempo', color: colors.danger }, cancelled: { label: 'Batal', color: colors.muted },
};
const PAY: Record<string, { label: string; color: string }> = {
  paid: { label: 'Berhasil', color: colors.success }, pending: { label: 'Menunggu', color: colors.warning },
  failed: { label: 'Gagal', color: colors.danger }, expired: { label: 'Kedaluwarsa', color: colors.muted }, cancelled: { label: 'Batal', color: colors.muted },
};

export default function Keuangan() {
  const { children, child, isLoading, error } = useChildren();
  const [tab, setTab] = useState<Tab>('bill');
  const summary = useFinSummary(child?.id);
  const t = summary.data?.totals;

  return (
    <Screen refreshing={summary.isRefetching} onRefresh={() => summary.refetch()} header={<Hero back subtitle="Tagihan, pembayaran & kuitansi" />}>
      {isLoading ? <Loading /> : null}
      {error ? <ErrorNote message={errorMessage(error)} /> : null}
      {child ? (
        <>
          <ChildPicker children={children} current={child} />
          <ChildHeader child={child} />
          {t ? (
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <StatTile icon="alert-circle" tint={t.overdue_total > 0 ? colors.danger : colors.warning} label="Belum dibayar" value={rupiah(t.outstanding_total)} hint={t.overdue_total > 0 ? `${rupiah(t.overdue_total)} jatuh tempo` : undefined} />
              <StatTile icon="checkmark-circle" tint={colors.success} label="Sudah dibayar" value={rupiah(t.paid_total)} />
            </View>
          ) : null}
          {t?.credit_balance > 0 ? <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Saldo deposit Anda: <Text style={{ fontWeight: '800' }}>{rupiah(t.credit_balance)}</Text></Text></Card> : null}

          <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'bill', label: 'Tagihan' }, { key: 'paid', label: 'Riwayat bayar' }, { key: 'receipt', label: 'Kuitansi' }, { key: 'plan', label: 'Cicilan' }, { key: 'rereg', label: 'Daftar ulang' }]} />
          {tab === 'bill' ? <Bills studentId={child.id} /> : null}
          {tab === 'paid' ? <Payments studentId={child.id} /> : null}
          {tab === 'receipt' ? <Receipts studentId={child.id} /> : null}
          {tab === 'plan' ? <Plans studentId={child.id} /> : null}
          {tab === 'rereg' ? <ReReg studentId={child.id} /> : null}
        </>
      ) : null}
    </Screen>
  );
}

function Bills({ studentId }: { studentId: number }) {
  const q = useFinInvoices(studentId);
  const [open, setOpen] = useState<R | null>(null);
  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  if (!q.data?.length) return <EmptyState icon="receipt-outline" title="Belum ada tagihan" />;
  const rows = [...q.data].sort((a, b) => Number(Number(b.remaining_amount) > 0) - Number(Number(a.remaining_amount) > 0));
  return (
    <>
      {rows.map((inv) => {
        const st = INV[inv.status] ?? { label: inv.status_label ?? inv.status, color: colors.muted };
        return (
          <Card key={inv.id} onPress={() => setOpen(inv)}>
            <Row left={<Text style={T.title}>{inv.invoice_number}</Text>} sub={<Text style={T.small}>Terbit {dateShort(inv.issue_date)}{inv.due_date ? ` · tempo ${dateShort(inv.due_date)}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
              <Text style={T.small}>Total {rupiah(inv.total)}</Text>
              <Text style={{ fontWeight: '800', color: Number(inv.remaining_amount) > 0 ? colors.danger : colors.success }}>{Number(inv.remaining_amount) > 0 ? `Sisa ${rupiah(inv.remaining_amount)}` : 'Lunas'}</Text>
            </View>
          </Card>
        );
      })}
      {open ? <InvoiceSheet studentId={studentId} inv={open} onClose={() => setOpen(null)} /> : null}
    </>
  );
}

function InvoiceSheet({ studentId, inv, onClose }: { studentId: number; inv: R; onClose: () => void }) {
  const pay = usePayInvoice();
  const remaining = Number(inv.remaining_amount);
  const [amount, setAmount] = useState(String(Math.round(remaining)));
  // Satu kunci per percobaan bayar: ulang jaringan tidak membuat dua pembayaran.
  const key = useRef(newIdempotencyKey('pay'));
  const payable = remaining > 0 && inv.status !== 'cancelled';

  const go = () => {
    const n = Number(amount.replace(/\D/g, ''));
    if (!(n >= 1) || n > remaining) return Alert.alert('Nominal tidak valid', `Isi nominal 1 – ${rupiah(remaining)}.`);
    pay.mutate({ studentId, invoiceId: inv.id, amount: n, key: key.current }, {
      onSuccess: (r) => {
        key.current = newIdempotencyKey('pay');
        if (r.redirect_url) Linking.openURL(r.redirect_url).catch(() => Alert.alert('Tidak dapat membuka halaman pembayaran', r.redirect_url ?? ''));
        Alert.alert('Pembayaran dibuat', `No. ${r.payment_number}. Selesaikan di halaman pembayaran, lalu tarik layar ke bawah untuk menyegarkan status.`);
        onClose();
      },
      onError: (e) => Alert.alert('Tidak dapat membayar', errorMessage(e)),
    });
  };

  return (
    <Sheet visible title={inv.invoice_number} onClose={onClose} footer={payable ? <Button label={`Bayar ${rupiah(Number(amount.replace(/\D/g, '')) || 0)}`} icon="card-outline" onPress={go} loading={pay.isPending} /> : undefined}>
      <Card>
        <Kv k="Status" v={INV[inv.status]?.label ?? inv.status_label} />
        <Kv k="Terbit" v={dateShort(inv.issue_date)} />
        <Kv k="Jatuh tempo" v={inv.due_date ? dateShort(inv.due_date) : null} />
        <Kv k="Total" v={rupiah(inv.total)} />
        <Kv k="Sudah dibayar" v={rupiah(inv.paid_amount)} />
        <Kv k="Sisa" v={rupiah(inv.remaining_amount)} />
      </Card>
      {(inv.items ?? []).length > 0 ? (
        <Card>
          <Text style={T.title}>Rincian</Text>
          {inv.items.map((it: R) => (
            <Row key={it.id} left={<Text style={T.body}>{it.fee_type_name ?? it.description}</Text>} sub={it.quantity > 1 ? <Text style={T.small}>{it.quantity} × {rupiah(it.unit_price)}</Text> : undefined} right={<Text style={{ fontWeight: '700', color: colors.ink }}>{rupiah(it.total)}</Text>} />
          ))}
        </Card>
      ) : null}
      {payable ? <TextArea label="Nominal yang dibayar (Rp)" value={amount} onChangeText={setAmount} keyboardType="number-pad" multiline={false} hint="Boleh sebagian. Pembayaran online diproses melalui penyedia pembayaran sekolah." /> : null}
    </Sheet>
  );
}

function Payments({ studentId }: { studentId: number }) {
  const q = useFinPayments(studentId);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="card-outline" title="Belum ada pembayaran" />;
  return (
    <>
      {q.data.map((p) => {
        const st = PAY[p.status] ?? { label: p.status_label ?? p.status, color: colors.muted };
        return (
          <Card key={p.id}>
            <Row left={<Text style={T.title}>{rupiah(p.amount)}</Text>} sub={<Text style={T.small}>{p.payment_number} · {p.method_label}{p.invoice_number ? ` · ${p.invoice_number}` : ''}</Text>} right={<Pill label={st.label} color={st.color} />} />
            <Text style={T.small}>{p.paid_at ? `Dibayar ${dayjs(p.paid_at).format('D MMM YYYY HH:mm')}` : `Dibuat ${dateShort(p.created_at)}`}</Text>
            {p.reject_reason ? <Text style={{ color: colors.danger, fontSize: 12.5 }}>{p.reject_reason}</Text> : null}
          </Card>
        );
      })}
    </>
  );
}

function Receipts({ studentId }: { studentId: number }) {
  const q = useFinReceipts(studentId);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="document-text-outline" title="Belum ada kuitansi" />;
  return (
    <>
      {q.data.map((r) => (
        <Card key={r.id}>
          <Row left={<Text style={T.title}>{r.receipt_number}</Text>} sub={<Text style={T.small}>{rupiah(r.amount)} · {r.invoice_number ?? '-'} · {r.received_at ? dateShort(r.received_at) : ''}</Text>} right={<Pill label={r.is_cancelled ? 'Dibatalkan' : 'Sah'} color={r.is_cancelled ? colors.muted : colors.success} />} />
        </Card>
      ))}
    </>
  );
}

function Plans({ studentId }: { studentId: number }) {
  const q = useFinPlans(studentId);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="calendar-outline" title="Tidak ada rencana cicilan" />;
  return (
    <>
      {q.data.map((p) => (
        <Card key={p.id}>
          <Row left={<Text style={T.title}>{p.plan_number}</Text>} sub={<Text style={T.small}>{p.invoice_number} · {p.installment_count}× angsuran</Text>} right={<Pill label={p.status_label ?? p.status} />} />
          <View style={{ height: 6, borderRadius: 3, backgroundColor: colors.primarySoft }}><View style={{ height: 6, borderRadius: 3, width: `${Math.min(100, Number(p.progress ?? 0))}%`, backgroundColor: colors.primary }} /></View>
          <Text style={T.small}>Terbayar {rupiah(p.paid_amount)} dari {rupiah(p.total_amount)}{p.next_due_date ? ` · berikutnya ${dateShort(p.next_due_date)}` : ''}</Text>
          {(p.installments ?? []).map((i: R) => (
            <Row key={i.id ?? i.sequence} left={<Text style={T.body}>Angsuran {i.sequence}</Text>} sub={<Text style={T.small}>Tempo {dateShort(i.due_date)}</Text>} right={<Text style={{ fontWeight: '700', color: i.status === 'paid' ? colors.success : colors.ink }}>{rupiah(i.amount)}</Text>} />
          ))}
        </Card>
      ))}
    </>
  );
}

function ReReg({ studentId }: { studentId: number }) {
  const q = useFinReReg(studentId);
  if (q.isLoading) return <Loading />;
  if (!q.data?.length) return <EmptyState icon="school-outline" title="Belum ada daftar ulang" />;
  return (
    <>
      {q.data.map((r) => (
        <Card key={r.id}>
          <Row left={<Text style={T.title}>{r.current_academic_year} → {r.next_academic_year}</Text>} sub={<Text style={T.small}>{r.registration_number} · jatuh tempo {r.due_date ? dateShort(r.due_date) : '-'}</Text>} right={<Pill label={r.status_label ?? r.status} />} />
          <Row left={<Text style={T.body}>Total</Text>} right={<Text style={{ fontWeight: '800' }}>{rupiah(r.amount)}</Text>} />
          <Row left={<Text style={T.body}>Sisa</Text>} right={<Text style={{ fontWeight: '800', color: Number(r.remaining_amount) > 0 ? colors.danger : colors.success }}>{rupiah(r.remaining_amount)}</Text>} />
        </Card>
      ))}
    </>
  );
}
