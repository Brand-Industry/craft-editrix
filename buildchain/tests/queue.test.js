import { describe, it, expect, vi, afterEach } from 'vitest';
import { kickQueue } from '../src/js/utils/queue';

describe('kickQueue', () => {
  afterEach(() => {
    delete globalThis.window;
  });

  it("asks Craft's own runner to start the queue", () => {
    const runQueue = vi.fn();
    globalThis.window = { Craft: { cp: { runQueue } } };
    kickQueue();
    expect(runQueue).toHaveBeenCalledTimes(1);
  });

  it('does nothing when Craft is not on the page', () => {
    globalThis.window = {};
    expect(() => kickQueue()).not.toThrow();
  });

  it('survives a runner that throws', () => {
    globalThis.window = { Craft: { cp: { runQueue: () => { throw new Error('no'); } } } };
    expect(() => kickQueue()).not.toThrow();
  });
});
