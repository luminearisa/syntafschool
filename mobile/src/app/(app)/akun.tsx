import React, { useState } from 'react';
import { Text, View } from 'react-native';
import { Alert } from '@/lib/dialog';
import { Ionicons } from '@expo/vector-icons';
import { useQueryClient } from '@tanstack/react-query';
import { Avatar, Button, Card, Row, Screen, SectionTitle, T } from '@/components/ui';
import { Hero } from '@/components/Hero';
import { RoleSwitcher } from '@/components/RoleSwitcher';
import { authApi } from '@/api/auth';
import { useAuth } from '@/store/auth';
import { colors, roleMeta } from '@/theme';

export default function Akun() {
  const { user, active, contexts, clear } = useAuth();
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);

  const logout = async () => {
    try { await authApi.logout(); } catch { /* token mungkin sudah kedaluwarsa */ }
    qc.clear();
    clear();
  };
  const confirm = () => Alert.alert('Keluar', 'Anda yakin ingin keluar?', [{ text: 'Batal', style: 'cancel' }, { text: 'Keluar', style: 'destructive', onPress: logout }]);

  return (
    <Screen header={<Hero />}>
      <Card style={{ alignItems: 'center', gap: 6, paddingVertical: 20 }}>
        <Avatar name={user?.name} size={72} />
        <Text style={{ fontSize: 19, fontWeight: '800', color: colors.ink }}>{user?.name}</Text>
        <Text style={T.body}>{user?.email}</Text>
        {user?.phone ? <Text style={T.small}>{user.phone}</Text> : null}
      </Card>

      <SectionTitle title="Peran" />
      {contexts.map((c) => {
        const meta = roleMeta[c.type];
        return (
          <Card key={c.type} onPress={contexts.length > 1 ? () => setOpen(true) : undefined}>
            <Row
              left={<Text style={T.title}>{c.label}</Text>}
              sub={<Text style={T.small}>{c.subtitle}</Text>}
              right={c.type === active ? <Ionicons name="checkmark-circle" size={22} color={meta.tint} /> : <Text style={{ color: colors.primary, fontWeight: '700' }}>Ganti</Text>}
            />
          </Card>
        );
      })}
      <Button label="Keluar" variant="ghost" icon="log-out-outline" onPress={confirm} />
      <Text style={{ textAlign: 'center', color: colors.muted, fontSize: 12 }}>Syntaf School · versi 1.0.0</Text>
      <RoleSwitcher visible={open} onClose={() => setOpen(false)} />
    </Screen>
  );
}
