import React from 'react';
import {NavigationContainer} from '@react-navigation/native';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {View, Text, ActivityIndicator, StyleSheet} from 'react-native';
import {useAuth} from '../contexts/AuthContext';
import {LoginScreen} from '../screens/auth/LoginScreen';
import {AdminNotSupportedScreen} from '../screens/common/AdminNotSupportedScreen';
import {StudentNavigator} from './StudentNavigator';
import {TeacherNavigator} from './TeacherNavigator';
import {ParentNavigator} from './ParentNavigator';

const Stack = createNativeStackNavigator();

const LoadingScreen = () => (
  <View style={styles.loadingContainer}>
    <ActivityIndicator size="large" color="#3B82F6" />
    <Text style={styles.loadingText}>Memuat...</Text>
  </View>
);

const getMainScreen = (roleType: string) => {
  switch (roleType) {
    case 'teacher':
    case 'homeroom_teacher':
      return TeacherNavigator;
    case 'parent':
      return ParentNavigator;
    case 'admin':
    case 'school_admin':
    case 'super_admin':
      // Role admin hanya bisa diakses via web dashboard
      return AdminNotSupportedScreen;
    case 'student':
    default:
      return StudentNavigator;
  }
};

export const RootNavigator = () => {
  const {isAuthenticated, isLoading, user} = useAuth();

  if (isLoading) {
    return <LoadingScreen />;
  }

  return (
    <NavigationContainer>
      <Stack.Navigator screenOptions={{headerShown: false}}>
        {isAuthenticated && user ? (
          <Stack.Screen name="Main" component={getMainScreen(user.role_type)} />
        ) : (
          <Stack.Screen name="Login" component={LoginScreen} />
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
};

const styles = StyleSheet.create({
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#F3F4F6',
  },
  loadingText: {
    marginTop: 16,
    fontSize: 16,
    color: '#6B7280',
  },
});
