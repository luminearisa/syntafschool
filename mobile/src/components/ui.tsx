import React, { ReactNode } from 'react';
import {
  ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, TextInputProps,
  View, ViewStyle,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import { Ionicons } from '@expo/vector-icons';
import { colors, radius, shadow, space } from '@/theme';
import { initials } from '@/lib/format';

type IconName = React.ComponentProps<typeof Ionicons>['name'];

export function Screen({
  children, onRefresh, refreshing, header, padded = true,
}: { children: ReactNode; onRefresh?: () => void; refreshing?: boolean; header?: ReactNode; padded?: boolean }) {
  return (
    <View style={{ flex: 1, backgroundColor: colors.bg }}>
      <ScrollView
        contentContainerStyle={{ paddingBottom: 120 }}
        showsVerticalScrollIndicator={false}
        refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} tintColor={colors.primary} /> : undefined}
      >
        {header}
        <View style={padded ? { padding: space.lg, gap: space.lg } : undefined}>{children}</View>
      </ScrollView>
    </View>
  );
}

export function PlainScreen({ children }: { children: ReactNode }) {
  return <SafeAreaView style={{ flex: 1, backgroundColor: colors.bg }}>{children}</SafeAreaView>;
}

export function Card({ children, style, onPress }: { children: ReactNode; style?: ViewStyle; onPress?: () => void }) {
  const Body = onPress ? Pressable : View;
  return (
    <Body onPress={onPress} style={[styles.card, style]}>
      {children}
    </Body>
  );
}

export function SectionTitle({ title, action, onAction }: { title: string; action?: string; onAction?: () => void }) {
  return (
    <View style={styles.sectionRow}>
      <Text style={styles.sectionTitle}>{title}</Text>
      {action ? <Text onPress={onAction} style={styles.sectionAction}>{action}</Text> : null}
    </View>
  );
}

export function Pill({ label, color = colors.primary, bg }: { label: string; color?: string; bg?: string }) {
  return (
    <View style={[styles.pill, { backgroundColor: bg ?? color + '1A' }]}>
      <Text style={[styles.pillText, { color }]}>{label}</Text>
    </View>
  );
}

export function StatTile({ icon, label, value, tint = colors.primary, hint }: { icon: IconName; label: string; value: string; tint?: string; hint?: string }) {
  return (
    <View style={[styles.card, { flex: 1, gap: 6, padding: space.md }]}>
      <View style={[styles.iconBubble, { backgroundColor: tint + '1A' }]}>
        <Ionicons name={icon} size={18} color={tint} />
      </View>
      <Text style={styles.statValue} numberOfLines={1} adjustsFontSizeToFit>{value}</Text>
      <Text style={styles.statLabel}>{label}</Text>
      {hint ? <Text style={[styles.statLabel, { color: tint }]}>{hint}</Text> : null}
    </View>
  );
}

export function Avatar({ name, size = 44, tint = colors.primary }: { name?: string | null; size?: number; tint?: string }) {
  return (
    <View style={{ width: size, height: size, borderRadius: size / 2, backgroundColor: tint + '1F', alignItems: 'center', justifyContent: 'center', borderWidth: 1.5, borderColor: tint + '40' }}>
      <Text style={{ color: tint, fontWeight: '700', fontSize: size * 0.36 }}>{initials(name)}</Text>
    </View>
  );
}

export function Button({
  label, onPress, loading, variant = 'primary', icon, disabled,
}: { label: string; onPress: () => void; loading?: boolean; variant?: 'primary' | 'ghost' | 'gold'; icon?: IconName; disabled?: boolean }) {
  const bg = variant === 'primary' ? colors.primary : variant === 'gold' ? colors.gold : 'transparent';
  const fg = variant === 'ghost' ? colors.primary : '#fff';
  return (
    <Pressable
      onPress={onPress}
      disabled={loading || disabled}
      style={({ pressed }) => [styles.button, { backgroundColor: bg, opacity: disabled ? 0.5 : pressed ? 0.85 : 1, borderWidth: variant === 'ghost' ? 1.5 : 0, borderColor: colors.primary + '55' }]}
    >
      {loading ? <ActivityIndicator color={fg} /> : (
        <>
          {icon ? <Ionicons name={icon} size={18} color={fg} /> : null}
          <Text style={[styles.buttonText, { color: fg }]}>{label}</Text>
        </>
      )}
    </Pressable>
  );
}

export function Field({ label, icon, ...props }: TextInputProps & { label: string; icon: IconName }) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={styles.fieldLabel}>{label}</Text>
      <View style={styles.fieldBox}>
        <Ionicons name={icon} size={18} color={colors.muted} />
        <TextInput placeholderTextColor={colors.muted} style={styles.fieldInput} {...props} />
      </View>
    </View>
  );
}

export function Segmented<T extends string>({ value, options, onChange }: { value: T; options: { key: T; label: string }[]; onChange: (k: T) => void }) {
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 8 }}>
      {options.map((o) => {
        const active = o.key === value;
        return (
          <Pressable key={o.key} onPress={() => onChange(o.key)} style={[styles.seg, active && { backgroundColor: colors.primary, borderColor: colors.primary }]}>
            <Text style={[styles.segText, active && { color: '#fff' }]}>{o.label}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

export function EmptyState({ icon = 'leaf-outline', title, hint }: { icon?: IconName; title: string; hint?: string }) {
  return (
    <View style={{ alignItems: 'center', paddingVertical: 36, gap: 8 }}>
      <View style={[styles.iconBubble, { width: 56, height: 56, borderRadius: 28, backgroundColor: colors.primarySoft }]}>
        <Ionicons name={icon} size={26} color={colors.primary} />
      </View>
      <Text style={{ fontWeight: '700', color: colors.ink, fontSize: 15 }}>{title}</Text>
      {hint ? <Text style={{ color: colors.muted, textAlign: 'center', maxWidth: 260 }}>{hint}</Text> : null}
    </View>
  );
}

export function Loading() {
  return <View style={{ paddingVertical: 48 }}><ActivityIndicator color={colors.primary} /></View>;
}

export function ErrorNote({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <Card style={{ borderColor: colors.danger + '55', borderWidth: 1 }}>
      <Text style={{ color: colors.danger, fontWeight: '600' }}>{message}</Text>
      {onRetry ? <Text onPress={onRetry} style={{ color: colors.primary, fontWeight: '700', marginTop: 6 }}>Coba lagi</Text> : null}
    </Card>
  );
}

export function Row({ left, right, sub }: { left: ReactNode; right?: ReactNode; sub?: ReactNode }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: 12 }}>
      <View style={{ flex: 1, gap: 2 }}>{left}{sub}</View>
      {right}
    </View>
  );
}

export const T = {
  title: { fontSize: 15, fontWeight: '700' as const, color: colors.ink },
  body: { fontSize: 14, color: colors.inkSoft },
  small: { fontSize: 12, color: colors.muted },
};

const styles = StyleSheet.create({
  card: { backgroundColor: colors.card, borderRadius: radius.md, padding: space.lg, gap: space.sm, borderWidth: StyleSheet.hairlineWidth, borderColor: colors.line, ...shadow },
  sectionRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'baseline', marginTop: space.sm },
  sectionTitle: { fontSize: 17, fontWeight: '800', color: colors.ink, letterSpacing: -0.2 },
  sectionAction: { fontSize: 13, fontWeight: '700', color: colors.primary },
  pill: { alignSelf: 'flex-start', paddingHorizontal: 10, paddingVertical: 4, borderRadius: radius.pill },
  pillText: { fontSize: 11.5, fontWeight: '700' },
  iconBubble: { width: 34, height: 34, borderRadius: 12, alignItems: 'center', justifyContent: 'center' },
  statValue: { fontSize: 20, fontWeight: '800', color: colors.ink },
  statLabel: { fontSize: 12, color: colors.muted, fontWeight: '600' },
  button: { height: 52, borderRadius: radius.md, flexDirection: 'row', gap: 8, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 20 },
  buttonText: { fontSize: 15.5, fontWeight: '700' },
  fieldLabel: { fontSize: 12.5, fontWeight: '700', color: colors.inkSoft },
  fieldBox: { height: 52, borderRadius: radius.md, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.line, flexDirection: 'row', alignItems: 'center', paddingHorizontal: 14, gap: 10 },
  fieldInput: { flex: 1, fontSize: 15.5, color: colors.ink, height: '100%' },
  seg: { paddingHorizontal: 16, paddingVertical: 9, borderRadius: radius.pill, borderWidth: 1, borderColor: colors.line, backgroundColor: '#fff' },
  segText: { fontSize: 13.5, fontWeight: '700', color: colors.inkSoft },
});
