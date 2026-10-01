import React, { useRef, useState } from 'react';
import { Linking, Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, T } from '@/components/ui';
import { Chips, Kv, Sheet, TextArea } from '@/components/forms';
import { ChildHeader, ChildPicker } from '@/components/ChildPicker';
import { Hero } from '@/components/Hero';
import { useChildren } from '@/api/hooks';
import { useTopup, useWallet } from '@/api/family';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { rupiah } from '@/lib/format';

type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any
const QUICK = [50000, 100000, 200000, 500000];
const TOPUP: Record<string, { label: string; color: string }> = {
  pending_payment: { label: 'Menunggu pembayaran', color: colors.warning }, awaiting_verification: { label: 'Diverifikasi', color: colors.info },
  completed: { label: 'Berhasil', color: colors.success }, success: { label: 'Berhasil', color: colors.success }, failed: { label: 'Gagal', color: colors.danger }, expired: { label: 'Kedaluwarsa', color: colors.muted },
};

export default function Dompet() {
  const { children, child, isLoading } = useChildren();
  const q = useWallet(child?.id);
  const d = q.data as R | undefined;
  const [open, setOpen] = useState(false);

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero back subtitle="Dompet & uang saku" />}>
      {isLoading || q.isLoading ? <Loading /> : null}
      {q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {child ? (
        <>
          <ChildPicker children={children} current={child} />
          <ChildHeader child={child} />
          {d && !d.has_wallet ? <EmptyState icon="wallet-outline" title="Belum ada dompet" hint="Hubungi petugas wallet sekolah untuk membuka dompet anak." /> : null}
          {d?.has_wallet ? (
            <>
              <Card style={{ backgroundColor: colors.primary, gap: 4 }}>
                <Text style={{ color: '#CFE6DD', fontSize: 12.5, fontWeight: '700' }}>SALDO · {d.wallet.number}</Text>
                <Text style={{ color: '#fff', fontSize: 30, fontWeight: '800' }}>{rupiah(d.wallet.balance)}</Text>
                {d.wallet.held_balance > 0 ? <Text style={{ color: '#CFE6DD' }}>Ditahan {rupiah(d.wallet.held_balance)}</Text> : null}
                {d.wallet.status !== 'active' ? <Pill label={d.wallet.status === 'blocked' ? 'Diblokir' : d.wallet.status} color={colors.gold} bg="rgba(255,255,255,0.9)" /> : null}
              </Card>
              {d.topup.allowed ? (
                d.topup.gateway_enabled && d.wallet.can_transact
                  ? <Button label="Top up saldo" icon="add-circle-outline" onPress={() => setOpen(true)} />
                  : <Card style={{ backgroundColor: colors.goldSoft }}><Text style={T.body}>Top up lewat aplikasi belum tersedia. Silakan transfer ke rekening sekolah dan konfirmasi ke petugas wallet.</Text></Card>
              ) : null}

              {(d.topups ?? []).length > 0 ? (
                <>
                  <SectionTitle title="Top up terakhir" />
                  {d.topups.map((t: R) => {
                    const st = TOPUP[t.status] ?? { label: t.status, color: colors.muted };
                    return (
                      <Card key={t.number}>
                        <Row left={<Text style={T.title}>{rupiah(t.amount)}</Text>} sub={<Text style={T.small}>{t.number} · {dayjs(t.created_at).format('D MMM HH:mm')}</Text>} right={<Pill label={st.label} color={st.color} />} />
                        {t.redirect_url ? <Button label="Lanjutkan pembayaran" variant="gold" onPress={() => Linking.openURL(t.redirect_url)} /> : null}
                      </Card>
                    );
                  })}
                </>
              ) : null}

              <SectionTitle title="Riwayat transaksi" />
              {(d.ledger ?? []).length === 0 ? <EmptyState icon="swap-vertical-outline" title="Belum ada transaksi" /> : null}
              {(d.ledger ?? []).map((l: R) => (
                <Card key={l.id}>
                  <Row left={<Text style={T.title}>{l.type_label}</Text>} sub={<Text style={T.small}>{l.description ?? l.transaction_number} · {l.posted_at ? dayjs(l.posted_at).format('D MMM HH:mm') : ''}</Text>} right={<Text style={{ fontWeight: '800', color: l.direction === 'credit' ? colors.success : colors.danger }}>{l.direction === 'credit' ? '+' : '−'}{rupiah(Math.abs(l.amount))}</Text>} />
                </Card>
              ))}
              {open ? <TopupSheet studentId={child.id} min={d.topup.min_amount} max={d.topup.max_amount} onClose={() => setOpen(false)} /> : null}
            </>
          ) : null}
        </>
      ) : null}
    </Screen>
  );
}

function TopupSheet({ studentId, min, max, onClose }: { studentId: number; min: number; max: number; onClose: () => void }) {
  const topup = useTopup();
  const [amount, setAmount] = useState('100000');
  const n = Number(amount.replace(/\D/g, ''));
  const guard = useRef(false);

  const go = () => {
    if (guard.current) return;
    if (!n || (min && n < min) || (max && n > max)) return Alert.alert('Nominal tidak valid', `Isi nominal ${rupiah(min)} – ${rupiah(max)}.`);
    guard.current = true;
    topup.mutate({ studentId, amount: n }, {
      onSuccess: (r) => {
        if (r.redirect_url) Linking.openURL(r.redirect_url).catch(() => undefined);
        Alert.alert('Top up dibuat', `No. ${r.number}. Selesaikan pembayaran di halaman penyedia, lalu tarik layar ke bawah untuk menyegarkan.`);
        onClose();
      },
      onError: (e) => { guard.current = false; Alert.alert('Tidak dapat top up', errorMessage(e)); },
    });
  };

  return (
    <Sheet visible title="Top up saldo" onClose={onClose} footer={<Button label={`Bayar ${rupiah(n)}`} icon="card-outline" onPress={go} loading={topup.isPending} />}>
      <Chips label="Nominal cepat" value={n} options={QUICK.map((q) => ({ key: q, label: rupiah(q) }))} onChange={(k) => setAmount(String(k))} />
      <TextArea label="Nominal (Rp)" value={amount} onChangeText={setAmount} keyboardType="number-pad" multiline={false} hint={`Minimal ${rupiah(min)}, maksimal ${rupiah(max)}.`} />
      <Card><Kv k="Metode" v="Payment gateway" /><Kv k="Berlaku" v="Sesuai batas waktu penyedia pembayaran" /></Card>
    </Sheet>
  );
}
