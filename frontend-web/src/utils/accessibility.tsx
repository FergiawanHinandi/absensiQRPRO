import React, { useEffect, useRef, useCallback } from 'react';

/**
 * Accessibility Utilities and Components
 * Provides ARIA support, keyboard navigation, and focus management
 */

// ============================================================
// SKIP LINK COMPONENT
// ============================================================

interface SkipLinkProps {
  mainId?: string;
  label?: string;
}

export const SkipLink: React.FC<SkipLinkProps> = ({
  mainId = 'main-content',
  label = 'Langsung ke konten utama',
}) => (
  <a
    href={`#${mainId}`}
    className="skip-link sr-only focus:not-sr-only focus:fixed focus:top-0 focus:left-0 focus:z-50 focus:bg-blue-600 focus:text-white focus:p-4 focus:outline-none"
  >
    {label}
  </a>
);

// ============================================================
// VISUALLY HIDDEN (Screen Reader Only)
// ============================================================

interface VisuallyHiddenProps extends React.ComponentPropsWithoutRef<'span'> {
  as?: React.ElementType;
}

export const VisuallyHidden: React.FC<VisuallyHiddenProps> = ({
  children,
  as: Component = 'span',
  ...props
}) => {
  return <Component className="sr-only" {...props}>{children}</Component>;
};

// ============================================================
// FOCUS TRAP COMPONENT
// ============================================================

interface FocusTrapProps {
  children: React.ReactNode;
  active?: boolean;
  initialFocus?: React.RefObject<HTMLElement>;
  returnFocus?: boolean;
  className?: string;
}

export const FocusTrap: React.FC<FocusTrapProps> = ({
  children,
  active = true,
  initialFocus,
  returnFocus = true,
  className,
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const previousActiveElement = useRef<Element | null>(null);

  const getFocusableElements = useCallback(() => {
    if (!containerRef.current) return [];

    const selectors = [
      'button:not([disabled])',
      'a[href]',
      'input:not([disabled])',
      'select:not([disabled])',
      'textarea:not([disabled])',
      '[tabindex]:not([tabindex="-1"])',
    ];

    return Array.from(
      containerRef.current.querySelectorAll<HTMLElement>(selectors.join(','))
    );
  }, []);

  useEffect(() => {
    if (!active) return;

    previousActiveElement.current = document.activeElement;

    // Set initial focus
    if (initialFocus?.current) {
      initialFocus.current.focus();
    } else {
      const focusable = getFocusableElements();
      if (focusable.length > 0) {
        focusable[0].focus();
      }
    }

    return () => {
      if (returnFocus && previousActiveElement.current instanceof HTMLElement) {
        previousActiveElement.current.focus();
      }
    };
  }, [active, initialFocus, returnFocus, getFocusableElements]);

  useEffect(() => {
    if (!active) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Tab') return;

      const focusable = getFocusableElements();
      if (focusable.length === 0) return;

      const firstElement = focusable[0];
      const lastElement = focusable[focusable.length - 1];

      if (e.shiftKey) {
        if (document.activeElement === firstElement) {
          e.preventDefault();
          lastElement.focus();
        }
      } else {
        if (document.activeElement === lastElement) {
          e.preventDefault();
          firstElement.focus();
        }
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [active, getFocusableElements]);

  return (
    <div ref={containerRef} className={className}>
      {children}
    </div>
  );
};

// ============================================================
// KEYBOARD NAVIGATION HOOK
// ============================================================

type NavigationDirection = 'horizontal' | 'vertical' | 'both';

interface UseKeyboardNavigationOptions {
  direction?: NavigationDirection;
  wrap?: boolean;
  onSelect?: (index: number) => void;
  onEscape?: () => void;
}

export function useKeyboardNavigation(
  itemCount: number,
  options: UseKeyboardNavigationOptions = {}
) {
  const { direction = 'vertical', wrap = true, onSelect, onEscape } = options;
  const [activeIndex, setActiveIndex] = React.useState(0);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      let nextIndex = activeIndex;

      const moveNext = () => {
        nextIndex = wrap
          ? (activeIndex + 1) % itemCount
          : Math.min(activeIndex + 1, itemCount - 1);
      };

      const movePrev = () => {
        nextIndex = wrap
          ? (activeIndex - 1 + itemCount) % itemCount
          : Math.max(activeIndex - 1, 0);
      };

      switch (e.key) {
        case 'ArrowDown':
          if (direction === 'vertical' || direction === 'both') {
            e.preventDefault();
            moveNext();
          }
          break;
        case 'ArrowUp':
          if (direction === 'vertical' || direction === 'both') {
            e.preventDefault();
            movePrev();
          }
          break;
        case 'ArrowRight':
          if (direction === 'horizontal' || direction === 'both') {
            e.preventDefault();
            moveNext();
          }
          break;
        case 'ArrowLeft':
          if (direction === 'horizontal' || direction === 'both') {
            e.preventDefault();
            movePrev();
          }
          break;
        case 'Home':
          e.preventDefault();
          nextIndex = 0;
          break;
        case 'End':
          e.preventDefault();
          nextIndex = itemCount - 1;
          break;
        case 'Enter':
        case ' ':
          e.preventDefault();
          onSelect?.(activeIndex);
          break;
        case 'Escape':
          e.preventDefault();
          onEscape?.();
          break;
        default:
          return;
      }

      setActiveIndex(nextIndex);
    },
    [activeIndex, itemCount, direction, wrap, onSelect, onEscape]
  );

  return {
    activeIndex,
    setActiveIndex,
    handleKeyDown,
    getItemProps: (index: number) => ({
      tabIndex: index === activeIndex ? 0 : -1,
      'aria-selected': index === activeIndex,
      onFocus: () => setActiveIndex(index),
    }),
  };
}

// ============================================================
// LIVE REGION COMPONENT
// ============================================================

type LiveRegionPoliteness = 'polite' | 'assertive' | 'off';

interface LiveRegionProps {
  message: string;
  politeness?: LiveRegionPoliteness;
  atomic?: boolean;
  className?: string;
}

export const LiveRegion: React.FC<LiveRegionProps> = ({
  message,
  politeness = 'polite',
  atomic = true,
  className = '',
}) => (
  <div
    aria-live={politeness}
    aria-atomic={atomic}
    className={`sr-only ${className}`}
    role={politeness === 'assertive' ? 'alert' : 'status'}
  >
    {message}
  </div>
);

// ============================================================
// ANNOUNCER HOOK
// ============================================================

export function useAnnouncer() {
  const [announcement, setAnnouncement] = React.useState('');
  const [politeness, setPoliteness] = React.useState<LiveRegionPoliteness>('polite');

  const announce = useCallback((message: string, priority: LiveRegionPoliteness = 'polite') => {
    // Clear first to ensure re-announcement of same message
    setAnnouncement('');
    setPoliteness(priority);
    setTimeout(() => setAnnouncement(message), 100);
  }, []);

  const Announcer = useCallback(
    () => <LiveRegion message={announcement} politeness={politeness} />,
    [announcement, politeness]
  );

  return { announce, Announcer };
}

// ============================================================
// REDUCED MOTION HOOK
// ============================================================

export function usePrefersReducedMotion(): boolean {
  const [prefersReducedMotion, setPrefersReducedMotion] = React.useState(false);

  useEffect(() => {
    const mediaQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    setPrefersReducedMotion(mediaQuery.matches);

    const handleChange = (e: MediaQueryListEvent) => {
      setPrefersReducedMotion(e.matches);
    };

    mediaQuery.addEventListener('change', handleChange);
    return () => mediaQuery.removeEventListener('change', handleChange);
  }, []);

  return prefersReducedMotion;
}

// ============================================================
// FOCUS VISIBLE HOOK
// ============================================================

export function useFocusVisible(): boolean {
  const [isFocusVisible, setIsFocusVisible] = React.useState(false);
  const hadKeyboardEvent = useRef(false);

  useEffect(() => {
    const onKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Tab') {
        hadKeyboardEvent.current = true;
      }
    };

    const onPointerDown = () => {
      hadKeyboardEvent.current = false;
    };

    const onFocus = () => {
      setIsFocusVisible(hadKeyboardEvent.current);
    };

    const onBlur = () => {
      setIsFocusVisible(false);
    };

    document.addEventListener('keydown', onKeyDown);
    document.addEventListener('pointerdown', onPointerDown);
    document.addEventListener('focusin', onFocus);
    document.addEventListener('focusout', onBlur);

    return () => {
      document.removeEventListener('keydown', onKeyDown);
      document.removeEventListener('pointerdown', onPointerDown);
      document.removeEventListener('focusin', onFocus);
      document.removeEventListener('focusout', onBlur);
    };
  }, []);

  return isFocusVisible;
}

// ============================================================
// ARIA UTILITIES
// ============================================================

/**
 * Generate unique IDs for ARIA relationships
 */
let idCounter = 0;
export function generateAriaId(prefix = 'aria'): string {
  return `${prefix}-${++idCounter}`;
}

/**
 * Get ARIA props for describedby relationships
 */
export function getAriaDescribedBy(...ids: (string | undefined | null)[]): {
  'aria-describedby'?: string;
} {
  const validIds = ids.filter(Boolean);
  if (validIds.length === 0) return {};
  return { 'aria-describedby': validIds.join(' ') };
}

/**
 * Get ARIA props for labelledby relationships
 */
export function getAriaLabelledBy(...ids: (string | undefined | null)[]): {
  'aria-labelledby'?: string;
} {
  const validIds = ids.filter(Boolean);
  if (validIds.length === 0) return {};
  return { 'aria-labelledby': validIds.join(' ') };
}

// ============================================================
// COLOR CONTRAST UTILITIES
// ============================================================

/**
 * Calculate relative luminance
 */
function getLuminance(r: number, g: number, b: number): number {
  const [rs, gs, bs] = [r, g, b].map((c) => {
    c /= 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * rs + 0.7152 * gs + 0.0722 * bs;
}

/**
 * Calculate contrast ratio between two colors
 */
export function getContrastRatio(
  color1: { r: number; g: number; b: number },
  color2: { r: number; g: number; b: number }
): number {
  const l1 = getLuminance(color1.r, color1.g, color1.b);
  const l2 = getLuminance(color2.r, color2.g, color2.b);
  const lighter = Math.max(l1, l2);
  const darker = Math.min(l1, l2);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Check if contrast meets WCAG AA requirements
 */
export function meetsContrastAA(
  foreground: { r: number; g: number; b: number },
  background: { r: number; g: number; b: number },
  isLargeText = false
): boolean {
  const ratio = getContrastRatio(foreground, background);
  return isLargeText ? ratio >= 3 : ratio >= 4.5;
}

/**
 * Check if contrast meets WCAG AAA requirements
 */
export function meetsContrastAAA(
  foreground: { r: number; g: number; b: number },
  background: { r: number; g: number; b: number },
  isLargeText = false
): boolean {
  const ratio = getContrastRatio(foreground, background);
  return isLargeText ? ratio >= 4.5 : ratio >= 7;
}

// ============================================================
// ROVING TABINDEX HOOK
// ============================================================

interface UseRovingTabIndexOptions {
  initialIndex?: number;
  orientation?: 'horizontal' | 'vertical';
}

export function useRovingTabIndex<T extends HTMLElement>(
  refs: React.RefObject<T>[],
  options: UseRovingTabIndexOptions = {}
) {
  const { initialIndex = 0, orientation = 'horizontal' } = options;
  const [focusedIndex, setFocusedIndex] = React.useState(initialIndex);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent, index: number) => {
      const nextKey = orientation === 'horizontal' ? 'ArrowRight' : 'ArrowDown';
      const prevKey = orientation === 'horizontal' ? 'ArrowLeft' : 'ArrowUp';

      let newIndex = index;

      if (e.key === nextKey) {
        e.preventDefault();
        newIndex = (index + 1) % refs.length;
      } else if (e.key === prevKey) {
        e.preventDefault();
        newIndex = (index - 1 + refs.length) % refs.length;
      } else if (e.key === 'Home') {
        e.preventDefault();
        newIndex = 0;
      } else if (e.key === 'End') {
        e.preventDefault();
        newIndex = refs.length - 1;
      }

      if (newIndex !== index) {
        setFocusedIndex(newIndex);
        refs[newIndex]?.current?.focus();
      }
    },
    [refs, orientation]
  );

  const getTabIndex = useCallback(
    (index: number) => (index === focusedIndex ? 0 : -1),
    [focusedIndex]
  );

  return {
    focusedIndex,
    setFocusedIndex,
    handleKeyDown,
    getTabIndex,
  };
}

export default {
  SkipLink,
  VisuallyHidden,
  FocusTrap,
  LiveRegion,
  useKeyboardNavigation,
  useAnnouncer,
  usePrefersReducedMotion,
  useFocusVisible,
  useRovingTabIndex,
  generateAriaId,
  getAriaDescribedBy,
  getAriaLabelledBy,
  getContrastRatio,
  meetsContrastAA,
  meetsContrastAAA,
};
