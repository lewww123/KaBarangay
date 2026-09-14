import { FormEvent, useEffect, useRef, useState } from 'react';
import { router } from '@inertiajs/react';

/*
|--------------------------------------------------------------------------
| Types
|--------------------------------------------------------------------------
*/

type Sender = 'user' | 'bot';

type CertificateStatus =
    | 'Pending'
    | 'For Payment'
    | 'Processing'
    | 'Ready for Claim'
    | 'Released';

type ActionType =
    | 'track'
    | 'certificates'
    | 'requirements'
    | 'request'
    | 'complaint'
    | 'main'
    | 'view-tracking'
    | 'continue-request'
    | 'open-lupon'
    | 'clear-chat';

type MessageAction = {
    label: string;
    action: ActionType;
};

type Message = {
    id: string;
    sender: Sender;
    text: string;
    actions?: MessageAction[];
};

type Certificate = {
    id: string;
    name: string;
    fee: number;
    keywords: string[];
    requirements: string[];
};

type CertificateRequest = {
    id: number;
    reference: string;
    certificate: string;
    status: CertificateStatus;
    date: string;
};

type SavedAssistantState = {
    messages: Message[];
    selectedCertificateId: string | null;
};

/*
|--------------------------------------------------------------------------
| Local Storage Keys
|--------------------------------------------------------------------------
*/

const CHAT_STORAGE_KEY = 'kabarangay_assistant_state';

const CERTIFICATE_FLOW_KEY = 'kabarangay_certificate_flow';

/*
|--------------------------------------------------------------------------
| Certificate Data
|--------------------------------------------------------------------------
|
| Temporary data only.
| Later, this will come from the database.
|
*/

const certificates: Certificate[] = [
    {
        id: 'residency',
        name: 'Certificate of Residency',
        fee: 100,
        keywords: [
            'residency',
            'resident',
            'certificate of residency',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Purpose of request',
        ],
    },
    {
        id: 'indigency',
        name: 'Certificate of Indigency',
        fee: 0,
        keywords: [
            'indigency',
            'indigent',
            'certificate of indigency',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Purpose of request',
        ],
    },
    {
        id: 'barangay-clearance',
        name: 'Barangay Clearance',
        fee: 0,
        keywords: [
            'clearance',
            'barangay clearance',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Purpose of request',
        ],
    },
    {
        id: 'retail',
        name: 'Retail',
        fee: 300,
        keywords: [
            'retail',
            'retail certificate',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Business name',
            'Business address',
            'Purpose of request',
        ],
    },
    {
        id: 'tricycle',
        name: 'Tricycle',
        fee: 300,
        keywords: [
            'tricycle',
            'tricycle certificate',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Purpose of request',
        ],
    },
    {
        id: 'sari-sari',
        name: 'Sari-Sari Store',
        fee: 500,
        keywords: [
            'sari sari',
            'sari-sari',
            'sari sari store',
            'sari-sari store',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Business name',
            'Business address',
            'Purpose of request',
        ],
    },
    {
        id: 'hardware',
        name: 'Hardware Store',
        fee: 3000,
        keywords: [
            'hardware',
            'hardware store',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Business name',
            'Business address',
            'Purpose of request',
        ],
    },
    {
        id: 'pool-resort',
        name: 'Pool / Resort',
        fee: 1500,
        keywords: [
            'pool',
            'resort',
            'pool resort',
            'pool / resort',
        ],
        requirements: [
            'Verified KaBarangay resident account',
            'Business name',
            'Business address',
            'Purpose of request',
        ],
    },
];

/*
|--------------------------------------------------------------------------
| Temporary Request Data
|--------------------------------------------------------------------------
|
| Later this will be replaced by the logged-in resident's actual requests.
|
*/

const sampleRequests: CertificateRequest[] = [
    {
        id: 1,
        reference: 'CERT-2026-0001',
        certificate: 'Certificate of Residency',
        status: 'Processing',
        date: 'September 3, 2026',
    },
    {
        id: 2,
        reference: 'CERT-2026-0002',
        certificate: 'Certificate of Indigency',
        status: 'Ready for Claim',
        date: 'September 2, 2026',
    },
];

/*
|--------------------------------------------------------------------------
| Initial Message
|--------------------------------------------------------------------------
*/

const initialMessages: Message[] = [
    {
        id: 'welcome',
        sender: 'bot',
        text:
            'Magandang araw! 👋 I am KaBarangay Assistant.\n\n' +
            'You can ask me about certificate requests, tracking, fees, ' +
            'requirements, complaints, and other barangay services.',
        actions: [
            {
                label: 'Track Certificate',
                action: 'track',
            },
            {
                label: 'Certificates & Fees',
                action: 'certificates',
            },
            {
                label: 'Request Certificate',
                action: 'request',
            },
            {
                label: 'Requirements',
                action: 'requirements',
            },
            {
                label: 'File a Complaint',
                action: 'complaint',
            },
        ],
    },
];

/*
|--------------------------------------------------------------------------
| Component
|--------------------------------------------------------------------------
*/

export default function KaBarangayAssistant() {
    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    const [open, setOpen] = useState(false);

    const [message, setMessage] = useState('');

    const [typing, setTyping] = useState(false);

    const [loaded, setLoaded] = useState(false);

    const [messages, setMessages] =
        useState<Message[]>(initialMessages);

    const [
        selectedCertificateId,
        setSelectedCertificateId,
    ] = useState<string | null>(null);

    const messagesEndRef =
        useRef<HTMLDivElement | null>(null);

    /*
    |--------------------------------------------------------------------------
    | Load Saved Conversation
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        try {
            const saved =
                window.localStorage.getItem(
                    CHAT_STORAGE_KEY,
                );

            if (saved) {
                const parsed =
                    JSON.parse(
                        saved,
                    ) as SavedAssistantState;

                if (
                    Array.isArray(
                        parsed.messages,
                    ) &&
                    parsed.messages.length >
                        0
                ) {
                    setMessages(
                        parsed.messages,
                    );
                }

                if (
                    parsed.selectedCertificateId
                ) {
                    setSelectedCertificateId(
                        parsed.selectedCertificateId,
                    );
                }
            }
        } catch (error) {
            console.error(
                'Unable to load KaBarangay Assistant history.',
                error,
            );
        }

        setLoaded(true);
    }, []);

    /*
    |--------------------------------------------------------------------------
    | Save Conversation
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (!loaded) {
            return;
        }

        const state: SavedAssistantState = {
            messages,
            selectedCertificateId,
        };

        try {
            window.localStorage.setItem(
                CHAT_STORAGE_KEY,
                JSON.stringify(
                    state,
                ),
            );
        } catch (error) {
            console.error(
                'Unable to save KaBarangay Assistant history.',
                error,
            );
        }
    }, [
        messages,
        selectedCertificateId,
        loaded,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Scroll To Latest Message
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (!open) {
            return;
        }

        window.setTimeout(() => {
            messagesEndRef.current?.scrollIntoView({
                behavior: 'smooth',
            });
        }, 50);
    }, [
        messages,
        typing,
        open,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    const createId = () => {
        return `${Date.now()}-${Math.random()
            .toString(36)
            .slice(2)}`;
    };

    const addUserMessage = (
        text: string,
    ) => {
        const newMessage: Message = {
            id: createId(),
            sender: 'user',
            text,
        };

        setMessages((current) => [
            ...current,
            newMessage,
        ]);
    };

    const addBotMessage = (
        text: string,
        actions?: MessageAction[],
    ) => {
        const newMessage: Message = {
            id: createId(),
            sender: 'bot',
            text,
            actions,
        };

        setMessages((current) => [
            ...current,
            newMessage,
        ]);
    };

    const selectedCertificate =
        certificates.find(
            (certificate) =>
                certificate.id ===
                selectedCertificateId,
        ) ?? null;

    /*
    |--------------------------------------------------------------------------
    | Send Typed Message
    |--------------------------------------------------------------------------
    */

    const sendMessage = (
        customMessage?: string,
    ) => {
        const content =
            (
                customMessage ??
                message
            ).trim();

        if (
            !content ||
            typing
        ) {
            return;
        }

        addUserMessage(
            content,
        );

        setMessage('');
        setTyping(true);

        window.setTimeout(() => {
            const response =
                getBotResponse(
                    content,
                );

            /*
            |--------------------------------------------------------------------------
            | Remember Certificate Mentioned By User
            |--------------------------------------------------------------------------
            */

            if (
                response.certificateId
            ) {
                setSelectedCertificateId(
                    response.certificateId,
                );
            }

            addBotMessage(
                response.text,
                response.actions,
            );

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Submit Text
    |--------------------------------------------------------------------------
    */

    const handleSubmit = (
        event: FormEvent<HTMLFormElement>,
    ) => {
        event.preventDefault();

        sendMessage();
    };

    /*
    |--------------------------------------------------------------------------
    | Handle Suggested Button
    |--------------------------------------------------------------------------
    */

    const handleAction = (
        action: ActionType,
    ) => {
        if (typing) {
            return;
        }

        switch (action) {
            case 'track':
                handleTrackCertificate();
                break;

            case 'certificates':
                handleCertificates();
                break;

            case 'requirements':
                handleRequirements();
                break;

            case 'request':
                handleRequestCertificate();
                break;

            case 'complaint':
                handleComplaint();
                break;

            case 'main':
                handleMainMenu();
                break;

            case 'view-tracking':
                handleViewTracking();
                break;

            case 'continue-request':
                handleContinueRequest();
                break;

            case 'open-lupon':
                handleOpenLupon();
                break;

            case 'clear-chat':
                handleClearChat();
                break;
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Track Certificate
    |--------------------------------------------------------------------------
    */

    const handleTrackCertificate = () => {
        addUserMessage(
            'Track my certificate',
        );

        setTyping(true);

        window.setTimeout(() => {
            if (
                sampleRequests.length ===
                0
            ) {
                addBotMessage(
                    'You currently have no certificate requests.',
                    [
                        {
                            label:
                                'Request Certificate',
                            action:
                                'request',
                        },
                        {
                            label:
                                'Main Menu',
                            action:
                                'main',
                        },
                    ],
                );

                setTyping(false);

                return;
            }

            const latest =
                sampleRequests[0];

            addBotMessage(
                `I found your latest certificate request.

${latest.certificate}
Reference No.: ${latest.reference}

Current Status: ${latest.status}

Submitted: ${latest.date}`,
                [
                    {
                        label:
                            'View Full Tracking',
                        action:
                            'view-tracking',
                    },
                    {
                        label:
                            'Main Menu',
                        action:
                            'main',
                    },
                ],
            );

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Full Tracking
    |--------------------------------------------------------------------------
    */

    const handleViewTracking = () => {
        const latest =
            sampleRequests[0];

        addUserMessage(
            'Show full tracking',
        );

        setTyping(true);

        window.setTimeout(() => {
            addBotMessage(
                `${latest.certificate}
${latest.reference}

Request Progress

✓ Submitted
✓ Reviewed
● Processing
○ Ready for Claim
○ Released

Your certificate is currently being prepared.

You can close this chat and return later. Your conversation will still be here.`,
                [
                    {
                        label:
                            'Track Certificate',
                        action:
                            'track',
                    },
                    {
                        label:
                            'Main Menu',
                        action:
                            'main',
                    },
                ],
            );

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Certificates & Fees
    |--------------------------------------------------------------------------
    */

    const handleCertificates = () => {
        addUserMessage(
            'Show available certificates and fees',
        );

        setTyping(true);

        window.setTimeout(() => {
            addBotMessage(
                buildCertificateList(),
                [
                    {
                        label:
                            'Request Certificate',
                        action:
                            'request',
                    },
                    {
                        label:
                            'View Requirements',
                        action:
                            'requirements',
                    },
                    {
                        label:
                            'Main Menu',
                        action:
                            'main',
                    },
                ],
            );

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    */

    const handleRequirements = () => {
        addUserMessage(
            'What are the requirements?',
        );

        setTyping(true);

        window.setTimeout(() => {
            if (
                selectedCertificate
            ) {
                addBotMessage(
                    buildRequirementMessage(
                        selectedCertificate,
                    ),
                    [
                        {
                            label:
                                `Continue with ${shortCertificateName(
                                    selectedCertificate,
                                )}`,
                            action:
                                'continue-request',
                        },
                        {
                            label:
                                'Certificates & Fees',
                            action:
                                'certificates',
                        },
                        {
                            label:
                                'Main Menu',
                            action:
                                'main',
                        },
                    ],
                );
            } else {
                addBotMessage(
                    `Requirements depend on the certificate you need.

Your verified resident information will be used automatically.

For regular certificates, you will usually provide:
• Purpose of request

Business-related certificates may also require:
• Business name
• Business address
• Other required business information

Tell me the certificate name, for example:
"What are the requirements for Residency?"`,
                    [
                        {
                            label:
                                'Certificates & Fees',
                            action:
                                'certificates',
                        },
                        {
                            label:
                                'Request Certificate',
                            action:
                                'request',
                        },
                        {
                            label:
                                'Main Menu',
                            action:
                                'main',
                        },
                    ],
                );
            }

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Request Certificate
    |--------------------------------------------------------------------------
    */

    const handleRequestCertificate = () => {
        addUserMessage(
            'I want to request a certificate',
        );

        setTyping(true);

        window.setTimeout(() => {
            if (
                selectedCertificate
            ) {
                addBotMessage(
                    `Sure! You previously selected:

${selectedCertificate.name}

${formatFee(
    selectedCertificate,
)}

I can take you directly to the Certificate Request page and remember this certificate for you.`,
                    [
                        {
                            label:
                                `Continue with ${shortCertificateName(
                                    selectedCertificate,
                                )}`,
                            action:
                                'continue-request',
                        },
                        {
                            label:
                                'View Requirements',
                            action:
                                'requirements',
                        },
                        {
                            label:
                                'Choose Another Certificate',
                            action:
                                'certificates',
                        },
                    ],
                );
            } else {
                addBotMessage(
                    `Sure! Which certificate would you like to request?

You can tell me naturally, for example:

"I need Certificate of Residency"

or view the complete certificate list first.`,
                    [
                        {
                            label:
                                'Certificates & Fees',
                            action:
                                'certificates',
                        },
                        {
                            label:
                                'View Requirements',
                            action:
                                'requirements',
                        },
                        {
                            label:
                                'Main Menu',
                            action:
                                'main',
                        },
                    ],
                );
            }

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Continue Certificate Request
    |--------------------------------------------------------------------------
    */

    const handleContinueRequest = () => {
        if (
            !selectedCertificate
        ) {
            addBotMessage(
                'Please tell me which certificate you want to request first.',
                [
                    {
                        label:
                            'Certificates & Fees',
                        action:
                            'certificates',
                    },
                ],
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Save Certificate Flow
        |--------------------------------------------------------------------------
        |
        | Certificates.tsx will read this.
        |
        */

        try {
            window.localStorage.setItem(
                CERTIFICATE_FLOW_KEY,
                JSON.stringify({
                    certificateId:
                        selectedCertificate.id,
                    certificateName:
                        selectedCertificate.name,
                    fee:
                        selectedCertificate.fee,
                    source:
                        'chatbot',
                    savedAt:
                        new Date().toISOString(),
                }),
            );
        } catch (error) {
            console.error(
                'Unable to save certificate flow.',
                error,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Add Message BEFORE Navigation
        |--------------------------------------------------------------------------
        */

        const navigationMessage: Message = {
            id: createId(),
            sender: 'bot',
            text:
                `Okay! I remembered ${selectedCertificate.name}.\n\n` +
                'I am taking you to the Certificate Request page now. ' +
                'You can open me again there if you need help.',
        };

        const nextMessages = [
            ...messages,
            {
                id: createId(),
                sender: 'user' as const,
                text:
                    `Continue with ${selectedCertificate.name}`,
            },
            navigationMessage,
        ];

        setMessages(
            nextMessages,
        );

        /*
        |--------------------------------------------------------------------------
        | Save Immediately Before Navigation
        |--------------------------------------------------------------------------
        */

        try {
            window.localStorage.setItem(
                CHAT_STORAGE_KEY,
                JSON.stringify({
                    messages:
                        nextMessages,
                    selectedCertificateId:
                        selectedCertificate.id,
                }),
            );
        } catch (error) {
            console.error(
                'Unable to save chat before navigation.',
                error,
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Navigate
        |--------------------------------------------------------------------------
        */

        window.setTimeout(() => {
            router.visit(
                '/resident/certificates',
            );
        }, 250);
    };

    /*
    |--------------------------------------------------------------------------
    | Complaint
    |--------------------------------------------------------------------------
    */

    const handleComplaint = () => {
        addUserMessage(
            'I want to file a complaint',
        );

        setTyping(true);

        window.setTimeout(() => {
            addBotMessage(
                `I can guide you.

To file a complaint:

1. Open File a Complaint.
2. Provide the respondent's information.
3. Enter the incident details.
4. Review the information.
5. Submit the complaint.
6. Wait for barangay/Lupon review.

Would you like to go to the complaint page?`,
                [
                    {
                        label:
                            'Continue to File a Complaint',
                        action:
                            'open-lupon',
                    },
                    {
                        label:
                            'Main Menu',
                        action:
                            'main',
                    },
                ],
            );

            setTyping(false);
        }, 450);
    };

    /*
    |--------------------------------------------------------------------------
    | Open Lupon
    |--------------------------------------------------------------------------
    */

    const handleOpenLupon = () => {
        const nextMessages: Message[] = [
            ...messages,
            {
                id: createId(),
                sender: 'user',
                text:
                    'Continue to File a Complaint',
            },
            {
                id: createId(),
                sender: 'bot',
                text:
                    'Okay! I am taking you to the File a Complaint page. ' +
                    'You can open me again there if you need help.',
            },
        ];

        setMessages(
            nextMessages,
        );

        try {
            window.localStorage.setItem(
                CHAT_STORAGE_KEY,
                JSON.stringify({
                    messages:
                        nextMessages,
                    selectedCertificateId,
                }),
            );
        } catch (error) {
            console.error(
                'Unable to save chat before navigation.',
                error,
            );
        }

        window.setTimeout(() => {
            router.visit(
                '/resident/lupon',
            );
        }, 250);
    };

    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    */

    const handleMainMenu = () => {
        addBotMessage(
            'What can I help you with?',
            [
                {
                    label:
                        'Track Certificate',
                    action:
                        'track',
                },
                {
                    label:
                        'Certificates & Fees',
                    action:
                        'certificates',
                },
                {
                    label:
                        'Request Certificate',
                    action:
                        'request',
                },
                {
                    label:
                        'Requirements',
                    action:
                        'requirements',
                },
                {
                    label:
                        'File a Complaint',
                    action:
                        'complaint',
                },
            ],
        );
    };

    /*
    |--------------------------------------------------------------------------
    | Clear Chat
    |--------------------------------------------------------------------------
    */

    const handleClearChat = () => {
        setMessages(
            initialMessages,
        );

        setSelectedCertificateId(
            null,
        );

        setMessage('');

        try {
            window.localStorage.removeItem(
                CHAT_STORAGE_KEY,
            );

            window.localStorage.removeItem(
                CERTIFICATE_FLOW_KEY,
            );
        } catch (error) {
            console.error(
                'Unable to clear KaBarangay Assistant history.',
                error,
            );
        }
    };

    /*
    |--------------------------------------------------------------------------
    | Render
    |--------------------------------------------------------------------------
    */

    return (
        <>
            {/* ============================================================
                FLOATING BUTTON
            ============================================================ */}

            {!open && (
                <button
                    type="button"
                    onClick={() =>
                        setOpen(true)
                    }
                    aria-label="Open KaBarangay Assistant"
                    className="
                        fixed
                        bottom-[86px]
                        right-4
                        z-[60]
                        flex
                        h-14
                        w-14
                        items-center
                        justify-center
                        rounded-full
                        bg-[#3f6f3d]
                        text-white
                        shadow-lg
                        transition
                        hover:bg-[#315b30]
                        active:scale-95

                        lg:bottom-6
                        lg:right-6
                    "
                >
                    <ChatIcon />
                </button>
            )}

            {/* ============================================================
                CHAT WINDOW
            ============================================================ */}

            {open && (
                <div
                    className="
                        fixed
                        bottom-[76px]
                        left-2
                        right-2
                        z-[70]
                        flex
                        h-[calc(100dvh-90px)]
                        max-h-[680px]
                        min-h-[480px]
                        flex-col
                        overflow-hidden
                        rounded-2xl
                        border
                        border-gray-200
                        bg-white
                        shadow-2xl

                        sm:bottom-4
                        sm:left-auto
                        sm:right-4
                        sm:h-[620px]
                        sm:w-[400px]

                        lg:bottom-6
                        lg:right-6
                        lg:h-[630px]
                        lg:w-[410px]
                    "
                >
                    {/* ====================================================
                        HEADER
                    ==================================================== */}

                    <div className="flex shrink-0 items-center justify-between bg-[#3f6f3d] px-4 py-3 text-white">

                        <div className="flex min-w-0 items-center gap-3">

                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white/15">
                                <AssistantIcon />
                            </div>

                            <div className="min-w-0">

                                <p className="truncate text-base font-semibold">
                                    KaBarangay Assistant
                                </p>

                                <div className="mt-0.5 flex items-center gap-1.5">

                                    <span className="h-2 w-2 rounded-full bg-green-300" />

                                    <span className="text-xs text-white/80">
                                        Ready to help
                                    </span>

                                </div>

                            </div>

                        </div>

                        <div className="flex items-center gap-1">

                            {/* CLEAR CHAT */}

                            <button
                                type="button"
                                onClick={
                                    handleClearChat
                                }
                                aria-label="Clear chat"
                                title="Clear chat"
                                className="flex h-10 w-10 items-center justify-center rounded-full transition hover:bg-white/10"
                            >
                                <TrashIcon />
                            </button>

                            {/* CLOSE */}

                            <button
                                type="button"
                                onClick={() =>
                                    setOpen(false)
                                }
                                aria-label="Close KaBarangay Assistant"
                                className="flex h-10 w-10 items-center justify-center rounded-full transition hover:bg-white/10"
                            >
                                <CloseIcon />
                            </button>

                        </div>

                    </div>

                    {/* ====================================================
                        CHAT MESSAGES
                    ==================================================== */}

                    <div className="flex-1 overflow-y-auto overscroll-contain bg-[#f7f6f0] p-3 sm:p-4">

                        <div className="space-y-4">

                            {messages.map(
                                (
                                    item,
                                ) => (
                                    <div
                                        key={
                                            item.id
                                        }
                                    >
                                        {/* MESSAGE */}

                                        <div
                                            className={`flex ${
                                                item.sender ===
                                                'user'
                                                    ? 'justify-end'
                                                    : 'justify-start'
                                            }`}
                                        >

                                            {item.sender ===
                                                'bot' && (
                                                <div className="mr-2 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e6efe3] text-[#3f6f3d]">
                                                    <AssistantSmallIcon />
                                                </div>
                                            )}

                                            <div
                                                className={`max-w-[85%] whitespace-pre-line break-words rounded-2xl px-4 py-3 text-sm leading-6 ${
                                                    item.sender ===
                                                    'user'
                                                        ? 'rounded-br-md bg-[#3f6f3d] text-white'
                                                        : 'rounded-bl-md border border-gray-200 bg-white text-gray-700'
                                                }`}
                                            >
                                                {
                                                    item.text
                                                }
                                            </div>

                                        </div>

                                        {/* ACTION BUTTONS */}

                                        {item.sender ===
                                            'bot' &&
                                            item.actions &&
                                            item.actions.length >
                                                0 && (
                                                <div className="ml-10 mt-2 flex flex-wrap gap-2">

                                                    {item.actions.map(
                                                        (
                                                            action,
                                                            index,
                                                        ) => (
                                                            <ActionButton
                                                                key={`${item.id}-${index}`}
                                                                label={
                                                                    action.label
                                                                }
                                                                onClick={() =>
                                                                    handleAction(
                                                                        action.action,
                                                                    )
                                                                }
                                                            />
                                                        ),
                                                    )}

                                                </div>
                                            )}

                                    </div>
                                ),
                            )}

                            {/* TYPING */}

                            {typing && (
                                <div className="flex justify-start">

                                    <div className="mr-2 mt-1 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[#e6efe3] text-[#3f6f3d]">
                                        <AssistantSmallIcon />
                                    </div>

                                    <div className="flex items-center gap-1 rounded-2xl rounded-bl-md border border-gray-200 bg-white px-4 py-3">

                                        <TypingDot delay="0ms" />

                                        <TypingDot delay="150ms" />

                                        <TypingDot delay="300ms" />

                                    </div>

                                </div>
                            )}

                            <div
                                ref={
                                    messagesEndRef
                                }
                            />

                        </div>

                    </div>

                    {/* ====================================================
                        INPUT
                    ==================================================== */}

                    <form
                        onSubmit={
                            handleSubmit
                        }
                        className="shrink-0 border-t border-gray-200 bg-white p-3"
                    >

                        <div className="flex items-end gap-2">

                            <textarea
                                rows={1}
                                value={
                                    message
                                }
                                onChange={(
                                    event,
                                ) =>
                                    setMessage(
                                        event.target
                                            .value,
                                    )
                                }
                                onKeyDown={(
                                    event,
                                ) => {
                                    if (
                                        event.key ===
                                            'Enter' &&
                                        !event.shiftKey
                                    ) {
                                        event.preventDefault();

                                        sendMessage();
                                    }
                                }}
                                placeholder="Ask KaBarangay..."
                                aria-label="Ask KaBarangay"
                                className="
                                    max-h-24
                                    min-h-11
                                    min-w-0
                                    flex-1
                                    resize-none
                                    rounded-xl
                                    border
                                    border-gray-300
                                    px-4
                                    py-2.5
                                    text-base
                                    text-gray-800
                                    outline-none
                                    transition
                                    placeholder:text-gray-400
                                    focus:border-[#3f6f3d]
                                    focus:ring-2
                                    focus:ring-[#3f6f3d]/20

                                    sm:text-sm
                                "
                            />

                            <button
                                type="submit"
                                disabled={
                                    !message.trim() ||
                                    typing
                                }
                                aria-label="Send message"
                                className="
                                    flex
                                    h-11
                                    w-11
                                    shrink-0
                                    items-center
                                    justify-center
                                    rounded-full
                                    bg-[#3f6f3d]
                                    text-white
                                    transition
                                    hover:bg-[#315b30]
                                    active:scale-95
                                    disabled:cursor-not-allowed
                                    disabled:opacity-40
                                "
                            >
                                <SendIcon />
                            </button>

                        </div>

                        <p className="mt-2 text-center text-[10px] leading-4 text-gray-400">
                            Your conversation is saved on this device.
                        </p>

                    </form>

                </div>
            )}
        </>
    );
}

/*
|--------------------------------------------------------------------------
| Bot Response
|--------------------------------------------------------------------------
*/

function getBotResponse(
    userMessage: string,
): {
    text: string;
    actions?: MessageAction[];
    certificateId?: string;
} {
    const message =
        normalizeText(
            userMessage,
        );

    /*
    |--------------------------------------------------------------------------
    | Detect Certificate
    |--------------------------------------------------------------------------
    */

    const certificate =
        findCertificate(
            message,
        );

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    */

    if (
        includesAny(
            message,
            [
                'status',
                'track',
                'tracking',
                'ready na',
                'certificate ko',
                'request ko',
                'nasaan na',
                'tapos na',
            ],
        )
    ) {
        const latest =
            sampleRequests[0];

        return {
            text:
                `Your latest request is ${latest.certificate}.\n\n` +
                `Reference No.: ${latest.reference}\n` +
                `Current Status: ${latest.status}\n\n` +
                'Would you like to see the complete tracking progress?',
            actions: [
                {
                    label:
                        'View Full Tracking',
                    action:
                        'view-tracking',
                },
                {
                    label:
                        'Main Menu',
                    action:
                        'main',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Specific Certificate
    |--------------------------------------------------------------------------
    */

    if (certificate) {
        /*
        |--------------------------------------------------------------------------
        | Requirements
        |--------------------------------------------------------------------------
        */

        if (
            includesAny(
                message,
                [
                    'requirement',
                    'requirements',
                    'kailangan',
                    'need',
                    'needed',
                ],
            )
        ) {
            return {
                certificateId:
                    certificate.id,

                text:
                    buildRequirementMessage(
                        certificate,
                    ),

                actions: [
                    {
                        label:
                            `Continue with ${shortCertificateName(
                                certificate,
                            )}`,
                        action:
                            'continue-request',
                    },
                    {
                        label:
                            'Certificates & Fees',
                        action:
                            'certificates',
                    },
                ],
            };
        }

        /*
        |--------------------------------------------------------------------------
        | User Wants To Request It
        |--------------------------------------------------------------------------
        */

        if (
            includesAny(
                message,
                [
                    'request',
                    'kuha',
                    'kumuha',
                    'gusto ko',
                    'need ko',
                    'i need',
                    'get',
                    'apply',
                ],
            )
        ) {
            return {
                certificateId:
                    certificate.id,

                text:
                    `Sure! You selected:

${certificate.name}

${formatFee(
    certificate,
)}

I can take you to the Certificate Request page and remember this certificate for you.`,

                actions: [
                    {
                        label:
                            `Continue with ${shortCertificateName(
                                certificate,
                            )}`,
                        action:
                            'continue-request',
                    },
                    {
                        label:
                            'View Requirements',
                        action:
                            'requirements',
                    },
                    {
                        label:
                            'Choose Another Certificate',
                        action:
                            'certificates',
                    },
                ],
            };
        }

        /*
        |--------------------------------------------------------------------------
        | Certificate Fee / General Question
        |--------------------------------------------------------------------------
        */

        return {
            certificateId:
                certificate.id,

            text:
                `${certificate.name}\n\n` +
                `${formatFee(
                    certificate,
                )}\n\n` +
                'Would you like to view the requirements or start a request?',

            actions: [
                {
                    label:
                        'View Requirements',
                    action:
                        'requirements',
                },
                {
                    label:
                        `Request ${shortCertificateName(
                            certificate,
                        )}`,
                    action:
                        'continue-request',
                },
                {
                    label:
                        'Certificates & Fees',
                    action:
                        'certificates',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Certificate List / Fees
    |--------------------------------------------------------------------------
    */

    if (
        includesAny(
            message,
            [
                'available certificate',
                'available certificates',
                'list of certificate',
                'list of certificates',
                'what certificate',
                'what certificates',
                'anong certificate',
                'anong certificates',
                'certificate available',
                'certificates available',
                'certificate fee',
                'certificate fees',
                'fees',
                'magkano',
                'bayad',
                'libre',
                'free',
            ],
        )
    ) {
        return {
            text:
                buildCertificateList(),

            actions: [
                {
                    label:
                        'Request Certificate',
                    action:
                        'request',
                },
                {
                    label:
                        'View Requirements',
                    action:
                        'requirements',
                },
                {
                    label:
                        'Main Menu',
                    action:
                        'main',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | General Request Certificate
    |--------------------------------------------------------------------------
    */

    if (
        (
            message.includes(
                'request',
            ) &&
            message.includes(
                'certificate',
            )
        ) ||
        message.includes(
            'kuha ng certificate',
        ) ||
        message.includes(
            'kumuha ng certificate',
        ) ||
        message.includes(
            'gusto ko certificate',
        )
    ) {
        return {
            text:
                `Sure! I can help you request a certificate.

Tell me which certificate you need, or view the complete list first.

For example:
"Gusto ko kumuha ng Certificate of Residency."`,

            actions: [
                {
                    label:
                        'Certificates & Fees',
                    action:
                        'certificates',
                },
                {
                    label:
                        'View Requirements',
                    action:
                        'requirements',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Requirements
    |--------------------------------------------------------------------------
    */

    if (
        includesAny(
            message,
            [
                'requirement',
                'requirements',
                'kailangan',
            ],
        )
    ) {
        return {
            text:
                `Requirements depend on the certificate you need.

Tell me the certificate name and I can explain the requirements.

For example:
"What are the requirements for Certificate of Residency?"`,

            actions: [
                {
                    label:
                        'Certificates & Fees',
                    action:
                        'certificates',
                },
                {
                    label:
                        'Request Certificate',
                    action:
                        'request',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Complaint
    |--------------------------------------------------------------------------
    */

    if (
        includesAny(
            message,
            [
                'complaint',
                'reklamo',
                'lupon',
                'file complaint',
            ],
        )
    ) {
        return {
            text:
                `I can help you file a complaint.

You will provide the respondent information and incident details, review the information, and submit it for barangay/Lupon review.`,

            actions: [
                {
                    label:
                        'Continue to File a Complaint',
                    action:
                        'open-lupon',
                },
                {
                    label:
                        'Main Menu',
                    action:
                        'main',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Greeting
    |--------------------------------------------------------------------------
    */

    if (
        includesAny(
            message,
            [
                'hi',
                'hello',
                'hey',
                'kumusta',
                'magandang araw',
                'good morning',
                'good afternoon',
                'good evening',
            ],
        )
    ) {
        return {
            text:
                'Magandang araw! 👋 What can I help you with?',

            actions: [
                {
                    label:
                        'Track Certificate',
                    action:
                        'track',
                },
                {
                    label:
                        'Certificates & Fees',
                    action:
                        'certificates',
                },
                {
                    label:
                        'Request Certificate',
                    action:
                        'request',
                },
                {
                    label:
                        'Requirements',
                    action:
                        'requirements',
                },
                {
                    label:
                        'File a Complaint',
                    action:
                        'complaint',
                },
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Default
    |--------------------------------------------------------------------------
    */

    return {
        text:
            `I can help you with:

• Certificate requests
• Certificate tracking
• Certificate fees
• Requirements
• Filing a complaint
• Barangay services

You can type your question naturally or choose an option below.`,

        actions: [
            {
                label:
                    'Track Certificate',
                action:
                    'track',
            },
            {
                label:
                    'Certificates & Fees',
                action:
                    'certificates',
            },
            {
                label:
                    'Request Certificate',
                action:
                    'request',
            },
            {
                label:
                    'Requirements',
                action:
                    'requirements',
            },
            {
                label:
                    'File a Complaint',
                action:
                    'complaint',
            },
        ],
    };
}

/*
|--------------------------------------------------------------------------
| Find Certificate
|--------------------------------------------------------------------------
*/

function findCertificate(
    message: string,
) {
    return certificates.find(
        (certificate) =>
            certificate.keywords.some(
                (keyword) =>
                    message.includes(
                        normalizeText(
                            keyword,
                        ),
                    ),
            ),
    );
}

/*
|--------------------------------------------------------------------------
| Normalize Text
|--------------------------------------------------------------------------
*/

function normalizeText(
    text: string,
) {
    return text
        .toLowerCase()
        .replace(/[-/]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/*
|--------------------------------------------------------------------------
| Includes Any
|--------------------------------------------------------------------------
*/

function includesAny(
    text: string,
    words: string[],
) {
    return words.some(
        (word) =>
            text.includes(
                normalizeText(
                    word,
                ),
            ),
    );
}

/*
|--------------------------------------------------------------------------
| Certificate List
|--------------------------------------------------------------------------
*/

function buildCertificateList() {
    return `Here are the available certificates and their current fees:

📄 Certificate of Residency
₱100 — WITH PAYMENT

📄 Certificate of Indigency
FREE — NO PAYMENT

📄 Barangay Clearance
FREE — NO PAYMENT

🏪 Retail
₱300 — WITH PAYMENT

🛺 Tricycle
₱300 — WITH PAYMENT

🏪 Sari-Sari Store
₱500 — WITH PAYMENT

🔧 Hardware Store
₱3,000 — WITH PAYMENT

🏊 Pool / Resort
₱1,500 — WITH PAYMENT

You can tell me which certificate you want.

Example:
"Gusto ko kumuha ng Certificate of Residency."`;
}

/*
|--------------------------------------------------------------------------
| Requirement Message
|--------------------------------------------------------------------------
*/

function buildRequirementMessage(
    certificate: Certificate,
) {
    const requirements =
        certificate.requirements
            .map(
                (
                    requirement,
                    index,
                ) =>
                    `${index + 1}. ${requirement}`,
            )
            .join('\n');

    return `${certificate.name}

${formatFee(
    certificate,
)}

Requirements:

${requirements}

Your verified resident information will be used automatically where applicable.`;
}

/*
|--------------------------------------------------------------------------
| Format Fee
|--------------------------------------------------------------------------
*/

function formatFee(
    certificate: Certificate,
) {
    if (
        certificate.fee ===
        0
    ) {
        return 'FREE — NO PAYMENT';
    }

    return `₱${certificate.fee.toLocaleString()} — WITH PAYMENT`;
}

/*
|--------------------------------------------------------------------------
| Short Certificate Name
|--------------------------------------------------------------------------
*/

function shortCertificateName(
    certificate: Certificate,
) {
    if (
        certificate.id ===
        'residency'
    ) {
        return 'Residency';
    }

    if (
        certificate.id ===
        'indigency'
    ) {
        return 'Indigency';
    }

    if (
        certificate.id ===
        'barangay-clearance'
    ) {
        return 'Barangay Clearance';
    }

    return certificate.name;
}

/*
|--------------------------------------------------------------------------
| Action Button
|--------------------------------------------------------------------------
*/

function ActionButton({
    label,
    onClick,
}: {
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={
                onClick
            }
            className="
                min-h-10
                rounded-full
                border
                border-[#bcd2b6]
                bg-white
                px-3.5
                py-2
                text-xs
                font-semibold
                text-[#3f6f3d]
                transition
                hover:bg-[#edf4ea]
                active:scale-[0.98]

                sm:text-sm
            "
        >
            {label}
        </button>
    );
}

/*
|--------------------------------------------------------------------------
| Typing Dot
|--------------------------------------------------------------------------
*/

function TypingDot({
    delay,
}: {
    delay: string;
}) {
    return (
        <span
            className="h-1.5 w-1.5 animate-bounce rounded-full bg-gray-400"
            style={{
                animationDelay:
                    delay,
            }}
        />
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function ChatIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-7 w-7"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M21 12a8 8 0 0 1-8 8H6l-4 2 1.4-4.2A8 8 0 1 1 21 12Z" />
            <path d="M8 12h.01" />
            <path d="M12 12h.01" />
            <path d="M16 12h.01" />
        </svg>
    );
}

function AssistantIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect
                x="5"
                y="7"
                width="14"
                height="11"
                rx="4"
            />

            <path d="M12 3v4" />

            <path d="M9 12h.01" />

            <path d="M15 12h.01" />

            <path d="M9 15h6" />
        </svg>
    );
}

function AssistantSmallIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect
                x="5"
                y="7"
                width="14"
                height="11"
                rx="4"
            />

            <path d="M12 3v4" />

            <path d="M9 12h.01" />

            <path d="M15 12h.01" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            aria-hidden="true"
        >
            <path d="M6 6l12 12" />

            <path d="M18 6 6 18" />
        </svg>
    );
}

function SendIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m22 2-7 20-4-9-9-4 20-7Z" />

            <path d="M22 2 11 13" />
        </svg>
    );
}

function TrashIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4.5 w-4.5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M3 6h18" />

            <path d="M8 6V4h8v2" />

            <path d="M19 6l-1 14H6L5 6" />

            <path d="M10 11v5" />

            <path d="M14 11v5" />
        </svg>
    );
}