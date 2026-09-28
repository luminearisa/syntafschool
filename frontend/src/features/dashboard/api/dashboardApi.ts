import { api } from '@/lib/axios';
import type { ApiResponse } from '@/types';
import type { DashboardOverview } from '../types';

export const dashboardApi = {
  async overview(days = 14) {
    const response = await api.get<ApiResponse<DashboardOverview>>('/dashboard/overview', {
      params: { days },
    });
    return response.data.data;
  },
};
