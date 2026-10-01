import React from 'react';
import { Platform } from 'react-native';
import { Tabs } from 'expo-router';
import { Ionicons } from '@expo/vector-icons';
import { colors } from '@/theme';
import { useAuth } from '@/store/auth';
import { useNotifications } from '@/api/hooks';

type Icon = React.ComponentProps<typeof Ionicons>['name'];
const icon = (on: Icon, off: Icon) => ({ color, focused }: { color: import("react-native").ColorValue; focused: boolean }) => (
  <Ionicons name={focused ? on : off} size={23} color={color} />
);

export default function AppTabs() {
  const active = useAuth((s) => s.active);
  const unread = useNotifications().data?.unread ?? 0;
  const family = active === 'guardian' || active === 'student';
  const employee = active === 'employee';

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
      <Tabs.Screen name="akademik" options={{ title: 'Akademik', href: family ? undefined : null, tabBarIcon: icon('book', 'book-outline') }} />
      <Tabs.Screen name="keuangan" options={{ title: 'Keuangan', href: active === 'guardian' ? undefined : null, tabBarIcon: icon('wallet', 'wallet-outline') }} />
      <Tabs.Screen name="presensi" options={{ title: 'Presensi', href: employee ? undefined : null, tabBarIcon: icon('finger-print', 'finger-print-outline') }} />
      <Tabs.Screen name="notifikasi" options={{ title: 'Kabar', tabBarBadge: unread > 0 ? unread : undefined, tabBarBadgeStyle: { backgroundColor: colors.danger }, tabBarIcon: icon('notifications', 'notifications-outline') }} />
      <Tabs.Screen name="akun" options={{ title: 'Akun', tabBarIcon: icon('person-circle', 'person-circle-outline') }} />
    </Tabs>
  );
}
