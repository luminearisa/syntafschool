import React from 'react';
import { Pressable, Text, View } from 'react-native';
import dayjs from 'dayjs';
import { Card, EmptyState, Loading, Screen, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { useNotifications, useReadNotifications } from '@/api/hooks';
import { colors } from '@/theme';

export default function Notifikasi() {
  const q = useNotifications();
  const read = useReadNotifications();
  const items = q.data?.items ?? [];

  return (
    <Screen refreshing={q.isRefetching} onRefresh={() => q.refetch()} header={<Hero subtitle={q.data?.unread ? `${q.data.unread} belum dibaca` : 'Kabar terbaru dari sekolah'} />}>
      {q.data?.unread ? <Text onPress={() => read.mutate(undefined)} style={{ color: colors.primary, fontWeight: '700', alignSelf: 'flex-end' }}>Tandai semua dibaca</Text> : null}
      {q.isLoading ? <Loading /> : items.length === 0 ? <EmptyState icon="notifications-off-outline" title="Belum ada kabar" hint="Pengumuman dan pemberitahuan sekolah muncul di sini." /> : null}
      {items.map((n) => (
        <Pressable key={n.id} onPress={() => !n.read && read.mutate(n.id)}>
          <Card style={{ borderLeftWidth: 4, borderLeftColor: n.read ? colors.line : colors.gold }}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 8 }}>
              <Text style={[T.title, { flex: 1, fontWeight: n.read ? '600' : '800' }]}>{n.title}</Text>
              <Text style={T.small}>{dayjs(n.created_at).format('D MMM, HH:mm')}</Text>
            </View>
            {n.body ? <Text style={T.body}>{n.body}</Text> : null}
          </Card>
        </Pressable>
      ))}
    </Screen>
  );
}
