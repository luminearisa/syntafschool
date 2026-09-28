import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { GeneralSettingsPayload, GeneralSettingsResponse } from '../types';

export const settingsApi = {
  async general() {
    const response = await api.get<ApiResponse<GeneralSettingsResponse>>('/settings/general');
    return response.data.data;
  },

  async updateGeneral(payload: GeneralSettingsPayload) {
    const response = await api.put<ApiResponse<GeneralSettingsResponse>>('/settings/general', payload);
    return response.data.data;
  },
};
