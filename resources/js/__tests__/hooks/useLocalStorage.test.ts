import { renderHook, act } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { useLocalStorage, useSessionStorage } from '@shared/lib/hooks/useLocalStorage';

describe('useLocalStorage', () => {
    beforeEach(() => {
        localStorage.clear();
        vi.clearAllMocks();
    });

    afterEach(() => {
        localStorage.clear();
    });

    it('returns initialValue when localStorage is empty', () => {
        const { result } = renderHook(() => useLocalStorage('test-key', 'default'));

        expect(result.current[0]).toBe('default');
    });

    it('reads existing value from localStorage', () => {
        vi.mocked(window.localStorage.getItem).mockReturnValueOnce(JSON.stringify('existing value'));

        const { result } = renderHook(() => useLocalStorage('test-key', 'default'));

        expect(result.current[0]).toBe('existing value');
    });

    it('sets value and updates localStorage', () => {
        const { result } = renderHook(() => useLocalStorage('test-key', 'default'));

        act(() => { result.current[1]('new value'); });

        expect(result.current[0]).toBe('new value');
    });

    it('supports functional updates', () => {
        const { result } = renderHook(() => useLocalStorage('count', 0));

        act(() => { result.current[1](prev => prev + 1); });
        act(() => { result.current[1](prev => prev + 1); });

        expect(result.current[0]).toBe(2);
    });

    it('removes value from localStorage', () => {
        localStorage.setItem('test-key', JSON.stringify('value'));
        const { result } = renderHook(() => useLocalStorage('test-key', 'default'));

        act(() => { result.current[2](); });

        // After removeValue, state resets to initialValue
        expect(result.current[0]).toBe('default');
    });

    it('works with objects', () => {
        const initialObj = { name: 'Test', value: 42 };
        const { result } = renderHook(() => useLocalStorage('obj-key', initialObj));

        act(() => {
            result.current[1]({ name: 'Updated', value: 100 });
        });

        expect(result.current[0]).toEqual({ name: 'Updated', value: 100 });
    });

    it('works with arrays', () => {
        const { result } = renderHook(() => useLocalStorage<string[]>('list', []));

        act(() => { result.current[1](['item1', 'item2']); });

        expect(result.current[0]).toEqual(['item1', 'item2']);
    });

    it('works with booleans', () => {
        const { result } = renderHook(() => useLocalStorage('bool-key', false));

        act(() => { result.current[1](true); });

        expect(result.current[0]).toBe(true);
    });

    it('works with numbers', () => {
        const { result } = renderHook(() => useLocalStorage('num-key', 0));

        act(() => { result.current[1](42); });

        expect(result.current[0]).toBe(42);
    });

    it('handles invalid JSON in localStorage gracefully', () => {
        localStorage.setItem('bad-key', 'not valid json {{{');

        // يجب أن يعود للقيمة الافتراضية بدون crash
        const { result } = renderHook(() => useLocalStorage('bad-key', 'fallback'));

        expect(result.current[0]).toBe('fallback');
    });

    it('different keys are independent', () => {
        const { result: result1 } = renderHook(() => useLocalStorage('key1', 'value1'));
        const { result: result2 } = renderHook(() => useLocalStorage('key2', 'value2'));

        act(() => { result1.current[1]('updated1'); });

        expect(result1.current[0]).toBe('updated1');
        expect(result2.current[0]).toBe('value2');
    });
});

describe('useSessionStorage', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    afterEach(() => {
        sessionStorage.clear();
    });

    it('returns initialValue when sessionStorage is empty', () => {
        const { result } = renderHook(() => useSessionStorage('test-key', 'default'));

        expect(result.current[0]).toBe('default');
    });

    it('reads existing value from sessionStorage', () => {
        sessionStorage.setItem('test-key', JSON.stringify('existing'));

        const { result } = renderHook(() => useSessionStorage('test-key', 'default'));

        expect(result.current[0]).toBe('existing');
    });

    it('sets value in sessionStorage', () => {
        const { result } = renderHook(() => useSessionStorage('test-key', 'default'));

        act(() => { result.current[1]('updated'); });

        expect(result.current[0]).toBe('updated');
        expect(JSON.parse(sessionStorage.getItem('test-key')!)).toBe('updated');
    });

    it('removes value from sessionStorage', () => {
        sessionStorage.setItem('test-key', JSON.stringify('value'));
        const { result } = renderHook(() => useSessionStorage('test-key', 'default'));

        act(() => { result.current[2](); });

        expect(result.current[0]).toBe('default');
    });

    it('supports functional updates', () => {
        const { result } = renderHook(() => useSessionStorage('count', 10));

        act(() => { result.current[1](prev => prev * 2); });

        expect(result.current[0]).toBe(20);
    });
});
