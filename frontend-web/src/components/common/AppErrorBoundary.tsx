import React, { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
    children: ReactNode;
}

interface State {
    hasError: boolean;
    error?: Error;
}

export class AppErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false
    };

    public static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('App Error Boundary caught an error:', error, errorInfo);
        
        // Log to monitoring service in production
        if (import.meta.env.PROD) {
            const errorId = Date.now().toString(36);
            console.error(`Error ID: ${errorId}`, {
                message: error.message,
                timestamp: new Date().toISOString(),
                // Don't log stack trace in production for security
            });
        }
    }

    public render() {
        if (this.state.hasError) {
            return (
                <div className="min-h-screen flex items-center justify-center bg-gray-50">
                    <div className="max-w-md w-full bg-white shadow-lg rounded-lg p-6">
                        <div className="flex items-center justify-center w-12 h-12 mx-auto bg-red-100 rounded-full">
                            <svg className="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z" />
                            </svg>
                        </div>
                        <div className="mt-4 text-center">
                            <h3 className="text-lg font-medium text-gray-900">
                                Terjadi Kesalahan
                            </h3>
                            <p className="mt-2 text-sm text-gray-500">
                                Aplikasi mengalami kesalahan yang tidak terduga. Silakan muat ulang halaman atau hubungi administrator.
                            </p>
                            <div className="mt-6 flex space-x-3">
                                <button
                                    onClick={() => window.location.reload()}
                                    className="flex-1 bg-blue-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                >
                                    Muat Ulang
                                </button>
                                <button
                                    onClick={() => window.location.href = '/login'}
                                    className="flex-1 bg-gray-300 text-gray-700 px-4 py-2 rounded-md text-sm font-medium hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
                                >
                                    Kembali ke Login
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}