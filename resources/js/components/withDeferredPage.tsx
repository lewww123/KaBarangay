import { Deferred } from '@inertiajs/react';
import type { ComponentType, ReactNode } from 'react';

/** Keep data-dependent hooks and forms unmounted until their props arrive. */
export default function withDeferredPage<Props extends object>(
    Page: ComponentType<Props>,
    data: (keyof Props & string)[],
    fallback: (props: Props) => ReactNode,
) {
    return function DeferredPage(props: Props) {
        return (
            <Deferred data={data} fallback={fallback(props)}>
                <Page {...props} />
            </Deferred>
        );
    };
}
