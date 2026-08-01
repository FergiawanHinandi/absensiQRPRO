import React from 'react';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {DashboardScreen} from '../screens/dashboard/DashboardScreen';
import {ScanQRScreen} from '../screens/attendance/ScanQRScreen';
import {StudentHistoryScreen} from '../screens/student/StudentHistoryScreen';
import {StudentProfileScreen} from '../screens/student/StudentProfileScreen';
import {LeaderboardScreen} from '../screens/student/LeaderboardScreen';
import {BadgesScreen} from '../screens/student/BadgesScreen';
import {AnnouncementsScreen} from '../screens/common/AnnouncementsScreen';

export type StudentStackParamList = {
  StudentDashboard: undefined;
  ScanQR: undefined;
  StudentHistory: undefined;
  StudentProfile: undefined;
  Leaderboard: undefined;
  Badges: undefined;
  Announcements: undefined;
};

const Stack = createNativeStackNavigator<StudentStackParamList>();

export const StudentNavigator = () => (
  <Stack.Navigator
    screenOptions={{
      headerStyle: {backgroundColor: '#3B82F6'},
      headerTintColor: '#fff',
      headerTitleStyle: {fontWeight: 'bold'},
    }}>
    <Stack.Screen
      name="StudentDashboard"
      component={DashboardScreen}
      options={{title: 'Dashboard Siswa', headerLeft: () => null}}
    />
    <Stack.Screen
      name="ScanQR"
      component={ScanQRScreen}
      options={{headerShown: false}}
    />
    <Stack.Screen
      name="StudentHistory"
      component={StudentHistoryScreen}
      options={{title: 'Riwayat Absensi'}}
    />
    <Stack.Screen
      name="StudentProfile"
      component={StudentProfileScreen}
      options={{title: 'Profil Saya'}}
    />
    <Stack.Screen
      name="Leaderboard"
      component={LeaderboardScreen}
      options={{title: 'Leaderboard'}}
    />
    <Stack.Screen
      name="Badges"
      component={BadgesScreen}
      options={{title: 'Badge Saya'}}
    />
    <Stack.Screen
      name="Announcements"
      component={AnnouncementsScreen}
      options={{title: 'Pengumuman'}}
    />
  </Stack.Navigator>
);
