import { Head } from '@inertiajs/react';
import LuponLayout from '@/layouts/LuponLayout';

type Props = {
    auth?: {
        user: { name?: string };
    };
    barangayName?: string | null;
};

export default function LuponTagapamayapa({ auth, barangayName }: Props) {
    return (
        <LuponLayout
            active="tagapamayapa"
            user={auth?.user}
            barangayName={barangayName}
        >
            <Head title="Lupon Tagapamayapa" />

            <div className="mx-auto max-w-4xl space-y-5">
                <section className="rounded-xl border border-line-soft bg-white p-6 shadow-card">
                    <p className="text-xs font-semibold tracking-[0.16em] text-[#3f6f3d] uppercase">
                        Katarungang Pambarangay
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold text-ink-strong">
                        Lupon Tagapamayapa
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-ink-muted">
                        The Lupon organization directory will be completed in a
                        later implementation batch. Member and official records
                        remain managed through the barangay administration
                        pages.
                    </p>
                </section>
            </div>
        </LuponLayout>
    );
}
