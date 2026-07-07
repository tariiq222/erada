import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import React from 'react';
import { IconButton } from '@shared/ui/IconButton';

describe('IconButton a11y guard', () => {
  let errorSpy: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
  });

  afterEach(() => {
    errorSpy.mockRestore();
  });

  it('warns when rendered without an accessible name', () => {
    render(<IconButton data-testid="icon-btn" />);

    expect(errorSpy).toHaveBeenCalledTimes(1);
    expect(errorSpy.mock.calls[0][0]).toContain(
      'IconButton requires an accessible name',
    );
  });

  it('does not warn when aria-label is provided', () => {
    render(<IconButton aria-label="Delete item" data-testid="icon-btn" />);

    expect(errorSpy).not.toHaveBeenCalled();
  });

  it('does not warn when title is provided', () => {
    render(<IconButton title="Close" data-testid="icon-btn" />);

    expect(errorSpy).not.toHaveBeenCalled();
  });

  it('does not warn when aria-labelledby is provided', () => {
    render(
      <>
        <span id="lbl">Menu</span>
        <IconButton aria-labelledby="lbl" data-testid="icon-btn" />
      </>,
    );

    expect(errorSpy).not.toHaveBeenCalled();
  });

  it('does not warn when visible text children are provided', () => {
    render(<IconButton>+</IconButton>);

    expect(errorSpy).not.toHaveBeenCalled();
  });

  it('treats empty aria-label as missing and still warns', () => {
    render(<IconButton aria-label="" data-testid="icon-btn" />);

    expect(errorSpy).toHaveBeenCalledTimes(1);
  });

  it('does not change rendering or block the button when the label is missing', () => {
    render(<IconButton data-testid="icon-btn" />);

    const btn = screen.getByTestId('icon-btn');
    expect(btn.tagName.toLowerCase()).toBe('button');
    expect(btn).toHaveAttribute('type', 'button');
  });

  it('passes through extra HTML attributes', () => {
    render(<IconButton aria-label="Edit" data-testid="icon-btn" />);

    const btn = screen.getByTestId('icon-btn');
    expect(btn).toHaveAttribute('aria-label', 'Edit');
  });
});
