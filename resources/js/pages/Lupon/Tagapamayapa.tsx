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
                <section className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <p className="text-xs font-semibold tracking-[0.16em] text-[#3f6f3d] uppercase">
                        Katarungang Pambarangay
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold text-gray-900">
                        Lupon Tagapamayapa
                    </h1>
                    <p className="mt-2 max-w-2xl text-sm text-gray-500">
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
