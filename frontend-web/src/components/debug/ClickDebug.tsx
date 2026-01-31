import React from 'react';

interface ClickDebugProps {
  children: React.ReactNode;
  label?: string;
  onClick?: () => void;
}

export const ClickDebug: React.FC<ClickDebugProps> = ({ children, label, onClick }) => {
  const handleClick = (e: React.MouseEvent) => {
    console.log(`[CLICK DEBUG] ${label || 'Button'} clicked`, {
      target: e.target,
      currentTarget: e.currentTarget,
      timestamp: new Date().toISOString()
    });
    
    if (onClick) {
      onClick();
    }
  };

  return (
    <div 
      onClick={handleClick}
      style={{ cursor: 'pointer' }}
      className="click-debug-wrapper"
    >
      {children}
    </div>
  );
};