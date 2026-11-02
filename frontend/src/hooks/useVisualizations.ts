import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchVisualizationOptions, fetchVisualizations } from '../api/visualizations';

export function useVisualizations() {
  const [selectedUnit, setSelectedUnit] = useState<string>();
  const [years, setYears] = useState(10);
  const [materialYear, setMaterialYear] = useState<number | 'all'>();
  const options = useQuery({ queryKey: ['visualization-options'], queryFn: fetchVisualizationOptions });
  const unitId = selectedUnit ?? options.data?.units.find((unit) => unit.code === 'SC')?.id
    ?? options.data?.units[0]?.id;
  const figures = useQuery({
    queryKey: ['visualizations', unitId, years, materialYear],
    queryFn: () => fetchVisualizations({
      acqUnitId: unitId!, years,
      ...(materialYear === undefined ? {} : { materialFiscalYearEnd: materialYear }),
    }),
    enabled: Boolean(unitId),
  });
  const changeUnit = (id: string) => {
    setSelectedUnit(id);
    setMaterialYear(undefined);
  };
  const resolvedMaterialYear: number | 'all' | '' = materialYear ?? figures.data?.purchasesByMaterial.fiscalYearEnd
    ?? figures.data?.currentFiscalYearEnd ?? '';
  return {
    options, figures, unitId, years, setYears, changeUnit, setMaterialYear,
    materialYear: resolvedMaterialYear,
    retry: () => options.isError ? options.refetch() : figures.refetch(),
  };
}
