import React from 'react';

interface DarkThemeWrapperProps {
    children: React.ReactNode;
    title?: string;
    subtitle?: string;
}

/**
 * Wrapper component that automatically converts light theme pages to dark theme
 * by applying CSS class overrides
 */
export const DarkThemeWrapper: React.FC<DarkThemeWrapperProps> = ({ children, title, subtitle }) => {
    return (
        <div className="dark-theme-wrapper">
            {/* Header */}
            {title && (
                <div className="mb-6">
                    <h1 className="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-purple-500">
                        {title}
                    </h1>
                    {subtitle && <p className="text-gray-400 mt-2">{subtitle}</p>}
                </div>
            )}

            {/* Content with dark theme overrides */}
            <div className="dark-content">
                {children}
            </div>

            {/* Global CSS overrides for dark theme */}
            <style>{`
                .dark-theme-wrapper {
                    color: #E5E7EB;
                }

                /* Override light backgrounds */
                .dark-content .bg-white,
                .dark-content .bg-slate-50,
                .dark-content .bg-gray-50 {
                    background-color: #1F2937 !important;
                    border-color: #374151 !important;
                }

                /* Override light cards */
                .dark-content .bg-white {
                    background-color: #1F2937 !important;
                }

                /* Override borders */
                .dark-content .border-slate-200,
                .dark-content .border-slate-300,
                .dark-content .border-gray-200,
                .dark-content .border-gray-300 {
                    border-color: #374151 !important;
                }

                /* Override text colors */
                .dark-content .text-slate-900,
                .dark-content .text-gray-900 {
                    color: #F3F4F6 !important;
                }

                .dark-content .text-slate-700,
                .dark-content .text-gray-700 {
                    color: #D1D5DB !important;
                }

                .dark-content .text-slate-600,
                .dark-content .text-gray-600 {
                    color: #9CA3AF !important;
                }

                .dark-content .text-slate-500,
                .dark-content .text-gray-500 {
                    color: #6B7280 !important;
                }

                .dark-content .text-slate-400,
                .dark-content .text-gray-400 {
                    color: #6B7280 !important;
                }

                /* Override input fields */
                .dark-content input[type="text"],
                .dark-content input[type="email"],
                .dark-content input[type="password"],
                .dark-content input[type="number"],
                .dark-content input[type="date"],
                .dark-content input[type="search"],
                .dark-content select,
                .dark-content textarea {
                    background-color: #111827 !important;
                    border-color: #4B5563 !important;
                    color: #F3F4F6 !important;
                }

                .dark-content input::placeholder,
                .dark-content textarea::placeholder {
                    color: #6B7280 !important;
                }

                /* Override buttons */
                .dark-content button:not(.bg-blue-600):not(.bg-red-600):not(.bg-green-600) {
                    background-color: #374151 !important;
                    border-color: #4B5563 !important;
                    color: #D1D5DB !important;
                }

                .dark-content button:hover:not(.bg-blue-600):not(.bg-red-600):not(.bg-green-600) {
                    background-color: #4B5563 !important;
                }

                /* Override table */
                .dark-content table {
                    background-color: #1F2937 !important;
                }

                .dark-content thead {
                    background-color: #111827 !important;
                }

                .dark-content th {
                    color: #D1D5DB !important;
                    border-color: #374151 !important;
                }

                .dark-content td {
                    color: #9CA3AF !important;
                    border-color: #374151 !important;
                }

                .dark-content tr:hover {
                    background-color: #374151 !important;
                }

                /* Override badges */
                .dark-content .bg-blue-50 {
                    background-color: rgba(59, 130, 246, 0.2) !important;
                    color: #93C5FD !important;
                    border-color: rgba(59, 130, 246, 0.3) !important;
                }

                .dark-content .bg-green-50 {
                    background-color: rgba(34, 197, 94, 0.2) !important;
                    color: #86EFAC !important;
                    border-color: rgba(34, 197, 94, 0.3) !important;
                }

                .dark-content .bg-red-50 {
                    background-color: rgba(239, 68, 68, 0.2) !important;
                    color: #FCA5A5 !important;
                    border-color: rgba(239, 68, 68, 0.3) !important;
                }

                .dark-content .bg-amber-50,
                .dark-content .bg-yellow-50 {
                    background-color: rgba(245, 158, 11, 0.2) !important;
                    color: #FCD34D !important;
                    border-color: rgba(245, 158, 11, 0.3) !important;
                }

                .dark-content .bg-purple-50 {
                    background-color: rgba(168, 85, 247, 0.2) !important;
                    color: #C4B5FD !important;
                    border-color: rgba(168, 85, 247, 0.3) !important;
                }

                /* Override modals */
                .dark-content .bg-black\\/50 {
                    background-color: rgba(0, 0, 0, 0.7) !important;
                }

                /* Override shadows */
                .dark-content .shadow-sm,
                .dark-content .shadow,
                .dark-content .shadow-md,
                .dark-content .shadow-lg {
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2) !important;
                }

                /* Override hover states */
                .dark-content .hover\\:bg-slate-50:hover,
                .dark-content .hover\\:bg-gray-50:hover {
                    background-color: #374151 !important;
                }

                .dark-content .hover\\:bg-slate-100:hover,
                .dark-content .hover\\:bg-gray-100:hover {
                    background-color: #4B5563 !important;
                }

                /* Override disabled states */
                .dark-content button:disabled,
                .dark-content input:disabled,
                .dark-content select:disabled {
                    opacity: 0.5 !important;
                    cursor: not-allowed !important;
                }

                /* Override scrollbar */
                .dark-content ::-webkit-scrollbar {
                    width: 8px;
                    height: 8px;
                }

                .dark-content ::-webkit-scrollbar-track {
                    background: #1F2937;
                }

                .dark-content ::-webkit-scrollbar-thumb {
                    background: #4B5563;
                    border-radius: 4px;
                }

                .dark-content ::-webkit-scrollbar-thumb:hover {
                    background: #6B7280;
                }

                /* Ensure rounded corners are preserved */
                .dark-content .rounded-lg,
                .dark-content .rounded-xl {
                    border-radius: inherit !important;
                }

                /* Override specific component backgrounds */
                .dark-content .min-h-screen {
                    background: transparent !important;
                }

                /* Fix pagination */
                .dark-content .bg-white.rounded-lg.shadow-sm {
                    background-color: #1F2937 !important;
                }
            `}</style>
        </div>
    );
};
