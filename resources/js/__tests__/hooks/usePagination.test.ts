import { renderHook, act } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { usePagination } from '@shared/lib/hooks/usePagination';

describe('usePagination', () => {
    it('initializes with default values', () => {
        const { result } = renderHook(() => usePagination());

        expect(result.current.page).toBe(1);
        expect(result.current.perPage).toBe(15);
        expect(result.current.total).toBe(0);
        expect(result.current.totalPages).toBe(1);
    });

    it('initializes with custom values', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 2, initialPerPage: 10, initialTotal: 50 })
        );

        expect(result.current.page).toBe(2);
        expect(result.current.perPage).toBe(10);
        expect(result.current.total).toBe(50);
        expect(result.current.totalPages).toBe(5);
    });

    it('calculates totalPages correctly', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPerPage: 10, initialTotal: 25 })
        );

        expect(result.current.totalPages).toBe(3);
    });

    it('rounds up totalPages for non-exact division', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPerPage: 10, initialTotal: 21 })
        );

        expect(result.current.totalPages).toBe(3);
    });

    it('totalPages is 1 when total is 0', () => {
        const { result } = renderHook(() => usePagination({ initialTotal: 0 }));

        expect(result.current.totalPages).toBe(1);
    });

    // ========== hasNextPage / hasPrevPage ==========

    it('hasNextPage is false on last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 3, initialPerPage: 10, initialTotal: 30 })
        );

        expect(result.current.hasNextPage).toBe(false);
    });

    it('hasNextPage is true when not on last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 1, initialPerPage: 10, initialTotal: 30 })
        );

        expect(result.current.hasNextPage).toBe(true);
    });

    it('hasPrevPage is false on first page', () => {
        const { result } = renderHook(() => usePagination({ initialPage: 1 }));

        expect(result.current.hasPrevPage).toBe(false);
    });

    it('hasPrevPage is true on pages after first', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 2, initialTotal: 50 })
        );

        expect(result.current.hasPrevPage).toBe(true);
    });

    // ========== isFirstPage / isLastPage ==========

    it('isFirstPage is true on page 1', () => {
        const { result } = renderHook(() => usePagination());

        expect(result.current.isFirstPage).toBe(true);
    });

    it('isLastPage is true on last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 2, initialPerPage: 10, initialTotal: 20 })
        );

        expect(result.current.isLastPage).toBe(true);
    });

    // ========== startIndex / endIndex ==========

    it('calculates startIndex correctly', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 2, initialPerPage: 10, initialTotal: 25 })
        );

        expect(result.current.startIndex).toBe(11);
    });

    it('calculates endIndex correctly on full page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 1, initialPerPage: 10, initialTotal: 25 })
        );

        expect(result.current.endIndex).toBe(10);
    });

    it('calculates endIndex correctly on partial last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 3, initialPerPage: 10, initialTotal: 25 })
        );

        expect(result.current.endIndex).toBe(25);
    });

    // ========== setPage ==========

    it('setPage navigates to the specified page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialTotal: 100 })
        );

        act(() => { result.current.setPage(3); });

        expect(result.current.page).toBe(3);
    });

    it('setPage does not go below page 1', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 1, initialTotal: 100 })
        );

        act(() => { result.current.setPage(0); });

        expect(result.current.page).toBe(1);
    });

    it('setPage does not exceed totalPages', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPerPage: 10, initialTotal: 30 })
        );

        act(() => { result.current.setPage(99); });

        expect(result.current.page).toBe(3);
    });

    // ========== setPerPage ==========

    it('setPerPage updates perPage and resets to page 1', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 3, initialTotal: 100 })
        );

        act(() => { result.current.setPerPage(20); });

        expect(result.current.perPage).toBe(20);
        expect(result.current.page).toBe(1);
    });

    // ========== setTotal ==========

    it('setTotal updates total', () => {
        const { result } = renderHook(() => usePagination());

        act(() => { result.current.setTotal(100); });

        expect(result.current.total).toBe(100);
    });

    it('setTotal adjusts current page if it exceeds new totalPages', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 5, initialPerPage: 10, initialTotal: 100 })
        );

        act(() => { result.current.setTotal(20); });

        // 20 items / 10 per page = 2 pages, page 5 يجب أن يرجع لـ 2
        expect(result.current.page).toBe(2);
    });

    // ========== nextPage / prevPage ==========

    it('nextPage increments page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 1, initialTotal: 50 })
        );

        act(() => { result.current.nextPage(); });

        expect(result.current.page).toBe(2);
    });

    it('prevPage decrements page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 3, initialTotal: 50 })
        );

        act(() => { result.current.prevPage(); });

        expect(result.current.page).toBe(2);
    });

    it('nextPage does not exceed last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 3, initialPerPage: 10, initialTotal: 30 })
        );

        act(() => { result.current.nextPage(); });

        expect(result.current.page).toBe(3);
    });

    it('prevPage does not go below 1', () => {
        const { result } = renderHook(() => usePagination({ initialPage: 1 }));

        act(() => { result.current.prevPage(); });

        expect(result.current.page).toBe(1);
    });

    // ========== firstPage / lastPage ==========

    it('firstPage navigates to page 1', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 5, initialTotal: 100 })
        );

        act(() => { result.current.firstPage(); });

        expect(result.current.page).toBe(1);
    });

    it('lastPage navigates to last page', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPerPage: 10, initialTotal: 55 })
        );

        act(() => { result.current.lastPage(); });

        expect(result.current.page).toBe(6);
    });

    // ========== getPageNumbers ==========

    it('returns all pages when total pages <= maxVisible', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPerPage: 10, initialTotal: 30 })
        );

        const pages = result.current.getPageNumbers(5);

        expect(pages).toEqual([1, 2, 3]);
    });

    it('returns maxVisible pages when total pages > maxVisible', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 5, initialPerPage: 10, initialTotal: 100 })
        );

        const pages = result.current.getPageNumbers(5);

        expect(pages).toHaveLength(5);
    });

    it('centers current page in visible pages', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 5, initialPerPage: 10, initialTotal: 100 })
        );

        const pages = result.current.getPageNumbers(5);

        expect(pages).toContain(5);
    });

    // ========== reset ==========

    it('reset returns to initial values', () => {
        const { result } = renderHook(() =>
            usePagination({ initialPage: 1, initialPerPage: 15, initialTotal: 0 })
        );

        act(() => {
            result.current.setPage(5);
            result.current.setTotal(100);
        });

        act(() => { result.current.reset(); });

        expect(result.current.page).toBe(1);
        expect(result.current.total).toBe(0);
    });
});
