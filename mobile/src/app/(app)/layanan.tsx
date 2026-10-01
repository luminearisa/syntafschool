import React, { useMemo, useState } from 'react';
import { Pressable, Text, TextInput, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useRouter } from 'expo-router';
import { Hero } from '@/components/Hero';
import { EmptyState, Pill, Screen, SectionTitle } from '@/components/ui';
import { useAuth } from '@/store/auth';
import { CATEGORY_ORDER, featuresFor, type Feature } from '@/lib/features';
import { colors, radius, shadow } from '@/theme';

/** Pusat layanan: semua fitur untuk peran aktif, dikelompokkan per kategori. */
export default function Layanan() {
  const active = useAuth((s) => s.active);
  const router = useRouter();
  const [q, setQ] = useState('');

  const grouped = useMemo(() => {
    const term = q.trim().toLowerCase();
    const list = featuresFor(active).filter((f) => !term || `${f.title} ${f.hint}`.toLowerCase().includes(term));
    const groups = CATEGORY_ORDER.map((c) => ({ category: c, items: list.filter((f) => f.category === c) })).filter((x) => x.items.length > 0);
    // Kategori yang sudah punya fitur siap pakai tampil lebih dulu (urutan kategori tetap stabil).
    const ready = (g: { items: { route?: string }[] }) => (g.items.some((f) => f.route) ? 0 : 1);
    return groups.sort((a, b) => ready(a) - ready(b));
  }, [active, q]);

  return (
    <Screen header={<Hero subtitle="Semua layanan untuk peran Anda" />}>
      <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, backgroundColor: '#fff', borderRadius: radius.pill, paddingHorizontal: 16, height: 48, borderWidth: 1, borderColor: colors.line }}>
        <Ionicons name="search" size={18} color={colors.muted} />
        <TextInput value={q} onChangeText={setQ} placeholder="Cari layanan…" placeholderTextColor={colors.muted} style={{ flex: 1, fontSize: 15, color: colors.ink }} />
      </View>

      {grouped.length === 0 ? <EmptyState icon="search-outline" title="Layanan tidak ditemukan" /> : null}

      {grouped.map(({ category, items }) => (
        <View key={category} style={{ gap: 10 }}>
          <SectionTitle title={category} />
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 12 }}>
            {items.map((f) => (
              <Tile key={f.key} f={f} onPress={() => f.route && router.push(f.route as never)} />
            ))}
          </View>
        </View>
      ))}
    </Screen>
  );
}

function Tile({ f, onPress }: { f: Feature; onPress: () => void }) {
  const ready = !!f.route;
  return (
    <Pressable
      onPress={onPress}
      disabled={!ready}
      accessibilityRole="button"
      accessibilityLabel={ready ? f.title : `${f.title}, segera hadir`}
      style={({ pressed }) => ({ width: '47.5%', backgroundColor: '#fff', borderRadius: radius.md, padding: 14, gap: 10, opacity: ready ? (pressed ? 0.85 : 1) : 0.6, borderWidth: 1, borderColor: colors.line, ...shadow })}
    >
      <View style={{ width: 42, height: 42, borderRadius: 14, backgroundColor: f.tint + '1A', alignItems: 'center', justifyContent: 'center' }}>
        <Ionicons name={f.icon} size={22} color={f.tint} />
      </View>
      <View style={{ gap: 2 }}>
        <Text style={{ fontWeight: '800', fontSize: 15, color: colors.ink }}>{f.title}</Text>
        <Text style={{ fontSize: 12, color: colors.muted }} numberOfLines={2}>{f.hint}</Text>
      </View>
      {!ready ? <Pill label={`Segera · Fase ${f.phase}`} color={colors.gold} /> : null}
    </Pressable>
  );
}
