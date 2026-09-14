import { Head, Link, useForm } from '@inertiajs/react';
import {
    ChangeEvent,
    FormEvent,
    ReactNode,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

type Sex = '' | 'Male' | 'Female';
type CivilStatus = '' | 'Single' | 'Married' | 'Widowed' | 'Separated';

type Purok = {
    id: number;
    name: string;
    code: string | null;
};

type Barangay = {
    id: number;
    name: string;
    code: string;
    city_municipality: string | null;
    province: string | null;
    puroks: Purok[];
};

type Sector = {
    id: number;
    name: string;
    slug: string;
    requires_proof: boolean;
};

type Props = {
    barangays?: Barangay[];
    sectors?: Sector[];
};

type ClientErrors = Record<string, string>;

const TOTAL_STEPS = 6;

const STEP_TITLES = [
    'Barangay',
    'Personal Information',
    'Sector Information',
    'Identity Verification',
    'Account Security',
    'Review & Submit',
];

export default function Register({ barangays, sectors }: Props) {
    const safeBarangays = Array.isArray(barangays) ? barangays : [];
    const safeSectors = Array.isArray(sectors) ? sectors : [];

    const [step, setStep] = useState(1);
    const [clientErrors, setClientErrors] = useState<ClientErrors>({});
    const [acceptedDeclaration, setAcceptedDeclaration] = useState(false);
    const [regularResidentSelected, setRegularResidentSelected] =
        useState(false);
    const [showPassword, setShowPassword] = useState(false);
    const [showPasswordConfirmation, setShowPasswordConfirmation] =
        useState(false);

    const {
        data,
        setData,
        post,
        processing,
        errors,
        clearErrors,
        progress,
    } = useForm({
        barangay_id: '',
        purok_id: '',
        first_name: '',
        middle_name: '',
        last_name: '',
        suffix: '',
        birthdate: '',
        sex: '' as Sex,
        civil_status: '' as CivilStatus,
        address_line: '',
        sectors: [] as string[],
        mobile: '',
        email: '',
        password: '',
        password_confirmation: '',
        valid_id: null as File | null,
        live_identity_photo: null as File | null,
        senior_proof: null as File | null,
        pwd_proof: null as File | null,
    });

    const selectedBarangay = useMemo(
        () =>
            safeBarangays.find(
                (barangay) => barangay.id === Number(data.barangay_id),
            ) ?? null,
        [safeBarangays, data.barangay_id],
    );

    const availablePuroks = Array.isArray(selectedBarangay?.puroks)
        ? selectedBarangay.puroks
        : [];

    const selectedPurok = useMemo(
        () =>
            availablePuroks.find(
                (purok) => purok.id === Number(data.purok_id),
            ) ?? null,
        [availablePuroks, data.purok_id],
    );

    const selectedSectorNames = useMemo(
        () =>
            safeSectors
                .filter((sector) => data.sectors.includes(sector.slug))
                .map((sector) => sector.name),
        [data.sectors, safeSectors],
    );

    const isSenior = data.sectors.includes('senior');
    const isPwd = data.sectors.includes('pwd');

    const fieldError = (key: string) =>
        clientErrors[key] ?? (errors as Record<string, string>)[key];

    const clearFieldError = (key: string) => {
        setClientErrors((current) => {
            const next = { ...current };
            delete next[key];
            return next;
        });

        clearErrors(key as never);
    };

    const scrollTop = () => {
        window.scrollTo({
            top: 0,
            behavior: 'smooth',
        });
    };

    const chooseBarangay = (value: string) => {
        setData('barangay_id', value);
        setData('purok_id', '');
        clearFieldError('barangay_id');
        clearFieldError('purok_id');
    };

    const toggleRegularResident = () => {
        const nextSelected = !regularResidentSelected;

        setRegularResidentSelected(nextSelected);

        if (nextSelected) {
            setData('sectors', []);
            setData('senior_proof', null);
            setData('pwd_proof', null);

            clearFieldError('senior_proof');
            clearFieldError('pwd_proof');
            clearFieldError('sectors');
        }

        clearFieldError('resident_classification');
    };

    const toggleSector = (slug: string) => {
        if (regularResidentSelected) {
            return;
        }

        const selected = data.sectors.includes(slug);

        setData(
            'sectors',
            selected
                ? data.sectors.filter((item) => item !== slug)
                : [...data.sectors, slug],
        );

        if (slug === 'senior' && selected) {
            setData('senior_proof', null);
            clearFieldError('senior_proof');
        }

        if (slug === 'pwd' && selected) {
            setData('pwd_proof', null);
            clearFieldError('pwd_proof');
        }

        clearFieldError('sectors');
        clearFieldError('resident_classification');
    };

    const validateStep = (targetStep = step) => {
        const nextErrors: ClientErrors = {};

        if (targetStep === 1) {
            if (!data.barangay_id) {
                nextErrors.barangay_id = 'Please select your barangay.';
            } else if (selectedBarangay && selectedBarangay.puroks.length === 0) {
                nextErrors.barangay_id =
                    'Registration for this barangay is not available yet because its puroks have not been configured.';
            }
        }

        if (targetStep === 2) {
            if (!data.first_name.trim()) {
                nextErrors.first_name = 'First name is required.';
            }

            if (!data.last_name.trim()) {
                nextErrors.last_name = 'Last name is required.';
            }

            if (!data.birthdate) {
                nextErrors.birthdate = 'Birthdate is required.';
            }

            if (!data.sex) {
                nextErrors.sex = 'Please select your sex.';
            }

            if (!data.civil_status) {
                nextErrors.civil_status = 'Civil status is required.';
            }

            if (!data.purok_id) {
                nextErrors.purok_id = 'Please select your purok.';
            }

            if (!data.address_line.trim()) {
                nextErrors.address_line =
                    'House number, street, or sitio is required.';
            }
        }

        if (targetStep === 3) {
            if (!regularResidentSelected && data.sectors.length === 0) {
                nextErrors.resident_classification =
                    'Please select your resident classification.';
            }
        }

        if (targetStep === 4) {
            if (!data.valid_id) {
                nextErrors.valid_id = 'Please provide a valid ID.';
            }

            if (!data.live_identity_photo) {
                nextErrors.live_identity_photo =
                    'Please capture your live identity photo.';
            }

            if (isSenior && !data.senior_proof) {
                nextErrors.senior_proof =
                    'Senior Citizen ID or supporting proof is required.';
            }

            if (isPwd && !data.pwd_proof) {
                nextErrors.pwd_proof =
                    'PWD ID or supporting proof is required.';
            }
        }

        if (targetStep === 5) {
            const normalizedMobile = data.mobile.replace(/\D/g, '');

            if (!data.mobile.trim()) {
                nextErrors.mobile = 'Mobile number is required.';
            } else if (!/^09\d{9}$/.test(normalizedMobile)) {
                nextErrors.mobile =
                    'Enter a valid Philippine mobile number, for example 09171234567.';
            }

            if (
                data.email.trim() &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(data.email)
            ) {
                nextErrors.email = 'Enter a valid email address.';
            }

            if (!data.password) {
                nextErrors.password = 'Password is required.';
            } else {
                if (data.password.length < 8) {
                    nextErrors.password =
                        'Password must contain at least 8 characters.';
                } else if (!/[A-Za-z]/.test(data.password)) {
                    nextErrors.password =
                        'Password must contain at least one letter.';
                } else if (!/\d/.test(data.password)) {
                    nextErrors.password =
                        'Password must contain at least one number.';
                }
            }

            if (!data.password_confirmation) {
                nextErrors.password_confirmation =
                    'Please confirm your password.';
            } else if (data.password !== data.password_confirmation) {
                nextErrors.password_confirmation =
                    'Passwords do not match.';
            }
        }

        if (targetStep === 6 && !acceptedDeclaration) {
            nextErrors.declaration =
                'Please confirm that the information you provided is true and correct.';
        }

        setClientErrors(nextErrors);
        return Object.keys(nextErrors).length === 0;
    };

    const nextStep = () => {
        if (!validateStep()) {
            return;
        }

        setClientErrors({});
        setStep((current) => Math.min(current + 1, TOTAL_STEPS));
        scrollTop();
    };

    const previousStep = () => {
        setClientErrors({});
        setStep((current) => Math.max(current - 1, 1));
        scrollTop();
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!validateStep(6)) {
            return;
        }

        setClientErrors({});

        post('/resident/register', {
            forceFormData: true,
            preserveScroll: true,
            onError: (serverErrors) => {
                const keys = Object.keys(serverErrors);

                if (
                    keys.some((key) =>
                        ['barangay_id'].includes(key),
                    )
                ) {
                    setStep(1);
                } else if (
                    keys.some((key) =>
                        [
                            'purok_id',
                            'first_name',
                            'middle_name',
                            'last_name',
                            'suffix',
                            'birthdate',
                            'sex',
                            'civil_status',
                            'address_line',
                        ].includes(key),
                    )
                ) {
                    setStep(2);
                } else if (
                    keys.some((key) =>
                        ['sectors', 'sectors.0', 'sectors.1'].includes(key),
                    )
                ) {
                    setStep(3);
                } else if (
                    keys.some((key) =>
                        [
                            'valid_id',
                            'live_identity_photo',
                            'senior_proof',
                            'pwd_proof',
                        ].includes(key),
                    )
                ) {
                    setStep(4);
                } else if (
                    keys.some((key) =>
                        [
                            'mobile',
                            'email',
                            'password',
                            'password_confirmation',
                        ].includes(key),
                    )
                ) {
                    setStep(5);
                }

                scrollTop();
            },
        });
    };

    const fullName = [
        data.first_name,
        data.middle_name,
        data.last_name,
        data.suffix,
    ]
        .filter(Boolean)
        .join(' ');

    const barangayLocation = selectedBarangay
        ? [
              selectedBarangay.name,
              selectedBarangay.city_municipality,
              selectedBarangay.province,
          ]
              .filter(Boolean)
              .join(', ')
        : '';

    return (
        <>
            <Head title="Create Resident Account" />

            <div className="min-h-screen bg-[#f7f6f0]">
                <PublicHeader />

                <main className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 sm:py-8 lg:px-8 lg:py-10">
                    <div className="mx-auto max-w-4xl">
                        <div className="mb-5 text-center sm:mb-7">
                            <h1 className="text-2xl font-bold text-gray-900 sm:text-3xl">
                                Create Resident Account
                            </h1>

                            <p className="mx-auto mt-2 max-w-2xl text-sm leading-6 text-gray-500">
                                Create your account under the barangay where you
                                currently reside. Your barangay, address, sector
                                documents, and live identity photo will be reviewed
                                before your account is activated.
                            </p>
                        </div>

                        <RegistrationProgress step={step} />

                        {selectedBarangay && step > 1 && (
                            <div className="mt-4 flex flex-col gap-3 rounded-xl border border-[#dce8d8] bg-[#f5f9f3] px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex min-w-0 items-start gap-3">
                                    <div className="mt-0.5 shrink-0 text-[#3f6f3d]">
                                        <MapPinIcon />
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-[11px] font-bold uppercase tracking-wider text-[#3f6f3d]">
                                            Registering under
                                        </p>
                                        <p className="mt-0.5 truncate text-sm font-bold text-gray-800">
                                            {selectedBarangay.name}
                                        </p>
                                        <p className="mt-0.5 text-xs text-gray-500">
                                            {[
                                                selectedBarangay.city_municipality,
                                                selectedBarangay.province,
                                            ]
                                                .filter(Boolean)
                                                .join(', ')}
                                        </p>
                                    </div>
                                </div>

                                <button
                                    type="button"
                                    onClick={() => {
                                        setStep(1);
                                        setClientErrors({});
                                        scrollTop();
                                    }}
                                    className="min-h-10 shrink-0 rounded-lg border border-[#b9ceb4] bg-white px-4 text-xs font-bold text-[#3f6f3d] transition hover:bg-[#eef5eb]"
                                >
                                    Change Barangay
                                </button>
                            </div>
                        )}

                        {(errors as Record<string, string>).registration && (
                            <div role="alert" aria-live="polite" className="mt-5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                                {(errors as Record<string, string>).registration}
                            </div>
                        )}

                        <form
                            onSubmit={handleSubmit}
                            className="mt-5 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm sm:mt-6"
                        >
                            <div className="border-b border-gray-100 px-5 py-5 sm:px-7">
                                <p className="text-xs font-bold uppercase tracking-wider text-[#3f6f3d]">
                                    Step {step} of {TOTAL_STEPS}
                                </p>

                                <h2 className="mt-1 text-lg font-bold text-gray-900 sm:text-xl">
                                    {STEP_TITLES[step - 1]}
                                </h2>

                                <p className="mt-1 text-sm leading-6 text-gray-500">
                                    {getStepDescription(step)}
                                </p>
                            </div>

                            <div className="p-5 sm:p-7">
                                {step === 1 && (
                                    <BarangayStep
                                        barangays={safeBarangays}
                                        selectedBarangay={selectedBarangay}
                                        barangayId={data.barangay_id}
                                        error={fieldError('barangay_id')}
                                        onChange={chooseBarangay}
                                    />
                                )}

                                {step === 2 && (
                                    <PersonalInformationStep
                                        data={data}
                                        barangay={selectedBarangay}
                                        puroks={availablePuroks}
                                        errors={fieldError}
                                        setData={setData}
                                        clearFieldError={clearFieldError}
                                    />
                                )}

                                {step === 3 && (
                                    <SectorInformationStep
                                        sectors={safeSectors}
                                        selectedSlugs={data.sectors}
                                        regularSelected={regularResidentSelected}
                                        error={
                                            fieldError(
                                                'resident_classification',
                                            ) ?? fieldError('sectors')
                                        }
                                        onToggleRegular={toggleRegularResident}
                                        onToggle={toggleSector}
                                    />
                                )}

                                {step === 4 && (
                                    <IdentityVerificationStep
                                        isSenior={isSenior}
                                        isPwd={isPwd}
                                        validId={data.valid_id}
                                        liveIdentityPhoto={
                                            data.live_identity_photo
                                        }
                                        seniorProof={data.senior_proof}
                                        pwdProof={data.pwd_proof}
                                        errors={fieldError}
                                        setFile={(field, file) => {
                                            setData(field, file);
                                            clearFieldError(field);
                                        }}
                                    />
                                )}

                                {step === 5 && (
                                    <AccountSecurityStep
                                        data={data}
                                        errors={fieldError}
                                        setData={setData}
                                        clearFieldError={clearFieldError}
                                        showPassword={showPassword}
                                        showPasswordConfirmation={
                                            showPasswordConfirmation
                                        }
                                        togglePassword={() =>
                                            setShowPassword((value) => !value)
                                        }
                                        togglePasswordConfirmation={() =>
                                            setShowPasswordConfirmation(
                                                (value) => !value,
                                            )
                                        }
                                    />
                                )}

                                {step === 6 && (
                                    <ReviewStep
                                        fullName={fullName}
                                        barangay={selectedBarangay}
                                        purok={selectedPurok}
                                        addressLine={data.address_line}
                                        birthdate={data.birthdate}
                                        sex={data.sex}
                                        civilStatus={data.civil_status}
                                        mobile={data.mobile}
                                        email={data.email}
                                        sectorNames={selectedSectorNames}
                                        validId={data.valid_id}
                                        livePhoto={data.live_identity_photo}
                                        seniorProof={data.senior_proof}
                                        pwdProof={data.pwd_proof}
                                        isSenior={isSenior}
                                        isPwd={isPwd}
                                        declarationChecked={
                                            acceptedDeclaration
                                        }
                                        declarationError={fieldError(
                                            'declaration',
                                        )}
                                        onDeclarationChange={(checked) => {
                                            setAcceptedDeclaration(checked);
                                            if (checked) {
                                                clearFieldError('declaration');
                                            }
                                        }}
                                        goToStep={(targetStep) => {
                                            setStep(targetStep);
                                            setClientErrors({});
                                            scrollTop();
                                        }}
                                    />
                                )}
                            </div>

                            {processing && progress && (
                                <div className="border-t border-gray-100 px-5 pt-4 sm:px-7">
                                    <div className="flex items-center justify-between text-xs font-semibold text-gray-500">
                                        <span>Uploading registration...</span>
                                        <span>{progress.percentage}%</span>
                                    </div>
                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-gray-100">
                                        <div
                                            className="h-full rounded-full bg-[#3f6f3d] transition-all"
                                            style={{
                                                width: `${progress.percentage}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="flex flex-col-reverse gap-3 border-t border-gray-100 bg-gray-50/70 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                                {step > 1 ? (
                                    <button
                                        type="button"
                                        onClick={previousStep}
                                        disabled={processing}
                                        className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        <ArrowLeftIcon />
                                        Back
                                    </button>
                                ) : (
                                    <Link
                                        href="/resident/login"
                                        className="flex min-h-11 items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-bold text-gray-700 transition hover:bg-gray-50"
                                    >
                                        <ArrowLeftIcon />
                                        Back to Login
                                    </Link>
                                )}

                                {step < TOTAL_STEPS ? (
                                    <button
                                        type="button"
                                        onClick={nextStep}
                                        className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#315b30] active:scale-[0.99]"
                                    >
                                        Continue
                                        <ArrowRightIcon />
                                    </button>
                                ) : (
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#315b30] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {processing ? (
                                            <>
                                                <SpinnerIcon />
                                                Submitting...
                                            </>
                                        ) : (
                                            <>
                                                <SendIcon />
                                                Submit Registration
                                            </>
                                        )}
                                    </button>
                                )}
                            </div>
                        </form>

                        <p className="mt-5 text-center text-xs leading-5 text-gray-400">
                            Already registered?{' '}
                            <Link
                                href="/resident/login"
                                className="font-semibold text-[#3f6f3d] hover:underline"
                            >
                                Sign in to your account
                            </Link>
                        </p>

                        {selectedBarangay && (
                            <p className="mt-2 text-center text-xs text-gray-400">
                                Selected location: {barangayLocation}
                            </p>
                        )}
                    </div>
                </main>
            </div>
        </>
    );
}

function BarangayStep({
    barangays,
    selectedBarangay,
    barangayId,
    error,
    onChange,
}: {
    barangays: Barangay[];
    selectedBarangay: Barangay | null;
    barangayId: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-5">
            <SectionTitle
                title="Select Your Barangay"
                description="Choose the barangay where you currently reside. Your address and identification documents will be checked during verification."
            />

            <SelectField
                label="Barangay"
                required
                value={barangayId}
                error={error}
                onChange={onChange}
                options={[
                    {
                        value: '',
                        label: 'Select your barangay',
                    },
                    ...barangays.map((barangay) => ({
                        value: String(barangay.id),
                        label: [
                            barangay.name,
                            barangay.city_municipality,
                        ]
                            .filter(Boolean)
                            .join(' — '),
                    })),
                ]}
            />

            {barangays.length === 0 && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-bold text-amber-900">
                        No barangays are available.
                    </p>
                    <p className="mt-1 text-xs leading-5 text-amber-800">
                        Make sure the resident registration route uses RegistrationController::create and that your barangay records are active.
                    </p>
                </div>
            )}

            {selectedBarangay && (
                <div
                    className={[
                        'rounded-xl border p-4',
                        selectedBarangay.puroks.length > 0
                            ? 'border-[#dce8d8] bg-[#f5f9f3]'
                            : 'border-amber-200 bg-amber-50',
                    ].join(' ')}
                >
                    <div className="flex items-start gap-3">
                        <div
                            className={
                                selectedBarangay.puroks.length > 0
                                    ? 'text-[#3f6f3d]'
                                    : 'text-amber-700'
                            }
                        >
                            <MapPinIcon />
                        </div>

                        <div>
                            <p className="text-sm font-bold text-gray-800">
                                {selectedBarangay.name}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-gray-600">
                                {[
                                    selectedBarangay.city_municipality,
                                    selectedBarangay.province,
                                ]
                                    .filter(Boolean)
                                    .join(', ')}
                            </p>

                            {selectedBarangay.puroks.length > 0 ? (
                                <p className="mt-2 text-xs text-gray-500">
                                    {selectedBarangay.puroks.length} purok
                                    {selectedBarangay.puroks.length === 1
                                        ? ''
                                        : 's'}{' '}
                                    available for registration.
                                </p>
                            ) : (
                                <p className="mt-2 text-xs font-semibold text-amber-800">
                                    Puroks for this barangay have not been
                                    configured yet.
                                </p>
                            )}
                        </div>
                    </div>
                </div>
            )}

            <InfoBox>
                Select the barangay where you currently live. If you choose the
                wrong barangay, the application may be returned for correction.
                Selection alone does not verify residency.
            </InfoBox>
        </div>
    );
}

function PersonalInformationStep({
    data,
    barangay,
    puroks,
    errors,
    setData,
    clearFieldError,
}: {
    data: {
        first_name: string;
        middle_name: string;
        last_name: string;
        suffix: string;
        birthdate: string;
        sex: Sex;
        civil_status: CivilStatus;
        purok_id: string;
        address_line: string;
    };
    barangay: Barangay | null;
    puroks: Purok[];
    errors: (key: string) => string | undefined;
    setData: (key: any, value: any) => void;
    clearFieldError: (key: string) => void;
}) {
    return (
        <div className="space-y-6">
            <div>
                <SectionTitle
                    title="Basic Information"
                    description="Enter your information exactly as it appears on your identification documents."
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <InputField
                        label="First Name"
                        required
                        value={data.first_name}
                        placeholder="Enter first name"
                        error={errors('first_name')}
                        onChange={(value) => {
                            setData('first_name', value);
                            clearFieldError('first_name');
                        }}
                    />

                    <InputField
                        label="Middle Name"
                        value={data.middle_name}
                        placeholder="Enter middle name"
                        helper="Leave blank if not applicable."
                        error={errors('middle_name')}
                        onChange={(value) => {
                            setData('middle_name', value);
                            clearFieldError('middle_name');
                        }}
                    />

                    <InputField
                        label="Last Name"
                        required
                        value={data.last_name}
                        placeholder="Enter last name"
                        error={errors('last_name')}
                        onChange={(value) => {
                            setData('last_name', value);
                            clearFieldError('last_name');
                        }}
                    />

                    <InputField
                        label="Suffix"
                        value={data.suffix}
                        placeholder="e.g. Jr., Sr., III"
                        helper="Optional"
                        error={errors('suffix')}
                        onChange={(value) => {
                            setData('suffix', value);
                            clearFieldError('suffix');
                        }}
                    />

                    <InputField
                        label="Birthdate"
                        type="date"
                        required
                        value={data.birthdate}
                        error={errors('birthdate')}
                        onChange={(value) => {
                            setData('birthdate', value);
                            clearFieldError('birthdate');
                        }}
                    />

                    <SelectField
                        label="Sex"
                        required
                        value={data.sex}
                        error={errors('sex')}
                        onChange={(value) => {
                            setData('sex', value as Sex);
                            clearFieldError('sex');
                        }}
                        options={[
                            { value: '', label: 'Select sex' },
                            { value: 'Male', label: 'Male' },
                            { value: 'Female', label: 'Female' },
                        ]}
                    />

                    <SelectField
                        label="Civil Status"
                        required
                        value={data.civil_status}
                        error={errors('civil_status')}
                        onChange={(value) => {
                            setData('civil_status', value as CivilStatus);
                            clearFieldError('civil_status');
                        }}
                        options={[
                            { value: '', label: 'Select civil status' },
                            { value: 'Single', label: 'Single' },
                            { value: 'Married', label: 'Married' },
                            { value: 'Widowed', label: 'Widowed' },
                            { value: 'Separated', label: 'Separated' },
                        ]}
                    />
                </div>
            </div>

            <div className="border-t border-gray-100 pt-6">
                <SectionTitle
                    title="Residential Address"
                    description="Choose the purok that belongs to your selected barangay, then enter your complete current address."
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <SelectField
                        label="Purok"
                        required
                        value={data.purok_id}
                        error={errors('purok_id')}
                        onChange={(value) => {
                            setData('purok_id', value);
                            clearFieldError('purok_id');
                        }}
                        disabled={!barangay || puroks.length === 0}
                        options={[
                            {
                                value: '',
                                label: !barangay
                                    ? 'Select a barangay first'
                                    : puroks.length === 0
                                      ? 'No puroks configured'
                                      : 'Select your purok',
                            },
                            ...puroks.map((purok) => ({
                                value: String(purok.id),
                                label: purok.name,
                            })),
                        ]}
                    />

                    <InputField
                        label="House No. / Street / Sitio"
                        required
                        value={data.address_line}
                        placeholder="Enter complete local address"
                        error={errors('address_line')}
                        onChange={(value) => {
                            setData('address_line', value);
                            clearFieldError('address_line');
                        }}
                    />
                </div>

                {barangay && (
                    <div className="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                        <p className="text-xs font-medium text-gray-500">
                            Selected Barangay
                        </p>
                        <p className="mt-1 text-sm font-semibold text-gray-800">
                            {[
                                barangay.name,
                                barangay.city_municipality,
                                barangay.province,
                            ]
                                .filter(Boolean)
                                .join(', ')}
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}

function SectorInformationStep({
    sectors,
    selectedSlugs,
    regularSelected,
    error,
    onToggleRegular,
    onToggle,
}: {
    sectors: Sector[];
    selectedSlugs: string[];
    regularSelected: boolean;
    error?: string;
    onToggleRegular: () => void;
    onToggle: (slug: string) => void;
}) {
    const hasSectorSelection = selectedSlugs.length > 0;

    return (
        <div className="space-y-5">
            <SectionTitle
                title="Resident Classification"
                description="Choose the classification that applies to you. Select Regular Resident if you are not registering as a Senior Citizen or PWD. If both Senior Citizen and PWD apply to you, you may select both."
            />

            {sectors.length === 0 && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p className="text-sm font-bold text-amber-900">
                        Senior Citizen and PWD options are not available.
                    </p>
                    <p className="mt-1 text-xs leading-5 text-amber-800">
                        Regular Resident is still available. Run the SectorSeeder
                        to restore the Senior Citizen and PWD options.
                    </p>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <SectorCard
                    title="Regular Resident"
                    selected={regularSelected}
                    disabled={hasSectorSelection}
                    description="Choose this if you are not registering under Senior Citizen or PWD."
                    icon={<RegularResidentIcon />}
                    onClick={onToggleRegular}
                />

                {sectors.map((sector) => (
                    <SectorCard
                        key={sector.id}
                        title={sector.name}
                        selected={selectedSlugs.includes(sector.slug)}
                        disabled={regularSelected}
                        description={
                            sector.slug === 'senior'
                                ? 'Choose this if you are a Senior Citizen. Supporting proof will be required.'
                                : sector.slug === 'pwd'
                                  ? 'Choose this if you are a Person with Disability. Supporting proof will be required.'
                                  : sector.requires_proof
                                    ? 'Supporting proof will be required.'
                                    : 'Choose this if this classification applies to you.'
                        }
                        icon={
                            sector.slug === 'senior' ? (
                                <SeniorIcon />
                            ) : (
                                <AccessibilityIcon />
                            )
                        }
                        onClick={() => onToggle(sector.slug)}
                    />
                ))}
            </div>

            {error && (
                <div
                    role="alert"
                    className="rounded-xl border border-red-200 bg-red-50 px-4 py-3"
                >
                    <p className="text-xs font-semibold text-red-700">
                        {error}
                    </p>
                </div>
            )}

            {regularSelected && (
                <div className="rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-4">
                    <p className="text-sm font-bold text-gray-800">
                        Selected classification
                    </p>
                    <div className="mt-2">
                        <span className="rounded-full bg-[#e8f1e4] px-3 py-1 text-xs font-bold text-[#3f6f3d]">
                            Regular Resident
                        </span>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-gray-500">
                        Senior Citizen and PWD are disabled while Regular
                        Resident is selected. Deselect Regular Resident first if
                        you need to choose a sector.
                    </p>
                </div>
            )}

            {!regularSelected && hasSectorSelection && (
                <div className="rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-4">
                    <p className="text-sm font-bold text-gray-800">
                        Selected classification
                        {selectedSlugs.length > 1 ? 's' : ''}
                    </p>

                    <div className="mt-2 flex flex-wrap gap-2">
                        {sectors
                            .filter((sector) =>
                                selectedSlugs.includes(sector.slug),
                            )
                            .map((sector) => (
                                <span
                                    key={sector.id}
                                    className="rounded-full bg-[#e8f1e4] px-3 py-1 text-xs font-bold text-[#3f6f3d]"
                                >
                                    {sector.name}
                                </span>
                            ))}
                    </div>

                    <p className="mt-3 text-xs leading-5 text-gray-500">
                        Regular Resident is disabled while a Senior Citizen or
                        PWD classification is selected. You may select both
                        Senior Citizen and PWD if both apply to you.
                    </p>
                </div>
            )}

            {!regularSelected && !hasSectorSelection && (
                <InfoBox>
                    Please choose one classification before continuing.
                </InfoBox>
            )}
        </div>
    );
}

function IdentityVerificationStep({
    isSenior,
    isPwd,
    validId,
    liveIdentityPhoto,
    seniorProof,
    pwdProof,
    errors,
    setFile,
}: {
    isSenior: boolean;
    isPwd: boolean;
    validId: File | null;
    liveIdentityPhoto: File | null;
    seniorProof: File | null;
    pwdProof: File | null;
    errors: (key: string) => string | undefined;
    setFile: (
        field:
            | 'valid_id'
            | 'live_identity_photo'
            | 'senior_proof'
            | 'pwd_proof',
        file: File | null,
    ) => void;
}) {
    return (
        <div className="space-y-6">
            <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <div className="flex items-start gap-3">
                    <div className="mt-0.5 shrink-0 text-amber-700">
                        <ShieldIcon />
                    </div>
                    <div>
                        <p className="text-sm font-bold text-amber-900">
                            Identity verification
                        </p>
                        <p className="mt-1 text-xs leading-5 text-amber-800">
                            Your valid ID and live camera photo will be reviewed
                            by authorized barangay personnel for identity
                            confirmation before approval.
                        </p>
                    </div>
                </div>
            </div>

            <div>
                <SectionTitle
                    title="Valid Identification"
                    description="Upload a clear image or PDF of your valid identification document."
                />
                <div className="mt-4">
                    <FileUpload
                        title="Valid ID"
                        description="Accepted: JPG, PNG, or PDF. Maximum 5 MB."
                        accept="image/jpeg,image/png,application/pdf"
                        value={validId}
                        error={errors('valid_id')}
                        onChange={(file) => setFile('valid_id', file)}
                        icon={<IdCardIcon />}
                    />
                </div>
            </div>

            {(isSenior || isPwd) && (
                <div className="border-t border-gray-100 pt-6">
                    <SectionTitle
                        title="Sector Supporting Documents"
                        description="Provide proof for every sector you selected."
                    />

                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
                        {isSenior && (
                            <FileUpload
                                title="Senior Citizen ID / Proof"
                                description="Upload your Senior Citizen ID or accepted supporting document."
                                accept="image/jpeg,image/png,application/pdf"
                                value={seniorProof}
                                error={errors('senior_proof')}
                                onChange={(file) =>
                                    setFile('senior_proof', file)
                                }
                                icon={<DocumentIcon />}
                            />
                        )}

                        {isPwd && (
                            <FileUpload
                                title="PWD ID / Proof"
                                description="Upload your PWD ID or accepted supporting document."
                                accept="image/jpeg,image/png,application/pdf"
                                value={pwdProof}
                                error={errors('pwd_proof')}
                                onChange={(file) =>
                                    setFile('pwd_proof', file)
                                }
                                icon={<DocumentIcon />}
                            />
                        )}
                    </div>
                </div>
            )}

            <div className="border-t border-gray-100 pt-6">
                <SectionTitle
                    title="Live Identity Photo"
                    description="Use your device camera to take a live photo. Existing gallery photos cannot be selected here."
                />

                <div className="mt-4">
                    <LiveCameraCapture
                        value={liveIdentityPhoto}
                        error={errors('live_identity_photo')}
                        onChange={(file) =>
                            setFile('live_identity_photo', file)
                        }
                    />
                </div>
            </div>
        </div>
    );
}

function AccountSecurityStep({
    data,
    errors,
    setData,
    clearFieldError,
    showPassword,
    showPasswordConfirmation,
    togglePassword,
    togglePasswordConfirmation,
}: {
    data: {
        mobile: string;
        email: string;
        password: string;
        password_confirmation: string;
    };
    errors: (key: string) => string | undefined;
    setData: (key: any, value: any) => void;
    clearFieldError: (key: string) => void;
    showPassword: boolean;
    showPasswordConfirmation: boolean;
    togglePassword: () => void;
    togglePasswordConfirmation: () => void;
}) {
    return (
        <div className="space-y-6">
            <div>
                <SectionTitle
                    title="Contact Information"
                    description="Your contact details will be used for account and barangay service notifications."
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <InputField
                        label="Mobile Number"
                        required
                        type="tel"
                        value={data.mobile}
                        placeholder="09XXXXXXXXX"
                        error={errors('mobile')}
                        onChange={(value) => {
                            setData('mobile', value);
                            clearFieldError('mobile');
                        }}
                    />

                    <InputField
                        label="Email Address"
                        type="email"
                        value={data.email}
                        placeholder="name@example.com"
                        helper="Optional"
                        error={errors('email')}
                        onChange={(value) => {
                            setData('email', value);
                            clearFieldError('email');
                        }}
                    />
                </div>
            </div>

            <div className="border-t border-gray-100 pt-6">
                <SectionTitle
                    title="Create Your Password"
                    description="Use a strong password that you do not use for other accounts."
                />

                <div className="mt-4 grid gap-4 sm:grid-cols-2">
                    <PasswordField
                        label="Password"
                        value={data.password}
                        show={showPassword}
                        error={errors('password')}
                        onToggle={togglePassword}
                        onChange={(value) => {
                            setData('password', value);
                            clearFieldError('password');
                        }}
                    />

                    <PasswordField
                        label="Confirm Password"
                        value={data.password_confirmation}
                        show={showPasswordConfirmation}
                        error={errors('password_confirmation')}
                        onToggle={togglePasswordConfirmation}
                        onChange={(value) => {
                            setData('password_confirmation', value);
                            clearFieldError('password_confirmation');
                        }}
                    />
                </div>

                <PasswordRequirements password={data.password} />
            </div>

            <InfoBox>
                Your account will remain pending until the selected barangay
                verifies and approves your registration.
            </InfoBox>
        </div>
    );
}

function ReviewStep({
    fullName,
    barangay,
    purok,
    addressLine,
    birthdate,
    sex,
    civilStatus,
    mobile,
    email,
    sectorNames,
    validId,
    livePhoto,
    seniorProof,
    pwdProof,
    isSenior,
    isPwd,
    declarationChecked,
    declarationError,
    onDeclarationChange,
    goToStep,
}: {
    fullName: string;
    barangay: Barangay | null;
    purok: Purok | null;
    addressLine: string;
    birthdate: string;
    sex: string;
    civilStatus: string;
    mobile: string;
    email: string;
    sectorNames: string[];
    validId: File | null;
    livePhoto: File | null;
    seniorProof: File | null;
    pwdProof: File | null;
    isSenior: boolean;
    isPwd: boolean;
    declarationChecked: boolean;
    declarationError?: string;
    onDeclarationChange: (checked: boolean) => void;
    goToStep: (step: number) => void;
}) {
    return (
        <div className="space-y-4">
            <ReviewCard title="Barangay" onEdit={() => goToStep(1)}>
                <ReviewItem
                    label="Barangay"
                    value={
                        barangay
                            ? [
                                  barangay.name,
                                  barangay.city_municipality,
                                  barangay.province,
                              ]
                                  .filter(Boolean)
                                  .join(', ')
                            : '—'
                    }
                />
            </ReviewCard>

            <ReviewCard
                title="Personal Information"
                onEdit={() => goToStep(2)}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewItem label="Full Name" value={fullName} />
                    <ReviewItem label="Birthdate" value={birthdate} />
                    <ReviewItem label="Sex" value={sex} />
                    <ReviewItem label="Civil Status" value={civilStatus} />
                    <ReviewItem label="Purok" value={purok?.name ?? '—'} />
                    <ReviewItem
                        label="House No. / Street / Sitio"
                        value={addressLine}
                    />
                </div>
            </ReviewCard>

            <ReviewCard
                title="Sector Information"
                onEdit={() => goToStep(3)}
            >
                {sectorNames.length === 0 ? (
                    <p className="text-sm font-semibold text-gray-700">
                        Regular Resident
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {sectorNames.map((name) => (
                            <span
                                key={name}
                                className="rounded-full bg-[#e8f1e4] px-3 py-1.5 text-xs font-bold text-[#3f6f3d]"
                            >
                                {name}
                            </span>
                        ))}
                    </div>
                )}
            </ReviewCard>

            <ReviewCard
                title="Identity Verification"
                onEdit={() => goToStep(4)}
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <DocumentStatus label="Valid ID" file={validId} />
                    <DocumentStatus
                        label="Live Identity Photo"
                        file={livePhoto}
                    />
                    {isSenior && (
                        <DocumentStatus
                            label="Senior Citizen Proof"
                            file={seniorProof}
                        />
                    )}
                    {isPwd && (
                        <DocumentStatus
                            label="PWD Proof"
                            file={pwdProof}
                        />
                    )}
                </div>
            </ReviewCard>

            <ReviewCard
                title="Account Information"
                onEdit={() => goToStep(5)}
            >
                <div className="grid gap-4 sm:grid-cols-2">
                    <ReviewItem label="Mobile Number" value={mobile} />
                    <ReviewItem
                        label="Email Address"
                        value={email || 'Not provided'}
                    />
                </div>
            </ReviewCard>

            {barangay && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 shrink-0 text-amber-700">
                            <InfoIcon />
                        </div>
                        <p className="text-xs leading-5 text-amber-800 sm:text-sm">
                            This application will be submitted to <strong>{barangay.name}</strong> for review. Make sure this is the barangay where you currently reside.
                        </p>
                    </div>
                </div>
            )}

            <div className="rounded-xl border border-gray-200 bg-gray-50 p-4">
                <label className="flex cursor-pointer items-start gap-3">
                    <input
                        type="checkbox"
                        checked={declarationChecked}
                        onChange={(event) =>
                            onDeclarationChange(event.target.checked)
                        }
                        className="mt-0.5 h-5 w-5 shrink-0 rounded border-gray-300 accent-[#3f6f3d]"
                    />
                    <span className="text-xs leading-5 text-gray-600 sm:text-sm">
                        I confirm that the information and documents I provided
                        are true and belong to me. I understand that my selected
                        barangay will review my residency, identification, live
                        camera photo, and any Senior/PWD proof before approval.
                    </span>
                </label>

                {declarationError && (
                    <p className="mt-2 text-xs font-semibold text-red-600">
                        {declarationError}
                    </p>
                )}
            </div>
        </div>
    );
}

function LiveCameraCapture({
    value,
    error,
    onChange,
}: {
    value: File | null;
    error?: string;
    onChange: (file: File | null) => void;
}) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);

    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraReady, setCameraReady] = useState(false);
    const [cameraError, setCameraError] = useState('');
    const [startingCamera, setStartingCamera] = useState(false);
    const [capturing, setCapturing] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!value) {
            setPreviewUrl(null);
            return;
        }

        const url = URL.createObjectURL(value);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [value]);

    useEffect(() => {
        if (!cameraOpen || !streamRef.current || !videoRef.current) {
            return;
        }

        const video = videoRef.current;
        video.srcObject = streamRef.current;

        const markReady = () => {
            if (video.videoWidth > 0 && video.videoHeight > 0) {
                setCameraReady(true);
            }
        };

        video.addEventListener('loadedmetadata', markReady);
        video.addEventListener('canplay', markReady);

        const playVideo = async () => {
            try {
                await video.play();
                markReady();
            } catch {
                // Some mobile browsers start playback after metadata is loaded.
            }
        };

        void playVideo();

        return () => {
            video.removeEventListener('loadedmetadata', markReady);
            video.removeEventListener('canplay', markReady);
        };
    }, [cameraOpen]);

    useEffect(() => {
        return () => {
            streamRef.current?.getTracks().forEach((track) => track.stop());
        };
    }, []);

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;

        if (videoRef.current) {
            videoRef.current.pause();
            videoRef.current.srcObject = null;
        }

        setCameraReady(false);
        setCameraOpen(false);
        setCapturing(false);
    };

    const requestCamera = async () => {
        // Start with lightweight constraints so laptops and phones open faster.
        try {
            return await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'user' },
                    width: { ideal: 640, max: 1280 },
                    height: { ideal: 480, max: 720 },
                    frameRate: { ideal: 24, max: 30 },
                },
                audio: false,
            });
        } catch (firstError) {
            // Fallback for older browsers/devices that reject advanced constraints.
            try {
                return await navigator.mediaDevices.getUserMedia({
                    video: true,
                    audio: false,
                });
            } catch {
                throw firstError;
            }
        }
    };

    const openCamera = async () => {
        setCameraError('');
        setCameraReady(false);

        if (typeof navigator === 'undefined') {
            return;
        }

        if (!window.isSecureContext && window.location.hostname !== 'localhost') {
            setCameraError(
                'Camera access requires HTTPS. Please open KaBarangay using a secure connection.',
            );
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraError(
                'Camera access is not supported by this browser. Please use an updated Chrome, Edge, Safari, or Firefox browser.',
            );
            return;
        }

        try {
            setStartingCamera(true);
            stopCamera();

            const stream = await requestCamera();
            streamRef.current = stream;
            setCameraOpen(true);
        } catch (cameraException) {
            const exception = cameraException as DOMException;

            if (exception?.name === 'NotAllowedError') {
                setCameraError(
                    'Camera permission was blocked. Allow camera access in your browser settings, then try again.',
                );
            } else if (exception?.name === 'NotFoundError') {
                setCameraError('No camera was detected on this device.');
            } else if (exception?.name === 'NotReadableError') {
                setCameraError(
                    'The camera is currently being used by another app. Close the other camera app and try again.',
                );
            } else {
                setCameraError(
                    'The camera could not be opened. Please check camera permission and try again.',
                );
            }
        } finally {
            setStartingCamera(false);
        }
    };

    const capturePhoto = () => {
        if (capturing) {
            return;
        }

        const video = videoRef.current;
        const canvas = canvasRef.current;

        if (
            !cameraReady ||
            !video ||
            !canvas ||
            !video.videoWidth ||
            !video.videoHeight
        ) {
            setCameraError(
                'The camera is still preparing. Wait for the preview, then take the photo.',
            );
            return;
        }

        setCameraError('');
        setCapturing(true);

        // Keep the image clear enough for manual identity review without creating
        // a large file that is slow on phones or lower-powered laptops.
        const maxWidth = 720;
        const sourceWidth = video.videoWidth;
        const sourceHeight = video.videoHeight;
        const scale = Math.min(1, maxWidth / sourceWidth);
        const outputWidth = Math.max(1, Math.round(sourceWidth * scale));
        const outputHeight = Math.max(1, Math.round(sourceHeight * scale));

        canvas.width = outputWidth;
        canvas.height = outputHeight;

        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
            setCameraError('Unable to capture the photo.');
            setCapturing(false);
            return;
        }

        // Mirror the saved selfie so it matches the live front-camera preview.
        context.save();
        context.translate(outputWidth, 0);
        context.scale(-1, 1);
        context.drawImage(video, 0, 0, outputWidth, outputHeight);
        context.restore();

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setCameraError('Unable to capture the photo. Please try again.');
                    setCapturing(false);
                    return;
                }

                const file = new File(
                    [blob],
                    `live-identity-${Date.now()}.jpg`,
                    { type: 'image/jpeg' },
                );

                onChange(file);
                stopCamera();
            },
            'image/jpeg',
            0.82,
        );
    };

    return (
        <div>
            {cameraOpen ? (
                <div className="overflow-hidden rounded-2xl border border-gray-200 bg-black">
                    <div className="relative aspect-[4/3] w-full overflow-hidden bg-black sm:aspect-video">
                        <video
                            ref={videoRef}
                            autoPlay
                            playsInline
                            muted
                            disablePictureInPicture
                            className="h-full w-full scale-x-[-1] object-cover"
                        />

                        {!cameraReady && (
                            <div className="absolute inset-0 flex items-center justify-center bg-black/65 text-white">
                                <div className="flex items-center gap-2 text-sm font-semibold">
                                    <SpinnerIcon />
                                    Preparing camera...
                                </div>
                            </div>
                        )}

                        {cameraReady && (
                            <>
                                <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                                    <div className="h-[72%] w-[58%] max-w-[260px] rounded-[50%] border-2 border-dashed border-white/80 shadow-[0_0_0_999px_rgba(0,0,0,0.18)]" />
                                </div>

                                <div className="absolute bottom-3 left-1/2 -translate-x-1/2 whitespace-nowrap rounded-full bg-black/55 px-3 py-1 text-xs font-semibold text-white">
                                    Position your face inside the guide
                                </div>
                            </>
                        )}
                    </div>

                    <div className="flex flex-col gap-3 bg-white p-4 sm:flex-row sm:justify-center">
                        <button
                            type="button"
                            onClick={stopCamera}
                            disabled={capturing}
                            className="min-h-11 rounded-xl border border-gray-300 px-5 text-sm font-bold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Cancel
                        </button>

                        <button
                            type="button"
                            onClick={capturePhoto}
                            disabled={!cameraReady || capturing}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-6 text-sm font-bold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {capturing ? (
                                <>
                                    <SpinnerIcon />
                                    Capturing...
                                </>
                            ) : (
                                <>
                                    <CameraIcon />
                                    Take Photo
                                </>
                            )}
                        </button>
                    </div>
                </div>
            ) : value && previewUrl ? (
                <div className="rounded-2xl border border-[#c9dcc5] bg-[#f6faf5] p-4">
                    <div className="grid gap-4 sm:grid-cols-[180px_1fr] sm:items-center">
                        <img
                            src={previewUrl}
                            alt="Live identity photo preview"
                            className="aspect-[4/3] w-full rounded-xl border border-gray-200 bg-white object-cover"
                        />

                        <div>
                            <div className="flex items-center gap-2 text-[#3f6f3d]">
                                <CircleCheckIcon />
                                <p className="text-sm font-bold">
                                    Live identity photo captured
                                </p>
                            </div>

                            <p className="mt-2 text-xs leading-5 text-gray-500">
                                This photo was captured directly from this device camera.
                            </p>

                            <button
                                type="button"
                                onClick={openCamera}
                                disabled={startingCamera}
                                className="mt-4 min-h-10 rounded-lg border border-gray-300 bg-white px-4 text-xs font-bold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {startingCamera ? 'Opening Camera...' : 'Retake Photo'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : (
                <div
                    className={[
                        'flex flex-col items-center justify-center rounded-2xl border-2 border-dashed p-6 text-center sm:p-8',
                        error
                            ? 'border-red-300 bg-red-50/30'
                            : 'border-gray-300 bg-gray-50/50',
                    ].join(' ')}
                >
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e8f1e4] text-[#3f6f3d]">
                        <CameraIcon />
                    </div>

                    <p className="mt-3 text-sm font-bold text-gray-800">
                        Live Identity Photo
                    </p>

                    <p className="mt-1 max-w-md text-xs leading-5 text-gray-500">
                        Open your device camera and take a current photo of yourself.
                        Gallery upload is not available for this verification step.
                    </p>

                    <button
                        type="button"
                        onClick={openCamera}
                        disabled={startingCamera}
                        className="mt-4 flex min-h-11 items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-5 text-sm font-bold text-white hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {startingCamera ? (
                            <>
                                <SpinnerIcon />
                                Opening Camera...
                            </>
                        ) : (
                            <>
                                <CameraIcon />
                                Open Camera
                            </>
                        )}
                    </button>
                </div>
            )}

            <canvas ref={canvasRef} className="hidden" />

            {(cameraError || error) && (
                <p className="mt-2 text-xs font-semibold text-red-600">
                    {cameraError || error}
                </p>
            )}

            <p className="mt-2 text-xs leading-5 text-gray-400">
                Works with laptop webcams and phone front cameras. Camera permission
                is required; deployed sites must use HTTPS.
            </p>
        </div>
    );
}

function FileUpload({
    title,
    description,
    accept,
    value,
    error,
    icon,
    onChange,
}: {
    title: string;
    description: string;
    accept: string;
    value: File | null;
    error?: string;
    icon: ReactNode;
    onChange: (file: File | null) => void;
}) {
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const [localError, setLocalError] = useState('');

    useEffect(() => {
        if (!value || !value.type.startsWith('image/')) {
            setPreviewUrl(null);
            return;
        }

        const url = URL.createObjectURL(value);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [value]);

    const handleFile = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0] ?? null;
        event.target.value = '';

        if (!file) {
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            setLocalError('File must not be larger than 5 MB.');
            return;
        }

        const allowed = [
            'image/jpeg',
            'image/png',
            'application/pdf',
        ];

        if (!allowed.includes(file.type)) {
            setLocalError('Only JPG, PNG, and PDF files are allowed.');
            return;
        }

        setLocalError('');
        onChange(file);
    };

    return (
        <div>
            {!value ? (
                <label
                    className={[
                        'flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed p-6 text-center transition sm:p-8',
                        error || localError
                            ? 'border-red-300 bg-red-50/30'
                            : 'border-gray-300 bg-gray-50/50 hover:border-[#7ca278] hover:bg-[#f7faf6]',
                    ].join(' ')}
                >
                    <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#e8f1e4] text-[#3f6f3d]">
                        {icon}
                    </div>
                    <p className="mt-3 text-sm font-bold text-gray-800">
                        {title}
                    </p>
                    <p className="mt-1 max-w-md text-xs leading-5 text-gray-500">
                        {description}
                    </p>
                    <span className="mt-4 rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-bold text-gray-700 shadow-sm">
                        Choose File
                    </span>
                    <input
                        type="file"
                        accept={accept}
                        onChange={handleFile}
                        className="hidden"
                    />
                </label>
            ) : (
                <div className="rounded-2xl border border-[#c9dcc5] bg-[#f6faf5] p-4">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                        {previewUrl ? (
                            <img
                                src={previewUrl}
                                alt={`${title} preview`}
                                className="h-24 w-full rounded-xl border border-gray-200 bg-white object-cover sm:w-28"
                            />
                        ) : (
                            <div className="flex h-20 w-20 shrink-0 items-center justify-center rounded-xl bg-white text-[#3f6f3d] shadow-sm">
                                <DocumentIcon />
                            </div>
                        )}

                        <div className="min-w-0 flex-1">
                            <div className="flex items-center gap-2 text-[#3f6f3d]">
                                <CircleCheckIcon />
                                <p className="text-sm font-bold text-gray-800">
                                    {title} added
                                </p>
                            </div>
                            <p className="mt-1 truncate text-xs text-gray-500">
                                {value.name}
                            </p>
                            <p className="mt-1 text-xs text-gray-400">
                                {formatFileSize(value.size)}
                            </p>
                        </div>

                        <button
                            type="button"
                            onClick={() => {
                                setLocalError('');
                                onChange(null);
                            }}
                            className="min-h-10 rounded-lg border border-gray-300 bg-white px-3 text-xs font-bold text-gray-600 hover:bg-gray-50"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            )}

            {(localError || error) && (
                <p className="mt-2 text-xs font-semibold text-red-600">
                    {localError || error}
                </p>
            )}
        </div>
    );
}

function RegistrationProgress({ step }: { step: number }) {
    const labels = [
        'Barangay',
        'Personal Info',
        'Sectors',
        'Verification',
        'Account',
        'Review',
    ];

    return (
        <>
            <div className="rounded-xl border border-gray-200 bg-white p-4 shadow-sm md:hidden">
                <div className="flex items-center justify-between gap-3">
                    <span className="text-xs font-bold text-[#3f6f3d]">
                        Step {step} of {TOTAL_STEPS}
                    </span>
                    <span className="truncate text-xs font-semibold text-gray-500">
                        {labels[step - 1]}
                    </span>
                </div>
                <div className="mt-3 h-2 overflow-hidden rounded-full bg-gray-100">
                    <div
                        className="h-full rounded-full bg-[#3f6f3d] transition-all duration-300"
                        style={{
                            width: `${(step / TOTAL_STEPS) * 100}%`,
                        }}
                    />
                </div>
            </div>

            <div className="hidden rounded-2xl border border-gray-200 bg-white px-5 py-5 shadow-sm md:block">
                <div className="flex items-start">
                    {labels.map((label, index) => {
                        const number = index + 1;
                        const complete = number < step;
                        const active = number === step;

                        return (
                            <div
                                key={label}
                                className="flex min-w-0 flex-1 items-start"
                            >
                                <div className="flex min-w-[72px] flex-col items-center text-center">
                                    <div
                                        className={[
                                            'flex h-9 w-9 items-center justify-center rounded-full border-2 text-xs font-bold transition',
                                            complete || active
                                                ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                                                : 'border-gray-200 bg-white text-gray-400',
                                        ].join(' ')}
                                    >
                                        {complete ? (
                                            <SmallCheckIcon />
                                        ) : (
                                            number
                                        )}
                                    </div>
                                    <p
                                        className={[
                                            'mt-2 text-[11px] font-semibold lg:text-xs',
                                            active
                                                ? 'text-[#3f6f3d]'
                                                : complete
                                                  ? 'text-gray-700'
                                                  : 'text-gray-400',
                                        ].join(' ')}
                                    >
                                        {label}
                                    </p>
                                </div>

                                {number < TOTAL_STEPS && (
                                    <div
                                        className={[
                                            'mt-4 h-0.5 flex-1',
                                            number < step
                                                ? 'bg-[#3f6f3d]'
                                                : 'bg-gray-200',
                                        ].join(' ')}
                                    />
                                )}
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

function PublicHeader() {
    return (
        <header className="border-b border-[#315b30] bg-[#3f6f3d]">
            <div className="mx-auto flex min-h-16 w-full max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                <Link
                    href="/resident/login"
                    className="inline-flex min-w-0 items-center gap-3 text-white"
                >
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-[#3f6f3d]">
                        KB
                    </div>
                    <div className="min-w-0">
                        <p className="text-base font-bold leading-tight sm:text-lg">
                            KaBarangay
                        </p>
                        <p className="text-xs text-white/80">
                            Resident Portal
                        </p>
                    </div>
                </Link>

                <Link
                    href="/resident/login"
                    className="rounded-lg px-3 py-2 text-sm font-semibold text-white/90 transition hover:bg-white/10 hover:text-white"
                >
                    Sign In
                </Link>
            </div>
        </header>
    );
}

function SectionTitle({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div>
            <h3 className="text-sm font-bold text-gray-900 sm:text-base">
                {title}
            </h3>
            <p className="mt-1 text-xs leading-5 text-gray-500 sm:text-sm">
                {description}
            </p>
        </div>
    );
}

function InfoBox({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-4">
            <div className="flex items-start gap-3">
                <div className="mt-0.5 shrink-0 text-[#3f6f3d]">
                    <InfoIcon />
                </div>
                <p className="text-xs leading-5 text-gray-600 sm:text-sm">
                    {children}
                </p>
            </div>
        </div>
    );
}

function InputField({
    label,
    value,
    onChange,
    type = 'text',
    placeholder,
    required = false,
    helper,
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: string;
    placeholder?: string;
    required?: boolean;
    helper?: string;
    error?: string;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}
                {required && <span className="ml-1 text-red-500">*</span>}
            </span>
            <input
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className={[
                    'min-h-11 w-full rounded-xl border bg-white px-3.5 text-base text-gray-800 outline-none transition placeholder:text-gray-400 focus:ring-2 sm:text-sm',
                    error
                        ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
                        : 'border-gray-300 focus:border-[#3f6f3d] focus:ring-[#3f6f3d]/15',
                ].join(' ')}
            />
            {helper && !error && (
                <p className="mt-1.5 text-xs text-gray-400">{helper}</p>
            )}
            {error && (
                <p className="mt-1.5 text-xs font-semibold text-red-600">
                    {error}
                </p>
            )}
        </label>
    );
}

function SelectField({
    label,
    value,
    onChange,
    options,
    required = false,
    error,
    disabled = false,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: string }[];
    required?: boolean;
    error?: string;
    disabled?: boolean;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}
                {required && <span className="ml-1 text-red-500">*</span>}
            </span>
            <select
                value={value}
                disabled={disabled}
                onChange={(event) => onChange(event.target.value)}
                className={[
                    'min-h-11 w-full rounded-xl border bg-white px-3.5 text-base text-gray-800 outline-none transition focus:ring-2 disabled:cursor-not-allowed disabled:bg-gray-100 disabled:text-gray-400 sm:text-sm',
                    error
                        ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
                        : 'border-gray-300 focus:border-[#3f6f3d] focus:ring-[#3f6f3d]/15',
                ].join(' ')}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
            {error && (
                <p className="mt-1.5 text-xs font-semibold text-red-600">
                    {error}
                </p>
            )}
        </label>
    );
}

function SectorCard({
    selected,
    disabled = false,
    title,
    description,
    icon,
    onClick,
}: {
    selected: boolean;
    disabled?: boolean;
    title: string;
    description: string;
    icon: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            disabled={disabled}
            aria-pressed={selected}
            className={[
                'relative min-h-44 rounded-2xl border-2 p-5 text-left transition',
                selected
                    ? 'border-[#3f6f3d] bg-[#f4f8f2] shadow-sm'
                    : disabled
                      ? 'cursor-not-allowed border-gray-200 bg-gray-50 opacity-55'
                      : 'border-gray-200 bg-white hover:border-[#b9ceb4] hover:bg-gray-50',
            ].join(' ')}
        >
            <div
                className={[
                    'flex h-11 w-11 items-center justify-center rounded-xl',
                    selected
                        ? 'bg-[#3f6f3d] text-white'
                        : disabled
                          ? 'bg-gray-100 text-gray-400'
                          : 'bg-gray-100 text-gray-500',
                ].join(' ')}
            >
                {icon}
            </div>

            <p
                className={[
                    'mt-4 text-sm font-bold',
                    disabled && !selected ? 'text-gray-400' : 'text-gray-900',
                ].join(' ')}
            >
                {title}
            </p>

            <p
                className={[
                    'mt-2 text-xs leading-5',
                    disabled && !selected ? 'text-gray-400' : 'text-gray-500',
                ].join(' ')}
            >
                {description}
            </p>

            <div
                className={[
                    'absolute right-4 top-4 flex h-6 w-6 items-center justify-center rounded-md border-2',
                    selected
                        ? 'border-[#3f6f3d] bg-[#3f6f3d] text-white'
                        : disabled
                          ? 'border-gray-200 bg-gray-100'
                          : 'border-gray-300 bg-white',
                ].join(' ')}
            >
                {selected && <TinyCheckIcon />}
            </div>
        </button>
    );
}

function PasswordField({
    label,
    value,
    show,
    error,
    onChange,
    onToggle,
}: {
    label: string;
    value: string;
    show: boolean;
    error?: string;
    onChange: (value: string) => void;
    onToggle: () => void;
}) {
    return (
        <label className="block">
            <span className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}
                <span className="ml-1 text-red-500">*</span>
            </span>

            <div
                className={[
                    'flex min-h-11 items-center rounded-xl border bg-white px-3.5 transition focus-within:ring-2',
                    error
                        ? 'border-red-300 focus-within:border-red-400 focus-within:ring-red-100'
                        : 'border-gray-300 focus-within:border-[#3f6f3d] focus-within:ring-[#3f6f3d]/15',
                ].join(' ')}
            >
                <input
                    type={show ? 'text' : 'password'}
                    value={value}
                    onChange={(event) => onChange(event.target.value)}
                    placeholder="Enter password"
                    className="h-11 min-w-0 flex-1 border-0 bg-transparent p-0 text-base outline-none focus:ring-0 sm:text-sm"
                />

                <button
                    type="button"
                    onClick={onToggle}
                    className="ml-2 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                    aria-label={show ? 'Hide password' : 'Show password'}
                >
                    {show ? <EyeOffIcon /> : <EyeIcon />}
                </button>
            </div>

            {error && (
                <p className="mt-1.5 text-xs font-semibold text-red-600">
                    {error}
                </p>
            )}
        </label>
    );
}

function PasswordRequirements({ password }: { password: string }) {
    return (
        <div className="mt-4 rounded-xl bg-gray-50 p-4">
            <p className="text-xs font-bold text-gray-700">
                Password requirements
            </p>
            <div className="mt-2 grid gap-2 sm:grid-cols-3">
                <Requirement
                    met={password.length >= 8}
                    text="8+ characters"
                />
                <Requirement
                    met={/[A-Za-z]/.test(password)}
                    text="Contains a letter"
                />
                <Requirement
                    met={/\d/.test(password)}
                    text="Contains a number"
                />
            </div>
        </div>
    );
}

function Requirement({ met, text }: { met: boolean; text: string }) {
    return (
        <div className="flex items-center gap-2">
            <div
                className={[
                    'flex h-4 w-4 items-center justify-center rounded-full',
                    met
                        ? 'bg-[#3f6f3d] text-white'
                        : 'border border-gray-300 text-transparent',
                ].join(' ')}
            >
                <TinyCheckIcon />
            </div>
            <span
                className={[
                    'text-xs',
                    met
                        ? 'font-medium text-[#3f6f3d]'
                        : 'text-gray-500',
                ].join(' ')}
            >
                {text}
            </span>
        </div>
    );
}

function ReviewCard({
    title,
    onEdit,
    children,
}: {
    title: string;
    onEdit: () => void;
    children: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-gray-200 p-4 sm:p-5">
            <div className="mb-4 flex items-center justify-between gap-3">
                <h3 className="text-sm font-bold text-gray-900">{title}</h3>
                <button
                    type="button"
                    onClick={onEdit}
                    className="text-xs font-bold text-[#3f6f3d] hover:underline"
                >
                    Edit
                </button>
            </div>
            {children}
        </div>
    );
}

function ReviewItem({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-gray-400">{label}</p>
            <p className="mt-1 break-words text-sm font-semibold text-gray-700">
                {value || '—'}
            </p>
        </div>
    );
}

function DocumentStatus({
    label,
    file,
}: {
    label: string;
    file: File | null;
}) {
    return (
        <div className="flex min-w-0 items-center gap-3 rounded-xl bg-gray-50 p-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-[#e8f1e4] text-[#3f6f3d]">
                <DocumentIcon />
            </div>
            <div className="min-w-0">
                <p className="text-xs font-bold text-gray-700">{label}</p>
                <p className="mt-0.5 truncate text-xs text-gray-500">
                    {file?.name ?? 'Not provided'}
                </p>
            </div>
        </div>
    );
}

function getStepDescription(step: number) {
    switch (step) {
        case 1:
            return 'Choose the barangay where you currently reside.';
        case 2:
            return 'Enter your personal information and select your purok.';
        case 3:
            return 'Choose Regular Resident, Senior Citizen, PWD, or both Senior Citizen and PWD.';
        case 4:
            return 'Upload your valid ID and supporting proof, then capture a live identity photo.';
        case 5:
            return 'Add your contact information and create your password.';
        case 6:
            return 'Review everything carefully before submitting.';
        default:
            return '';
    }
}

function formatFileSize(bytes: number) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function ArrowLeftIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m15 18-6-6 6-6" />
        </svg>
    );
}

function ArrowRightIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m9 18 6-6-6-6" />
        </svg>
    );
}

function SendIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m22 2-7 20-4-9-9-4Z" />
            <path d="M22 2 11 13" />
        </svg>
    );
}

function SpinnerIcon() {
    return (
        <svg
            className="h-4 w-4 animate-spin"
            viewBox="0 0 24 24"
            fill="none"
        >
            <circle
                cx="12"
                cy="12"
                r="9"
                stroke="currentColor"
                strokeWidth="3"
                opacity="0.25"
            />
            <path
                d="M21 12a9 9 0 0 0-9-9"
                stroke="currentColor"
                strokeWidth="3"
                strokeLinecap="round"
            />
        </svg>
    );
}

function InfoIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v6" />
            <path d="M12 7h.01" />
        </svg>
    );
}

function MapPinIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />
            <circle cx="12" cy="10" r="2.5" />
        </svg>
    );
}

function RegularResidentIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="7" r="3" />
            <path d="M5 21c.8-4.5 3.2-7 7-7s6.2 2.5 7 7" />
        </svg>
    );
}

function SeniorIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="5" r="2" />
            <path d="m10 9 3 2 2 5" />
            <path d="M10 9 8 14" />
            <path d="m13 11-2 5-3 4" />
            <path d="M15 16h3" />
            <path d="M18 12v9" />
        </svg>
    );
}

function AccessibilityIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="4" r="2" />
            <path d="M5 8h14" />
            <path d="M12 6v7" />
            <path d="m8 21 4-8 4 8" />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6Z" />
        </svg>
    );
}

function IdCardIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <rect x="3" y="5" width="18" height="14" rx="2" />
            <circle cx="8" cy="11" r="2" />
            <path d="M5.5 16c.5-1.8 1.3-2.5 2.5-2.5s2 .7 2.5 2.5" />
            <path d="M13 10h5" />
            <path d="M13 14h4" />
        </svg>
    );
}

function CameraIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M4 7h3l2-3h6l2 3h3a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2Z" />
            <circle cx="12" cy="13" r="4" />
        </svg>
    );
}

function DocumentIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M6 3h8l4 4v14H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M8 13h6" />
            <path d="M8 17h5" />
        </svg>
    );
}

function EyeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
    );
}

function EyeOffIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m3 3 18 18" />
            <path d="M10.6 10.7a2 2 0 0 0 2.7 2.7" />
            <path d="M9.9 4.3A11 11 0 0 1 12 4c6.5 0 10 8 10 8a15 15 0 0 1-2.1 3.3" />
            <path d="M6.5 6.6C3.6 8.4 2 12 2 12s3.5 8 10 8a9.8 9.8 0 0 0 4.2-.9" />
        </svg>
    );
}

function SmallCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}

function TinyCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-3 w-3"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}

function CircleCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12 2.5 2.5L16 9" />
        </svg>
    );
}
