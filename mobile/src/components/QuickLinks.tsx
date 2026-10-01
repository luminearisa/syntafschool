import React from 'react';
import { Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { SectionTitle } from './ui';
import { useAuth } from '@/store/auth';
import { featuresFor } from '@/lib/features';
import { colors } from '@/theme';

/** Pintasan ke fitur yang sudah tersedia untuk peran aktif + tautan ke semua layanan. */
export function QuickLinks() {
  const active = useAuth((s) => s.active);
  const router = useRouter();
  const ready = featuresFor(active).filter((f) => f.route).slice(0, 4);
  const upcoming = featuresFor(active).filter((f) => !f.route).length;

  return (
    <View style={{ gap: 10 }}>
      <SectionTitle title="Akses cepat" action="Semua layanan" onAction={() => router.push('/layanan')} />
      <View style={{ flexDirection: 'row', gap: 10 }}>
        {ready.map((f) => (
          <Pressable key={f.key} onPress={() => router.push(f.route as never)} accessibilityRole="button" accessibilityLabel={f.title} style={{ flex: 1, alignItems: 'center', gap: 6, paddingVertical: 12, backgroundColor: '#fff', borderRadius: 16, borderWidth: 1, borderColor: colors.line }}>
            <View style={{ width: 40, height: 40, borderRadius: 14, backgroundColor: f.tint + '1A', alignItems: 'center', justifyContent: 'center' }}>
              <Ionicons name={f.icon} size={21} color={f.tint} />
            </View>
            <Text style={{ fontSize: 12, fontWeight: '700', color: colors.ink }} numberOfLines={1}>{f.title}</Text>
          </Pressable>
        ))}
      </View>
      {upcoming > 0 ? <Text style={{ color: colors.muted, fontSize: 12 }}>{upcoming} layanan lain sedang disiapkan.</Text> : null}
    </View>
  );
}
