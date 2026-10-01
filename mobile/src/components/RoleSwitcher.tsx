import React, { useState } from 'react';
import { ActivityIndicator, Modal, Pressable, Text, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { authApi } from '@/api/auth';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors, radius, roleMeta } from '@/theme';
import type { RoleType } from '@/api/types';

/** Bottom sheet pemilih peran — dipakai di Beranda & Akun. */
export function RoleSwitcher({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  const { contexts, active, setSession } = useAuth();
  const qc = useQueryClient();
  const router = useRouter();
  const [busy, setBusy] = useState<RoleType | null>(null);
  const [error, setError] = useState<string | null>(null);

  const choose = async (type: RoleType) => {
    if (type === active) return onClose();
    setBusy(type);
    setError(null);
    try {
      const session = await authApi.switchContext(type);
      setSession(session);
      qc.clear(); // data peran lama tidak boleh tersisa di cache
      onClose();
      router.replace('/');
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(null);
    }
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable onPress={onClose} style={{ flex: 1, backgroundColor: 'rgba(8,30,24,0.55)', justifyContent: 'flex-end' }}>
        <Pressable style={{ backgroundColor: colors.bg, borderTopLeftRadius: 26, borderTopRightRadius: 26, padding: 20, paddingBottom: 36, gap: 12 }}>
          <View style={{ alignSelf: 'center', width: 44, height: 4, borderRadius: 2, backgroundColor: colors.line, marginBottom: 6 }} />
          <Text style={{ fontSize: 18, fontWeight: '800', color: colors.ink }}>Masuk sebagai</Text>
          <Text style={{ color: colors.muted, marginBottom: 4 }}>Akun Anda memiliki lebih dari satu peran.</Text>
          {contexts.map((c) => {
            const meta = roleMeta[c.type];
            const selected = c.type === active;
            return (
              <Pressable
                key={c.type}
                onPress={() => choose(c.type)}
                style={{ flexDirection: 'row', alignItems: 'center', gap: 14, padding: 14, borderRadius: radius.md, backgroundColor: '#fff', borderWidth: 1.5, borderColor: selected ? meta.tint : colors.line }}
              >
                <View style={{ width: 44, height: 44, borderRadius: 14, backgroundColor: meta.tint + '1F', alignItems: 'center', justifyContent: 'center' }}>
                  <Ionicons name={meta.icon} size={22} color={meta.tint} />
                </View>
                <View style={{ flex: 1 }}>
                  <Text style={{ fontWeight: '800', color: colors.ink, fontSize: 15.5 }}>{c.label}</Text>
                  {c.subtitle ? <Text style={{ color: colors.muted, fontSize: 12.5 }}>{c.subtitle}</Text> : null}
                </View>
                {busy === c.type ? <ActivityIndicator color={meta.tint} /> : selected ? <Ionicons name="checkmark-circle" size={22} color={meta.tint} /> : <Ionicons name="chevron-forward" size={18} color={colors.muted} />}
              </Pressable>
            );
          })}
          {error ? <Text style={{ color: colors.danger, fontWeight: '600' }}>{error}</Text> : null}
        </Pressable>
      </Pressable>
    </Modal>
  );
}
