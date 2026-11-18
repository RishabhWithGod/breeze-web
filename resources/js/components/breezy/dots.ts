// Which menu links get Breezy's yellow dot. The dock (lazy) writes it; the sidebar (always loaded) reads it.
import { useSyncExternalStore } from 'react';

let paths: string[] = [];
const subs = new Set<() => void>();

export function setBreezyDots(next: string[]) {
  if (next.length === paths.length && next.every((p, i) => p === paths[i])) return;
  paths = next;
  subs.forEach((f) => f());
}

export function useBreezyDots(): string[] {
  return useSyncExternalStore((f) => { subs.add(f); return () => subs.delete(f); }, () => paths, () => paths);
}
