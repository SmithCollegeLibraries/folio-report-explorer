import {
  BaseEdge,
  EdgeLabelRenderer,
  getSmoothStepPath,
  type Edge,
  type EdgeProps,
} from '@xyflow/react';

export interface BuilderRelationshipEdgeData extends Record<string, unknown> {
  pairId: string;
  relationshipId: string;
  leftTable: string;
  rightTable: string;
  label: string;
  alternativeCount: number;
  isDefault: boolean;
  onChoose: (pairId: string, trigger: HTMLButtonElement) => void;
}

type BuilderRelationshipFlowEdge = Edge<BuilderRelationshipEdgeData, 'builderRelationship'>;

export default function BuilderRelationshipEdge({
  id,
  sourceX,
  sourceY,
  targetX,
  targetY,
  sourcePosition,
  targetPosition,
  markerEnd,
  style,
  data,
}: EdgeProps<BuilderRelationshipFlowEdge>) {
  const [edgePath, labelX, labelY] = getSmoothStepPath({
    sourceX,
    sourceY,
    targetX,
    targetY,
    sourcePosition,
    targetPosition,
  });

  if (!data) return <BaseEdge id={id} path={edgePath} markerEnd={markerEnd} style={style} />;

  return (
    <>
      <BaseEdge id={id} path={edgePath} markerEnd={markerEnd} style={style} />
      <EdgeLabelRenderer>
        <div
          className="absolute nodrag nopan"
          style={{
            transform: `translate(-50%, -50%) translate(${labelX}px, ${labelY}px)`,
            pointerEvents: 'all',
          }}
        >
          {data.alternativeCount > 1 ? (
            <button
              type="button"
              aria-label={`Choose relationship between ${data.leftTable} and ${data.rightTable}`}
              data-relationship-pair-id={data.pairId}
              onClick={(event) => data.onChoose(data.pairId, event.currentTarget)}
              className={`rounded border px-2 py-1 font-mono text-[10px] shadow-sm ${
                data.isDefault
                  ? 'border-blue-200 bg-blue-50 text-blue-800'
                  : 'border-indigo-300 bg-indigo-50 text-indigo-900'
              }`}
            >
              {data.label}
            </button>
          ) : (
            <span className="rounded border border-blue-100 bg-blue-50 px-2 py-1 font-mono text-[10px] text-blue-800">
              {data.label}
            </span>
          )}
        </div>
      </EdgeLabelRenderer>
    </>
  );
}
