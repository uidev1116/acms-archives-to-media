export type Route = { name: 'wizard' } | { name: 'jobs' } | { name: 'job'; id: number };

/** location.hash（#/ , #/jobs , #/jobs/12）を画面に対応させる。不明なものはウィザード。 */
export function parseRoute(hash: string): Route {
  const path = hash.replace(/^#/, '');
  if (path === '/jobs') return { name: 'jobs' };
  const m = /^\/jobs\/(\d+)$/.exec(path);
  if (m?.[1] !== undefined) {
    const id = Number(m[1]);
    if (id > 0) return { name: 'job', id };
  }
  return { name: 'wizard' };
}
