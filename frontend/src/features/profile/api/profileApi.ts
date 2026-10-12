import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';

export type ProfileType = 'students' | 'guardians' | 'employees';

export type BlockFormat = 'text' | 'date' | 'datetime' | 'money' | 'number' | 'percent' | 'bool' | 'badge';

export interface StatItem {
  label: string;
  value: string | number | null;
  format: BlockFormat;
  tone: string | null;
  hint: string | null;
}

export interface KvItem {
  label: string;
  value: string | number | boolean | null;
  format: BlockFormat;
}

export interface TableColumn {
  key: string;
  label: string;
  format: BlockFormat;
}

export type ProfileBlock =
  | { type: 'stats'; items: StatItem[] }
  | { type: 'kv'; title: string; items: KvItem[] }
  | {
      type: 'table';
      title: string;
      columns: TableColumn[];
      rows: Array<Record<string, string | number | boolean | null>>;
      total: number;
    }
  | { type: 'note'; text: string; tone: 'info' | 'warn' };

export interface ProfileHeader {
  title: string;
  subtitle: string;
  initials: string;
  photo_path: string | null;
  is_active: boolean;
  badges: Array<{ label: string; tone: string }>;
  facts: Array<{ label: string; value: string }>;
}

export interface SectionMeta {
  key: string;
  label: string;
  icon: string;
}

export interface ProfileOverview {
  type: ProfileType;
  id: number;
  header: ProfileHeader;
  summary: ProfileBlock[];
  sections: SectionMeta[];
}

export interface ProfileSection {
  key: string;
  label: string;
  blocks: ProfileBlock[];
}

export const profileApi = {
  async overview(type: ProfileType, id: number) {
    const res = await api.get<ApiResponse<ProfileOverview>>(`/profiles/${type}/${id}`);
    return res.data.data;
  },

  async section(type: ProfileType, id: number, key: string) {
    const res = await api.get<ApiResponse<ProfileSection>>(`/profiles/${type}/${id}/sections/${key}`);
    return res.data.data;
  },
};
