import React from 'react';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {TeacherDashboardScreen} from '../screens/teacher/TeacherDashboardScreen';
import {GenerateQRScreen} from '../screens/teacher/GenerateQRScreen';
import {TeacherScheduleScreen} from '../screens/teacher/TeacherScheduleScreen';
import {TeacherProfileScreen} from '../screens/teacher/TeacherProfileScreen';
import {DeviceManagementScreen} from '../screens/teacher/DeviceManagementScreen';
import {AnnouncementsScreen} from '../screens/common/AnnouncementsScreen';

export type TeacherStackParamList = {
  TeacherDashboard: undefined;
  GenerateQR: {scheduleId?: number} | undefined;
  TeacherSchedule: undefined;
  TeacherProfile: undefined;
  DeviceManagement: undefined;
  Announcements: undefined;
};

const Stack = createNativeStackNavigator<TeacherStackParamList>();

export const TeacherNavigator = () => (
  <Stack.Navigator
    screenOptions={{
      headerStyle: {backgroundColor: '#10B981'},
      headerTintColor: '#fff',
      headerTitleStyle: {fontWeight: 'bold'},
    }}>
    <Stack.Screen
      name="TeacherDashboard"
      component={TeacherDashboardScreen}
      options={{title: 'Dashboard Guru', headerLeft: () => null}}
    />
    <Stack.Screen
      name="GenerateQR"
      component={GenerateQRScreen}
      options={{title: 'Generate QR Absensi'}}
    />
    <Stack.Screen
      name="TeacherSchedule"
      component={TeacherScheduleScreen}
      options={{title: 'Jadwal Mengajar'}}
    />
    <Stack.Screen
      name="TeacherProfile"
      component={TeacherProfileScreen}
      options={{title: 'Profil Saya'}}
    />
    <Stack.Screen
      name="DeviceManagement"
      component={DeviceManagementScreen}
      options={{title: 'Manajemen Perangkat'}}
    />
    <Stack.Screen
      name="Announcements"
      component={AnnouncementsScreen}
      options={{title: 'Pengumuman'}}
    />
  </Stack.Navigator>
);
