import React from 'react';
import { Loader2 } from 'lucide-react';

interface LoadingProps {
    text?: string;
    fullScreen?: boolean;
}

const Loading: React.FC<LoadingProps> = ({ text = 'Memuat...', fullScreen = false }) => {
    const content = (
        <div className="flex flex-col items-center justify-center p-4 text-center">
            <Loader2 className="w-10 h-10 text-blue-600 animate-spin mb-3" />
            <p className="text-gray-600 font-medium animate-pulse">{text}</p>
        </div>
    );

    if (fullScreen) {
        return (
            <div className="fixed inset-0 bg-white/80 backdrop-blur-sm z-50 flex items-center justify-center">
                {content}
            </div>
        );
    }

    return <div className="py-12">{content}</div>;
};

export default Loading;
