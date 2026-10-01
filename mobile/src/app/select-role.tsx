import React, { useState } from 'react';
import { ActivityIndicator, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { Hero } from '@/components/Hero';
import { Screen } from '@/components/ui';
import { authApi } from '@/api/auth';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors, radius, roleMeta } from '@/theme';
import type { RoleType } from '@/api/types';

/** Dipakai setelah login bila akun memiliki lebih dari satu peran. */
export default function SelectRole() {
  const { contexts, setSession, clear } = useAuth();
  const [busy, setBusy] = useState<RoleType | null>(null);
  const [error, setError] = useState<string | null>(null);

  const choose = async (t: RoleType) => {
    setBusy(t);
    setError(null);
    try {
      setSession(await authApi.switchContext(t));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Screen header={<Hero subtitle="Pilih peran untuk melanjutkan. Anda dapat menggantinya kapan saja." />}>
      {contexts.map((c) => {
        const meta = roleMeta[c.type];
        return (
          <Pressable key={c.type} onPress={() => choose(c.type)} style={{ flexDirection: 'row', alignItems: 'center', gap: 14, padding: 18, borderRadius: radius.lg, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.line }}>
            <View style={{ width: 52, height: 52, borderRadius: 16, backgroundColor: meta.tint + '1F', alignItems: 'center', justifyContent: 'center' }}>
              <Ionicons name={meta.icon} size={26} color={meta.tint} />
            </View>
            <View style={{ flex: 1 }}>
              <Text style={{ fontSize: 17, fontWeight: '800', color: colors.ink }}>{c.label}</Text>
              {c.subtitle ? <Text style={{ color: colors.muted }}>{c.subtitle}</Text> : null}
            </View>
            {busy === c.type ? <ActivityIndicator color={meta.tint} /> : <Ionicons name="arrow-forward-circle" size={26} color={meta.tint} />}
          </Pressable>
        );
      })}
      {error ? <Text style={{ color: colors.danger, fontWeight: '600' }}>{error}</Text> : null}
      <Text onPress={clear} style={{ textAlign: 'center', color: colors.primary, fontWeight: '700', marginTop: 8 }}>Keluar</Text>
    </Screen>
  );
}
