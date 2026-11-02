import { Head, usePage } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';
import BarangayChatbot from '@/components/resident/BarangayChatbot';

export default function Chatbot() {
    const { chatbotStorageKey } = usePage<{ chatbotStorageKey?: string | null }>().props;
    return <ResidentLayout active="chatbot"><Head title="Kabarangay AI" /><div className="mx-auto max-w-3xl"><BarangayChatbot storageKey={chatbotStorageKey ?? null} /></div></ResidentLayout>;
}
