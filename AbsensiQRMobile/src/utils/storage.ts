import EncryptedStorage from 'react-native-encrypted-storage';

export const StorageKeys = {
    TOKEN: '@auth_token',
    USER: '@auth_user',
};

export const storage = {
    async setToken(token: string) {
        try {
            await EncryptedStorage.setItem(StorageKeys.TOKEN, token);
        } catch (error) {
            console.error('Error setting token:', error);
        }
    },

    async getToken(): Promise<string | null> {
        try {
            return await EncryptedStorage.getItem(StorageKeys.TOKEN);
        } catch (error) {
            console.error('Error getting token:', error);
            return null;
        }
    },

    async removeToken() {
        try {
            await EncryptedStorage.removeItem(StorageKeys.TOKEN);
        } catch (error) {
            console.error('Error removing token:', error);
        }
    },

    async setUser(user: any) {
        try {
            await EncryptedStorage.setItem(StorageKeys.USER, JSON.stringify(user));
        } catch (error) {
            console.error('Error setting user:', error);
        }
    },

    async getUser() {
        try {
            const data = await EncryptedStorage.getItem(StorageKeys.USER);
            return data ? JSON.parse(data) : null;
        } catch (error) {
            console.error('Error getting user:', error);
            return null;
        }
    },

    async removeUser() {
        try {
            await EncryptedStorage.removeItem(StorageKeys.USER);
        } catch (error) {
            console.error('Error removing user:', error);
        }
    },

    async clear() {
        try {
            await EncryptedStorage.clear();
        } catch (error) {
            console.error('Error clearing storage:', error);
        }
    }
};
