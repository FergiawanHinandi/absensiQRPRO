/**
 * Error Handler Utility
 * Mengubah error teknis menjadi pesan yang manusiawi
 */

interface ApiError {
    response?: {
        status: number;
        data?: {
            message?: string;
            error?: string;
        };
    };
    message?: string;
}

// Mapping error teknis ke pesan user-friendly
const ERROR_MESSAGES: Record<string, string> = {
    'Network Error': 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.',
    'Request failed with status code 401': 'Sesi Anda telah berakhir. Silakan login kembali.',
    'Request failed with status code 403': 'Anda tidak memiliki akses untuk melakukan ini.',
    'Request failed with status code 404': 'Data tidak ditemukan.',
    'Request failed with status code 422': 'Data yang Anda masukkan tidak valid.',
    'Request failed with status code 429': 'Terlalu banyak permintaan. Mohon tunggu sebentar.',
    'Request failed with status code 500': 'Server sedang sibuk. Silakan coba beberapa saat lagi.',
};

export const getErrorMessage = (error: unknown): string => {
    const apiError = error as ApiError;

    // 1. Cek pesan spesifik dari Backend (Domain Exception)
    if (apiError.response?.data?.message) {
        return apiError.response.data.message;
    }

    if (apiError.response?.data?.error) {
        return apiError.response.data.error;
    }

    // 2. Cek pesan error standard axios/mapping manual
    if (apiError.message && ERROR_MESSAGES[apiError.message]) {
        return ERROR_MESSAGES[apiError.message];
    }

    // 3. Fallback untuk status code tanpa pesan spesifik
    const status = apiError.response?.status;
    if (status) {
        const statusKey = `Request failed with status code ${status}`;
        if (ERROR_MESSAGES[statusKey]) {
            return ERROR_MESSAGES[statusKey];
        }
    }

    // 4. Fallback terakhir (Error benar-benar teknis)
    // Jangan tampilkan stack trace atau "undefined is not a function"
    return 'Terjadi kendala teknis. Tim kami sedang menanganinya.';
};
