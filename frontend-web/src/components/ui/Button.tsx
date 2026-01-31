import React from 'react';
import type { ButtonHTMLAttributes } from 'react';
import { Loader2 } from 'lucide-react';

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    isLoading?: boolean;
    variant?: 'primary' | 'secondary' | 'danger';
}

export const Button: React.FC<ButtonProps> = ({
    children,
    isLoading,
    variant = 'primary',
    className = '',
    disabled,
    type = 'button', // default to button
    ...props
}) => {
    const baseStyles = "relative w-full flex justify-center py-2 px-4 border border-transparent text-sm font-medium rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors disabled:opacity-50 disabled:cursor-not-allowed";

    const variants = {
        primary: "text-white bg-blue-600 hover:bg-blue-700 focus:ring-blue-500",
        secondary: "text-blue-700 bg-blue-100 hover:bg-blue-200 focus:ring-blue-500",
        danger: "text-white bg-red-600 hover:bg-red-700 focus:ring-red-500"
    };

    console.log('[BUTTON] Rendered with type:', type);

    return (
        <button
            disabled={disabled || isLoading}
            className={`${baseStyles} ${variants[variant]} ${className}`}
            type={type}
            {...props}
        >
            {isLoading && <Loader2 className="w-4 h-4 mr-2 animate-spin" />}
            {children}
        </button>
    );
};
