import React from 'react';
import {createNativeStackNavigator} from '@react-navigation/native-stack';
import {ParentDashboardScreen} from '../screens/parent/ParentDashboardScreen';
import {ParentChildDetailScreen} from '../screens/parent/ParentChildDetailScreen';
import {ParentProfileScreen} from '../screens/parent/ParentProfileScreen';

export type ParentStackParamList = {
  ParentDashboard: undefined;
  ParentChildDetail: {childId: number; childName: string};
  ParentProfile: undefined;
};

const Stack = createNativeStackNavigator<ParentStackParamList>();

export const ParentNavigator = () => (
  <Stack.Navigator
    screenOptions={{
      headerStyle: {backgroundColor: '#8B5CF6'},
      headerTintColor: '#fff',
      headerTitleStyle: {fontWeight: 'bold'},
    }}>
    <Stack.Screen
      name="ParentDashboard"
      component={ParentDashboardScreen}
      options={{title: 'Dashboard Orang Tua', headerLeft: () => null}}
    />
    <Stack.Screen
      name="ParentChildDetail"
      component={ParentChildDetailScreen}
      options={({route}) => ({title: route.params.childName})}
    />
    <Stack.Screen
      name="ParentProfile"
      component={ParentProfileScreen}
      options={{title: 'Profil Saya'}}
    />
  </Stack.Navigator>
);
