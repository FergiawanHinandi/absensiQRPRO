import React, { useEffect } from "react";
import { NavigationContainer } from "@react-navigation/native";
import { createStackNavigator } from "@react-navigation/stack";
import { useAuthStore } from "../store/useAuthStore";
import { View, ActivityIndicator } from "react-native";

// Components
import { OfflineQueueManager } from "../components/common/OfflineQueueManager";

// Auth Screens
// Note: Folder name is 'Auth' (capital A) based on typical convention, but let's stick to what works or check.
// Previous import was `../screens/auth/LoginScreen`.
// LS showed `d:\Project\absensiQRPro\mobile-app\src\screens\Auth` (Capital A).
import { LoginScreen } from "../screens/Auth/LoginScreen";

// Student Screens
import { StudentDashboard } from "../screens/Student/StudentDashboard";
import { ScanQRScreen } from "../screens/Student/ScanQRScreen";
import { AttendanceHistoryScreen } from "../screens/Student/AttendanceHistoryScreen";

// Teacher Screens
import { TeacherDashboard } from "../screens/Teacher/TeacherDashboard";
import { GenerateQRScreen } from "../screens/Teacher/GenerateQRScreen";

// Parent Screens
import { ParentDashboard } from "../screens/Parent/ParentDashboard";
import { ChildAttendanceHistoryScreen } from "../screens/Parent/ChildAttendanceHistoryScreen";

const Stack = createStackNavigator();

const AppNavigator = () => {
  const { isAuthenticated, isLoading, checkAuth, user } = useAuthStore();

  useEffect(() => {
    checkAuth();
  }, []);

  if (isLoading) {
    return (
      <View style={{ flex: 1, justifyContent: "center", alignItems: "center" }}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  const getInitialRouteName = () => {
    if (!user) return "Login";
    switch (user.role_type) {
        case 'student': return 'StudentDashboard';
        case 'teacher': 
        case 'homeroom_teacher': return 'TeacherDashboard';
        case 'parent': return 'ParentDashboard';
        default: return 'StudentDashboard';
    }
  };

  return (
    <NavigationContainer>
      {isAuthenticated && <OfflineQueueManager />}
      <Stack.Navigator screenOptions={{ headerShown: true }}>
        {isAuthenticated ? (
          <>
            {/* Role Based Routing Groups */}
            {(user?.role_type === 'student' || !user?.role_type) && (
                <>
                    <Stack.Screen
                        name="StudentDashboard"
                        component={StudentDashboard}
                        options={{ title: "Dashboard Siswa", headerShown: false }}
                    />
                    <Stack.Screen
                        name="ScanQR"
                        component={ScanQRScreen}
                        options={{ title: "Scan QR Absensi" }}
                    />
                    <Stack.Screen
                        name="History"
                        component={AttendanceHistoryScreen}
                        options={{ title: "Riwayat Absensi" }}
                    />
                </>
            )}

            {(user?.role_type === 'teacher' || user?.role_type === 'homeroom_teacher') && (
                <>
                    <Stack.Screen
                        name="TeacherDashboard"
                        component={TeacherDashboard}
                        options={{ title: "Dashboard Guru", headerShown: false }}
                    />
                    <Stack.Screen
                        name="GenerateQR"
                        component={GenerateQRScreen}
                        options={{ title: "Buat QR Absensi", headerShown: false }}
                    />
                </>
            )}

            {user?.role_type === 'parent' && (
                <>
                    <Stack.Screen
                        name="ParentDashboard"
                        component={ParentDashboard}
                        options={{ title: "Dashboard Orang Tua", headerShown: false }}
                    />
                    <Stack.Screen
                        name="ChildHistory"
                        component={ChildAttendanceHistoryScreen}
                        options={{ title: "Riwayat Anak" }}
                    />
                </>
            )}
          </>
        ) : (
          <Stack.Screen
            name="Login"
            component={LoginScreen}
            options={{ headerShown: false }}
          />
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
};

export default AppNavigator;
