import React from 'react';
import { LoginForm } from '../components/LoginForm';

export const LoginPage: React.FC = () => {
    return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
            <div className="max-w-md w-full space-y-8 bg-white p-8 rounded-lg shadow-md border border-gray-100">
                <div className="text-center">
                    <h2 className="mt-2 text-3xl font-extrabold text-gray-900">
                        AbsensiQR Pro
                    </h2>
                    <p className="mt-2 text-sm text-gray-600">
                        Silakan login untuk melanjutkan
                    </p>
                </div>

                <LoginForm />
            </div>
        </div>
    );
};
