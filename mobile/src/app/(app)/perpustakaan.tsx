import React, { useState } from 'react';
import { Text, TextInput, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Button, Card, EmptyState, ErrorNote, Loading, Pill, Row, Screen, SectionTitle, Segmented, StatTile, T } from '@/components/ui';
import { Kv } from '@/components/forms';
import { Hero } from '@/components/Hero';
import { libraryAvailability, useCancelReservation, useLibraryMe, useLibrarySearch, useRenewLoan, useReserve } from '@/api/student';
import { errorMessage } from '@/api/client';
import { colors } from '@/theme';
import { dateShort, rupiah } from '@/lib/format';

type Tab = 'me' | 'catalog';
type R = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

/** Perpustakaan untuk anggota (siswa / karyawan): pinjaman, reservasi, denda, dan katalog. */
export default function Perpustakaan() {
  const [tab, setTab] = useState<Tab>('me');
  return (
    <Screen header={<Hero back subtitle="Perpustakaan" />}>
      <Segmented<Tab> value={tab} onChange={setTab} options={[{ key: 'me', label: 'Pinjaman saya' }, { key: 'catalog', label: 'Katalog' }]} />
      {tab === 'me' ? <Mine /> : <Catalog />}
    </Screen>
  );
}

function Mine() {
  const q = useLibraryMe();
  const renew = useRenewLoan();
  const cancel = useCancelReservation();
  const d = q.data as R | undefined;

  if (q.isLoading) return <Loading />;
  if (q.error) return <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} />;
  if (!d?.is_member) return <EmptyState icon="library-outline" title="Belum menjadi anggota" hint="Hubungi petugas perpustakaan untuk mendaftar sebagai anggota." />;

  const m = d.member as R;
  const fine = Number(d.fines?.outstanding ?? 0);

  return (
    <>
      <Card>
        <Row left={<Text style={T.title}>{m.name}</Text>} sub={<Text style={T.small}>No. anggota {m.member_number}{m.type ? ` · ${m.type}` : ''}</Text>} right={<Pill label={m.status} color={m.status === 'active' ? colors.success : colors.warning} />} />
        {m.valid_until ? <Kv k="Berlaku hingga" v={dateShort(m.valid_until)} /> : null}
      </Card>
      <View style={{ flexDirection: 'row', gap: 10 }}>
        <StatTile icon="book" tint={colors.primary} label="Dipinjam" value={String((d.loans ?? []).length)} />
        <StatTile icon="cash" tint={fine > 0 ? colors.danger : colors.success} label="Denda" value={rupiah(fine)} />
      </View>

      <SectionTitle title="Sedang dipinjam" />
      {(d.loans ?? []).length === 0 ? <Card><Text style={T.body}>Tidak ada pinjaman aktif.</Text></Card> : null}
      {(d.loans ?? []).map((l: R) => (
        <Card key={l.id}>
          <Row left={<Text style={T.title} numberOfLines={2}>{l.title}</Text>} sub={<Text style={T.small}>Jatuh tempo {dateShort(l.due_at)}{l.renewals ? ` · diperpanjang ${l.renewals}×` : ''}</Text>} right={l.is_overdue ? <Pill label={`Telat ${l.overdue_days} hr`} color={colors.danger} /> : undefined} />
          <Button label="Perpanjang" variant="ghost" icon="refresh-outline" loading={renew.isPending && renew.variables === l.id}
            onPress={() => renew.mutate(l.id, { onSuccess: (r) => Alert.alert('Diperpanjang', `Jatuh tempo baru ${dateShort(r.due_at)}.`), onError: (e) => Alert.alert('Tidak dapat memperpanjang', errorMessage(e)) })} />
        </Card>
      ))}

      {(d.reservations ?? []).length > 0 ? (
        <>
          <SectionTitle title="Reservasi" />
          {d.reservations.map((r: R) => (
            <Card key={r.id}>
              <Row left={<Text style={T.title} numberOfLines={2}>{r.title}</Text>} sub={<Text style={T.small}>{r.code}{r.queue_position ? ` · antrean ke-${r.queue_position}` : ''}{r.expires_at ? ` · hingga ${dateShort(r.expires_at)}` : ''}</Text>} right={<Pill label={r.status} color={colors.info} />} />
              <Button label="Batalkan" variant="ghost" onPress={() => Alert.alert('Batalkan reservasi?', r.title, [
                { text: 'Tidak', style: 'cancel' },
                { text: 'Batalkan', onPress: () => cancel.mutate(r.id, { onError: (e) => Alert.alert('Gagal', errorMessage(e)) }) },
              ])} />
            </Card>
          ))}
        </>
      ) : null}

      {(d.history ?? []).length > 0 ? (
        <>
          <SectionTitle title="Riwayat terakhir" />
          <Card>{d.history.map((h: R) => <Row key={h.id} left={<Text style={T.body} numberOfLines={1}>{h.title}</Text>} right={<Text style={T.small}>{dateShort(h.returned_on)}</Text>} />)}</Card>
        </>
      ) : null}
    </>
  );
}

function Catalog() {
  const [text, setText] = useState('');
  const [term, setTerm] = useState('');
  const q = useLibrarySearch(term);
  const reserve = useReserve();
  const [avail, setAvail] = useState<Record<string, R>>({});

  const check = (slug: string) => libraryAvailability(slug).then((r) => setAvail((a) => ({ ...a, [slug]: (r.data ?? r) as R }))).catch((e) => Alert.alert('Gagal', errorMessage(e)));
  const book = (b: R) => Alert.alert('Reservasi koleksi?', b.title, [
    { text: 'Batal', style: 'cancel' },
    { text: 'Reservasi', onPress: () => reserve.mutate(b.slug, { onSuccess: (r) => Alert.alert('Reservasi dibuat', `Kode ${r.code}${r.queue_position ? ` · antrean ke-${r.queue_position}` : ''}`), onError: (e) => Alert.alert('Tidak dapat mereservasi', errorMessage(e)) }) },
  ]);

  return (
    <>
      <View style={{ flexDirection: 'row', gap: 8 }}>
        <TextInput value={text} onChangeText={setText} onSubmitEditing={() => setTerm(text)} returnKeyType="search" placeholder="Cari judul, pengarang, subjek…" placeholderTextColor={colors.muted}
          style={{ flex: 1, backgroundColor: '#fff', borderRadius: 14, paddingHorizontal: 14, height: 48, borderWidth: 1, borderColor: colors.line, color: colors.ink }} />
        <View style={{ width: 90 }}><Button label="Cari" onPress={() => setTerm(text)} /></View>
      </View>
      {term.trim().length < 2 ? <EmptyState icon="search-outline" title="Cari koleksi" hint="Ketik minimal 2 huruf." /> : null}
      {q.isFetching ? <Loading /> : q.error ? <ErrorNote message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : null}
      {term.trim().length >= 2 && !q.isFetching && (q.data ?? []).length === 0 && !q.error ? <EmptyState icon="book-outline" title="Tidak ditemukan" /> : null}
      {(q.data ?? []).map((b: R) => {
        const a = avail[b.slug];
        return (
          <Card key={b.slug}>
            <Text style={T.title}>{b.title}</Text>
            <Text style={T.small}>{(b.creators ?? []).map((c: R) => c.name).filter(Boolean).join(', ')}{b.publication_year ? ` · ${b.publication_year}` : ''}</Text>
            {b.call_number ? <Text style={T.small}>No. panggil {b.call_number}</Text> : null}
            {a ? (
              <Row left={<Pill label={a.is_available ? `Tersedia ${a.available}/${a.total_copies}` : 'Sedang dipinjam'} color={a.is_available ? colors.success : colors.warning} />} />
            ) : null}
            <View style={{ flexDirection: 'row', gap: 10 }}>
              <View style={{ flex: 1 }}><Button label="Cek stok" variant="ghost" onPress={() => check(b.slug)} /></View>
              <View style={{ flex: 1 }}><Button label="Reservasi" icon="bookmark-outline" onPress={() => book(b)} loading={reserve.isPending && reserve.variables === b.slug} /></View>
            </View>
          </Card>
        );
      })}
    </>
  );
}
