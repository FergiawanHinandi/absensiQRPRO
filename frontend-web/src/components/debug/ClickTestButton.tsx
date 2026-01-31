import React from 'react';

interface ClickTestButtonProps {
  label: string;
  onClick?: () => void;
  className?: string;
}

export const ClickTestButton: React.FC<ClickTestButtonProps> = ({ 
  label, 
  onClick, 
  className = '' 
}) => {
  const handleClick = (e: React.MouseEvent) => {
    e.preventDefault();
    e.stopPropagation();
    
    console.log(`[CLICK TEST] Button "${label}" clicked at:`, new Date().toISOString());
    console.log('[CLICK TEST] Event details:', {
      target: e.target,
      currentTarget: e.currentTarget,
      clientX: e.clientX,
      clientY: e.clientY,
      button: e.button,
      buttons: e.buttons
    });
    
    if (onClick) {
      try {
        onClick();
        console.log(`[CLICK TEST] onClick handler executed successfully for "${label}"`);
      } catch (error) {
        console.error(`[CLICK TEST] onClick handler failed for "${label}":`, error);
      }
    } else {
      console.warn(`[CLICK TEST] No onClick handler provided for "${label}"`);
    }
  };

  return (
    <button
      onClick={handleClick}
      className={`
        px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 
        transition-colors cursor-pointer border-2 border-blue-600
        focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2
        ${className}
      `}
      style={{ 
        pointerEvents: 'auto',
        userSelect: 'none',
        touchAction: 'manipulation'
      }}
      type="button"
    >
      {label}
    </button>
  );
};