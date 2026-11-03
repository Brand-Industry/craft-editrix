// The API URLs come from PHP and can already carry a query string
// (`?site=en_us` on a multi-site install), so the id goes into the path,
// ahead of the query.
export const urlWithId = (url, id) => {
  const queryAt = url.indexOf('?');
  if (queryAt === -1) return `${url}/${id}`;
  return `${url.slice(0, queryAt)}/${id}${url.slice(queryAt)}`;
};
