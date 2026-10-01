import React from 'react';
import { Text, View } from 'react-native';
import { Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, StatTile, T } from '@/components/ui';
import { ChildHeader, ChildPicker } from '@/components/ChildPicker';
import { Hero } from '@/components/Hero';
import { useChildFinance, useChildren } from '@/api/hooks';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort, rupiah } from '@/lib/format';

const STATUS: Record<string, { label: string; color: string }> = {
  issued: { label: 'Belum bayar', color: colors.warning },
  partial: { label: 'Sebagian', color: colors.info },
  paid: { label: 'Lunas', color: colors.success },
  overdue: { label: 'Jatuh tempo', color: colors.danger },
};

export default function Keuangan() {
  const { children, child, isLoading: loadingKids, error: kidsError } = useChildren();
  const q = useChildFinance(child?.id);
  const t = q.data?.summary.totals;

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero subtitle="Tagihan & riwayat pembayaran" />}>
      {loadingKids || q.isLoading ? <Loading /> : null}
      {kidsError || q.error ? <ErrorNote message={errorMessage(kidsError ?? q.error)} onRetry={() => q.refetch()} /> : null}
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

          <SectionTitle title="Tagihan" />
          {q.data && q.data.invoices.length === 0 ? <EmptyState icon="receipt-outline" title="Belum ada tagihan" /> : null}
          {q.data?.invoices.map((inv) => {
            const st = STATUS[inv.status] ?? { label: inv.status, color: colors.muted };
            return (
              <Card key={inv.id}>
                <Row
                  left={<Text style={T.title}>{inv.invoice_number}</Text>}
                  sub={<Text style={T.small}>Terbit {dateShort(inv.issue_date)}{inv.due_date ? ` · jatuh tempo ${dateShort(inv.due_date)}` : ''}</Text>}
                  right={<Pill label={st.label} color={st.color} />}
                />
                {inv.notes ? <Text style={T.body}>{inv.notes}</Text> : null}
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 4 }}>
                  <Text style={T.small}>Total {rupiah(inv.total)}</Text>
                  <Text style={{ fontWeight: '800', color: Number(inv.remaining_amount) > 0 ? colors.danger : colors.success }}>
                    {Number(inv.remaining_amount) > 0 ? `Sisa ${rupiah(inv.remaining_amount)}` : 'Lunas'}
                  </Text>
                </View>
              </Card>
            );
          })}
          <Card style={{ backgroundColor: colors.goldSoft }}>
            <Text style={T.body}>Pembayaran dilakukan melalui kasir/transfer sekolah. Status akan diperbarui setelah dikonfirmasi bendahara.</Text>
          </Card>
        </>
      ) : null}
    </Screen>
  );
}
