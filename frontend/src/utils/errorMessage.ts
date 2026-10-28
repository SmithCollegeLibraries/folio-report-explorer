type ErrorRecord = Record<string, unknown>;

function asRecord(value: unknown): ErrorRecord | null {
  return typeof value === 'object' && value !== null ? value as ErrorRecord : null;
}

export function errorMessage(error: unknown, fallback = 'The request could not be completed.'): string {
  const response = asRecord(asRecord(error)?.response);
  const data = asRecord(response?.data);
  const apiMessage = [data?.error, data?.message].find((message): message is string => typeof message === 'string' && Boolean(message.trim()));
  if (apiMessage) return apiMessage;
  if (error instanceof Error) return error.message;
  return fallback;
}
