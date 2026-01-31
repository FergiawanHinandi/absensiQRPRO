import { useEffect } from 'react';
import * as Google from 'expo-auth-session/providers/google';
import * as WebBrowser from 'expo-web-browser';
import { useMutation } from '@tanstack/react-query';
import Toast from 'react-native-toast-message';
import { useAuthStore } from '../store/useAuthStore';
import api from '../services/api';

WebBrowser.maybeCompleteAuthSession();

const googleLogin = async (accessToken: string) => {
    // This endpoint on your backend needs to handle the Google token
    const { data } = await api.post('/auth/google/callback', {
        token: accessToken,
    });
    return data;
};

export const useGoogleAuth = () => {
    const { login: storeLogin } = useAuthStore();

    // TODO: Replace these with your own client IDs
    const [request, response, promptAsync] = Google.useAuthRequest({
        expoClientId: 'YOUR_EXPO_GO_CLIENT_ID.apps.googleusercontent.com',
        iosClientId: 'YOUR_IOS_CLIENT_ID.apps.googleusercontent.com',
        androidClientId: 'YOUR_ANDROID_CLIENT_ID.apps.googleusercontent.com',
        webClientId: 'YOUR_WEB_CLIENT_ID.apps.googleusercontent.com', // Optional
    });

    const mutation = useMutation({
        mutationFn: googleLogin,
        onSuccess: (data) => {
            if (data.token && data.user) {
                storeLogin(data.token, data.user);
                Toast.show({
                    type: 'success',
                    text1: `Welcome, ${data.user.name}!`,
                    text2: 'Logged in with Google.',
                });
            } else {
                Toast.show({
                    type: 'error',
                    text1: 'Login Failed',
                    text2: 'Invalid response from server.',
                });
            }
        },
        onError: (error: any) => {
            const message = error.response?.data?.message || 'Google login failed.';
            Toast.show({
                type: 'error',
                text1: 'Login Failed',
                text2: message,
            });
        },
    });

    useEffect(() => {
        if (response?.type === 'success') {
            const { authentication } = response;
            if (authentication?.accessToken) {
                mutation.mutate(authentication.accessToken);
            }
        }
    }, [response]);

    return {
        promptGoogleLogin: () => {
            promptAsync();
        },
        isLoading: mutation.isPending,
    };
};
