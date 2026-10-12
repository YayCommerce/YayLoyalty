/** Sorted by name. A name several campaigns share (an archived copy, say) gets its id, so the options can be told apart. */
export function campaignFilterOptions(campaignNames: Map<number, string>): { id: number; label: string }[] {
  const nameCounts = new Map<string, number>();

  for (const name of campaignNames.values()) {
    nameCounts.set(name, (nameCounts.get(name) ?? 0) + 1);
  }

  return [...campaignNames]
    .sort((a, b) => a[1].localeCompare(b[1]) || a[0] - b[0])
    .map(([id, name]) => ({ id, label: (nameCounts.get(name) ?? 0) > 1 ? `${name} (#${id})` : name }));
}
