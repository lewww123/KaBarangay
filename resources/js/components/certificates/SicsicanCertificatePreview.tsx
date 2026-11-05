import type { CertificateOfficial } from './BrandedCertificatePreview';

type Props = {
    type: string; name: string; purok: string; purpose: string; years: string;
    businessName: string; businessAddress: string; captain: CertificateOfficial | null;
    reference: string; sex?: string; officerName?: string;
};

const smallNumbers = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
function numberWords(value: string): string {
    const n = Number(value);
    if (!value || !Number.isInteger(n) || n < 0 || n > 120) return '_____';
    if (n < 20) return smallNumbers[n];
    if (n >= 100) return `one hundred${n > 100 ? ` ${numberWords(String(n - 100))}` : ''}`;
    return ['','','twenty','thirty','forty','fifty','sixty','seventy','eighty','ninety'][Math.floor(n / 10)] + (n % 10 ? `-${smallNumbers[n % 10]}` : '');
}

/** DOCX wording and signature blocks inside the approved image design. */
export default function SicsicanCertificatePreview(p: Props) {
    const business = p.type === 'Business Clearance';
    const residency = p.type === 'Barangay Certification';
    const indigency = p.type === 'Sicsican Indigency';
    const variant = business ? 'business' : residency ? 'residency' : indigency ? 'indigency' : 'clearance';
    const asset = (file: string, folder = variant) => `/clerk/certificates/sicsican-reference-artwork?variant=${folder}&asset=${file}`;
    const date = new Date();
    const day = date.getDate();
    const suffix = day % 100 >= 11 && day % 100 <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[day % 10] || 'th');
    const sex = p.sex?.trim().toLowerCase();
    const gender = sex === 'female' ? 'female' : sex === 'male' ? 'male' : '________';
    const possessive = sex === 'female' ? 'her' : sex === 'male' ? 'his' : 'his/her';
    const pronoun = sex === 'female' ? 'She' : sex === 'male' ? 'He' : 'He/She';
    const purok = `Purok ${p.purok.replace(/^purok\s*/i, '')}`;
    const color = business ? '#209552' : indigency ? '#70309a' : residency ? '#173650' : '#20acd1';
    const officer = p.officerName || '___________________';
    const isOic = p.captain?.title === 'Barangay Kagawad, OIC';
    return <>
        <style>{`
            .sicsican-sheet .sicsican-reference-bg{position:absolute;inset:0;width:100%;height:100%}
            .sicsican-docx-body{position:absolute;left:9%;right:10%;top:31%;min-height:37%;padding:3mm 0;background:white;font:13pt 'Times New Roman';line-height:1.2;color:black;overflow-wrap:anywhere}
            .sicsican-docx-body p{margin:0 0 5mm;text-align:justify;text-indent:6mm}
            .sicsican-docx-body .sicsican-salutation{text-indent:0;font-weight:bold}
            .sicsican-docx-body .sicsican-clearance-intro{text-align:center;text-indent:0;margin-bottom:4mm}
            .sicsican-business-fields{text-align:center;font-size:11pt;line-height:1.15;margin-bottom:4mm}
            .sicsican-business-fields strong{display:flex;justify-content:center;align-items:center;min-height:9mm;border:1mm solid #78aa4c;border-radius:2mm;font-size:12pt;padding:1mm;box-sizing:border-box}
            .sicsican-signatures{position:absolute;z-index:2;color:black;left:53%;top:64%;width:36%;height:21%;background:white;text-align:center;font:11pt 'Times New Roman';line-height:1.15;padding:1mm;box-sizing:border-box;overflow-wrap:anywhere}
            .sicsican-signatures strong{font-size:12pt}.sicsican-signatures .authority{margin-top:2.5mm;font-size:10pt}.sicsican-signatures .officer{margin-top:6mm}.sicsican-signatures .acting{margin-top:3mm;font-size:10pt}
            .sicsican-reference-mask{position:absolute;left:8%;top:${indigency ? '67.5%' : '74%'};width:24%;height:${!business && !residency && !indigency ? '6.1%' : '6.5%'};background:white}
            .sicsican-reference-block{position:absolute;top:${indigency ? '68%' : '74.5%'};left:12%;width:20%;height:5%;background:white;text-align:center;font:bold 10pt Arial;color:${color};line-height:1.25}
            .sicsican-reference-block small{display:block;font:italic 6pt Arial;color:black;margin-top:1mm}
            .sicsican-footer{position:absolute;left:32%;right:10%;top:84.5%;height:9%;padding-top:1mm;background:white;text-align:center;font:9pt 'Times New Roman';color:#d02026;line-height:1.15}
            .sicsican-footer strong{display:block;color:${color};font-size:11pt;margin-bottom:1.5mm}
            .sicsican-sheet .sicsican-footer img{position:static;display:inline-block;width:3mm;height:2mm;vertical-align:middle;object-fit:contain}
        `}</style>
        <img className="sicsican-reference-bg" src={asset('approved-design.jpg')} alt="" />
        <section className="sicsican-docx-body" style={indigency ? {top:'34%',minHeight:'34%'} : undefined}>
            {business ? <>
                <p className="sicsican-clearance-intro">This is to certify that the Business of</p>
                <div className="sicsican-business-fields"><strong>{p.name.toUpperCase()}</strong>(Operator/Owner)<br /><strong>{p.businessAddress.toUpperCase()}</strong>(Business Address)<br /><strong>{p.businessName.toUpperCase()}</strong>(Name/Type of Establishment)</div>
                <p style={{textIndent:0}}>situated in this barangay shall not cause public disturbance, shall not exhibit immoral or indecent acts, and shall not constitute health hazard to the residents of this barangay.</p>
                <p>This clearance is issued upon request of the above mentioned in connection with {possessive} Mayor’s Permit application.</p>
            </> : <>
                {(residency || indigency) && <p className="sicsican-salutation">TO WHOM IT MAY CONCERN:</p>}
                {!residency && !indigency ? <>
                    <p className="sicsican-clearance-intro">THIS IS CERTIFY that</p>
                    <p className="sicsican-clearance-intro"><strong>{p.name.toUpperCase()}</strong></p>
                    <p style={{textIndent:0}}>of legal age, {gender}, is a resident of <strong>{purok}</strong>, Barangay Sicsican, Puerto Princesa City.</p>
                    <p>{pronoun} is known to be a person of good moral character and a law-abiding citizen of the community.</p>
                    <p>This clearance is being issued upon the request of the above-mentioned person as a requirement for <strong>{p.purpose || '___________________'}</strong>.</p>
                </> : <>
                    <p>THIS IS TO CERTIFY that <strong>{p.name.toUpperCase()}</strong>, of legal age, {gender}, is a resident of <strong>{purok}, this Barangay.</strong></p>
                    <p>{residency ? <>This certifies further that {possessive} family has been residing in this barangay for {numberWords(p.years)} ({p.years || '___'}) years now.</> : <>This certifies further that {possessive} family belongs to the indigent families of this barangay.</>}</p>
                    <p>{residency ? 'This certification is being issued upon request of above mentioned as a proof of residency.' : <>This certificate is issued upon request of the above-mentioned person as a requirement for <strong>{p.purpose || '___________________'}</strong>.</>}</p>
                </>}
            </>}
            <p>Given this <strong>{day}<sup>{suffix}</sup> day of {date.toLocaleDateString('en-US', {month:'long',year:'numeric'})}</strong> at the {indigency ? 'Office' : 'office'} of the Punong Barangay, National Highway, Purok Banaba, Barangay Sicsican, Puerto Princesa City.</p>
        </section>
        <div className="sicsican-signatures" style={business ? {top:'68%',height:'17%'} : undefined}>
            <strong>{p.captain?.name.toUpperCase() || '________________________'}</strong><br />{p.captain?.title || 'Punong Barangay'}
            {!isOic && <>
                <div className="authority">By Authority of the Punong Barangay:</div>
                <div className="officer"><strong>{officer.toUpperCase()}</strong><br />Sangguniang Barangay Member{!business && <><br />Officer of the Day</>}</div>
            </>}
        </div>
        <div className="sicsican-reference-mask" />
        <div className="sicsican-reference-block">Reference #<br />{p.reference}<small>Note: This document is not valid if with<br />erasure or alteration.</small></div>
        <footer className="sicsican-footer"><strong>“To God Be the Glory”</strong>The LORD is good, a refuge in times of trouble. He cares for those who trust in Him.<br />Nahum {residency || (!business && !indigency) ? '1:77' : '1:7'} (NIV) <img src={asset('footer-logo.png','residency')} alt="Barangay footer logo" /></footer>
    </>;
}
