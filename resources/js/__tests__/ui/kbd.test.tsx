import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { Kbd } from '@shared/ui/Kbd';

describe('Kbd', () => {
  it('renders its children inside a <kbd> element', () => {
    render(<Kbd>⌘K</Kbd>);
    const kbd = screen.getByText('⌘K');
    expect(kbd.tagName.toLowerCase()).toBe('kbd');
  });

  it('applies a custom className alongside the base styles', () => {
    render(<Kbd className="my-extra">x</Kbd>);
    const kbd = screen.getByText('x');
    expect(kbd.className).toContain('my-extra');
  });

  it('passes through extra HTML attributes', () => {
    render(<Kbd data-testid="kbd-1" aria-label="command">K</Kbd>);
    const kbd = screen.getByTestId('kbd-1');
    expect(kbd).toHaveAttribute('aria-label', 'command');
  });
});
