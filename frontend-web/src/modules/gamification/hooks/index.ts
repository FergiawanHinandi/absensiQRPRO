import { useQuery } from '@tanstack/react-query';
import { gamificationService } from '../services/gamificationService';

export function useLeaderboard() {
  return useQuery({
    queryKey: ['gamification', 'leaderboard'],
    queryFn: gamificationService.getLeaderboard,
  });
}

export function useClassCompetition() {
  return useQuery({
    queryKey: ['gamification', 'class-competition'],
    queryFn: gamificationService.getClassCompetition,
  });
}

export function useBadges() {
  return useQuery({
    queryKey: ['gamification', 'badges'],
    queryFn: gamificationService.getBadges,
  });
}

export function useHallOfFame() {
  return useQuery({
    queryKey: ['gamification', 'hall-of-fame'],
    queryFn: gamificationService.getHallOfFame,
  });
}
