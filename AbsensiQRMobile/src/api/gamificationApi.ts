import apiClient from './client';

export interface LeaderboardEntry {
  rank: number;
  student_id: number;
  student_name: string;
  class_name: string;
  total_points: number;
  attendance_rate: number;
  avatar_initial: string;
}

export interface Badge {
  id: number;
  name: string;
  description: string;
  icon: string;
  earned_at: string | null;
  progress: number;
  target: number;
  category: 'attendance' | 'streak' | 'punctuality' | 'special';
}

export interface LeaderboardResponse {
  success: boolean;
  data: {
    leaderboard: LeaderboardEntry[];
    my_rank?: number;
    my_points?: number;
    period: string;
  };
}

export interface BadgeResponse {
  success: boolean;
  data: {
    earned: Badge[];
    available: Badge[];
    total_points: number;
  };
}

export const gamificationApi = {
  getLeaderboard: async (params?: {period?: string; class_id?: number}) => {
    const response = await apiClient.get<LeaderboardResponse>('/leaderboard', {
      params,
    });
    return response.data;
  },

  getMyBadges: async () => {
    const response = await apiClient.get<BadgeResponse>(
      '/student/gamification',
    );
    return response.data;
  },
};

export default gamificationApi;
