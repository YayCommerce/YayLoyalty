/** Requests in flight at once for a client-side bulk write; thousands at once would be a self-inflicted spike. */
const DEFAULT_CHUNK_SIZE = 10;

/**
 * Runs `task` over every item, `size` at a time, for bulk actions that have
 * no bulk endpoint. A failed item does not abort the rest; it is returned so
 * the caller can report or reselect it.
 */
export async function settleInChunks<T>(
  items: readonly T[],
  task: (item: T) => Promise<unknown>,
  size = DEFAULT_CHUNK_SIZE,
): Promise<{ succeeded: number; failedItems: T[] }> {
  let succeeded = 0;
  const failedItems: T[] = [];

  for (let i = 0; i < items.length; i += size) {
    const chunk = items.slice(i, i + size);
    const results = await Promise.allSettled(chunk.map((item) => task(item)));

    results.forEach((result, index) => {
      if (result.status === 'fulfilled') succeeded++;
      else failedItems.push(chunk[index]);
    });
  }

  return { succeeded, failedItems };
}
