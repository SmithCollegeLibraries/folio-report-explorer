import { describe, expect, it } from 'vitest';
import type { CanonicalQueryDefinition, QueryDefinition } from './schema';

const legacyDefinition: QueryDefinition = {
  tables: ['inventory_items'],
  columns: [],
  filters: [],
  joins: [{
    from_table: 'inventory_items',
    from_column: 'location_id',
    to_table: 'inventory_locations',
    to_column: 'id',
    foreign_key: 'legacy_fk',
  }],
  orderBy: [],
  limit: 100,
};

const canonicalDefinition: CanonicalQueryDefinition = {
  schemaIdentity: 'ldlite',
  tables: ['inventory.item__t', 'inventory.location__t'],
  columns: [],
  filters: [],
  joins: [{ relationship_id: 'item-effective-location' }],
  orderBy: [],
  limit: 100,
};

const unsafeCanonicalDefinition: CanonicalQueryDefinition = {
  schemaIdentity: 'ldlite',
  tables: ['inventory.item__t', 'inventory.location__t'],
  columns: [],
  filters: [],
  joins: [{
    // @ts-expect-error canonical definitions reject raw join predicates
    from_table: 'inventory.item__t',
    from_column: 'effective_location_id',
    to_table: 'inventory.location__t',
    to_column: 'id',
    foreign_key: 'untrusted',
  }],
  orderBy: [],
  limit: 100,
};

describe('canonical query definition types', () => {
  it('retain legacy definitions and accept trusted canonical selections', () => {
    expect(legacyDefinition.tables).toEqual(['inventory_items']);
    expect(canonicalDefinition.schemaIdentity).toBe('ldlite');
    expect(unsafeCanonicalDefinition.schemaIdentity).toBe('ldlite');
  });
});
