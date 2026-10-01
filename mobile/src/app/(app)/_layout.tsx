import React, { useEffect } from 'react';
import { Platform } from 'react-native';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '@/theme';
import { useNotifications } from '@/api/hooks';
import { useAuth } from '@/store/auth';
import { get } from '@/api/client';

type Icon = React.ComponentProps<typeof Ionicons>['name'];
const icon = (on: Icon, off: Icon) => ({ color, focused }: { color: import("react-native").ColorValue; focused: boolean }) => (
  <Ionicons name={focused ? on : off} size={23} color={color} />
);

/** Sesi lama tidak membawa izin; segarkan dari server agar menu sesuai hak terbaru. */
function usePermissionRefresh() {
  const token = useAuth((s) => s.token);
  const active = useAuth((s) => s.active);
  const setPermissions = useAuth((s) => s.setPermissions);
  useEffect(() => {
    if (!token || !active) return;
    get<{ permissions: string[] }>('/auth/contexts').then((r) => setPermissions(r.permissions ?? [])).catch(() => undefined);
  }, [token, active, setPermissions]);
}

export default function AppTabs() {
  usePermissionRefresh();
  const unread = useNotifications().data?.unread ?? 0;

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: colors.primary,
        tabBarInactiveTintColor: colors.muted,
        tabBarLabelStyle: { fontSize: 11, fontWeight: '700' },
        tabBarStyle: {
          position: 'absolute', left: 14, right: 14, bottom: Platform.OS === 'web' ? 12 : 16,
          height: 66, paddingTop: 6, paddingBottom: 8, borderRadius: 24,
          backgroundColor: '#fff', borderTopWidth: 0,
          shadowColor: '#0B2A22', shadowOpacity: 0.14, shadowRadius: 18, shadowOffset: { width: 0, height: 8 }, elevation: 10,
        },
        sceneStyle: { backgroundColor: colors.bg },
      }}
    >
      <Tabs.Screen name="index" options={{ title: 'Beranda', tabBarIcon: icon('home', 'home-outline') }} />
      <Tabs.Screen name="layanan" options={{ title: 'Layanan', tabBarIcon: icon('apps', 'apps-outline') }} />
      <Tabs.Screen name="notifikasi" options={{ title: 'Kabar', tabBarBadge: unread > 0 ? unread : undefined, tabBarBadgeStyle: { backgroundColor: colors.danger }, tabBarIcon: icon('notifications', 'notifications-outline') }} />
      <Tabs.Screen name="akun" options={{ title: 'Akun', tabBarIcon: icon('person-circle', 'person-circle-outline') }} />
      {/* Layar fitur: dibuka dari Layanan/Beranda, tidak tampil di tab bar. */}
      <Tabs.Screen name="akademik" options={{ href: null }} />
      <Tabs.Screen name="keuangan" options={{ href: null }} />
      <Tabs.Screen name="presensi" options={{ href: null }} />
      <Tabs.Screen name="persetujuan" options={{ href: null }} />
      <Tabs.Screen name="jurnal" options={{ href: null }} />
      <Tabs.Screen name="absen-siswa" options={{ href: null }} />
      <Tabs.Screen name="nilai" options={{ href: null }} />
      <Tabs.Screen name="tugas-guru" options={{ href: null }} />
      <Tabs.Screen name="aset" options={{ href: null }} />
      <Tabs.Screen name="asrama" options={{ href: null }} />
      <Tabs.Screen name="tahfiz" options={{ href: null }} />
      <Tabs.Screen name="ekskul" options={{ href: null }} />
      <Tabs.Screen name="kesehatan" options={{ href: null }} />
      <Tabs.Screen name="konseling" options={{ href: null }} />
      <Tabs.Screen name="dompet" options={{ href: null }} />
    </Tabs>
  );
}
