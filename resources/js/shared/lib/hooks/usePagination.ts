import { useState, useCallback, useMemo } from 'react';

interface PaginationState {
    page: number;
    perPage: number;
    total: number;
}

interface UsePaginationReturn {
    page: number;
    perPage: number;
    total: number;
    totalPages: number;
    hasNextPage: boolean;
    hasPrevPage: boolean;
    isFirstPage: boolean;
    isLastPage: boolean;
    startIndex: number;
    endIndex: number;
    setPage: (page: number) => void;
    setPerPage: (perPage: number) => void;
    setTotal: (total: number) => void;
    nextPage: () => void;
    prevPage: () => void;
    firstPage: () => void;
    lastPage: () => void;
    getPageNumbers: (maxVisible?: number) => number[];
    reset: () => void;
}

interface UsePaginationOptions {
    initialPage?: number;
    initialPerPage?: number;
    initialTotal?: number;
}

/**
 * Hook for pagination state management
 */
export function usePagination(options: UsePaginationOptions = {}): UsePaginationReturn {
    const {
        initialPage = 1,
        initialPerPage = 15,
        initialTotal = 0,
    } = options;

    const [state, setState] = useState<PaginationState>({
        page: initialPage,
        perPage: initialPerPage,
        total: initialTotal,
    });

    const totalPages = useMemo(() =>
        Math.ceil(state.total / state.perPage) || 1,
        [state.total, state.perPage]
    );

    const hasNextPage = state.page < totalPages;
    const hasPrevPage = state.page > 1;
    const isFirstPage = state.page === 1;
    const isLastPage = state.page === totalPages;

    const startIndex = (state.page - 1) * state.perPage + 1;
    const endIndex = Math.min(state.page * state.perPage, state.total);

    const setPage = useCallback((page: number) => {
        setState(prev => ({
            ...prev,
            page: Math.max(1, Math.min(page, Math.ceil(prev.total / prev.perPage) || 1)),
        }));
    }, []);

    const setPerPage = useCallback((perPage: number) => {
        setState(prev => ({
            ...prev,
            perPage,
            page: 1, // Reset to first page when changing per page
        }));
    }, []);

    const setTotal = useCallback((total: number) => {
        setState(prev => {
            const newTotalPages = Math.ceil(total / prev.perPage) || 1;
            return {
                ...prev,
                total,
                page: Math.min(prev.page, newTotalPages),
            };
        });
    }, []);

    const nextPage = useCallback(() => {
        setPage(state.page + 1);
    }, [state.page, setPage]);

    const prevPage = useCallback(() => {
        setPage(state.page - 1);
    }, [state.page, setPage]);

    const firstPage = useCallback(() => {
        setPage(1);
    }, [setPage]);

    const lastPage = useCallback(() => {
        setPage(totalPages);
    }, [totalPages, setPage]);

    const getPageNumbers = useCallback((maxVisible: number = 5): number[] => {
        const pages: number[] = [];

        if (totalPages <= maxVisible) {
            for (let i = 1; i <= totalPages; i++) {
                pages.push(i);
            }
        } else {
            const half = Math.floor(maxVisible / 2);
            let start = Math.max(1, state.page - half);
            let end = Math.min(totalPages, start + maxVisible - 1);

            if (end - start + 1 < maxVisible) {
                start = Math.max(1, end - maxVisible + 1);
            }

            for (let i = start; i <= end; i++) {
                pages.push(i);
            }
        }

        return pages;
    }, [state.page, totalPages]);

    const reset = useCallback(() => {
        setState({
            page: initialPage,
            perPage: initialPerPage,
            total: initialTotal,
        });
    }, [initialPage, initialPerPage, initialTotal]);

    return {
        page: state.page,
        perPage: state.perPage,
        total: state.total,
        totalPages,
        hasNextPage,
        hasPrevPage,
        isFirstPage,
        isLastPage,
        startIndex,
        endIndex,
        setPage,
        setPerPage,
        setTotal,
        nextPage,
        prevPage,
        firstPage,
        lastPage,
        getPageNumbers,
        reset,
    };
}
