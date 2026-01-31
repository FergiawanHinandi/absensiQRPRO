import { useState, useEffect } from 'react';
import NetInfo from '@react-native-community/netinfo';

export const useNetworkStatus = () => {
  const [isOnline, setIsOnline] = useState<boolean | null>(null);

  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener(state => {
      setIsOnline(state.isConnected != null && state.isConnected && state.isInternetReachable != null && state.isInternetReachable);
    });

    return () => {
      unsubscribe();
    };
  }, []);

  return isOnline;
};
