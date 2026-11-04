import type { CertificateOfficial } from '../components/certificates/BrandedCertificatePreview';

/** A delegation applies only to the current dropdown selection. */
export function resolveCertificateOfficials(
    incumbent: CertificateOfficial | null,
    members: CertificateOfficial[],
    actingCaptainId = '',
) {
    const selected = members.find(
        (member) => String(member.id) === actingCaptainId,
    );
    const captain = selected
        ? { ...selected, title: 'Barangay Kagawad, OIC' }
        : incumbent;
    const nameKey = (name: string) =>
        name.trim().replace(/\s+/g, ' ').toLowerCase();

    return {
        captain,
        kagawads: members.filter(
            (member) =>
                member.id !== captain?.id &&
                (!captain || nameKey(member.name) !== nameKey(captain.name)),
        ),
    };
}
