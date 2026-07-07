import * as React from 'react';
import { cn } from '@shared/lib/utils';

export interface TooltipProps {
  content: React.ReactNode;
  children: React.ReactElement;
  position?: 'top' | 'bottom' | 'left' | 'right';
  delay?: number;
}

const Tooltip: React.FC<TooltipProps> = ({
  content,
  children,
  position = 'top',
  delay = 200,
}) => {
  const [isVisible, setIsVisible] = React.useState(false);
  const [coords, setCoords] = React.useState({ x: 0, y: 0 });
  const triggerRef = React.useRef<HTMLElement>(null);
  const tooltipRef = React.useRef<HTMLDivElement>(null);
  const timeoutRef = React.useRef<ReturnType<typeof setTimeout> | null>(null);
  const tooltipId = React.useId();

  const showTooltip = () => {
    timeoutRef.current = setTimeout(() => {
      if (triggerRef.current) {
        const rect = triggerRef.current.getBoundingClientRect();
        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        let x = 0;
        let y = 0;

        switch (position) {
          case 'top':
            x = rect.left + scrollX + rect.width / 2;
            y = rect.top + scrollY - 8;
            break;
          case 'bottom':
            x = rect.left + scrollX + rect.width / 2;
            y = rect.bottom + scrollY + 8;
            break;
          case 'left':
            x = rect.left + scrollX - 8;
            y = rect.top + scrollY + rect.height / 2;
            break;
          case 'right':
            x = rect.right + scrollX + 8;
            y = rect.top + scrollY + rect.height / 2;
            break;
        }

        setCoords({ x, y });
        setIsVisible(true);
      }
    }, delay);
  };

  const hideTooltip = () => {
    if (timeoutRef.current) {
      clearTimeout(timeoutRef.current);
    }
    setIsVisible(false);
  };

  React.useEffect(() => {
    return () => {
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, []);

  const positions = {
    top: '-translate-x-1/2 -translate-y-full',
    bottom: '-translate-x-1/2',
    left: '-translate-x-full -translate-y-1/2',
    right: '-translate-y-1/2',
  };

  const arrows = {
    top: 'bottom-0 left-1/2 -translate-x-1/2 translate-y-full border-t-[var(--text-primary)] border-x-transparent border-b-transparent',
    bottom: 'top-0 left-1/2 -translate-x-1/2 -translate-y-full border-b-[var(--text-primary)] border-x-transparent border-t-transparent',
    left: 'right-0 top-1/2 -translate-y-1/2 translate-x-full border-l-[var(--text-primary)] border-y-transparent border-r-transparent',
    right: 'left-0 top-1/2 -translate-y-1/2 -translate-x-full border-r-[var(--text-primary)] border-y-transparent border-l-transparent',
  };

  const childProps = children.props as Record<string, any>;
  const childRef = (children as any).ref as React.Ref<HTMLElement> | undefined;
  const setRef = (node: HTMLElement | null) => {
    triggerRef.current = node;
    if (typeof childRef === 'function') childRef(node);
    else if (childRef && typeof childRef === 'object') {
      (childRef as React.MutableRefObject<HTMLElement | null>).current = node;
    }
  };

  const compose =
    (mine: () => void, theirs?: (e: any) => void) => (e: any) => {
      theirs?.(e);
      mine();
    };

  const child = React.cloneElement(children, {
    ref: setRef,
    'aria-describedby': isVisible
      ? tooltipId
      : childProps['aria-describedby'],
    onMouseEnter: compose(showTooltip, childProps.onMouseEnter),
    onMouseLeave: compose(hideTooltip, childProps.onMouseLeave),
    onFocus: compose(showTooltip, childProps.onFocus),
    onBlur: compose(hideTooltip, childProps.onBlur),
  } as Partial<React.HTMLAttributes<HTMLElement>>);

  return (
    <>
      {child}
      {isVisible && (
        <div
          ref={tooltipRef}
          role="tooltip"
          id={tooltipId}
          className={cn(
            'fixed z-50 px-3 py-1 text-sm text-[var(--text-inverse)] bg-[var(--text-primary)] rounded-lg shadow-lg',
            'animate-in fade-in zoom-in-95 duration-100 motion-reduce:animate-none',
            positions[position]
          )}
          style={{
            left: coords.x,
            top: coords.y,
          }}
        >
          {content}
          <div
            className={cn(
              'absolute w-0 h-0 border-4',
              arrows[position]
            )}
          />
        </div>
      )}
    </>
  );
};

Tooltip.displayName = 'Tooltip';

export { Tooltip };
