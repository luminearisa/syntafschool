import React from 'react';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Avatar } from './ui';
import { colors, radius } from '@/theme';
import { useAuth } from '@/store/auth';
import type { ChildCard } from '@/api/types';

export function ChildPicker({ children, current }: { children: ChildCard[]; current: ChildCard | null }) {
  const select = useAuth((s) => s.selectChild);
  if (children.length < 2) return null;
  return (
    <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={{ gap: 10 }}>
      {children.map((c) => {
        const on = c.id === current?.id;
        return (
          <Pressable
            key={c.id}
            onPress={() => select(c.id)}
            style={{ flexDirection: 'row', alignItems: 'center', gap: 8, paddingVertical: 8, paddingHorizontal: 12, borderRadius: radius.pill, backgroundColor: on ? colors.primary : '#fff', borderWidth: 1, borderColor: on ? colors.primary : colors.line }}
          >
            <Avatar name={c.full_name} size={26} tint={on ? '#FFFFFF' : colors.primary} />
            <Text style={{ fontWeight: '700', color: on ? '#fff' : colors.ink }}>{c.nick_name || c.full_name.split(' ')[0]}</Text>
          </Pressable>
        );
      })}
    </ScrollView>
  );
}

export function ChildHeader({ child }: { child: ChildCard }) {
  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 12 }}>
      <Avatar name={child.full_name} size={48} />
      <View style={{ flex: 1 }}>
        <Text style={{ fontSize: 16, fontWeight: '800', color: colors.ink }}>{child.full_name}</Text>
        <Text style={{ color: colors.muted, fontSize: 12.5 }}>NIS {child.nis}{child.class_name ? ` · ${child.class_name}` : ''}</Text>
      </View>
    </View>
  );
}
