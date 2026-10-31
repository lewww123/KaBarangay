import type { RegistrationEmailOtpControls } from '@/hooks/useRegistrationEmailOtp';

interface Props {
    email: string;
    onEmailChange: (email: string) => void;
    error?: string;
    controls: RegistrationEmailOtpControls;
}

export default function EmailVerificationSection({ email, onEmailChange, error, controls }: Props) {
    return (
        <div className="space-y-3">
            <label htmlFor="registration-email" className="mb-1.5 block text-sm font-semibold text-gray-700">
                Email Address <span className="text-red-500">*</span>
            </label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <input id="registration-email" name="email" type="email" autoComplete="email" required
                    value={email} disabled={controls.locked} onChange={(event) => onEmailChange(event.target.value)}
                    placeholder="name@example.com"
                    className="min-h-11 min-w-0 flex-1 rounded-xl border border-gray-300 bg-white px-3.5 text-base disabled:bg-gray-100 disabled:text-gray-600 sm:text-sm" />
                <button type="button" onClick={() => void controls.send()}
                    disabled={controls.busy !== null || controls.verified || controls.secondsRemaining > 0}
                    className="min-h-11 shrink-0 rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60">
                    {controls.busy === 'send' ? 'Sending OTP…' : controls.verified ? 'Email Verified' : controls.secondsRemaining > 0 ? `Resend OTP in ${controls.secondsRemaining}s` : controls.status === 'unsent' ? 'Send OTP' : 'Resend OTP'}
                </button>
            </div>
            {error && <p role="alert" className="text-xs font-semibold text-red-600">{error}</p>}

            <section aria-label="Email Verification" className="space-y-3 rounded-xl border border-[#cbdcc5] bg-[#f5f9f3] p-4">
                <h3 className="text-sm font-bold text-[#315b30]">Email Verification — Required</h3>
                {controls.verified ? (
                    <p role="status" className="text-sm font-semibold text-[#315b30]">✓ Your email is verified. You can continue with registration.</p>
                ) : controls.status === 'unsent' ? (
                    <p className="text-xs leading-5 text-gray-600">Enter your email address and select Send OTP. The six-digit verification field will appear after the code is sent.</p>
                ) : (
                    <>
                        <p className="text-xs leading-5 text-gray-600">Send an OTP to your email, then enter the six-digit code below. The code expires in 10 minutes. You must verify your email before continuing.</p>
                        <label htmlFor="registration-email-otp" className="block text-xs font-semibold text-gray-700">6-digit verification code</label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <input id="registration-email-otp" type="text" inputMode="numeric" autoComplete="one-time-code" pattern="[0-9]{6}" maxLength={6}
                                value={controls.code} onChange={(event) => controls.setCode(event.target.value)}
                                onKeyDown={(event) => { if (event.key === 'Enter') { event.preventDefault(); void controls.verify(); } }}
                                disabled={controls.busy !== null}
                                placeholder="000000" aria-label="6-digit verification code"
                                className="min-h-11 min-w-0 flex-1 rounded-xl border border-gray-300 bg-white px-3 text-center font-mono text-xl tracking-[0.3em] disabled:bg-gray-100" />
                            <button type="button" onClick={() => void controls.verify()}
                                disabled={controls.code.length !== 6 || controls.busy !== null}
                                className="min-h-11 shrink-0 rounded-xl border border-[#3f6f3d] bg-white px-4 py-2 text-sm font-bold text-[#315b30] disabled:cursor-not-allowed disabled:opacity-50">
                                {controls.busy === 'verify' ? 'Verifying…' : 'Verify OTP'}
                            </button>
                        </div>
                    </>
                )}
                {controls.locked && <button type="button" disabled={controls.busy !== null} onClick={controls.reset} className="text-xs font-semibold text-[#315b30] underline disabled:opacity-50">Use a different email (requires a new OTP)</button>}
            </section>
        </div>
    );
}
