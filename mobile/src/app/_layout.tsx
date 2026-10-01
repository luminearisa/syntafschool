import React, { useState } from 'react';
import { ActivityIndicator, View } from 'react-native';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { useAuth } from '@/store/auth';
import { colors } from '@/theme';

export default function RootLayout() {
  const [client] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: 1, staleTime: 30_000 } } }));
  const { token, active, hydrated } = useAuth();

  if (!hydrated) {
    return (
      <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: colors.primary }}>
        <ActivityIndicator color="#fff" />
      </View>
    );
  }

  return (
    <SafeAreaProvider>
      <QueryClientProvider client={client}>
        <StatusBar style="auto" />
        <Stack screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.bg } }}>
          <Stack.Protected guard={!token}>
            <Stack.Screen name="login" />
          </Stack.Protected>
          <Stack.Protected guard={!!token && !active}>
            <Stack.Screen name="select-role" />
          </Stack.Protected>
          <Stack.Protected guard={!!token && !!active}>
            <Stack.Screen name="(app)" />
          </Stack.Protected>
        </Stack>
      </QueryClientProvider>
    </SafeAreaProvider>
  );
}
