import React, {useEffect, useState, useCallback} from 'react';
import {
  View,
  Text,
  StyleSheet,
  FlatList,
  RefreshControl,
  TouchableOpacity,
  ActivityIndicator,
} from 'react-native';
import gamificationApi, {LeaderboardEntry} from '../../api/gamificationApi';
import {useAuth} from '../../contexts/AuthContext';

const MEDAL_COLORS: Record<number, string> = {
  1: '#FFD700',
  2: '#C0C0C0',
  3: '#CD7F32',
};

export const LeaderboardScreen = () => {
  const {user} = useAuth();
  const [entries, setEntries] = useState<LeaderboardEntry[]>([]);
  const [myRank, setMyRank] = useState<number | null>(null);
  const [myPoints, setMyPoints] = useState<number>(0);
  const [period, setPeriod] = useState<string>('');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [activeTab, setActiveTab] = useState<'weekly' | 'monthly' | 'semester'>(
    'monthly',
  );

  const fetchLeaderboard = useCallback(async () => {
    try {
      const response = await gamificationApi.getLeaderboard({
        period: activeTab,
      });
      if (response.success) {
        setEntries(response.data.leaderboard || []);
        setMyRank(response.data.my_rank ?? null);
        setMyPoints(response.data.my_points ?? 0);
        setPeriod(response.data.period || '');
      }
    } catch (error) {
      console.log('Failed to fetch leaderboard:', error);
    } finally {
      setLoading(false);
    }
  }, [activeTab]);

  useEffect(() => {
    setLoading(true);
    fetchLeaderboard();
  }, [fetchLeaderboard]);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchLeaderboard();
    setRefreshing(false);
  };

  const renderItem = ({item}: {item: LeaderboardEntry}) => {
    const isMe = item.student_id === user?.id;
    const medalColor = MEDAL_COLORS[item.rank];

    return (
      <View style={[styles.card, isMe && styles.cardHighlight]}>
        <View style={styles.rankContainer}>
          {medalColor ? (
            <View style={[styles.medal, {backgroundColor: medalColor}]}>
              <Text style={styles.medalText}>{item.rank}</Text>
            </View>
          ) : (
            <Text style={styles.rankText}>{item.rank}</Text>
          )}
        </View>
        <View style={styles.avatar}>
          <Text style={styles.avatarText}>
            {item.avatar_initial || item.student_name.charAt(0).toUpperCase()}
          </Text>
        </View>
        <View style={styles.info}>
          <Text
            style={[styles.name, isMe && styles.nameHighlight]}
            numberOfLines={1}>
            {item.student_name} {isMe ? '(Kamu)' : ''}
          </Text>
          <Text style={styles.className}>{item.class_name}</Text>
        </View>
        <View style={styles.pointsContainer}>
          <Text style={styles.points}>{item.total_points}</Text>
          <Text style={styles.pointsLabel}>poin</Text>
        </View>
      </View>
    );
  };

  const tabs: {key: 'weekly' | 'monthly' | 'semester'; label: string}[] = [
    {key: 'weekly', label: 'Mingguan'},
    {key: 'monthly', label: 'Bulanan'},
    {key: 'semester', label: 'Semester'},
  ];

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#3B82F6" />
        <Text style={styles.loadingText}>Memuat leaderboard...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      {/* My Rank Summary */}
      {myRank !== null && (
        <View style={styles.summaryCard}>
          <View style={styles.summaryItem}>
            <Text style={styles.summaryValue}>#{myRank}</Text>
            <Text style={styles.summaryLabel}>Peringkat</Text>
          </View>
          <View style={styles.summaryDivider} />
          <View style={styles.summaryItem}>
            <Text style={styles.summaryValue}>{myPoints}</Text>
            <Text style={styles.summaryLabel}>Total Poin</Text>
          </View>
        </View>
      )}

      {/* Period Tabs */}
      <View style={styles.tabContainer}>
        {tabs.map(tab => (
          <TouchableOpacity
            key={tab.key}
            style={[styles.tab, activeTab === tab.key && styles.tabActive]}
            onPress={() => setActiveTab(tab.key)}>
            <Text
              style={[
                styles.tabText,
                activeTab === tab.key && styles.tabTextActive,
              ]}>
              {tab.label}
            </Text>
          </TouchableOpacity>
        ))}
      </View>

      {period ? <Text style={styles.periodText}>Periode: {period}</Text> : null}

      {/* Leaderboard List */}
      <FlatList
        data={entries}
        keyExtractor={item => String(item.student_id)}
        renderItem={renderItem}
        refreshControl={
          <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
        }
        contentContainerStyle={styles.listContent}
        ListEmptyComponent={
          <View style={styles.center}>
            <Text style={styles.emptyText}>Belum ada data leaderboard</Text>
          </View>
        }
      />
    </View>
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
    flexDirection: 'row',
    backgroundColor: '#3B82F6',
    marginHorizontal: 16,
    marginTop: 16,
    borderRadius: 12,
    padding: 20,
    alignItems: 'center',
    justifyContent: 'center',
  },
  summaryItem: {alignItems: 'center', flex: 1},
  summaryValue: {fontSize: 24, fontWeight: 'bold', color: '#fff'},
  summaryLabel: {fontSize: 12, color: '#BFDBFE', marginTop: 4},
  summaryDivider: {
    width: 1,
    height: 40,
    backgroundColor: 'rgba(255,255,255,0.3)',
  },

  tabContainer: {
    flexDirection: 'row',
    marginHorizontal: 16,
    marginTop: 16,
    backgroundColor: '#E5E7EB',
    borderRadius: 10,
    padding: 3,
  },
  tab: {flex: 1, paddingVertical: 8, alignItems: 'center', borderRadius: 8},
  tabActive: {
    backgroundColor: '#fff',
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 2,
    elevation: 2,
  },
  tabText: {fontSize: 13, color: '#6B7280', fontWeight: '500'},
  tabTextActive: {color: '#3B82F6', fontWeight: '700'},

  periodText: {
    fontSize: 12,
    color: '#9CA3AF',
    textAlign: 'center',
    marginTop: 8,
  },

  listContent: {padding: 16},
  card: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 12,
    marginBottom: 8,
    shadowColor: '#000',
    shadowOffset: {width: 0, height: 1},
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  cardHighlight: {
    borderWidth: 2,
    borderColor: '#3B82F6',
    backgroundColor: '#EFF6FF',
  },

  rankContainer: {width: 36, alignItems: 'center'},
  rankText: {fontSize: 16, fontWeight: '600', color: '#6B7280'},
  medal: {
    width: 28,
    height: 28,
    borderRadius: 14,
    justifyContent: 'center',
    alignItems: 'center',
  },
  medalText: {fontSize: 14, fontWeight: 'bold', color: '#fff'},

  avatar: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: '#DBEAFE',
    justifyContent: 'center',
    alignItems: 'center',
    marginLeft: 8,
  },
  avatarText: {fontSize: 16, fontWeight: 'bold', color: '#3B82F6'},

  info: {flex: 1, marginLeft: 12},
  name: {fontSize: 14, fontWeight: '600', color: '#111827'},
  nameHighlight: {color: '#3B82F6'},
  className: {fontSize: 12, color: '#6B7280', marginTop: 2},

  pointsContainer: {alignItems: 'center', marginLeft: 8},
  points: {fontSize: 16, fontWeight: 'bold', color: '#F59E0B'},
  pointsLabel: {fontSize: 10, color: '#9CA3AF'},
});
