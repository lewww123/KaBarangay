import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

type SelectOption = { value: string; label: string };

type FieldTarget = {
    key: string;
    label: string;
    type: string;
    value: string;
    options: SelectOption[];
};

type DocumentTarget = {
    key: string;
    label: string;
};

type Correction = {
    cycle_number: number;
    instructions: string;
    field_targets: FieldTarget[];
    document_targets: DocumentTarget[];
};

type Application = {
    reference: string;
    applicant_name: string;
    status: string;
    submitted_at: string | null;
    barangay: string | null;
    purok: string | null;
    correction: Correction | null;
};

type Props = {
    application: Application;
};

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pending review',
    needs_correction: 'Needs correction',
    rejected: 'Decision recorded',
    approved: 'Decision recorded',
};

export default function RegistrationApplication({ application }: Props) {
    const logout = useForm({});
    const correction = application.correction;
    const correctionForm = useForm<{
        fields: Record<string, string>;
        documents: Record<string, File | null>;
    }>({
        fields: Object.fromEntries(
            correction?.field_targets.map((target) => [
                target.key,
                target.value,
            ]) ?? [],
        ),
        documents: Object.fromEntries(
            correction?.document_targets.map((target) => [target.key, null]) ??
                [],
        ),
    });
    const correctionErrors = correctionForm.errors as Record<
        string,
        string | undefined
    >;
    const terminal =
        application.status === 'approved' || application.status === 'rejected';

    function signOut() {
        logout.post(
            `/resident/application/${encodeURIComponent(application.reference)}/logout`,
        );
    }

    function submitCorrection(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        correctionForm.post(
            `/resident/application/${encodeURIComponent(application.reference)}/correction`,
            {
                forceFormData: true,
                preserveScroll: true,
            },
        );
    }


    return (
        <div className="min-h-screen bg-[#f7f6f0]">
            <Head title="Resident Application Status" />
            <header className="border-b border-[#315b30] bg-[#3f6f3d]">
                <div className="mx-auto flex min-h-16 w-full max-w-4xl items-center justify-between px-4 sm:px-6">
                    <div className="text-white">
                        <p className="text-base font-bold">KaBarangay</p>
                        <p className="text-xs text-white/80">
                            Secure application access
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={signOut}
                        disabled={logout.processing}
                        className="rounded-lg border border-white/50 px-3 py-2 text-xs font-semibold text-white hover:bg-white/10 disabled:opacity-60"
                    >
                        {logout.processing ? 'Signing out…' : 'Sign out'}
                    </button>
                </div>
            </header>

            <main className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
                <section className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-8">
                    <p className="text-xs font-semibold tracking-wider text-gray-400 uppercase">
                        Application status
                    </p>
                    <h1 className="mt-2 text-2xl font-bold text-gray-900">
                        {STATUS_LABELS[application.status] ??
                            'Status unavailable'}
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-gray-600">
                        This page shows information for the application
                        authenticated in this session. It does not create a
                        Resident account or change your application.
                    </p>

                    <dl className="mt-6 grid gap-4 rounded-xl border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                Applicant
                            </dt>
                            <dd className="mt-1 text-sm font-medium text-gray-800">
                                {application.applicant_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                Application reference
                            </dt>
                            <dd className="mt-1 font-mono text-sm break-all text-gray-800">
                                {application.reference}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                Barangay
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800">
                                {application.barangay || '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                Purok
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800">
                                {application.purok || '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-gray-400 uppercase">
                                Submitted
                            </dt>
                            <dd className="mt-1 text-sm text-gray-800">
                                {application.submitted_at || '—'}
                            </dd>
                        </div>
                    </dl>

                    <section className="mt-6 rounded-xl border border-[#d7e5d2] bg-[#f8fbf6] p-4 sm:p-5">
                        <h2 className="text-sm font-semibold text-gray-800">Email notifications</h2>
                        <p className="mt-2 text-sm text-gray-600">
                            Email is optional. If supplied, it may receive the admin decision.
                        </p>
                        <p className="mt-2 text-sm text-gray-600">
                            Mobile number – Unverified. Verification is not yet available.
                        </p>
                    </section>

                    {application.status === 'needs_correction' &&
                        correction && (
                            <section className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-5">
                                <h2 className="text-lg font-bold text-amber-950">
                                    Correction requested
                                </h2>
                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-amber-900">
                                    {correction.instructions}
                                </p>

                                <form
                                    onSubmit={submitCorrection}
                                    className="mt-5 space-y-5"
                                >
                                    {correction.field_targets.length > 0 && (
                                        <fieldset className="space-y-4">
                                            <legend className="text-sm font-semibold text-gray-800">
                                                Fields you may correct
                                            </legend>
                                            {correction.field_targets.map(
                                                (target) => (
                                                    <div key={target.key}>
                                                        <label
                                                            htmlFor={`correction-${target.key}`}
                                                            className="mb-1 block text-sm font-medium text-gray-700"
                                                        >
                                                            {target.label}
                                                        </label>
                                                        {target.type ===
                                                        'select' ? (
                                                            <select
                                                                id={`correction-${target.key}`}
                                                                value={
                                                                    correctionForm
                                                                        .data
                                                                        .fields[
                                                                        target
                                                                            .key
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    correctionForm.setData(
                                                                        'fields',
                                                                        {
                                                                            ...correctionForm
                                                                                .data
                                                                                .fields,
                                                                            [target.key]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                required
                                                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                                                            >
                                                                <option value="">
                                                                    Select{' '}
                                                                    {target.label.toLowerCase()}
                                                                </option>
                                                                {target.options.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.value
                                                                            }
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        ) : (
                                                            <input
                                                                id={`correction-${target.key}`}
                                                                type={
                                                                    target.type
                                                                }
                                                                value={
                                                                    correctionForm
                                                                        .data
                                                                        .fields[
                                                                        target
                                                                            .key
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    correctionForm.setData(
                                                                        'fields',
                                                                        {
                                                                            ...correctionForm
                                                                                .data
                                                                                .fields,
                                                                            [target.key]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                required={
                                                                    ![
                                                                        'middle_name',
                                                                        'suffix',
                                                                        'email',
                                                                    ].includes(
                                                                        target.key,
                                                                    )
                                                                }
                                                                className="min-h-11 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                                                            />
                                                        )}
                                                        {correctionForm.errors[
                                                            `fields.${target.key}`
                                                        ] && (
                                                            <p
                                                                role="alert"
                                                                className="mt-1 text-xs text-red-700"
                                                            >
                                                                {
                                                                    correctionForm
                                                                        .errors[
                                                                        `fields.${target.key}`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </fieldset>
                                    )}

                                    {correction.document_targets.length > 0 && (
                                        <fieldset className="space-y-4">
                                            <legend className="text-sm font-semibold text-gray-800">
                                                Replacement documents
                                            </legend>
                                            {correction.document_targets.map(
                                                (target) => (
                                                    <div key={target.key}>
                                                        <label
                                                            htmlFor={`correction-document-${target.key}`}
                                                            className="mb-1 block text-sm font-medium text-gray-700"
                                                        >
                                                            {target.label}
                                                        </label>
                                                        <input
                                                            id={`correction-document-${target.key}`}
                                                            type="file"
                                                            accept={
                                                                target.key ===
                                                                'live_identity_photo'
                                                                    ? 'image/jpeg,image/png'
                                                                    : 'image/jpeg,image/png,application/pdf'
                                                            }
                                                            onChange={(event) =>
                                                                correctionForm.setData(
                                                                    'documents',
                                                                    {
                                                                        ...correctionForm
                                                                            .data
                                                                            .documents,
                                                                        [target.key]:
                                                                            event
                                                                                .target
                                                                                .files?.[0] ??
                                                                            null,
                                                                    },
                                                                )
                                                            }
                                                            required
                                                            className="block w-full rounded-lg border border-gray-300 bg-white p-2 text-sm"
                                                        />
                                                        {correctionForm.errors[
                                                            `documents.${target.key}`
                                                        ] && (
                                                            <p
                                                                role="alert"
                                                                className="mt-1 text-xs text-red-700"
                                                            >
                                                                {
                                                                    correctionForm
                                                                        .errors[
                                                                        `documents.${target.key}`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </fieldset>
                                    )}

                                    {correctionErrors.correction && (
                                        <p
                                            role="alert"
                                            className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                                        >
                                            {correctionErrors.correction}
                                        </p>
                                    )}

                                    <p className="text-xs leading-5 text-gray-600">
                                        Only the fields and documents listed
                                        above can be submitted. Your application
                                        remains the same application and returns
                                        to Pending review after successful
                                        resubmission.
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={correctionForm.processing}
                                        className="min-h-11 rounded-lg bg-[#3f6f3d] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {correctionForm.processing
                                            ? 'Submitting…'
                                            : 'Resubmit correction'}
                                    </button>
                                </form>
                            </section>
                        )}

                    {application.status === 'needs_correction' &&
                        !correction && (
                            <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                Correction details are temporarily unavailable.
                                Contact your barangay office for assistance.
                            </div>
                        )}

                    {terminal && (
                        <p className="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                            This application is read-only.
                        </p>
                    )}
                </section>
            </main>
        </div>
    );
}
