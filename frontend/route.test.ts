import { describe, expect, it } from 'vitest';
import { parseRoute } from './route';

describe('parseRoute', () => {
  it.each([
    ['', { name: 'wizard' }],
    ['#/', { name: 'wizard' }],
    ['#/jobs', { name: 'jobs' }],
    ['#/jobs/12', { name: 'job', id: 12 }],
    ['#/jobs/0', { name: 'wizard' }],
    ['#/jobs/abc', { name: 'wizard' }],
    ['#/jobs/12/x', { name: 'wizard' }],
    ['#/other', { name: 'wizard' }],
  ])('%s', (hash, expected) => {
    expect(parseRoute(hash)).toEqual(expected);
  });
});
