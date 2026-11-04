import DocxPreview from '@/components/DocxPreview';
export default function CertificateDocumentPreview({
    body,
    onReady,
}: {
    body: string;
    onReady: (ready: boolean) => void;
}) {
    return (
        <DocxPreview
            url="/clerk/certificates/preview"
            method="POST"
            body={body}
            onReady={onReady}
        />
    );
}
