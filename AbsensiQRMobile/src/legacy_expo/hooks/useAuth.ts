import { useMutation } from "@tanstack/react-query";
import Toast from "react-native-toast-message";
import { useAuthStore } from "../store/useAuthStore";
import api from "../services/api";

interface LoginCredentials {
  email: string;
  password: string;
  device_name: string;
}

const loginUser = async (credentials: LoginCredentials) => {
  const { data } = await api.post("/v1/auth/login", credentials);
  return data;
};

export const useAuth = () => {
  const { login: storeLogin } = useAuthStore();

  const loginMutation = useMutation({
    mutationFn: loginUser,
    onSuccess: (data, variables, context: any) => {
      if (data.token && data.user) {
        storeLogin(data.token, data.user);
        Toast.show({
          type: "success",
          text1: `Welcome, ${data.user.name}!`,
          text2: "You are now logged in.",
        });
        context?.onSuccess?.(); // Call the passed onSuccess callback
      } else {
        Toast.show({
          type: "error",
          text1: "Login Failed",
          text2: "Invalid response from server.",
        });
      }
    },
    onError: (error: any) => {
      const message =
        error.response?.data?.message || "An unexpected error occurred.";
      Toast.show({
        type: "error",
        text1: "Login Failed",
        text2: message,
      });
    },
  });

  return {
    login: (
      variables: LoginCredentials,
      options?: { onSuccess?: () => void },
    ) => {
      loginMutation.mutate(variables, options);
    },
    isLoading: loginMutation.isPending,
    isError: loginMutation.isError,
    error: loginMutation.error,
  };
};
