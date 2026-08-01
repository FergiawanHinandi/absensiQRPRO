import React, {useEffect, useState, useCallback} from 'react';
import {
  View,
  Text,
  StyleSheet,
  FlatList,
  RefreshControl,
  ActivityIndicator,
  TouchableOpacity,
} from 'react-native';
import apiClient from '../../api/client';

interface Announcement {
  id: number;
  title: string;
  content: string;
  type: 'info' | 'warning' | 'urgent' | 'event';
  author: string;
  created_at: string;
  is_read: boolean;
}

const TYPE_CONFIG: Record<
  string,
  {label: string; color: string; bg: string; icon: string}
> = {
  info: {label: 'Info', color: '#1E40AF', bg: '#DBEAFE', icon: 'ℹ️'},
  warning: {label: 'Peringatan', color: '#92400E', bg: '#FEF3C7', icon: '⚠️'},
  urgent: {label: 'Penting', color: '#991B1B', bg: '#FEE2E2', icon: '🔴'},
  event: {label: 'Acara', color: '#065F46', bg: '#D1FAE5', icon: '📅'},
};

export const AnnouncementsScreen = () => {
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const fetchAnnouncements = useCallback(async () => {
    try {
      setError(null);
      // Endpoint backend adalah /broadcasts (bukan /announcements)
      const response = await apiClient.get('/broadcasts');
      if (response.data?.success) {
        setAnnouncements(response.data.data || []);
      }
    } catch (err: any) {
      const message =
        err?.response?.data?.message ||
        err?.message ||
        'Gagal memuat pengumuman';
      setError(message);
      console.log('Failed to fetch announcements:', err);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    fetchAnnouncements();
  }, [fetchAnnouncements]);

  const onRefresh = async () => {
    setRefreshing(true);
    await fetchAnnouncements();
    setRefreshing(false);
  };

  const toggleExpand = (id: number) => {
    setExpandedId(expandedId === id ? null : id);
  };

  const formatDate = (dateStr: string) => {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffHours < 1) {
      return 'Baru saja';
    }
    if (diffHours < 24) {
      return `${diffHours} jam lalu`;
    }
    if (diffDays < 7) {
      return `${diffDays} hari lalu`;
    }
    return date.toLocaleDateString('id-ID', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    });
  };

  const renderItem = ({item}: {item: Announcement}) => {
    const config = TYPE_CONFIG[item.type] || TYPE_CONFIG.info;
    const isExpanded = expandedId === item.id;

    return (
      <TouchableOpacity
        style={[styles.card, !item.is_read && styles.cardUnread]}
        onPress={() => toggleExpand(item.id)}
        activeOpacity={0.7}>
        <View style={styles.cardHeader}>
          <View style={styles.cardLeft}>
            <Text style={styles.icon}>{config.icon}</Text>
            <View style={styles.cardInfo}>
              <Text
                style={styles.title}
                numberOfLines={isExpanded ? undefined : 2}>
                {item.title}
              </Text>
              <View style={styles.meta}>
                <View style={[styles.typeBadge, {backgroundColor: config.bg}]}>
                  <Text style={[styles.typeText, {color: config.color}]}>
                    {config.label}
                  </Text>
                </View>
                <Text style={styles.dateText}>
                  {formatDate(item.created_at)}
                </Text>
              </View>
            </View>
          </View>
          {!item.is_read && <View style={styles.unreadDot} />}
        </View>

        {isExpanded && (
          <View style={styles.expandedContent}>
            <Text style={styles.content}>{item.content}</Text>
            <Text style={styles.author}>— {item.author}</Text>
          </View>
        )}
      </TouchableOpacity>
    );
  };

  if (loading) {
    return (
      <View style={styles.center}>
        <ActivityIndicator size="large" color="#3B82F6" />
        <Text style={styles.loadingText}>Memuat pengumuman...</Text>
      </View>
    );
  }

  return (
    <FlatList
      style={styles.container}
      data={announcements}
      ListHeaderComponent={
        error ? (
          <Text
            style={{
              color: '#DC2626',
              textAlign: 'center',
              padding: 10,
              backgroundColor: '#FEE2E2',
              borderRadius: 8,
              marginBottom: 8,
            }}>
            {error}
          </Text>
        ) : null
      }
      keyExtractor={item => String(item.id)}
      renderItem={renderItem}
      refreshControl={
        <RefreshControl refreshing={refreshing} onRefresh={onRefresh} />
      }
      contentContainerStyle={styles.listContent}
      ListEmptyComponent={
        <View style={styles.emptyContainer}>
          <Text style={styles.emptyIcon}>📭</Text>
          <Text style={styles.emptyText}>Belum ada pengumuman</Text>
          <Text style={styles.emptySubtext}>
            Pengumuman dari sekolah akan tampil di sini
          </Text>
        </View>
      }
    />
  );
};

const styles = StyleSheet.create({
  container: {flex: 1, backgroundColor: '#F3F4F6'},
  listContent: {padding: 16, flexGrow: 1},
  center: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 40,
  },
  loadingText: {fontSize: 14, color: '#6B7280', marginTop: 12},

  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingVertical: 60,
  },
  emptyIcon: {fontSize: 48, marginBottom: 12},
  emptyText: {fontSize: 16, color: '#6B7280', fontWeight: '600'},
  emptySubtext: {fontSize: 13, color: '#9CA3AF', marginTop: 4},

  card: {
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
  cardUnread: {borderLeftWidth: 3, borderLeftColor: '#3B82F6'},

  cardHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
  },
  cardLeft: {flexDirection: 'row', flex: 1},
  icon: {fontSize: 20, marginRight: 10, marginTop: 2},
  cardInfo: {flex: 1},
  title: {fontSize: 15, fontWeight: '600', color: '#111827', lineHeight: 20},

  meta: {flexDirection: 'row', alignItems: 'center', marginTop: 6, gap: 8},
  typeBadge: {paddingHorizontal: 8, paddingVertical: 2, borderRadius: 4},
  typeText: {fontSize: 11, fontWeight: '600'},
  dateText: {fontSize: 12, color: '#9CA3AF'},

  unreadDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: '#3B82F6',
    marginTop: 6,
    marginLeft: 8,
  },

  expandedContent: {
    marginTop: 12,
    paddingTop: 12,
    borderTopWidth: 1,
    borderTopColor: '#F3F4F6',
  },
  content: {fontSize: 14, color: '#374151', lineHeight: 22},
  author: {fontSize: 12, color: '#9CA3AF', marginTop: 8, fontStyle: 'italic'},
});
