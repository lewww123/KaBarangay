import { useEffect, useState } from 'react';
import axios from 'axios';

export interface RegistrationEmailVerification {
    status: 'unsent' | 'pending' | 'verified';
    email: string;
    expires_in: number;
    retry_after: number;
}

const EMPTY_VERIFICATION: RegistrationEmailVerification = {
    status: 'unsent',
    email: '',
    expires_in: 0,
    retry_after: 0,
};

const csrfHeaders = () => ({
    'X-CSRF-TOKEN':
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '',
    Accept: 'application/json',
});

export default function useRegistrationEmailOtp(
    email: string,
    initial: RegistrationEmailVerification = EMPTY_VERIFICATION,
) {
    const [status, setStatus] = useState(initial.status);
    const [activeEmail, setActiveEmail] = useState(initial.email);
    const [code, setCode] = useState('');
    const [busy, setBusy] = useState<'send' | 'verify' | null>(null);
    const [resendAt, setResendAt] = useState(
        () => Date.now() + initial.retry_after * 1000,
    );
    const [expiresAt, setExpiresAt] = useState(
        () => Date.now() + initial.expires_in * 1000,
    );
    const [clock, setClock] = useState(Date.now());
    const [feedback, setFeedback] = useState<{
        kind: 'success' | 'error';
        message: string;
    } | null>(null);
    const ticking =
        resendAt > clock || (status === 'verified' && expiresAt > clock);

    useEffect(() => {
        if (!ticking) return;
        const timer = window.setInterval(() => setClock(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [ticking]);

    useEffect(() => {
        if (status === 'verified' && expiresAt <= clock) {
            setStatus('pending');
            setCode('');
            setFeedback({
                kind: 'error',
                message:
                    'Email verification expired. Send a new OTP before submitting registration.',
            });
        }
    }, [clock, expiresAt, status]);

    useEffect(() => {
        if (!feedback) return;
        const timer = window.setTimeout(() => setFeedback(null), 8000);
        return () => window.clearTimeout(timer);
    }, [feedback]);

    const apply = (result: RegistrationEmailVerification) => {
        const now = Date.now();
        setActiveEmail(result.email);
        setStatus(result.status);
        setResendAt(now + result.retry_after * 1000);
        setExpiresAt(now + result.expires_in * 1000);
        setClock(now);
        setCode('');
    };

    const reportError = (reason: unknown, fallback: string) => {
        let message = fallback;
        if (axios.isAxiosError(reason)) {
            const payload = reason.response?.data;
            const fieldMessage = payload?.errors
                ? Object.values(payload.errors)
                      .flat()
                      .find((value) => typeof value === 'string')
                : null;
            message =
                typeof fieldMessage === 'string'
                    ? fieldMessage
                    : payload?.message || fallback;
            const retryAfter = Number(
                payload?.retry_after ?? reason.response?.headers['retry-after'],
            );
            if (
                reason.response?.status === 429 &&
                Number.isFinite(retryAfter) &&
                retryAfter > 0
            ) {
                setResendAt(Date.now() + retryAfter * 1000);
                setClock(Date.now());
            }
        }
        setFeedback({ kind: 'error', message });
    };

    const send = async () => {
        if (busy) return;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
            setFeedback({
                kind: 'error',
                message: 'Enter a valid email address before sending an OTP.',
            });
            return;
        }
        setBusy('send');
        try {
            const response = await axios.post(
                '/register/send-otp',
                { email },
                { headers: csrfHeaders() },
            );
            apply(response.data);
            setFeedback({ kind: 'success', message: response.data.message });
        } catch (reason) {
            reportError(
                reason,
                'Unable to send the OTP. Check your connection and try again.',
            );
        } finally {
            setBusy(null);
        }
    };

    const verify = async () => {
        if (busy || !/^[0-9]{6}$/.test(code)) return;
        setBusy('verify');
        try {
            const response = await axios.post(
                '/register/verify-otp',
                { email, code },
                { headers: csrfHeaders() },
            );
            apply(response.data);
            setFeedback({ kind: 'success', message: response.data.message });
        } catch (reason) {
            reportError(reason, 'Unable to verify the OTP. Please try again.');
        } finally {
            setBusy(null);
        }
    };

    const reset = () => {
        setStatus('unsent');
        setActiveEmail('');
        setCode('');
        setResendAt(0);
        setExpiresAt(0);
        setFeedback(null);
    };

    return {
        status,
        code,
        busy,
        feedback,
        send,
        verify,
        reset,
        verified:
            status === 'verified' &&
            activeEmail === email.trim().toLowerCase() &&
            expiresAt > clock,
        locked: busy !== null || status !== 'unsent',
        secondsRemaining: Math.max(0, Math.ceil((resendAt - clock) / 1000)),
        setCode: (value: string) =>
            setCode(value.replace(/\D/g, '').slice(0, 6)),
        showError: (message: string) => setFeedback({ kind: 'error', message }),
        dismissFeedback: () => setFeedback(null),
    };
}

export type RegistrationEmailOtpControls = ReturnType<
    typeof useRegistrationEmailOtp
>;
