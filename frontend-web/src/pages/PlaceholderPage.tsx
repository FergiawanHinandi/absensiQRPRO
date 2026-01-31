import React from 'react';
import { Construction } from 'lucide-react';

interface PlaceholderProps {
    title: string;
}

const PlaceholderPage: React.FC<PlaceholderProps> = ({ title }) => {
    return (
        <div className="flex flex-col items-center justify-center min-h-[50vh] text-center p-8">
            <div className="bg-blue-50 p-6 rounded-full mb-6">
                <Construction className="w-12 h-12 text-blue-600" />
            </div>
            <h1 className="text-2xl font-bold text-gray-900 mb-2">{title}</h1>
            <p className="text-gray-500 max-w-md">
                Halaman ini sedang dalam tahap pengembangan. Fitur lengkap akan segera tersedia.
            </p>
        </div>
    );
};

export default PlaceholderPage;
