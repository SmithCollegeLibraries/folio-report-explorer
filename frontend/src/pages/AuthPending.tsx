/**
 * Shown when a user has authenticated via Shibboleth but their account
 * hasn't been approved by an admin yet.
 */
export default function AuthPending() {
  return (
    <div className="flex items-center justify-center min-h-[60vh]">
      <div className="bg-amber-50 border border-amber-200 rounded-lg p-8 max-w-lg text-center">
        <div className="text-4xl mb-4">⏳</div>
        <h2 className="text-xl font-semibold text-amber-800 mb-3">
          Account Pending Approval
        </h2>
        <p className="text-amber-700 mb-4">
          Your account has been created, but it needs to be approved by an
          administrator before you can access the application.
        </p>
        <p className="text-amber-600 text-sm">
          Please contact the library systems team to request access.
        </p>
      </div>
    </div>
  );
}
