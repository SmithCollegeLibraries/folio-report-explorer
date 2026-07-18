import { beforeEach, describe, expect, it, vi } from 'vitest';

const post = vi.fn();
const get = vi.fn();
const patch = vi.fn();
const del = vi.fn();

vi.mock('axios', () => ({
  default: {
    create: vi.fn(() => ({
      post,
      get,
      patch,
      delete: del,
      interceptors: {
        request: { use: vi.fn() },
        response: { use: vi.fn() },
      },
    })),
    post: vi.fn(),
  },
}));

describe('Builder schema identity API requests', () => {
  beforeEach(() => {
    post.mockReset();
    get.mockReset();
    patch.mockReset();
    del.mockReset();
    get.mockResolvedValue({ data: {} });
  });

  it('opts schema, table detail, and path requests into LDLite identity', async () => {
    const { fetchSchema, fetchTableDetail, findPath } = await import('./client');

    await fetchSchema(undefined, 'ldlite');
    expect(get).toHaveBeenCalledWith('/schema', { params: { identity: 'ldlite' } });

    await fetchTableDetail('inventory.item__t', 'ldlite');
    expect(get).toHaveBeenCalledWith('/schema/inventory.item__t', {
      params: { identity: 'ldlite' },
    });

    await findPath('inventory.item__t', 'inventory.location__t', false, 6, 'ldlite');
    expect(get).toHaveBeenCalledWith('/path', {
      params: {
        from: 'inventory.item__t',
        to: 'inventory.location__t',
        all: 0,
        maxDepth: 6,
        identity: 'ldlite',
      },
    });
  });

  it('keeps identity out of legacy schema requests', async () => {
    const { fetchSchema, fetchTableDetail, findPath } = await import('./client');

    await fetchSchema();
    expect(get).toHaveBeenCalledWith('/schema', { params: {} });

    await fetchTableDetail('inventory_items');
    expect(get).toHaveBeenCalledWith('/schema/inventory_items');

    await findPath('inventory_items', 'inventory_locations');
    expect(get).toHaveBeenCalledWith('/path', {
      params: {
        from: 'inventory_items',
        to: 'inventory_locations',
        all: 0,
        maxDepth: 6,
      },
    });
  });
});
