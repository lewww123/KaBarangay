import type { CertificateOfficial } from './BrandedCertificatePreview';

type Props = { type: string; name: string; purok: string; purpose: string; years: string; businessName: string; businessAddress: string; captain: CertificateOfficial | null; reference: string };

/** Wording and artwork from the four approved November 2026 Sicsican references. */
export default function SicsicanCertificatePreview(p: Props) {
    const business = p.type === 'Business Clearance';
    const residency = p.type === 'Barangay Certification';
    const indigency = p.type === 'Sicsican Indigency';
    const variant = business ? 'business' : residency ? 'residency' : indigency ? 'indigency' : 'clearance';
    const asset = (file: string) => `/clerk/certificates/sicsican-reference-artwork?variant=${variant}&asset=${file}`;
    const date = new Date();
    const day = date.getDate();
    const suffix = day % 100 >= 11 && day % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
    const purok = `Purok ${p.purok.replace(/^purok\s*/i, '')}`;
    return <>
        <style>{`.sicsican-sheet .sicsican-reference-bg{position:absolute;inset:0;width:100%;height:100%}.sicsican-reference-header{position:relative;margin:22mm 15mm 0;display:flex;align-items:center;justify-content:space-between;text-align:center;font:12pt 'Times New Roman';line-height:1.3}.sicsican-sheet .sicsican-reference-header img{position:static;width:24mm;height:24mm;object-fit:contain}.sicsican-reference-body{position:relative;margin:14mm 22mm 0;font:13pt 'Times New Roman';line-height:1.5;overflow-wrap:anywhere}.sicsican-reference-body h1{text-align:center;font-size:20pt;margin:0 0 12mm}.sicsican-reference-body p{margin:0 0 6mm;text-align:justify}.sicsican-reference-signature{text-align:center;margin:14mm 0 0 55mm;font:13pt 'Times New Roman'}.sicsican-reference-business{text-align:center;margin-bottom:6mm}.sicsican-reference-business strong{display:block;text-decoration:underline}.sicsican-reference-number{position:absolute;bottom:23mm;left:22mm;font:10pt 'Times New Roman'}`}</style>
        <img className="sicsican-reference-bg" src={asset('image1.png')} alt="" />
        <header className="sicsican-reference-header">
            <img src={asset('image4.png')} alt="Puerto Princesa City seal" />
            <div>Republic of the Philippines<br />Puerto Princesa City<br /><strong>Barangay Sicsican</strong><br /><strong>OFFICE OF THE PUNONG BARANGAY</strong></div>
            <img src={asset('image3.png')} alt="Barangay Sicsican seal" />
        </header>
        <section className="sicsican-reference-body">
            <h1>{business ? 'BUSINESS CLEARANCE' : residency ? 'BARANGAY CERTIFICATION' : indigency ? 'CERTIFICATE OF INDIGENCY' : 'BARANGAY CLEARANCE'}</h1>
            {business ? <>
                <p>This is to certify that the Business of</p>
                <div className="sicsican-reference-business"><strong>{p.name.toUpperCase()}</strong>(Operator/Owner)<br /><strong>{p.businessAddress.toUpperCase()}</strong>(Business Address)<br /><strong>{p.businessName.toUpperCase()}</strong>(Name/Type of Establishment)</div>
                <p>situated in this barangay shall not cause public disturbance, shall not exhibit immoral or indecent acts, and shall not constitute health hazard to the residents of this barangay.</p>
                <p>This clearance is issued upon request of the above mentioned in connection with their Mayor’s Permit application.</p>
            </> : <>
                <p>TO WHOM IT MAY CONCERN:</p>
                <p>THIS IS TO CERTIFY that <strong>{p.name.toUpperCase()}</strong> is a resident of <strong>{purok}</strong>, Barangay Sicsican, Puerto Princesa City.</p>
                <p>{residency ? `This certifies further that their family has been residing in this barangay for ${p.years || '___'} years now.` : indigency ? 'This certifies further that their family belongs to the indigent families of this barangay.' : 'The above-mentioned resident is known to be a person of good moral character and a law-abiding citizen of the community.'}</p>
                <p>This {indigency ? 'certificate' : residency ? 'certification' : 'clearance'} is being issued upon request of the above-mentioned person {residency ? 'as a proof of residency' : <>as a requirement for <strong>{p.purpose || '___________________'}</strong></>}.</p>
            </>}
            <p>Given this {day}{suffix} day of {date.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })} at the office of the Punong Barangay, National Highway, Purok Banaba, Barangay Sicsican, Puerto Princesa City.</p>
            <div className="sicsican-reference-signature"><strong>{p.captain?.name.toUpperCase() || '________________________'}</strong><br />{p.captain?.title || 'Punong Barangay'}</div>
        </section>
        <div className="sicsican-reference-number">Reference: {p.reference}</div>
    </>;
}
