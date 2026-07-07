/**
 * In-memory cache with TTL, deduplication, and prefix invalidation.
 *
 * Lightweight, single-process cache used to dedupe identical API requests
 * within a short window. Not persistent; resets on page reload.
 */

interface Entry<V> {
  value: V;
  expiresAt: number;
}

const store = new Map<string, Entry<unknown>>();
const inflight = new Map<string, Promise<unknown>>();

function now(): number {
  return Date.now();
}

function read(key: string): Entry<unknown> | undefined {
  const entry = store.get(key);
  if (!entry) return undefined;
  if (entry.expiresAt <= now()) {
    store.delete(key);
    return undefined;
  }
  return entry;
}

export const cache = {
  set<V>(key: string, value: V, ttlMs: number = 30_000): void {
    store.set(key, { value, expiresAt: now() + ttlMs });
  },

  get<V>(key: string): V | null {
    const entry = read(key);
    return entry ? (entry.value as V) : null;
  },

  has(key: string): boolean {
    return read(key) !== undefined;
  },

  get size(): number {
    let count = 0;
    for (const key of store.keys()) {
      if (read(key) !== undefined) count += 1;
    }
    return count;
  },

  keys(): string[] {
    const live: string[] = [];
    for (const key of Array.from(store.keys())) {
      if (read(key) !== undefined) live.push(key);
    }
    return live;
  },

  delete(key: string): boolean {
    return store.delete(key);
  },

  clear(): void {
    store.clear();
    inflight.clear();
  },

  invalidate(prefix: string): void {
    for (const key of Array.from(store.keys())) {
      if (key.startsWith(prefix)) store.delete(key);
    }
  },

  cleanup(): void {
    for (const key of Array.from(store.keys())) {
      read(key);
    }
  },

  async getOrFetch<V>(
    key: string,
    fetcher: () => Promise<V>,
    options: { ttl?: number } = {},
  ): Promise<V> {
    const cached = read(key);
    if (cached) return cached.value as V;

    const pending = inflight.get(key);
    if (pending) return pending as Promise<V>;

    const promise = (async () => {
      try {
        const value = await fetcher();
        store.set(key, { value, expiresAt: now() + (options.ttl ?? 30_000) });
        return value;
      } finally {
        inflight.delete(key);
      }
    })();
    inflight.set(key, promise);
    return promise;
  },
};

export const CacheTTL = {
  SHORT: 30_000,
  MEDIUM: 5 * 60_000,
  LONG: 30 * 60_000,
} as const;

export const CacheKeys = {
  projects: {
    byId: (id: number | string) => `projects:${id}`,
    all: () => 'projects:all',
  },
  tasks: {
    byProject: (projectId: number | string) => `tasks:project:${projectId}`,
    byId: (id: number | string) => `tasks:${id}`,
  },
  users: {
    current: () => 'users:current',
    byId: (id: number | string) => `users:${id}`,
  },
  departments: {
    all: () => 'departments:all',
  },
} as const;
