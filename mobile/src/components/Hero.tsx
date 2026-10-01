import React, { ReactNode, useState } from 'react';
import { LayoutChangeEvent, Pressable, Text, View } from 'react-native';
import { LinearGradient } from 'expo-linear-gradient';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { useRouter } from 'expo-router';
import { IslamicPattern } from './Pattern';
import { colors, radius, roleMeta } from '@/theme';
import { useAuth } from '@/store/auth';
import { greeting } from '@/lib/format';
import { RoleSwitcher } from './RoleSwitcher';
import { Avatar } from './ui';

/** Header beranda: gradien zamrud + motif bintang + sapaan + pengganti peran. */
export function Hero({ children, subtitle, back }: { children?: ReactNode; subtitle?: string; back?: boolean }) {
  const router = useRouter();
  const insets = useSafeAreaInsets();
  const { user, active, contexts } = useAuth();
  const [size, setSize] = useState({ w: 400, h: 220 });
  const [open, setOpen] = useState(false);
  const meta = active ? roleMeta[active] : null;
  const multi = contexts.length > 1;

  const onLayout = (e: LayoutChangeEvent) => setSize({ w: e.nativeEvent.layout.width, h: e.nativeEvent.layout.height });

  return (
    <View onLayout={onLayout} style={{ overflow: 'hidden', borderBottomLeftRadius: 28, borderBottomRightRadius: 28 }}>
      <LinearGradient colors={[colors.primary, colors.primaryMid]} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={{ position: 'absolute', inset: 0 }} />
      <IslamicPattern width={size.w} height={size.h} opacity={0.13} />
      <View style={{ paddingTop: insets.top + 14, paddingHorizontal: 20, paddingBottom: 24, gap: 18 }}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
          {back ? (
            <Pressable accessibilityLabel="Kembali" onPress={() => (router.canGoBack() ? router.back() : router.replace('/layanan'))} hitSlop={10} style={{ width: 40, height: 40, borderRadius: 20, backgroundColor: 'rgba(255,255,255,0.16)', alignItems: 'center', justifyContent: 'center' }}>
              <Ionicons name="chevron-back" size={22} color="#fff" />
            </Pressable>
          ) : null}
          <Avatar name={user?.name} size={46} tint="#FFFFFF" />
          <View style={{ flex: 1 }}>
            <Text style={{ color: '#CFE6DD', fontSize: 12.5 }}>{greeting()},</Text>
            <Text style={{ color: '#fff', fontSize: 19, fontWeight: '800' }} numberOfLines={1}>{user?.name}</Text>
          </View>
        </View>

        {meta ? (
          <Pressable
            disabled={!multi}
            onPress={() => setOpen(true)}
            style={{ alignSelf: 'flex-start', flexDirection: 'row', alignItems: 'center', gap: 8, backgroundColor: 'rgba(255,255,255,0.16)', borderRadius: radius.pill, paddingVertical: 8, paddingHorizontal: 14, borderWidth: 1, borderColor: 'rgba(255,255,255,0.28)' }}
          >
            <Ionicons name={meta.icon} size={16} color={colors.gold} />
            <Text style={{ color: '#fff', fontWeight: '700' }}>{meta.label}</Text>
            {multi ? <><Text style={{ color: '#CFE6DD', fontSize: 12 }}>· Ganti peran</Text><Ionicons name="swap-horizontal" size={16} color="#fff" /></> : null}
          </Pressable>
        ) : null}

        {subtitle ? <Text style={{ color: '#E5F2EC', fontSize: 13.5 }}>{subtitle}</Text> : null}
        {children}
      </View>
      <RoleSwitcher visible={open} onClose={() => setOpen(false)} />
    </View>
  );
}
