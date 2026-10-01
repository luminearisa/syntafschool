import React, { useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, Text, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { IslamicPattern } from '@/components/Pattern';
import { Button, Field } from '@/components/ui';
import { authApi } from '@/api/auth';
import { errorMessage } from '@/api/client';
import { useAuth } from '@/store/auth';
import { colors, radius } from '@/theme';

export default function LoginScreen() {
  const insets = useSafeAreaInsets();
  const setSession = useAuth((s) => s.setSession);
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [show, setShow] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (!email.trim() || !password) return setError('Email dan kata sandi wajib diisi.');
    setLoading(true);
    setError(null);
    try {
      setSession(await authApi.login(email.trim(), password));
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ flexGrow: 1 }}>
        <View style={{ height: 300 + insets.top, overflow: 'hidden', borderBottomLeftRadius: 36, borderBottomRightRadius: 36 }}>
          <LinearGradient colors={[colors.primary, colors.primaryMid]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={{ position: 'absolute', inset: 0 }} />
          <IslamicPattern width={720} height={340 + insets.top} opacity={0.14} size={52} />
          <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', gap: 12, paddingTop: insets.top }}>
            <View style={{ width: 76, height: 76, borderRadius: 24, backgroundColor: 'rgba(255,255,255,0.14)', borderWidth: 1.5, borderColor: colors.gold, alignItems: 'center', justifyContent: 'center' }}>
              <Ionicons name="school" size={38} color={colors.gold} />
            </View>
            <Text style={{ color: '#fff', fontSize: 28, fontWeight: '800', letterSpacing: -0.5 }}>Syntaf School</Text>
            <Text style={{ color: '#CFE6DD', fontSize: 14 }}>Portal Wali Murid · Siswa · Karyawan</Text>
          </View>
        </View>

        <View style={{ padding: 24, gap: 16, marginTop: -28 }}>
          <View style={{ backgroundColor: '#fff', borderRadius: radius.lg, padding: 20, gap: 16, shadowColor: '#0B2A22', shadowOpacity: 0.12, shadowRadius: 20, shadowOffset: { width: 0, height: 10 }, elevation: 6 }}>
            <Text style={{ fontSize: 20, fontWeight: '800', color: colors.ink }}>Selamat datang</Text>
            <Field label="Email" icon="mail-outline" value={email} onChangeText={setEmail} autoCapitalize="none" keyboardType="email-address" placeholder="nama@email.com" autoComplete="email" />
            <Field label="Kata sandi" icon="lock-closed-outline" value={password} onChangeText={setPassword} secureTextEntry={!show} placeholder="••••••••" onSubmitEditing={submit} />
            <Text onPress={() => setShow((v) => !v)} style={{ color: colors.primary, fontWeight: '700', fontSize: 13, alignSelf: 'flex-end' }}>{show ? 'Sembunyikan' : 'Tampilkan'} kata sandi</Text>
            {error ? <Text style={{ color: colors.danger, fontWeight: '600' }}>{error}</Text> : null}
            <Button label="Masuk" icon="log-in-outline" onPress={submit} loading={loading} />
          </View>
          <Text style={{ textAlign: 'center', color: colors.muted, fontSize: 12.5 }}>
            Belum punya akses? Hubungi administrasi sekolah.
          </Text>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
}
