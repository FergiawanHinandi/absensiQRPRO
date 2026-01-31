import { create } from "zustand";
import * as SecureStore from "expo-secure-store";
import Toast from "react-native-toast-message";
import api from "../services/api";

const BIOMETRIC_ENABLED_KEY = "biometric_enabled";
const BIOMETRIC_CREDENTIALS_KEY = "biometric_credentials";

interface User {
  id: number;
  name: string;
  role_type: string;
  school_id: number;
  device_id?: string;
}

interface AuthState {
  user: User | null;
  token: string | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  isBiometricEnabled: boolean;

  login: (token: string, user: User) => Promise<void>;
  logout: () => Promise<void>;
  checkAuth: () => Promise<void>;
  enableBiometrics: (credentials: {
    email: string;
    password: string;
  }) => Promise<void>;
  disableBiometrics: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  token: null,
  isAuthenticated: false,
  isLoading: true,
  isBiometricEnabled: false,

  login: async (token, user) => {
    await SecureStore.setItemAsync("authToken", token);
    set({ token, user, isAuthenticated: true, isLoading: false });
  },

  logout: async () => {
    await SecureStore.deleteItemAsync("authToken");
    api.defaults.headers.Authorization = null;
    set({ token: null, user: null, isAuthenticated: false, isLoading: false });
  },

  checkAuth: async () => {
    // Check for biometric status first
    const biometricStatus = await SecureStore.getItemAsync(
      BIOMETRIC_ENABLED_KEY,
    );
    set({ isBiometricEnabled: biometricStatus === "true" });

    try {
      const token = await SecureStore.getItemAsync("authToken");
      if (!token) {
        set({ isLoading: false, isAuthenticated: false });
        return;
      }

      set({ token });
      const response = await api.get("/auth/me");
      if (response.data) {
        set({ user: response.data, isAuthenticated: true, isLoading: false });
      } else {
        get().logout();
      }
    } catch (error) {
      get().logout();
    }
  },

  enableBiometrics: async (credentials) => {
    await SecureStore.setItemAsync(
      BIOMETRIC_CREDENTIALS_KEY,
      JSON.stringify(credentials),
    );
    await SecureStore.setItemAsync(BIOMETRIC_ENABLED_KEY, "true");
    set({ isBiometricEnabled: true });
    Toast.show({
      type: "success",
      text1: "Biometric Login Enabled",
    });
  },

  disableBiometrics: async () => {
    await SecureStore.deleteItemAsync(BIOMETRIC_CREDENTIALS_KEY);
    await SecureStore.setItemAsync(BIOMETRIC_ENABLED_KEY, "false");
    set({ isBiometricEnabled: false });
  },
}));
