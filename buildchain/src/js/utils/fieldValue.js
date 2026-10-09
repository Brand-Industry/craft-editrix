// What the server needs to find one result's field again. A row carries a
// short context and a fingerprint of the text, not the text.
export function fieldValueParams(result) {
  return {
    elementType: result.elementType,
    elementId: result.elementId,
    fieldHandle: result.fieldHandle,
    siteId: result.siteId,
    valueHash: result.valueHash ?? '',
  };
}

// A row sent back to the server (replace, preview) only needs to identify
// the field and say how many matches it had. The list of contexts is for
// display, and can be long.
export function slimRow(row, excludedMatches = []) {
  const { matches, ...rest } = row;
  return {
    ...rest,
    readOnlyMatchCount: (matches || []).filter(m => m.readOnly).length,
    // Occurrences left out of this replace, by their place in the row's list.
    ...(excludedMatches.length ? { excludedMatches } : {}),
  };
}
