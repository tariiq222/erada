import React, { act } from 'react';
import { render, type RenderOptions, type RenderResult } from '@testing-library/react';

export function renderInAct(ui: React.ReactElement, options?: RenderOptions): RenderResult {
  let result!: RenderResult;
  act(() => {
    result = render(ui, options);
  });
  return result;
}