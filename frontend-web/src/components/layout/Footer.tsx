import React from 'react';

export const Footer: React.FC = () => {
    return (
        <footer className="bg-white border-t border-gray-200 mt-auto">
            <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <div className="flex flex-col md:flex-row justify-between items-center">
                    <div className="text-sm text-gray-600">
                        © {new Date().getFullYear()} <span className="font-semibold text-blue-600">AbsensiQR Pro</span>. All rights reserved.
                    </div>
                    <div className="flex gap-6 mt-4 md:mt-0">
                        <a href="#" className="text-sm text-gray-600 hover:text-blue-600 transition-colors">
                            Bantuan
                        </a>
                        <a href="#" className="text-sm text-gray-600 hover:text-blue-600 transition-colors">
                            Kebijakan Privasi
                        </a>
                        <a href="#" className="text-sm text-gray-600 hover:text-blue-600 transition-colors">
                            Syarat & Ketentuan
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    );
};
