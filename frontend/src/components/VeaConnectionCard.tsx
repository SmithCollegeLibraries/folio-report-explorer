import { DoorOpen, Loader2, RefreshCw } from 'lucide-react';
import { useVeaConnection } from '../hooks/useVeaConnection';
import type { VeaErrorCode } from '../api/vea';

const ERROR_MESSAGES: Record<VeaErrorCode, string> = {
  not_configured: 'Vea is not configured.',
  unreachable: 'Could not reach Vea.',
  auth_failed: 'Vea rejected the credentials.',
  vea_error: 'Vea returned an error.',
  invalid_input: 'The door selection is invalid.',
  unknown_zone: 'One or more doors are no longer available.',
  not_selectable: 'Only active traffic counters can be selected.',
  save_failed: 'Could not save the selected doors.',
};

export default function VeaConnectionCard() {
  const connection = useVeaConnection();

  return (
    <section className="mb-8">
      <div className="flex items-center gap-2 mb-4">
        <DoorOpen size={18} className="text-blue-600" />
        <h3 className="text-lg font-semibold">Gate counts (SenSource Vea)</h3>
      </div>

      <div className="bg-white border rounded-lg p-5 space-y-4">
        {connection.statusLoading && <p className="text-sm text-gray-500">Loading Vea settings…</p>}
        {connection.statusError && <p className="text-sm text-red-700">Could not load Vea settings.</p>}
        {!connection.statusLoading && !connection.statusError && connection.status?.configured === false && (
          <p className="text-sm text-gray-600">
            Add VEA_CLIENT_ID and VEA_CLIENT_SECRET to the server .env
          </p>
        )}

        {connection.status?.configured && (
          <>
            <p className="text-sm text-gray-600">
              Choose active traffic-counting zones that represent library entrances.
            </p>
            <div className="flex flex-wrap gap-2">
              <button
                type="button"
                onClick={connection.test}
                disabled={connection.testPending}
                className="inline-flex items-center gap-2 border px-4 py-2 rounded text-sm hover:bg-gray-50 disabled:opacity-50"
              >
                {connection.testPending ? <Loader2 size={14} className="animate-spin" /> : <RefreshCw size={14} />}
                Test connection
              </button>
              <button
                type="button"
                onClick={connection.toggleZones}
                className="border px-4 py-2 rounded text-sm hover:bg-gray-50"
                aria-expanded={connection.zonesOpen}
              >
                {connection.zonesOpen ? 'Hide doors' : 'Load doors'}
              </button>
            </div>

            {connection.testResult && (
              <p role="status" className={`text-sm ${connection.testResult.ok ? 'text-green-700' : 'text-red-700'}`}>
                {connection.testResult.ok
                  ? `Connected to Vea. ${connection.testResult.zoneCount ?? 0} zones available.`
                  : ERROR_MESSAGES[connection.testResult.error ?? 'vea_error']}
              </p>
            )}

            {connection.zonesOpen && (
              <div className="border rounded-lg divide-y">
                {connection.zonesLoading && <p className="p-3 text-sm text-gray-500">Loading doors…</p>}
                {connection.zonesError && (
                  <p role="alert" className="p-3 text-sm text-red-700">{ERROR_MESSAGES[connection.zonesError]}</p>
                )}
                {!connection.zonesLoading && !connection.zonesError && connection.zones.length === 0 && (
                  <p className="p-3 text-sm text-gray-500">No Vea zones found.</p>
                )}
                {connection.zones.map((zone) => (
                  <label
                    key={zone.zoneId}
                    className={`flex items-center gap-3 px-3 py-2 text-sm ${zone.selectable ? 'text-gray-800' : 'text-gray-400'}`}
                  >
                    <input
                      type="checkbox"
                      checked={zone.selectable && connection.selectedZoneIds.includes(zone.zoneId)}
                      disabled={!zone.selectable}
                      onChange={() => connection.toggleZone(zone.zoneId)}
                    />
                    <span>{zone.name}</span>
                    {!zone.selectable && (
                      <span className="text-xs">{zone.disabled ? 'disabled' : 'not a traffic counter'}</span>
                    )}
                  </label>
                ))}
                {!connection.zonesLoading && !connection.zonesError && (
                  <div className="flex items-center gap-3 p-3">
                    <button
                      type="button"
                      onClick={connection.save}
                      disabled={connection.savePending}
                      className="bg-folio-600 text-white px-4 py-2 rounded text-sm hover:bg-folio-700 disabled:opacity-50"
                    >
                      {connection.savePending ? 'Saving…' : 'Save doors'}
                    </button>
                    {connection.saved && <span role="status" className="text-sm text-green-700">Doors saved.</span>}
                    {connection.saveError && <span role="alert" className="text-sm text-red-700">{ERROR_MESSAGES[connection.saveError]}</span>}
                  </div>
                )}
              </div>
            )}
          </>
        )}
      </div>
    </section>
  );
}
