function configuredOperationalOrigin(): URL {
  const configured = import.meta.env.VITE_OPERATIONAL_URL;
  if (!configured) {
    throw new Error('VITE_OPERATIONAL_URL is required');
  }

  let base: URL;
  try {
    base = new URL(configured);
  } catch {
    throw new Error('VITE_OPERATIONAL_URL must be an absolute URL');
  }

  if (base.protocol !== 'http:' && base.protocol !== 'https:') {
    throw new Error('VITE_OPERATIONAL_URL must use http or https');
  }

  return base;
}

export function operationalUrl(path: string): string {
  if (!path.startsWith('/') || path.startsWith('//') || path.includes('\\')) {
    throw new Error('Operational paths must be same-origin absolute paths');
  }

  const base = configuredOperationalOrigin();
  const target = new URL(path, base);
  if (target.origin !== base.origin) {
    throw new Error('Operational paths must remain on the configured origin');
  }

  return target.toString();
}
