import React, { ReactNode } from 'react';
import { KeyboardAvoidingView, Modal, Platform, Pressable, ScrollView, Text, TextInput, TextInputProps, View } from 'react-native';
import { Ionicons } from '@expo/vector-icons';
import { useSafeAreaInsets } from 'react-native-safe-area-context';
import { colors, radius } from '@/theme';

/** Layar penuh bergaya sheet: judul + tutup + isi yang bisa di-scroll + bilah aksi di bawah. */
export function Sheet({
  visible, title, onClose, children, footer,
}: { visible: boolean; title: string; onClose: () => void; children: ReactNode; footer?: ReactNode }) {
  const insets = useSafeAreaInsets();
  return (
    <Modal visible={visible} animationType="slide" onRequestClose={onClose} presentationStyle="fullScreen">
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: colors.bg }}>
        <View style={{ paddingTop: insets.top + 8, paddingHorizontal: 16, paddingBottom: 12, flexDirection: 'row', alignItems: 'center', gap: 12, backgroundColor: '#fff', borderBottomWidth: 1, borderColor: colors.line }}>
          <Pressable onPress={onClose} hitSlop={10} accessibilityLabel="Tutup" style={{ width: 36, height: 36, borderRadius: 18, backgroundColor: colors.primarySoft, alignItems: 'center', justifyContent: 'center' }}>
            <Ionicons name="close" size={20} color={colors.primary} />
          </Pressable>
          <Text style={{ flex: 1, fontSize: 17, fontWeight: '800', color: colors.ink }} numberOfLines={1}>{title}</Text>
        </View>
        <ScrollView keyboardShouldPersistTaps="handled" contentContainerStyle={{ padding: 16, gap: 14, paddingBottom: 40 }}>{children}</ScrollView>
        {footer ? <View style={{ padding: 16, paddingBottom: insets.bottom + 16, gap: 10, backgroundColor: '#fff', borderTopWidth: 1, borderColor: colors.line }}>{footer}</View> : null}
      </KeyboardAvoidingView>
    </Modal>
  );
}

export function TextArea({ label, hint, ...props }: TextInputProps & { label: string; hint?: string }) {
  return (
    <View style={{ gap: 6 }}>
      <Text style={{ fontSize: 12.5, fontWeight: '700', color: colors.inkSoft }}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        multiline={props.multiline ?? true}
        placeholderTextColor={colors.muted}
        textAlignVertical="top"
        {...props}
        style={[{ minHeight: props.multiline === false ? 48 : 90, borderRadius: radius.md, backgroundColor: '#fff', borderWidth: 1, borderColor: colors.line, padding: 12, fontSize: 15, color: colors.ink }, props.style]}
      />
      {hint ? <Text style={{ fontSize: 11.5, color: colors.muted }}>{hint}</Text> : null}
    </View>
  );
}

export function Chips<T extends string | number>({
  label, value, options, onChange,
}: { label?: string; value: T | null; options: { key: T; label: string }[]; onChange: (k: T) => void }) {
  return (
    <View style={{ gap: 6 }}>
      {label ? <Text style={{ fontSize: 12.5, fontWeight: '700', color: colors.inkSoft }}>{label}</Text> : null}
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 8 }}>
        {options.map((o) => {
          const on = o.key === value;
          return (
            <Pressable key={String(o.key)} onPress={() => onChange(o.key)} style={{ paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, borderWidth: 1, borderColor: on ? colors.primary : colors.line, backgroundColor: on ? colors.primary : '#fff' }}>
              <Text style={{ fontWeight: '700', fontSize: 13, color: on ? '#fff' : colors.inkSoft }}>{o.label}</Text>
            </Pressable>
          );
        })}
      </View>
    </View>
  );
}

export function Kv({ k, v }: { k: string; v?: string | number | null }) {
  if (v === null || v === undefined || v === '') return null;
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: 12 }}>
      <Text style={{ color: colors.muted, fontSize: 13 }}>{k}</Text>
      <Text style={{ color: colors.ink, fontSize: 13, fontWeight: '600', flexShrink: 1, textAlign: 'right' }}>{v}</Text>
    </View>
  );
}
