export function escapeRegExp(value: string) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function normalizeTag(tag: string) {
  return tag.trim().toLowerCase();
}

export function buildSplitPattern(splitChars: string[]) {
  if (splitChars.length === 0) {
    return null;
  }

  return new RegExp(splitChars.map(escapeRegExp).join('|'));
}

export function mergeTags(current: string[], tags: string[]) {
  const existing = new Set(current.map(normalizeTag));

  let changed = false;

  const next = [...current];

  for (const tag of tags) {
    const trimmed = tag.trim();

    if (!trimmed) {
      continue;
    }

    const normalized = normalizeTag(trimmed);

    if (existing.has(normalized)) {
      continue;
    }

    existing.add(normalized);
    next.push(trimmed);
    changed = true;
  }

  return changed ? next : current;
}
