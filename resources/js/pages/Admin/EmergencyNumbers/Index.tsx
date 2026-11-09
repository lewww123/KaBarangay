import { Deferred, Head, router, usePage } from '@inertiajs/react';
import AdminPageSkeleton from '@/components/AdminPageSkeleton';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

export type ContactItem = {
    id: number;
    category: 'barangay_hotline' | 'emergency_hotline';
    sub_category: string | null;
    service_type?: string | null;
    coverage_area?: string | null;
    name: string;
    label: string | null;
    phone_number: string;
    can_call: boolean;
    can_sms: boolean;
    display_order: number;
    is_active: boolean;
    tel_uri: string;
    sms_uri: string;
};

type Props = {
    barangayHotlines: ContactItem[];
    sharedEmergencyContacts: ContactItem[];
};

export default function EmergencyNumbersIndex(props: Props) {
    return (
        <Deferred
            data={['barangayHotlines', 'sharedEmergencyContacts']}
            fallback={
                <AdminLayout active="emergency-numbers">
                    <AdminPageSkeleton rows={3} />
                </AdminLayout>
            }
        >
            <EmergencyNumbersContent {...props} />
        </Deferred>
    );
}

function EmergencyNumbersContent({
    barangayHotlines,
    sharedEmergencyContacts,
}: Props) {
    const { flash, errors: pageErrors } = usePage<{
        flash?: { success?: string; error?: string };
        errors?: Record<string, string>;
    }>().props;

    const [isFormOpen, setIsFormOpen] = useState(false);
    const [editingContact, setEditingContact] = useState<ContactItem | null>(
        null,
    );
    const [contactToDelete, setContactToDelete] = useState<ContactItem | null>(
        null,
    );
    const [isDeleting, setIsDeleting] = useState(false);

    const [subCategory, setSubCategory] = useState<string>('');
    const [name, setName] = useState('');
    const [label, setLabel] = useState('');
    const [phoneNumber, setPhoneNumber] = useState('');
    const [canCall, setCanCall] = useState(true);
    const [canSms, setCanSms] = useState(false);
    const [displayOrder, setDisplayOrder] = useState<number>(0);
    const [isActive, setIsActive] = useState(true);
    const [isSubmitting, setIsSubmitting] = useState(false);

    const openAddModal = () => {
        setEditingContact(null);
        setSubCategory('Tanod');
        setName('');
        setLabel('');
        setPhoneNumber('');
        setCanCall(true);
        setCanSms(false);
        setDisplayOrder(0);
        setIsActive(true);
        setIsFormOpen(true);
    };

    const openEditModal = (contact: ContactItem) => {
        setEditingContact(contact);
        setSubCategory(contact.sub_category || '');
        setName(contact.name);
        setLabel(contact.label || '');
        setPhoneNumber(contact.phone_number);
        setCanCall(contact.can_call);
        setCanSms(contact.can_sms);
        setDisplayOrder(contact.display_order);
        setIsActive(contact.is_active);
        setIsFormOpen(true);
    };

    const handleFormSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setIsSubmitting(true);

        const payload = {
            sub_category: subCategory || null,
            name,
            label: label || null,
            phone_number: phoneNumber,
            can_call: canCall,
            can_sms: canSms,
            display_order: displayOrder,
            is_active: isActive,
        };

        if (editingContact) {
            router.put(
                `/admin/emergency-numbers/${editingContact.id}`,
                payload,
                {
                    onSuccess: () => {
                        setIsFormOpen(false);
                        setEditingContact(null);
                    },
                    onFinish: () => setIsSubmitting(false),
                },
            );
        } else {
            router.post('/admin/emergency-numbers', payload, {
                onSuccess: () => {
                    setIsFormOpen(false);
                },
                onFinish: () => setIsSubmitting(false),
            });
        }
    };

    const confirmDelete = () => {
        if (!contactToDelete) return;
        setIsDeleting(true);

        router.delete(`/admin/emergency-numbers/${contactToDelete.id}`, {
            onSuccess: () => {
                setContactToDelete(null);
            },
            onFinish: () => setIsDeleting(false),
        });
    };

    return (
        <AdminLayout active="emergency-numbers">
            <Head title="Emergency Numbers Management" />

            <div className="mx-auto max-w-7xl space-y-6 p-4 font-sans sm:p-6 lg:p-8">
                <div className="flex flex-col gap-2 border-b border-line-soft pb-5 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight text-ink-strong sm:text-3xl">
                            Emergency Numbers
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Manage your barangay hotlines and Tanod contacts.
                            Verified emergency service contacts are maintained
                            centrally and are read-only here.
                        </p>
                    </div>
                </div>

                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 shadow-card">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800 shadow-card">
                        {flash.error}
                    </div>
                )}

                <div className="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
                    <div className="flex flex-col overflow-hidden rounded-xl border border-line-soft bg-white shadow-card">
                        <div className="flex items-center justify-between border-b border-line-soft bg-[#fbfdfa] px-5 py-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-soft text-lg font-semibold text-brand">
                                    
                                </span>
                                <div>
                                    <h2 className="text-lg font-semibold text-ink-strong">
                                        Barangay Hotlines
                                    </h2>
                                    <p className="text-xs text-ink-muted">
                                        Official Tanod and barangay contacts (
                                        {barangayHotlines.length})
                                    </p>
                                </div>
                            </div>
                            <button
                                type="button"
                                onClick={openAddModal}
                                className="inline-flex items-center gap-1.5 rounded-xl bg-brand px-3.5 py-2 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep focus:ring-2 focus:ring-[#3b6fa8]/30 focus:outline-none"
                            >
                                <span className="text-sm">+</span> Add Hotline
                            </button>
                        </div>

                        <div className="space-y-3 p-4 sm:p-5">
                            {barangayHotlines.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-line p-8 text-center">
                                    <p className="text-sm font-semibold text-ink">
                                        No barangay hotlines added yet.
                                    </p>
                                    <p className="mt-1 text-xs text-ink-muted">
                                        Add the official hotline or Tanod
                                        personnel numbers.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={openAddModal}
                                        className="mt-4 inline-flex items-center gap-1 rounded-lg border border-brand px-3 py-1.5 text-xs font-semibold text-brand hover:bg-canvas"
                                    >
                                        + Add First Contact
                                    </button>
                                </div>
                            ) : (
                                barangayHotlines.map((contact) => (
                                    <ContactRow
                                        key={contact.id}
                                        contact={contact}
                                        theme="green"
                                        onEdit={() => openEditModal(contact)}
                                        onDelete={() =>
                                            setContactToDelete(contact)
                                        }
                                    />
                                ))
                            )}
                        </div>
                    </div>

                    <div className="flex flex-col overflow-hidden rounded-xl border border-line-soft bg-white shadow-card">
                        <div className="flex items-center justify-between border-b border-line-soft bg-[#fdfafb] px-5 py-4">
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-lg font-semibold text-red-700">
                                    
                                </span>
                                <div>
                                    <h2 className="text-lg font-semibold text-ink-strong">
                                        Emergency Services
                                    </h2>
                                    <p className="text-xs text-ink-muted">
                                        Verified shared directory (
                                        {sharedEmergencyContacts.length})
                                    </p>
                                </div>
                            </div>
                            <span className="rounded-full bg-secondary px-2.5 py-1 text-[10px] font-semibold tracking-wide text-ink-muted uppercase">
                                Read-only
                            </span>
                        </div>

                        <div className="space-y-3 p-4 sm:p-5">
                            <div className="rounded-xl border border-red-100 bg-red-50 p-3 text-xs leading-5 text-red-800">
                                These contacts are managed centrally to ensure
                                consistent, reviewed emergency information.
                                Contact your city or municipal administrator to
                                request a correction.
                            </div>

                            {sharedEmergencyContacts.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-line p-8 text-center">
                                    <p className="text-sm font-semibold text-ink">
                                        No verified emergency contacts
                                        available.
                                    </p>
                                    <p className="mt-1 text-xs text-ink-muted">
                                        Shared emergency services have not been
                                        added yet.
                                    </p>
                                </div>
                            ) : (
                                sharedEmergencyContacts.map((contact) => (
                                    <ContactRow
                                        key={contact.id}
                                        contact={contact}
                                        theme="red"
                                    />
                                ))
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {isFormOpen && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="contact-modal-title"
                >
                    <div className="w-full max-w-lg overflow-hidden rounded-xl border border-line-soft bg-white shadow-xl">
                        <div className="flex items-center justify-between border-b border-line-soft px-6 py-4">
                            <h3
                                id="contact-modal-title"
                                className="text-lg font-semibold text-ink-strong"
                            >
                                {editingContact
                                    ? 'Edit Barangay Hotline'
                                    : 'Add Barangay Hotline'}
                            </h3>
                            <button
                                type="button"
                                onClick={() => setIsFormOpen(false)}
                                className="rounded-lg p-1 font-semibold text-ink-faint hover:bg-secondary hover:text-ink-muted"
                            >
                                
                            </button>
                        </div>

                        <form
                            onSubmit={handleFormSubmit}
                            className="space-y-4 p-6"
                        >
                            <div>
                                <label className="mb-1 block text-xs font-semibold tracking-wider text-ink uppercase">
                                    Subcategory / Group
                                </label>
                                <input
                                    type="text"
                                    value={subCategory}
                                    onChange={(e) =>
                                        setSubCategory(e.target.value)
                                    }
                                    placeholder="e.g. Official, Tanod, Desk"
                                    className="w-full rounded-xl border border-line px-3.5 py-2.5 text-sm text-ink-strong focus:border-brand focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold tracking-wider text-ink uppercase">
                                    Contact / Personnel Name *
                                </label>
                                <input
                                    type="text"
                                    value={name}
                                    onChange={(e) => setName(e.target.value)}
                                    placeholder="e.g. Barangay Tanod or Cenon Mabutin Bocacao"
                                    className="w-full rounded-xl border border-line px-3.5 py-2.5 text-sm text-ink-strong focus:border-brand focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                                    required
                                />
                                {pageErrors?.name && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {pageErrors.name}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold tracking-wider text-ink uppercase">
                                    Label / Position (Optional)
                                </label>
                                <input
                                    type="text"
                                    value={label}
                                    onChange={(e) => setLabel(e.target.value)}
                                    placeholder="e.g. Chief Tanod, Official Hotline"
                                    className="w-full rounded-xl border border-line px-3.5 py-2.5 text-sm text-ink-strong focus:border-brand focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                                />
                            </div>

                            <div>
                                <label className="mb-1 block text-xs font-semibold tracking-wider text-ink uppercase">
                                    Phone Number *
                                </label>
                                <input
                                    type="text"
                                    value={phoneNumber}
                                    onChange={(e) =>
                                        setPhoneNumber(e.target.value)
                                    }
                                    placeholder="e.g. 09394596081"
                                    className="w-full rounded-xl border border-line px-3.5 py-2.5 font-mono text-sm text-ink-strong focus:border-brand focus:ring-1 focus:ring-[#3b6fa8] focus:outline-none"
                                    required
                                />
                                {pageErrors?.phone_number && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {pageErrors.phone_number}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4 rounded-xl border border-line-soft bg-canvas/70 p-3.5">
                                <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink">
                                    <input
                                        type="checkbox"
                                        checked={canCall}
                                        onChange={(e) =>
                                            setCanCall(e.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                    />
                                    <span>
                                        Allow Call (confirm this number accepts
                                        calls)
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink">
                                    <input
                                        type="checkbox"
                                        checked={canSms}
                                        onChange={(e) =>
                                            setCanSms(e.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                    />
                                    <span>
                                        Allow Text/SMS (enable only after
                                        confirming SMS support)
                                    </span>
                                </label>

                                <label className="flex cursor-pointer items-center gap-2 text-xs font-medium text-ink">
                                    <input
                                        type="checkbox"
                                        checked={isActive}
                                        onChange={(e) =>
                                            setIsActive(e.target.checked)
                                        }
                                        className="h-4 w-4 rounded border-line text-brand focus:ring-[#3b6fa8]"
                                    />
                                    <span>Visible to Residents</span>
                                </label>

                                <div className="flex items-center gap-2 text-xs font-medium text-ink">
                                    <span>Sort Order:</span>
                                    <input
                                        type="number"
                                        min={0}
                                        value={displayOrder}
                                        onChange={(e) =>
                                            setDisplayOrder(
                                                parseInt(e.target.value, 10) ||
                                                    0,
                                            )
                                        }
                                        className="w-16 rounded border border-line px-2 py-1 text-center text-xs"
                                    />
                                </div>
                            </div>

                            <div className="flex items-center justify-end gap-3 border-t border-line-soft pt-3">
                                <button
                                    type="button"
                                    onClick={() => setIsFormOpen(false)}
                                    className="rounded-xl border border-line bg-white px-4 py-2 text-xs font-semibold text-ink hover:bg-canvas"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={isSubmitting}
                                    className="rounded-xl bg-brand px-5 py-2 text-xs font-semibold text-white shadow-card hover:bg-brand-deep disabled:opacity-50"
                                >
                                    {isSubmitting
                                        ? 'Saving…'
                                        : editingContact
                                          ? 'Update Hotline'
                                          : 'Save Hotline'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {contactToDelete && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 backdrop-blur-sm"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="delete-dialog-title"
                >
                    <div className="w-full max-w-md space-y-4 overflow-hidden rounded-xl border border-line-soft bg-white p-6 shadow-xl">
                        <div className="flex items-center gap-3 text-red-600">
                            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-red-100 text-xl font-semibold">
                                
                            </span>
                            <h3
                                id="delete-dialog-title"
                                className="text-base font-semibold text-ink-strong"
                            >
                                Delete Barangay Hotline
                            </h3>
                        </div>

                        <p className="text-sm text-ink-muted">
                            Are you sure you want to delete{' '}
                            <strong className="text-ink-strong">
                                {contactToDelete.name}
                            </strong>{' '}
                            ({contactToDelete.phone_number})? Residents will no
                            longer see this barangay hotline.
                        </p>

                        <div className="flex items-center justify-end gap-3 pt-2">
                            <button
                                type="button"
                                onClick={() => setContactToDelete(null)}
                                disabled={isDeleting}
                                className="rounded-xl border border-line bg-white px-4 py-2 text-xs font-semibold text-ink hover:bg-canvas disabled:opacity-50"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={confirmDelete}
                                disabled={isDeleting}
                                className="rounded-xl bg-red-600 px-4 py-2 text-xs font-semibold text-white shadow-card hover:bg-red-700 disabled:opacity-50"
                            >
                                {isDeleting ? 'Deleting…' : 'Yes, Delete'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </AdminLayout>
    );
}

function ContactRow({
    contact,
    theme,
    onEdit,
    onDelete,
}: {
    contact: ContactItem;
    theme: 'green' | 'red';
    onEdit?: () => void;
    onDelete?: () => void;
}) {
    return (
        <div className="flex flex-col justify-between gap-3 rounded-xl border border-line-soft bg-white p-3.5 transition hover:border-line hover:shadow-card sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-semibold text-ink-strong">
                        {contact.name}
                    </span>
                    {contact.sub_category && (
                        <span
                            className={`rounded-full px-2 py-0.5 text-[10px] font-semibold tracking-wider uppercase ${theme === 'green' ? 'bg-canvas text-brand' : 'bg-red-100 text-red-700'}`}
                        >
                            {contact.sub_category}
                        </span>
                    )}
                    {!contact.is_active && (
                        <span className="rounded-full bg-secondary px-2 py-0.5 text-[10px] font-semibold text-ink-muted uppercase">
                            Inactive
                        </span>
                    )}
                </div>

                {contact.label && (
                    <p className="mt-0.5 text-xs text-ink-muted">
                        {contact.label}
                    </p>
                )}

                {contact.coverage_area && (
                    <p className="mt-0.5 text-xs text-ink-muted">
                        Coverage: {contact.coverage_area}
                    </p>
                )}

                <div className="mt-1 flex flex-wrap items-center gap-3">
                    <span
                        className={`font-mono text-xs font-semibold tracking-wide ${theme === 'green' ? 'text-brand-deep' : 'text-red-700'}`}
                    >
                        {formatPhoneNumber(contact.phone_number)}
                    </span>

                    <div className="flex items-center gap-1.5 text-[11px] text-ink-faint">
                        {contact.can_call && (
                            <span title="Call action enabled">Call</span>
                        )}
                        {contact.can_call && contact.can_sms && <span>•</span>}
                        {contact.can_sms && (
                            <span title="SMS action enabled">SMS</span>
                        )}
                    </div>
                </div>
            </div>

            {onEdit && onDelete ? (
                <div className="flex shrink-0 items-center gap-2 border-t border-line-soft pt-2 sm:border-t-0 sm:pt-0">
                    <button
                        type="button"
                        onClick={onEdit}
                        className="rounded-lg border border-line-soft bg-canvas px-2.5 py-1 text-xs font-medium text-ink transition hover:bg-secondary"
                        title="Edit contact"
                    >
                        Edit
                    </button>
                    <button
                        type="button"
                        onClick={onDelete}
                        className="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600 transition hover:bg-red-100"
                        title="Delete contact"
                    >
                        Delete
                    </button>
                </div>
            ) : (
                <span className="shrink-0 pt-2 text-[11px] font-medium text-ink-faint sm:pt-0">
                    Read-only
                </span>
            )}
        </div>
    );
}

function formatPhoneNumber(phone: string) {
    if (phone.length === 11 && phone.startsWith('09')) {
        return `${phone.slice(0, 4)} ${phone.slice(4, 7)} ${phone.slice(7)}`;
    }
    return phone;
}
