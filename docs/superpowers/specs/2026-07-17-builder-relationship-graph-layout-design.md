# Builder Relationship Graph Layout Design

## Purpose

Make the Query Builder relationship graph readable as tables are added without overriding a layout that the user has deliberately arranged.

The governing interaction rule is:

> Automatic layout may organize a graph until the user moves a node. After a manual move, existing node positions remain under user control until the user explicitly requests a new automatic layout.

## Current Behavior

`BuilderGraph` places selected tables in insertion order on a fixed grid of up to five columns. Related unselected tables are placed in a single row below the selected tables. The layout does not use relationship topology, minimize edge crossings, or keep strongly related tables close together.

When selected tables or relationship data changes, the component replaces the current React Flow nodes with freshly computed grid positions. This also discards useful manual positioning. React Flow provides node dragging and viewport controls, but it does not provide automatic graph layout itself.

## Proposed Layout Model

Use ELK's layered graph algorithm to calculate the initial layout and subsequent user-requested layouts. Configure it for a left-to-right relationship flow with adequate spacing between table nodes and orthogonal-style edge routing.

`BuilderGraph` owns two layout states for the lifetime of the open graph:

- `automatic`: no table node has been manually repositioned;
- `user-arranged`: at least one node has been moved by the user.

The state is local to the open graph. Closing and reopening the graph starts a new automatic-layout session. Persisting personal graph layouts is outside this delivery.

## Interaction Behavior

### Initial display

- Run ELK after the visible selected and connected-table nodes are available.
- Fit the completed layout into the viewport.
- Keep manual dragging, zooming, and panning enabled.
- Zooming and panning do not change the layout state.

### Changes while automatic

- Adding or removing a table reruns ELK for the complete visible graph.
- Animate nodes from their current positions to the new layout.
- Refit the viewport after the layout completes.
- Discard an obsolete asynchronous layout result if the graph changes again before it finishes.

### Manual arrangement

- Completing a node drag changes the state to `user-arranged`.
- Adding or removing a table must not move existing nodes while in this state.
- A related table added by clicking an existing ghost node retains that node's current position when it becomes selected.
- A newly visible node without a prior position is placed near its most strongly connected visible node. If that position overlaps another node, search adjacent grid offsets until an open position is found.
- Changes to ghost nodes may add or remove those nodes, but retained visible nodes keep their positions by node ID.

### Re-layout action

- Show a `Re-layout` button in the graph header or graph control panel.
- Keep the button available in both states so users can recover from an awkward automatic or manual layout.
- Clicking it runs ELK over the complete current graph, animates nodes into place, fits the viewport, and returns the state to `automatic`.
- Disable the button and show a short loading state while layout is running.
- The action affects positions only; selected tables, columns, filters, joins, and query state do not change.

## Layout Architecture

Add a focused layout adapter rather than embedding ELK configuration in `BuilderGraph`.

```ts
interface RelationshipLayoutInput {
  nodes: Node[];
  edges: Edge[];
  direction: 'RIGHT';
}

interface RelationshipLayoutResult {
  nodes: Node[];
  edges: Edge[];
}

async function layoutRelationshipGraph(
  input: RelationshipLayoutInput,
): Promise<RelationshipLayoutResult>;
```

The adapter:

- converts React Flow nodes and edges to ELK input;
- supplies measured node dimensions or stable defaults;
- uses the layered algorithm with left-to-right direction;
- configures node, layer, and edge spacing centrally;
- maps calculated positions back to React Flow nodes without changing node data;
- returns edges configured for readable orthogonal/smooth-step rendering; and
- has no React state or Query Builder business logic.

`BuilderGraph` remains responsible for building graph content, tracking node positions and layout mode, reacting to table changes, and calling React Flow's viewport methods after a successful layout.

## Position Reconciliation

Every graph update reconciles nodes by table ID:

1. Build the next selected and connected node/edge set.
2. If the layout state is `automatic`, send the complete set to ELK.
3. If the layout state is `user-arranged`, copy positions for every retained node ID.
4. Preserve an existing ghost node's position when it changes to a selected node.
5. Position only genuinely new nodes using the connected-neighbor and collision fallback policy.
6. Remove positions for nodes no longer visible.

This reconciliation prevents table-detail refreshes and unrelated React renders from resetting the graph.

## Visual Treatment

- Preserve the existing selected-table and connected-table visual distinction.
- Use layered placement and smooth-step or orthogonal edges to reduce crossings.
- Keep relationship labels legible and prevent them from sitting directly on table nodes.
- Add a restrained position transition of approximately 200–300 milliseconds for automatic layout changes.
- Respect reduced-motion preferences by disabling the transition.
- Use the existing Builder colors, typography, modal, legend, and React Flow controls rather than redesigning the page.

## Failure And Edge Cases

- If ELK fails, retain the last valid positions and show a non-blocking `Could not arrange this graph` message.
- An empty graph continues to show the current empty state.
- A one-node graph centers the node without unnecessary edge calculations.
- Disconnected selected components are laid out as separate groups with clear spacing.
- Cyclic relationships must not prevent layout; ELK may reverse layout direction internally while displayed edge arrows retain relationship direction.
- Rapid table additions use a request sequence token so only the latest layout result is applied.
- The current limit on visible connected ghost tables remains unchanged unless separately revised.

## Testing

### Layout adapter

- Related nodes receive distinct, non-overlapping positions.
- A simple parent chain is ordered left to right.
- Disconnected components remain separated.
- Cyclic input resolves without throwing.
- Node data and IDs are unchanged by layout.

### Builder graph interaction

- Initial display invokes automatic layout and fits the viewport.
- Adding a table in `automatic` mode reruns full layout.
- Dragging a node changes the mode to `user-arranged`.
- Adding or removing a table in `user-arranged` mode preserves retained positions.
- Promoting a dragged ghost node to selected preserves its position.
- Clicking `Re-layout` runs full layout, fits the viewport, and returns to `automatic` mode.
- A stale asynchronous result cannot overwrite a newer graph.
- Layout failure preserves the current graph and exposes a non-blocking message.

## Acceptance Criteria

- The initial relationship graph is arranged according to actual table relationships rather than selection order.
- Automatic layouts materially reduce overlap and avoid the current single-row ghost-node arrangement.
- No existing node moves automatically after the user manually arranges the graph.
- New tables remain visible and usable in a user-arranged graph without resetting existing positions.
- Users can request a complete ELK layout at any time through `Re-layout`.
- Table selection, removal, relationship display, query construction, zooming, and panning continue to work.
- Layout behavior has focused unit and interaction regression coverage.

## Non-Goals

- Saving graph positions between browser sessions or users.
- Allowing users to edit database relationships in the graph.
- Changing relationship discovery or Query Builder join semantics.
- Removing manual node dragging.
- Redesigning the Query Builder outside the relationship graph.
