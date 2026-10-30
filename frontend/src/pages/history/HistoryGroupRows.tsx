import type { ReactElement } from 'react';
import type { HistoryItem } from '../../types';
import type { HistoryGroupListItem } from '../../api/history';

export interface HistoryGroupRowsProps {
  group: HistoryGroupListItem;
  members: HistoryItem[];
  expanded: boolean;
  loading: boolean;
  onToggle: (group: HistoryGroupListItem) => void;
  onOpen: (member: HistoryItem) => void;
  onDelete: (jobId: string) => void;
  onCancel: (jobId: string) => void;
  onRename: (member: HistoryItem) => void;
}

export default function HistoryGroupRows(_props: HistoryGroupRowsProps): ReactElement {
  throw new Error('not implemented');
}
