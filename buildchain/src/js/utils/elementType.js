// Human-readable label for a SearchResult's elementType, shared between the
// results table, the detail modal, and CSV export so they stay in sync.
const LABELS = {
  entry: 'Text field',
  matrixBlock: 'Matrix',
  neoBlock: 'Neo',
  global: 'Global',
  category: 'Category',
};

export const elementTypeLabel = (elementType) => LABELS[elementType] || elementType;
