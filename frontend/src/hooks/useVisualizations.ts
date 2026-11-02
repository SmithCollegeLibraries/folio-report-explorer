import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchVisualizationOptions, fetchVisualizations, type VisualizationData } from '../api/visualizations';

interface SuccessfulSelection {
  unitId: string;
  years: number;
  materialYear: number | 'all' | undefined;
  data: VisualizationData;
}

function emptySelection(data: VisualizationData, years: number, materialYear: number | 'all' | undefined): VisualizationData {
  const lastYear = data.fiscalYearEnds[data.fiscalYearEnds.length - 1] ?? data.currentFiscalYearEnd;
  return {
    ...data,
    fiscalYearEnds: lastYear === null ? [] : Array.from({ length: years }, (_, index) => lastYear - years + index + 1),
    purchasesByCategory: [],
    purchasesByMaterial: { fiscalYearEnd: materialYear ?? data.currentFiscalYearEnd, items: [] },
    budget: [],
  };
}

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
  const [lastSuccess, setLastSuccess] = useState<SuccessfulSelection>();
  useEffect(() => {
    if (figures.data && unitId) setLastSuccess({ unitId, years, materialYear, data: figures.data });
  }, [figures.data, unitId, years, materialYear]);
  const changeUnit = (id: string) => {
    setSelectedUnit(id);
    setMaterialYear(undefined);
  };
  const previous = lastSuccess?.unitId === unitId ? lastSuccess : undefined;
  const materialFiscalYearEnds = figures.data?.materialFiscalYearEnds ?? previous?.data.materialFiscalYearEnds ?? [];
  let fallback = previous?.data;
  if (previous && fallback && previous.years !== years) fallback = emptySelection(fallback, years, materialYear);
  else if (previous && fallback && previous.materialYear !== materialYear) {
    fallback = { ...fallback, purchasesByMaterial: { fiscalYearEnd: materialYear ?? fallback.currentFiscalYearEnd, items: [] } };
  }
  const data = figures.data ?? fallback;
  const resolvedMaterialYear: number | 'all' | '' = materialYear ?? figures.data?.purchasesByMaterial.fiscalYearEnd
    ?? data?.currentFiscalYearEnd ?? '';
  return {
    options, figures, data, materialFiscalYearEnds,
    materialLoading: figures.isFetching && !figures.error,
    materialError: figures.error ? figures.error : undefined,
    materialAvailable: figures.data !== undefined,
    unitId, years, setYears, changeUnit, setMaterialYear,
    materialYear: resolvedMaterialYear,
    retry: () => options.isError ? options.refetch() : figures.refetch(),
  };
}
