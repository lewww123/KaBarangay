export type SectorType = 'senior' | 'pwd';

export type SectorApplicationStatus =
    | 'pending_staff_review'
    | 'under_admin_review'
    | 'approved'
    | 'rejected'
    | 'needs_correction'
    | 'requires_correction';

export interface SectorApplicantResident {
    id: number;
    resident_number: string;
    full_name: string;
    first_name?: string;
    last_name?: string;
    birthdate?: string | null;
    age?: number | null;
    sex?: string | null;
    purok?: string | null;
    address_line?: string | null;
    mobile?: string | null;
    existing_sectors?: {
        id: number;
        name: string;
        slug: string;
    }[];
}

export interface SectorApplicationItem {
    id: string | number;
    tracking_number: string;
    resident_id: number;
    resident_number: string;
    applicant_name: string;
    purok: string;
    sector: SectorType;
    sector_name: string;
    submission_type: 'staff_assisted' | 'self_service';
    submitted_by_name?: string;
    status: SectorApplicationStatus;
    submitted_at: string;
    reviewed_by_staff_name?: string;
    staff_review_notes?: string;
    staff_checklist_completed?: boolean;
    admin_decision_by_name?: string;
    admin_decision_notes?: string;
    admin_decided_at?: string;
    documents: {
        id: string | number;
        title: string;
        type: string;
        file_name?: string;
        file_size?: string;
        verification_status: 'pending' | 'verified' | 'rejected';
    }[];
}

export interface ResidentDirectoryItem {
    id: number;
    resident_number: string;
    full_name: string;
    sex: string | null;
    age?: number | null;
    birthdate?: string | null;
    purok: string;
    registered_date?: string;
    status: 'active' | 'inactive';
    has_qr: boolean;
    sectors: {
        id: number;
        name: string;
        slug: string;
    }[];
}
