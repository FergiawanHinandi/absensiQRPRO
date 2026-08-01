import React, {useEffect, useState, useCallback} from 'react';
import {
  View,
  Text,
  StyleSheet,
  ScrollView,
  RefreshControl,
  ActivityIndicator,
} from 'react-native';
import gamificationApi, {Badge} from '../../api/gamificationApi';

const CATEGORY_CONFIG: Record<
  string,
  {label: string; color: string; bg: string}
> = {
  attendance: {label: 'Kehadiran', color: '#065F46', bg: '#D1FAE5'},
  streak: {label: 'Streak', color: '#92400E', bg: '#FEF3C7'},
  punctuality: {label: 'Ketepatan', color: '#1E40AF', bg: '#DBEAFE'},
  special: {label: 'Spesial', color: '#6B21A8', bg: '#F3E8FF'},
};

export const BadgesScreen = () => {
  const [earnedBadges, setEarnedBadges] = useState<Badge[]>([]);
  const [availableBadges, setAvailableBadges] = useState<Badge[]>([]);
  const [totalPoints, setTotalPoints] = useState(0);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const fetchBadges = useCallback(async () => {
    try {
      const response = await gamificationApi.getMyBadges();
      if (response.success) {
        setEarnedBadges(response.data.earned || []);
        setAvailableBadges(response.data.available || []);
        setTotalPoints(response.data.total_points || 0);
      }
    } catch (error) {
      console.log('Failed to fetch badges:', error);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchBadges();
  }, [fetchBadges]);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchBadges();
    setRefreshing(false);
  };

  const renderBadge = (badge: Badge, earned: boolean) => {
    const catConfig =
      CATEGORY_CONFIG[badge.category] || CATEGORY_CONFIG.attendance;
    const progressPct =
      badge.target > 0
        ? Math.min((badge.progress / badge.target) * 100, 100)
        : 0;

    return (
      <View
        key={badge.id}
        style={[styles.badgeCard, !earned && styles.badgeCardLocked]}>
        <View style={styles.badgeHeader}>
          <View
            style={[
              styles.iconCircle,
              earned ? styles.iconEarned : styles.iconLocked,
            ]}>
            <Text style={styles.iconText}>{badge.icon || '🏆'}</Text>
          </View>
          <View style={styles.badgeInfo}>
            <Text style={[styles.badgeName, !earned && styles.textMuted]}>
              {badge.name}
            </Text>
            <View
              style={[styles.categoryBadge, {backgroundColor: catConfig.bg}]}>
              <Text style={[styles.categoryText, {color: catConfig.color}]}>
                {catConfig.label}
              </Text>
            </View>
          </View>
        </View>

        <Text style={[styles.badgeDesc, !earned && styles.textMuted]}>
          {badge.description}
        </Text>

        {earned && badge.earned_at ? (
          <Text style={styles.earnedDate}>
            Diperoleh: {new Date(badge.earned_at).toLocaleDateString('id-ID')}
          </Text>
        ) : (
          <View style={styles.progressContainer}>
            <View style={styles.progressBar}>
              <View style={[styles.progressFill, {width: `${progressPct}%`}]} />
            </View>
            <Text style={styles.progressText}>
              {badge.progress}/{badge.target}
            </Text>
          </View>
        )}
      </View>
    );
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#3B82F6" />
        <Text style={styles.loadingText}>Memuat badge...</Text>
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }>
      {/* Points Summary */}
      <View style={styles.summaryCard}>
        <Text style={styles.summaryIcon}>⭐</Text>
        <Text style={styles.summaryPoints}>{totalPoints}</Text>
        <Text style={styles.summaryLabel}>Total Poin</Text>
        <Text style={styles.summaryBadgeCount}>
          {earnedBadges.length} dari{' '}
          {earnedBadges.length + availableBadges.length} badge diperoleh
        </Text>
      </View>

      {/* Earned Badges */}
      {earnedBadges.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>🏆 Badge Diperoleh</Text>
          {earnedBadges.map(b => renderBadge(b, true))}
        </View>
      )}

      {/* Available Badges */}
      {availableBadges.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>🔒 Badge Tersedia</Text>
          {availableBadges.map(b => renderBadge(b, false))}
        </View>
      )}

      {earnedBadges.length === 0 && availableBadges.length === 0 && (
        <View style={styles.center}>
          <Text style={styles.emptyText}>Belum ada badge tersedia</Text>
        </View>
      )}

      <View style={{height: 32}} />
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6'},
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  loadingText: {fontSize: 14, color: '#6B7280', marginTop: 12},
  emptyText: {fontSize: 16, color: '#9CA3AF', fontStyle: 'italic'},

  summaryCard: {
    backgroundColor: '#3B82F6',
    margin: 16,
    borderRadius: 16,
    padding: 24,
    alignItems: 'center',
  },
  summaryIcon: {fontSize: 32},
  summaryPoints: {
    fontSize: 36,
    fontWeight: 'bold',
    color: '#fff',
    marginTop: 4,
  },
  summaryLabel: {fontSize: 14, color: '#BFDBFE', marginTop: 4},
  summaryBadgeCount: {fontSize: 12, color: '#93C5FD', marginTop: 8},

  section: {paddingHorizontal: 16, marginTop: 8},
  sectionTitle: {
    fontSize: 16,
    fontWeight: '700',
    color: '#111827',
    marginBottom: 12,
  },

  badgeCard: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 14,
    marginBottom: 10,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  badgeCardLocked: {opacity: 0.7},

  badgeHeader: {flexDirection: 'row', alignItems: 'center', marginBottom: 8},
  iconCircle: {
    width: 44,
    height: 44,
    borderRadius: 22,
    justifyContent: 'center',
    alignItems: 'center',
    marginRight: 12,
  },
  iconEarned: {backgroundColor: '#FEF3C7'},
  iconLocked: {backgroundColor: '#F3F4F6'},
  iconText: {fontSize: 22},

  badgeInfo: {flex: 1},
  badgeName: {fontSize: 15, fontWeight: '600', color: '#111827'},
  categoryBadge: {
    alignSelf: 'flex-start',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
    marginTop: 4,
  },
  categoryText: {fontSize: 11, fontWeight: '600'},

  badgeDesc: {fontSize: 13, color: '#6B7280', lineHeight: 18},
  textMuted: {color: '#9CA3AF'},

  earnedDate: {fontSize: 12, color: '#10B981', marginTop: 8, fontWeight: '500'},

  progressContainer: {
    flexDirection: 'row',
    alignItems: 'center',
    marginTop: 10,
  },
  progressBar: {
    flex: 1,
    height: 6,
    backgroundColor: '#E5E7EB',
    borderRadius: 3,
    marginRight: 8,
    overflow: 'hidden',
  },
  progressFill: {height: '100%', backgroundColor: '#3B82F6', borderRadius: 3},
  progressText: {
    fontSize: 12,
    color: '#6B7280',
    fontWeight: '500',
    minWidth: 40,
  },
});
