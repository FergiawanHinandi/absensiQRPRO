import React, {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback,
  ReactNode,
} from 'react';
import AuthService from '../services/AuthService';
import SecureStorage from '../services/SecureStorage';

export interface User {
  id: number;
  name: string;
  username: string;
  email: string;
  role_type:
    | 'student'
    | 'teacher'
    | 'homeroom_teacher'
    | 'admin'
    | 'school_admin'
    | 'super_admin'
    | 'parent';
  school_id: number;
  school_name?: string;
}

interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
}

interface AuthContextType extends AuthState {
  login: (
    username: string,
    password: string,
  ) => Promise<{success: boolean; user?: User; error?: string}>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const useAuth = (): AuthContextType => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

interface AuthProviderProps {
  children: ReactNode;
}

export const AuthProvider: React.FC<AuthProviderProps> = ({children}) => {
  const [state, setState] = useState<AuthState>({
    user: null,
    isAuthenticated: false,
    isLoading: true,
  });

  // Check existing auth on mount
  useEffect(() => {
    const checkAuth = async () => {
      try {
        const hasTokens = await AuthService.isAuthenticated();
        if (hasTokens) {
          const user = await AuthService.getCurrentUser();
          if (user) {
            setState({
              user: user as User,
              isAuthenticated: true,
              isLoading: false,
            });
            return;
          }
        }
      } catch (error) {
        console.error('[AuthContext] Failed to check auth:', error);
      }

      setState({
        user: null,
        isAuthenticated: false,
        isLoading: false,
      });
    };

    checkAuth();
  }, []);

  const login = useCallback(async (username: string, password: string) => {
    try {
      const result = await AuthService.login(username, password);

      if (result.success && result.user) {
        const user = result.user as User;
        setState({
          user,
          isAuthenticated: true,
          isLoading: false,
        });
        return {success: true, user};
      }

      return {success: false, error: 'Login gagal'};
    } catch (error: any) {
      const errorMsg =
        error.response?.data?.message ||
        error.message ||
        'Gagal login. Periksa koneksi dan coba lagi.';
      return {success: false, error: errorMsg};
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await AuthService.logout();
    } catch (error) {
      console.error('[AuthContext] Logout error:', error);
    } finally {
      setState({
        user: null,
        isAuthenticated: false,
        isLoading: false,
      });
    }
  }, []);

  const refreshUser = useCallback(async () => {
    try {
      const user = await SecureStorage.getUserData();
      if (user) {
        setState(prev => ({...prev, user: user as User}));
      }
    } catch (error) {
      console.error('[AuthContext] Failed to refresh user:', error);
    }
  }, []);

  return (
    <AuthContext.Provider value={{...state, login, logout, refreshUser}}>
      {children}
    </AuthContext.Provider>
  );
};
