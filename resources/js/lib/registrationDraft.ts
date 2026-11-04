const KEY = 'kabarangay.resident-registration.v1';
const TEXT_FIELDS = ['barangay_id', 'purok_id', 'first_name', 'middle_name', 'last_name', 'suffix', 'birthdate', 'sex', 'civil_status', 'address_line', 'mobile', 'email'] as const;
export const REGISTRATION_FILE_FIELDS = ['valid_id', 'live_identity_photo', 'senior_proof', 'pwd_proof'] as const;
export type DraftFiles = Partial<Record<typeof REGISTRATION_FILE_FIELDS[number], File | null>>;
export type RegistrationDraft = { fields: Partial<Record<typeof TEXT_FIELDS[number], string>> & { sectors?: string[] }; step: number; regularResidentSelected: boolean };

export function readRegistrationDraft(): RegistrationDraft | null {
    try {
        const value = JSON.parse(localStorage.getItem(KEY) || 'null');
        if (!value || value.version !== 1 || !value.fields) return null;
        const fields: RegistrationDraft['fields'] = {};
        for (const key of TEXT_FIELDS) if (typeof value.fields[key] === 'string') fields[key] = value.fields[key];
        if (Array.isArray(value.fields.sectors)) fields.sectors = value.fields.sectors.filter((v: unknown) => typeof v === 'string');
        return { fields, step: Math.max(1, Math.min(5, Number(value.step) || 1)), regularResidentSelected: value.regularResidentSelected === true };
    } catch { return null; }
}

export function saveRegistrationDraft(data: object, step: number, regularResidentSelected: boolean): boolean {
    try {
        const fields = Object.fromEntries([...TEXT_FIELDS, 'sectors'].map(key => [key, (data as Record<string, unknown>)[key]]));
        localStorage.setItem(KEY, JSON.stringify({ version: 1, fields, step, regularResidentSelected }));
        return true;
    } catch { return false; }
}

// Serialize file writes and deletion so a pending write cannot recreate a submitted draft.
let queue: Promise<unknown> = Promise.resolve();
function fileOperation(write?: DraftFiles | null): Promise<DraftFiles> {
    const operation = queue.catch(() => {}).then(() => new Promise<DraftFiles>((resolve, reject) => {
        const request = indexedDB.open(KEY, 1);
        request.onupgradeneeded = () => request.result.createObjectStore('draft');
        request.onerror = () => reject(request.error);
        request.onblocked = () => reject(new Error('Draft storage is blocked'));
        request.onsuccess = () => {
            const db = request.result;
            const transaction = db.transaction('draft', write === undefined ? 'readonly' : 'readwrite');
            const store = transaction.objectStore('draft');
            const result = write === undefined ? store.get('files') : write === null ? store.delete('files') : store.put(write, 'files');
            transaction.oncomplete = () => { db.close(); resolve(write === undefined ? result.result || {} : {}); };
            transaction.onerror = transaction.onabort = () => { db.close(); reject(transaction.error); };
        };
    }));
    queue = operation;
    return operation;
}
export const readRegistrationFiles = () => fileOperation();
export const saveRegistrationFiles = (files: DraftFiles) => fileOperation(files);
export function clearRegistrationDraft() {
    try { localStorage.removeItem(KEY); } catch { /* Storage may be disabled. */ }
    return fileOperation(null);
}
