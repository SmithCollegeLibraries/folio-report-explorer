export interface GateCountMonth {
  month: string;
  visits: number;
}

export interface GateCountFiscalYear {
  fiscalYearEnd: number;
  months: GateCountMonth[];
}

export interface GateCountDay {
  date: string;
  visits: number;
}

export interface GateCountHeatmapCell {
  weekday: number;
  hour: number;
  visits: number;
}

export interface GateCountResponse {
  doors: string[];
  period: { start: string; end: string };
  monthlyByFiscalYear: GateCountFiscalYear[];
  daily: GateCountDay[];
  heatmap: GateCountHeatmapCell[];
  topDays: GateCountDay[];
}
