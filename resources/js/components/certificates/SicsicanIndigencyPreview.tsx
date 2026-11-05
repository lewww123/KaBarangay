type Props = {
    residentName: string;
    purok: string;
    purpose: string;
    controlNumber: string;
    captain?: { name: string; title: string } | null;
    issueDate?: Date;
};

/** Fill the approved Sicsican artwork; the same page is captured for print/PDF. */
export default function SicsicanIndigencyPreview({ residentName, purok, purpose, controlNumber, captain, issueDate = new Date() }: Props) {
    return <>
        <img src="/clerk/certificates/sicsican-indigency-artwork" alt="Barangay Sicsican Certificate of Indigency" />
        <div className="sicsican-value" style={{ left: '40.2%', top: '36.4%', width: '34%', height: '1.5%', padding: 0, background: 'transparent', fontSize: residentName.length > 30 ? '9pt' : '12pt' }}>
            {residentName.trim().toUpperCase()}
        </div>
        <div className="sicsican-value" style={{ left: '16.6%', top: '38.2%', width: '24.8%', height: '1.5%', padding: 0, background: 'transparent', fontSize: '12pt' }}>
            {purok.trim().replace(/^purok\s*/i, '')}
        </div>
        <div className="sicsican-value" style={{ left: '24.5%', top: '48.9%', width: '37%', height: '1.5%', padding: 0, background: 'transparent', fontSize: purpose.length > 42 ? '9pt' : '12pt' }}>
            {purpose.trim()}
        </div>
        <div className="sicsican-value" style={{ left: '26%', top: '52.4%', width: '27%', height: '1.5%', padding: 0, background: 'transparent', fontSize: '12pt' }}>
            {issueDate.getDate()}
        </div>
        <div className="sicsican-value" style={{ left: '60.1%', top: '52.4%', width: '18.3%', height: '1.5%', padding: 0, background: 'transparent', fontSize: '12pt' }}>
            {issueDate.toLocaleDateString('en-US', { month: 'long', year: 'numeric' })}
        </div>
        <div className="sicsican-value" style={{ left: '55%', top: '68.1%', width: '30%', height: '2%', padding: 0 }}>
            {captain?.name.toUpperCase() || '___________________'}
        </div>
        <div className="sicsican-value" style={{ left: '55%', top: '70.1%', width: '30%', height: '2%', padding: 0, fontWeight: 'normal' }}>
            {captain?.title || 'Punong Barangay'}
        </div>
        <div className="sicsican-value" style={{ left: '12.4%', top: '69.8%', width: '28%', height: '1.3%', justifyContent: 'flex-start', textAlign: 'left', padding: 0, fontSize: '11pt', color: '#70309a' }}>
            {controlNumber}
        </div>
    </>;
}
