import { renderHook, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import { useDebounce, useDebouncedCallback, useSearch } from '@shared/lib/hooks/useDebounce';

describe('useDebounce', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('returns initial value immediately', () => {
        const { result } = renderHook(() => useDebounce('initial', 300));
        expect(result.current).toBe('initial');
    });

    it('does not update value before delay expires', () => {
        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value, 300),
            { initialProps: { value: 'initial' } }
        );

        rerender({ value: 'updated' });

        // قبل انتهاء الـ delay يجب أن تبقى القيمة القديمة
        expect(result.current).toBe('initial');
    });

    it('updates value after delay expires', () => {
        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value, 300),
            { initialProps: { value: 'initial' } }
        );

        rerender({ value: 'updated' });

        act(() => {
            vi.advanceTimersByTime(300);
        });

        expect(result.current).toBe('updated');
    });

    it('resets timer when value changes before delay', () => {
        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value, 300),
            { initialProps: { value: 'initial' } }
        );

        rerender({ value: 'change1' });
        act(() => { vi.advanceTimersByTime(150); });

        rerender({ value: 'change2' });
        act(() => { vi.advanceTimersByTime(150); });

        // لم ينتهِ الـ delay بعد آخر تغيير
        expect(result.current).toBe('initial');

        act(() => { vi.advanceTimersByTime(150); });
        expect(result.current).toBe('change2');
    });

    it('uses default delay of 300ms', () => {
        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value),
            { initialProps: { value: 'initial' } }
        );

        rerender({ value: 'updated' });
        act(() => { vi.advanceTimersByTime(300); });

        expect(result.current).toBe('updated');
    });

    it('works with numbers', () => {
        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value, 200),
            { initialProps: { value: 0 } }
        );

        rerender({ value: 42 });
        act(() => { vi.advanceTimersByTime(200); });

        expect(result.current).toBe(42);
    });

    it('works with objects', () => {
        const initialObj = { name: 'initial' };
        const updatedObj = { name: 'updated' };

        const { result, rerender } = renderHook(
            ({ value }) => useDebounce(value, 200),
            { initialProps: { value: initialObj } }
        );

        rerender({ value: updatedObj });
        act(() => { vi.advanceTimersByTime(200); });

        expect(result.current).toEqual(updatedObj);
    });
});

describe('useDebouncedCallback', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('does not call callback before delay', () => {
        const callback = vi.fn();
        const { result } = renderHook(() => useDebouncedCallback(callback, 300));

        act(() => { result.current('test'); });

        expect(callback).not.toHaveBeenCalled();
    });

    it('calls callback after delay', () => {
        const callback = vi.fn();
        const { result } = renderHook(() => useDebouncedCallback(callback, 300));

        act(() => { result.current('test'); });
        act(() => { vi.advanceTimersByTime(300); });

        expect(callback).toHaveBeenCalledWith('test');
        expect(callback).toHaveBeenCalledTimes(1);
    });

    it('cancels previous call when called again before delay', () => {
        const callback = vi.fn();
        const { result } = renderHook(() => useDebouncedCallback(callback, 300));

        act(() => { result.current('call1'); });
        act(() => { vi.advanceTimersByTime(150); });
        act(() => { result.current('call2'); });
        act(() => { vi.advanceTimersByTime(300); });

        expect(callback).toHaveBeenCalledTimes(1);
        expect(callback).toHaveBeenCalledWith('call2');
    });

    it('passes arguments correctly', () => {
        const callback = vi.fn();
        const { result } = renderHook(() => useDebouncedCallback(callback, 100));

        act(() => { result.current('arg1', 'arg2', 42); });
        act(() => { vi.advanceTimersByTime(100); });

        expect(callback).toHaveBeenCalledWith('arg1', 'arg2', 42);
    });
});

describe('useSearch', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('initializes with empty string by default', () => {
        const { result } = renderHook(() => useSearch());
        expect(result.current.searchTerm).toBe('');
    });

    it('initializes with provided value', () => {
        const { result } = renderHook(() => useSearch('initial search'));
        expect(result.current.searchTerm).toBe('initial search');
    });

    it('handleSearch updates searchTerm immediately', () => {
        const { result } = renderHook(() => useSearch());

        act(() => { result.current.handleSearch('new search'); });

        expect(result.current.searchTerm).toBe('new search');
    });

    it('debouncedSearchTerm updates after delay', () => {
        const { result } = renderHook(() => useSearch('', 300));

        act(() => { result.current.handleSearch('query'); });
        expect(result.current.debouncedSearchTerm).toBe('');

        act(() => { vi.advanceTimersByTime(300); });
        expect(result.current.debouncedSearchTerm).toBe('query');
    });

    it('isSearching is true when searchTerm differs from debouncedSearchTerm', () => {
        const { result } = renderHook(() => useSearch('', 300));

        act(() => { result.current.handleSearch('typing...'); });

        expect(result.current.isSearching).toBe(true);

        act(() => { vi.advanceTimersByTime(300); });

        expect(result.current.isSearching).toBe(false);
    });

    it('clearSearch resets searchTerm to empty', () => {
        const { result } = renderHook(() => useSearch('existing search'));

        act(() => { result.current.clearSearch(); });

        expect(result.current.searchTerm).toBe('');
    });
});
