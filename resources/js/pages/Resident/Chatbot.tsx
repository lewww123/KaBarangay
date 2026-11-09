import { Head, usePage } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';
import BarangayChatbot from '@/components/resident/BarangayChatbot';

export default function Chatbot() {
    const { chatbotStorageKey } = usePage<{
        chatbotStorageKey?: string | null;
    }>().props;
    return (
        <ResidentLayout active="chatbot">
            <Head title="Kabarangay AI" />
            <div className="mx-auto w-full max-w-4xl space-y-4">
                <header>
                    <p className="text-[11px] font-semibold tracking-wider text-brand uppercase">
                        Kabarangay AI
                    </p>
                    <h1 className="mt-1 text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                        Paano kita matutulungan?
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm leading-6 text-ink-muted">
                        Gabay sa certificates, Lupon Tagapamayapa, at ibang
                        serbisyo. Hindi nagsusumite, nag-a-approve, nagbabayad o
                        nag-e-edit ang chat.
                    </p>
                </header>
                <BarangayChatbot storageKey={chatbotStorageKey ?? null} />
            </div>
        </ResidentLayout>
    );
}
