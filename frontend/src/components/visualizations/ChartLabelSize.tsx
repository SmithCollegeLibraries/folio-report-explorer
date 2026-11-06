import { createContext, useContext } from 'react';

export const ChartLabelSizeContext = createContext(13);

export function useChartLabelSize() {
  return useContext(ChartLabelSizeContext);
}
