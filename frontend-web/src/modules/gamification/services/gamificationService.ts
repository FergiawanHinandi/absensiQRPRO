import { apiClient } from '../../../lib/api';

export interface LeaderboardEntry {
  rank: number;
  student_id: number;
  student_name: string;
  class_name: string;
  total_points: number;
  current_streak: number;
  attendance_rate: number;
  photo_url?: string;
}

export interface ClassCompetition {
  class_id: number;
  class_name: string;
  grade_level: string;
  total_students: number;
  avg_attendance_rate: number;
  total_points: number;
  rank: number;
}

export interface Badge {
  id: number;
  name: string;
  description: string;
  icon: string;
  category: string;
  earned_at?: string;
  is_earned: boolean;
}

export interface HallOfFameEntry {
  category: string;
  student_name: string;
  class_name: string;
  value: number;
  label: string;
}

export const gamificationService = {
  getLeaderboard: async (): Promise<LeaderboardEntry[]> => {
    const res = await apiClient.get('/gamification/leaderboard');
    return res.data?.leaderboard || res.data || [];
  },

  getClassCompetition: async (): Promise<ClassCompetition[]> => {
    const res = await apiClient.get('/gamification/leaderboard/class-competition');
    return res.data?.classes || res.data || [];
  },

  getBadges: async (): Promise<Badge[]> => {
    const res = await apiClient.get('/gamification/badges');
    return res.data?.badges || res.data || [];
  },

  getHallOfFame: async (): Promise<HallOfFameEntry[]> => {
    const res = await apiClient.get('/gamification/leaderboard/hall-of-fame');
    return res.data?.records || res.data || [];
  },
};
