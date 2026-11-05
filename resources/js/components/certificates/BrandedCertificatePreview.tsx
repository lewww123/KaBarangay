import { createContext, useContext, useEffect, useRef } from 'react';
import { waitForCertificateAssets } from '@/lib/certificateDocument';
import type { ReactNode } from 'react';
import CertificateDocumentPreview from './CertificateDocumentPreview';

export type CertificateBranding = {
    cityLogoPath: string | null;
    barangayLogoPath: string | null;
    watermarkPath: string | null;
    footerGraphicPath: string | null;
};

export type CertificateOfficial = { id: number; name: string; title: string };
export const officialNameKey = (name: string) =>
    name.trim().replace(/\s+/g, ' ').toLowerCase();
const CaptainContext = createContext<CertificateOfficial | null>(null);

type Props = {
    body: string;
    controlNumber?: string;
    punong_barangay?: CertificateOfficial | null;
    kagawads?: CertificateOfficial[];
    liveContent?: ReactNode;
    certificateType?: string;
    branding: CertificateBranding;
    onReady: (ready: boolean) => void;
};

const ARTWORK = {
    top: '/certificate-branding/image001.png',
    overlay: '/certificate-branding/image003.png',
    cityLogo: '/certificate-branding/image002.png',
    barangayLogo: '/certificate-branding/barangay-seal.png',
};

/** Print-ready government certificate shell around the rendered document body. */
export default function BrandedCertificatePreview({
    body,
    onReady,
    liveContent,
    certificateType,
    punong_barangay = null,
    controlNumber = '',
}: Props) {
    const sheetRef = useRef<HTMLElement>(null);
    const hasLiveContent = Boolean(liveContent);
    useEffect(() => {
        if (!hasLiveContent) return;
        let active = true;
        onReady(false);
        void document.fonts.ready
            .then(async () => {
                if (sheetRef.current)
                    await waitForCertificateAssets(sheetRef.current);
                if (active) onReady(true);
            })
            .catch(() => {
                if (active) onReady(false);
            });
        return () => {
            active = false;
        };
    }, [body, certificateType, hasLiveContent, onReady]);
    if (['Business Clearance', 'Barangay Certification', 'Sicsican Indigency', 'Sicsican Clearance'].includes(certificateType || ''))
        return (
            <div className="certificate-preview-frame">
                <style>
                    {
                        ".certificate-preview-frame{--certificate-scale:min(.55,var(--certificate-fit-scale,.55));width:calc(210mm * var(--certificate-scale));height:calc(297mm * var(--certificate-scale));position:relative;margin:0 auto}.certificate-preview-frame .sicsican-sheet{position:absolute;top:0;left:0;width:210mm;height:297mm;transform:scale(var(--certificate-scale));transform-origin:top left;background:white;overflow:hidden}.sicsican-sheet img{position:absolute;inset:0;width:100%;height:100%}.sicsican-value{position:absolute;display:flex;align-items:center;justify-content:center;font:bold 13pt 'Times New Roman';text-align:center;overflow-wrap:anywhere;line-height:1.15;padding:2mm;box-sizing:border-box;background:white;color:black}@media print{.certificate-preview-frame .sicsican-sheet{transform:none}}"
                    }
                </style>
                <article
                    ref={sheetRef}
                    id="certificate-print-sheet"
                    className="certificate-print-sheet sicsican-sheet"
                >
                    {liveContent}
                </article>
            </div>
        );
    const isTricycle = certificateType === 'Tricycle';
    const isStore = certificateType === 'Sari-sari Store';
    const isPermit = isTricycle || isStore;
    return (
        <div className="certificate-preview-frame">
            <article
                ref={sheetRef}
                id="certificate-print-sheet"
                className={`certificate-print-sheet isolate mx-auto overflow-hidden bg-white text-slate-900 shadow-lg ${isStore ? 'tricycle-sheet sari-store-sheet' : isTricycle ? 'tricycle-sheet' : ''}`}
            >
                <style>{`
                .certificate-preview-frame { --certificate-scale: min(0.55, var(--certificate-fit-scale, 0.55)); width: calc(210mm * var(--certificate-scale)); height: calc(297mm * var(--certificate-scale)); margin: 0 auto; position: relative; flex: none; }
                .certificate-print-sheet { position: relative; width: 210mm; height: 297mm; margin: 0 auto; box-sizing: border-box; }
                .certificate-preview-frame .certificate-print-sheet { position: absolute; top: 0; left: 0; margin: 0; transform: scale(var(--certificate-scale)); transform-origin: top left; }
                .certificate-print-sheet, .certificate-print-sheet * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .certificate-shell-art { pointer-events: none; position: absolute; z-index: 0; display: block; }
                /* Keep the original barangay seal embedded in the banner visible. */
                .certificate-shell-top { top: 0; right: 0; left: 0; width: 100%; height: auto; }
                .certificate-shell-overlay { inset: 0; width: 100%; height: 100%; object-fit: contain; object-position: bottom center; opacity: 0.62; }
                .certificate-shell-header { position: absolute; top: 2mm; right: 3mm; left: 3mm; z-index: 10; display: grid; grid-template-columns: 41mm minmax(0, 1fr) 41mm; gap: 1mm; min-height: 41mm; align-items: center; text-align: center; }
                .certificate-shell-logo { display: block; width: 41mm; height: 41mm; object-fit: contain; }
                .certificate-shell-logo-left { justify-self: start; border-radius: 50%; }
                .certificate-shell-logo-right { justify-self: end; mix-blend-mode: multiply; }
                .certificate-shell-heading { align-self: center; padding: 0 1mm; font-family: "Times New Roman", serif; font-size: 14pt; line-height: 1.15; }
                .certificate-shell-heading strong { display: block; }
                .certificate-shell-heading strong:last-child { margin-top: 3mm; font-size: 16pt; font-style: italic; }
                .certificate-shell-content { position: relative; z-index: 10; box-sizing: border-box; width: 100%; height: 100%; padding: 45mm 18mm 32mm; }
                .certificate-shell-content > div { height: 100%; }
                .certificate-shell-content .certificate-docx-wrapper { width: 100% !important; padding: 0 !important; background: transparent !important; }
                .certificate-print-sheet .certificate-docx-wrapper,
                .certificate-print-sheet .certificate-docx-wrapper > section.certificate-docx { width: 100% !important; min-height: 0 !important; margin: 0 !important; padding: 0 !important; box-sizing: border-box; background: transparent !important; box-shadow: none !important; }
                /* Text-only layout: artwork and logo geometry stay unchanged. */
                .certificate-shell-content:has(.certificate-live-copy) { padding: 48mm 16mm 15mm; }
                .certificate-live-copy { font-family: "Times New Roman", Times, serif; font-size: 14pt; line-height: 1.15; color: #000; }
                .certificate-live-copy h1 { margin: 0; text-align: center; font-size: 24pt; font-weight: 700; letter-spacing: 0.3em; }
                .certificate-live-copy .certificate-subtitle { margin: 1mm 0 0; text-align: center; font-size: 14pt; }
                .certificate-live-copy > .certificate-copy { margin-top: 12mm; }
                .certificate-copy p { margin: 0 0 6mm; font-size: inherit; line-height: inherit; }
                .certificate-copy > p.text-justify { text-align: justify; text-indent: 11mm; }
                .certificate-copy .certificate-signature { position: absolute; top: 205mm; right: 14mm; width: 95mm; text-align: center; margin: 0; }
                .certificate-copy .certificate-signature p { margin: 0; font-size: 14pt; font-style: normal; }
                .certificate-copy .certificate-authority { position: absolute; top: 235mm; right: 14mm; width: 85mm; margin: 0; text-align: center; font-size: 11pt; }
                .certificate-copy .certificate-authority > p { margin: 0 0 10mm; font-size: inherit; }
                .certificate-copy .certificate-authority > div { position: static; width: auto; margin: 0; text-align: center; }
                .certificate-copy .certificate-authority > div p { margin: 0; font-size: 11pt; font-style: normal; }
                .certificate-copy .certificate-note { position: absolute; bottom: 7mm; left: 40mm; right: 15mm; margin: 0; font-size: 7pt; text-align: center; font-style: italic; }
                .certificate-copy .certificate-note:last-child { bottom: 3mm; }
                .certificate-oic .certificate-authority { display: none; }
                .certificate-copy .certificate-purpose { margin: 6mm 0; font: inherit; color: #000; }
                .certificate-conditions { list-style: decimal; padding-left: 7mm; margin: 3mm 0; }
                .certificate-conditions li { margin-bottom: 2mm; }
                .certificate-copy .certificate-purpose span { color: inherit; }
                .certificate-copy-long .certificate-copy p { margin-bottom: 3mm; }
                .certificate-copy-long > .certificate-copy { margin-top: 8mm; }
                .certificate-copy-long .certificate-signature, .certificate-copy-long .certificate-authority { position: static; margin: 8mm 0 0 auto; }
                .certificate-copy-long .certificate-authority > p { margin-bottom: 6mm; }
                .certificate-board, .certificate-board-document { position: absolute; top: 244mm; left: 16mm; width: 178mm; z-index: 10; font-family: "Times New Roman", serif; font-size: 8pt; line-height: 1.15; text-align: center; color: #000; }
                .certificate-board-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2mm 3mm; margin-top: 2mm; }
                .certificate-board-grid span { display: block; }
                .certificate-board-document { float: none !important; margin: 0 !important; border-collapse: collapse; table-layout: fixed; }
                .certificate-board-document td { border: 0; padding: 1mm; }
                .certificate-board-document p { margin: 0 !important; font-size: 8pt !important; line-height: 1.15 !important; text-indent: 0 !important; }
                /* Scholarship reference: Times, justified copy, 12.5mm first-line indent. */
                .certificate-scholarship:has(.certificate-live-copy) { padding: 46.5mm 14.4mm 0; }
                .certificate-scholarship .certificate-live-copy { font-size: 14.5pt; line-height: 1.15; }
                .certificate-scholarship .certificate-live-copy h1 { font-size: 26pt; line-height: 1.1; }
                .certificate-scholarship .certificate-subtitle { margin: 1mm 0 0; font-size: 14pt; line-height: 1.2; }
                .certificate-scholarship .certificate-live-copy > .certificate-copy { margin-top: 11.5mm; }
                .certificate-scholarship-copy > p { margin: 0 0 6.2mm; line-height: 1.15; }
                .certificate-scholarship-copy > p.text-justify { text-indent: 13.5mm; text-align: justify; }
                .certificate-scholarship-copy .certificate-indigent-statement { text-align: justify; text-indent: 0; }
                .certificate-scholarship-copy .certificate-signature { top: 206mm; right: 14mm; width: 91mm; }
                .certificate-scholarship-copy .certificate-signature p { font-size: 13pt; line-height: 1.2; }
                .certificate-scholarship-copy .certificate-authority { top: 236mm; right: 14mm; width: 80mm; font-size: 12pt; }
                .certificate-scholarship-copy .certificate-authority > p { margin-bottom: 19mm; }
                .certificate-scholarship-copy .certificate-authority > div p { font-size: 12pt; line-height: 1.2; }
                .certificate-scholarship-copy .certificate-note { bottom: 6.8mm; left: 55mm; right: 14mm; font-size: 8pt; line-height: 1.1; }
                .certificate-scholarship-copy .certificate-note:last-child { bottom: 3.3mm; }
                /* Residency has its own centered identity block and sans-serif body. */
                .certificate-residency:has(.certificate-live-copy) { padding: 51mm 20mm 0; }
                .certificate-residency .certificate-live-copy { font-family: Arial, Helvetica, sans-serif; font-size: 13pt; font-weight: 500; font-style: italic; line-height: 1.4; }
                .certificate-residency .certificate-live-copy h1 { font-family: "Times New Roman", Times, serif; font-size: 28pt; font-style: normal; line-height: 1.1; letter-spacing: .5em; }
                .certificate-residency .certificate-live-copy > .certificate-copy { margin-top: 5.8mm; }
                .certificate-residency-copy > p { margin: 0; }
                .certificate-residency-copy .residency-duration { white-space: nowrap; }
                .certificate-residency-copy .residency-intro { text-align: center; margin-bottom: 5mm; }
                .certificate-residency-copy .residency-name { text-align: center; font-size: 22pt; font-style: normal; line-height: 1.1; }
                .certificate-residency-copy .residency-bona-fide { text-align: center; margin-bottom: 4mm; }
                .certificate-residency-copy .residency-purok, .certificate-residency-copy .residency-address { text-align: center; font-size: 18pt; font-style: normal; line-height: 1.2; }
                .certificate-residency-copy .residency-address { margin-bottom: 5mm; }
                .certificate-residency-copy > p.text-justify { text-align: justify; text-indent: 0; margin-bottom: 5.5mm; line-height: 1.4; }
                .certificate-residency-copy .certificate-purpose { margin: 7mm 0 0; font-size: 12pt; }
                .certificate-residency-copy .certificate-purpose strong { font-size: 14pt; font-weight: 800; }
                .certificate-residency-copy .certificate-signature { top: 205mm; right: 12mm; width: 92mm; font-family: "Times New Roman", Times, serif; font-style: normal; font-weight: 400; }
                .certificate-residency-copy .certificate-signature p { font-size: 13pt; }
                .certificate-residency-copy .certificate-authority { top: 240mm; right: 12mm; width: 88mm; font-family: "Times New Roman", Times, serif; font-style: normal; font-weight: 400; font-size: 12pt; }
                .certificate-residency-copy .certificate-authority > p { margin-bottom: 26mm; }
                .certificate-residency-copy .certificate-authority > div p { font-size: 12pt; }
                .certificate-residency-copy .certificate-note { font-family: "Times New Roman", Times, serif; font-weight: 400; font-size: 8pt; left: 55mm; right: 14mm; }
                .certificate-delayed-psa:has(.certificate-live-copy) { padding: 44mm 5mm 0 15mm; }
                .certificate-delayed-psa .certificate-live-copy { font-family: Calibri, Carlito, Arial, sans-serif; font-size: 12pt; line-height: 1.28; }
                .certificate-delayed-psa .certificate-live-copy h1 { font-family: "Times New Roman", Times, serif; font-size: 26pt; line-height: 1.1; letter-spacing: .4em; transform: translateX(-5mm); }
                .certificate-delayed-psa .certificate-live-copy > .certificate-copy { margin-top: 18mm; }
                .certificate-delayed-psa-copy .psa-period, .certificate-delayed-psa-copy .psa-purpose strong:last-child { white-space: nowrap; }
                .certificate-delayed-psa-copy .psa-control-number { position: absolute; top: 62mm; right: 8mm; min-width: 56mm; padding: 1.5mm 2.5mm; border: .25mm solid #000; box-sizing: border-box; font: bold 10pt "Times New Roman", Times, serif; }
                .certificate-delayed-psa-copy .psa-salutation { font-weight: 700; margin: 0 0 10mm; }
                .certificate-delayed-psa-copy .psa-introduction { margin: 0 0 4mm; text-align: justify; }
                .certificate-delayed-psa-copy .psa-photo-box { position: absolute; top: 91.5mm; right: 15mm; width: 32mm; height: 28mm; border: .25mm solid #000; box-sizing: border-box; }
                .certificate-delayed-psa-copy .psa-person-details { font-weight: 700; }
                .certificate-delayed-psa-copy .psa-person-details p { margin: 0; line-height: 1.28; overflow-wrap: anywhere; }
                .certificate-delayed-psa-copy .psa-person-details p:nth-child(-n+4) { padding-right: 38mm; }
                .certificate-delayed-psa-copy .psa-residency, .certificate-delayed-psa-copy .psa-purpose { margin: 5mm 0 0; text-align: justify; text-indent: 14mm; }
                .certificate-delayed-psa-copy .psa-resident-signature { margin-top: 10mm; }
                .certificate-delayed-psa-copy .psa-resident-signature p { margin: 0; line-height: 1.3; }
                .certificate-delayed-psa-copy .psa-resident-signature p:last-child { font-size: 11.5pt; font-weight: 600; }
                .certificate-delayed-psa-copy .psa-issue-date { margin-top: 5mm; font-size: 11pt; }
                .certificate-delayed-psa-copy .certificate-signature { top: 226mm; right: 12mm; width: 100mm; font-family: "Times New Roman", Times, serif; }
                .certificate-delayed-psa-copy .certificate-signature p { font-size: 13pt; line-height: 1.2; }
                .certificate-delayed-psa-copy .certificate-authority { top: 253mm; right: 12mm; width: 100mm; font-family: "Times New Roman", Times, serif; font-size: 12pt; }
                .certificate-delayed-psa-copy .certificate-authority > p { margin-bottom: 14.5mm; }
                .certificate-delayed-psa-copy .certificate-authority > div p { font-size: 12pt; line-height: 1.2; }
                .certificate-delayed-psa-copy .certificate-note { line-height: 1.1; font-family: "Times New Roman", Times, serif; font-size: 8pt; left: 55mm; right: 14mm; bottom: 6.8mm; }
                .certificate-delayed-psa-copy .certificate-note:last-child { bottom: 3.3mm; }
                .tricycle-sheet .certificate-shell-overlay { inset: auto 0 0; width: 100%; height: auto; opacity: 1; }
                .tricycle-sheet .certificate-shell-header { top: 4mm; left: 12mm; right: 8mm; grid-template-columns: 36mm minmax(0, 1fr) 36mm; min-height: 36mm; }
                .tricycle-sheet .certificate-shell-logo { width: 36mm; height: 36mm; }
                .tricycle-sheet .certificate-shell-logo-left { border-radius: 0; }
                .tricycle-watermark { position: absolute; top: 51mm; left: 3%; width: 94%; height: 213mm; object-fit: fill; z-index: 0; }
                .tricycle-bagong-logo { position: absolute; left: 7mm; bottom: 0; width: 33mm; z-index: 1; }
                .certificate-tricycle:has(.certificate-live-copy) { padding: 50mm 14mm 0; }
                .certificate-tricycle .certificate-live-copy { font-family: Calibri, Carlito, Arial, sans-serif; font-size: 12pt; line-height: 1.25; }
                .certificate-tricycle .certificate-live-copy h1 { font-family: "Times New Roman", Times, serif; font-size: 26pt; line-height: 1.1; letter-spacing: .4em; }
                .certificate-tricycle .certificate-live-copy > .certificate-copy { margin-top: 3mm; }
                .tricycle-identity { text-align: center; }
                .tricycle-identity p { margin: 0; line-height: 1.3; }
                .tricycle-identity .tricycle-owner { margin-top: 4.5mm; font-size: 15pt; }
                .tricycle-identity .tricycle-driver { font-size: 10.5pt; }
                .tricycle-conditions-box { position: absolute; top: 134mm; left: 14mm; right: 14mm; height: 66mm; box-sizing: border-box; border: .25mm solid #000; padding: 2mm 2.5mm; font: 11pt/1.18 "Times New Roman", Times, serif; text-align: justify; }
                .tricycle-conditions-box > p { margin: 0; }
                .tricycle-conditions-box ol { list-style: decimal; margin: 0 0 0 15mm; padding-left: 6mm; }
                .tricycle-conditions-box li { padding-left: 1mm; }
                .tricycle-owner-signature { position: absolute; bottom: 2mm; right: 2.5mm; width: 65mm; text-align: right; }
                .tricycle-owner-signature p { margin: 0; }
                .tricycle-owner-signature p:first-child { font: bold 10pt Calibri, Carlito, Arial, sans-serif; text-align: center; }
                .tricycle-purpose { position: absolute; top: 202mm; left: 14mm; right: 14mm; padding: 1.5mm 2.5mm; background: rgba(0,174,234,.5); font-size: 12pt; }
                .certificate-tricycle-copy .tricycle-issued { position: absolute; top: 213mm; left: 14mm; right: 20mm; margin: 0; font: 11pt/1.3 "Times New Roman", Times, serif; }
                .certificate-tricycle-copy .certificate-signature { top: 230mm; right: 7mm; width: 94mm; font-family: "Times New Roman", Times, serif; }
                .certificate-tricycle-copy .certificate-signature p { font-size: 11pt; line-height: 1.2; }
                .tricycle-payment { position: absolute; top: 247mm; left: 7mm; border: .25mm solid #000; padding: 1.5mm 2.5mm; min-width: 55mm; font: bold 10pt/1.2 Calibri, Carlito, Arial, sans-serif; }
                .tricycle-payment p { margin: 0; }
                .certificate-tricycle-copy .certificate-authority { top: 252mm; right: 8mm; width: 88mm; font-family: "Times New Roman", Times, serif; font-size: 11pt; }
                .certificate-tricycle-copy .certificate-authority > p { margin-bottom: 20mm; }
                .certificate-tricycle-copy .certificate-authority > div p { font-size: 11pt; line-height: 1.2; }
                .certificate-tricycle-copy .certificate-note { font: italic 8pt/1.1 "Times New Roman", Times, serif; bottom: 4mm; left: 55mm; right: 14mm; }
                .certificate-tricycle-copy .certificate-note:last-child { bottom: .5mm; }
                .sari-store-sheet .certificate-shell-top { height: 44mm; }
                .sari-store-sheet .certificate-shell-overlay { height: 44mm; }
                .sari-store-sheet .certificate-shell-content:has(.certificate-live-copy) { padding-top: 51mm; }
                .sari-store-sheet .certificate-live-copy > .certificate-copy { margin-top: 5mm; }
                .certificate-store-copy .tricycle-identity p { line-height: 1.3; }
                .certificate-store-copy .tricycle-identity > p:not(.tricycle-owner):not(.tricycle-driver) strong { font-size: 13pt; }
                .certificate-store-copy .tricycle-identity .tricycle-owner { margin-top: 7mm; font-size: 17.5pt; }
                .certificate-store-copy .tricycle-conditions-box { top: 138mm; left: 12mm; right: 10mm; height: 66mm; }
                .certificate-store-copy .tricycle-purpose { top: 204mm; left: 12mm; right: 10mm; background: #91abd8; border: .25mm solid #000; }
                .certificate-store-copy .tricycle-issued { top: 214mm; left: 14mm; right: 10mm; }
                .certificate-store-copy .certificate-signature { top: 233mm; right: 10mm; }
                .certificate-store-copy .tricycle-payment { top: 231mm; left: 12mm; min-width: 58mm; }
                .certificate-store-copy .certificate-authority { top: 251mm; right: 10mm; }
                .certificate-store-copy .certificate-authority > p { margin-bottom: 16mm; }
                .certificate-store-copy .certificate-note { bottom: 4.5mm; }
                .certificate-store-copy .certificate-note:last-child { bottom: 1mm; }
                .certificate-control-box { position: absolute; z-index: 10; top: 219mm; left: 14mm; padding: 1.5mm 2.5mm; min-width: 55mm; border: .25mm solid #000; box-sizing: border-box; font: bold 10pt/1.2 "Times New Roman", Times, serif; color: #000; }
                .certificate-control-top { top: 62mm; left: auto; right: 8mm; }
                @media print {
                    @page { size: A4; margin: 0; }
                    body * { visibility: hidden; }
                    .certificate-print-sheet, .certificate-print-sheet * { visibility: visible; }
                    .certificate-print-sheet { position: absolute; inset: 0; width: 210mm; height: 297mm; margin: 0; box-shadow: none; }
                    .certificate-preview-frame .certificate-print-sheet { position: absolute; top: 0; left: 0; transform: none; }
                }
                @media (max-height: 960px) {
                    .certificate-preview-frame { --certificate-scale: min(0.5, var(--certificate-fit-scale, 0.5)); }
    
                }
                @media (max-height: 800px) {
                    .certificate-preview-frame { --certificate-scale: min(0.42, var(--certificate-fit-scale, 0.42)); }
    
                }
            `}</style>

                <img
                    src={
                        isStore
                            ? '/certificate-branding/sari-sari/header.svg'
                            : isTricycle
                              ? '/certificate-branding/tricycle/image1.png'
                              : ARTWORK.top
                    }
                    alt=""
                    aria-hidden="true"
                    className="certificate-shell-art certificate-shell-top"
                />
                <img
                    src={
                        isStore
                            ? '/certificate-branding/sari-sari/footer.svg'
                            : isTricycle
                              ? '/certificate-branding/tricycle/image5.png'
                              : ARTWORK.overlay
                    }
                    alt=""
                    aria-hidden="true"
                    className="certificate-shell-art certificate-shell-overlay"
                />

                {isPermit && (
                    <>
                        <img
                            src="/certificate-branding/tricycle/image2.png"
                            alt=""
                            aria-hidden="true"
                            className="tricycle-watermark"
                        />
                        <img
                            src="/certificate-branding/tricycle/image6.png"
                            alt=""
                            aria-hidden="true"
                            className="tricycle-bagong-logo"
                        />
                    </>
                )}
                <header className="certificate-shell-header">
                    <Logo
                        src={
                            isPermit
                                ? '/certificate-branding/tricycle/image3.png'
                                : ARTWORK.barangayLogo
                        }
                        alt="Barangay San Jose seal"
                        className="certificate-shell-logo certificate-shell-logo-left"
                    />
                    <div className="certificate-shell-heading">
                        <div>Republic of the Philippines</div>
                        <div>City of Puerto Princesa</div>
                        <strong>BARANGAY SAN JOSE</strong>
                        <strong>Office of the Punong Barangay</strong>
                    </div>
                    <Logo
                        src={
                            isPermit
                                ? '/certificate-branding/tricycle/image4.png'
                                : ARTWORK.cityLogo
                        }
                        alt="City of Puerto Princesa seal"
                        className="certificate-shell-logo certificate-shell-logo-right"
                    />
                </header>

                <div
                    className={`certificate-shell-content ${certificateType === 'Scholarship Certificate' ? 'certificate-scholarship' : certificateType === 'Residency' ? 'certificate-residency' : certificateType === 'Delayed PSA Registration' ? 'certificate-delayed-psa' : isPermit ? 'certificate-tricycle' : ''}`}
                >
                    {liveContent ? (
                        <div
                            className={`certificate-live-copy ${punong_barangay?.title === 'Barangay Kagawad, OIC' ? 'certificate-oic' : ''}`}
                        >
                            <h1>CERTIFICATION</h1>
                            {['Indigency', 'Scholarship Certificate'].includes(
                                certificateType || '',
                            ) && (
                                <p className="certificate-subtitle">
                                    {certificateType ===
                                    'Scholarship Certificate'
                                        ? 'INDIGENT CERTIFICATE'
                                        : '(Indigent Certificate)'}
                                </p>
                            )}
                            <CaptainContext.Provider value={punong_barangay}>
                                {liveContent}
                            </CaptainContext.Provider>
                        </div>
                    ) : (
                        <CertificateDocumentPreview
                            body={body}
                            onReady={onReady}
                        />
                    )}
                </div>

                {liveContent &&
                    !['Sari-sari Store', 'Tricycle'].includes(
                        certificateType || '',
                    ) && (
                        <div
                            className={`certificate-control-box ${certificateType === 'Marriage Cohabitation' ? 'certificate-control-top' : ''}`}
                        >
                            CONTROL NO.:{' '}
                            <u>{controlNumber || '____________'}</u>
                        </div>
                    )}
            </article>
        </div>
    );
}

function Logo({
    src,
    alt,
    className = 'certificate-shell-logo',
}: {
    src: string;
    alt: string;
    className?: string;
}) {
    return <img src={src} alt={alt} className={className} />;
}

export function CaptainSignature() {
    const official = useContext(CaptainContext);
    return (
        <div className="certificate-signature">
            <p className="font-bold">
                {official?.name.toUpperCase() || '___________________'}
            </p>
            <p>{official?.title || 'Punong Barangay'}</p>
        </div>
    );
}
