const ADMIN_ORIGIN_FALLBACK = typeof window === 'undefined' ? 'http://localhost' : window.location.origin;

function adminOrigin(): URL {
  const configured = import.meta.env.VITE_ADMIN_URL || ADMIN_ORIGIN_FALLBACK;
  const origin = new URL(configured);

  if (!['http:', 'https:'].includes(origin.protocol) || origin.username || origin.password) {
    throw new Error('VITE_ADMIN_URL must be an absolute HTTP(S) origin');
  }

  origin.pathname = origin.pathname.replace(/\/$/, '');
  origin.search = '';
  origin.hash = '';
  return origin;
}

export function adminUrl(path = '/'): string {
  if (/^[a-z][a-z\d+.-]*:/i.test(path) || path.startsWith('//')) {
    throw new Error('Admin paths must be same-origin relative paths');
  }

  const normalizedPath = path.startsWith('/') ? path : `/${path}`;
  const origin = adminOrigin();
  const target = new URL(normalizedPath, origin);

  if (target.origin !== origin.origin) {
    throw new Error('Admin paths must stay on the configured admin origin');
  }

  return target.toString();
}
