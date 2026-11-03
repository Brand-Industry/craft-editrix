import { describe, it, expect } from 'vitest';
import { urlWithId, urlWithQuery } from '../src/js/utils/urlWithId';

describe('urlWithId', () => {
  it('adds the id to a plain url', () => {
    expect(urlWithId('https://site.test/admin/editrix/api/logs/revert', 251))
      .toBe('https://site.test/admin/editrix/api/logs/revert/251');
  });

  it('puts the id before the query string of a multi-site url', () => {
    expect(
      urlWithId('https://site.test/craft/editrix/api/logs/validate-revert?site=en_us', 251)
    ).toBe('https://site.test/craft/editrix/api/logs/validate-revert/251?site=en_us');
  });

  it('keeps every query parameter', () => {
    expect(urlWithId('/x/delete?site=en_us&foo=bar', 7)).toBe('/x/delete/7?site=en_us&foo=bar');
  });
});

describe('urlWithQuery', () => {
  it('starts the query on a plain url', () => {
    expect(urlWithQuery('/x/export', new URLSearchParams({ format: 'csv' }))).toBe('/x/export?format=csv');
  });

  it('continues the query of a multi-site url', () => {
    expect(
      urlWithQuery('/craft/editrix?site=en_us', new URLSearchParams({ q: 'Acme', ci: '1' }))
    ).toBe('/craft/editrix?site=en_us&q=Acme&ci=1');
  });

  it('leaves the url alone when there is nothing to add', () => {
    expect(urlWithQuery('/x?site=en_us', new URLSearchParams())).toBe('/x?site=en_us');
  });
});
