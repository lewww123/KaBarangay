export type OrgChartStatus = 'draft' | 'published' | 'archived';

export type OrgChartNode = {
    id: number;
    title: string;
    assigned_person: string | null;
    parent_id: number | null;
    status: OrgChartStatus;
    has_photo: boolean;
    photo_url: string | null;
    updated_at: string | null;
    children: OrgChartNode[];
};

export type OrgChartPosition = Omit<OrgChartNode, 'children'>;

export type OrgChartStats = {
    total: number;
    draft: number;
    published: number;
    archived: number;
};

export function orgChartStatusBadge(status: OrgChartStatus): {
    label: string;
    classes: string;
} {
    switch (status) {
        case 'published':
            return {
                label: 'Published',
                classes: 'border-emerald-200 bg-emerald-50 text-emerald-700',
            };
        case 'archived':
            return {
                label: 'Archived',
                classes: 'border-gray-200 bg-gray-100 text-gray-600',
            };
        default:
            return {
                label: 'Draft',
                classes: 'border-blue-200 bg-blue-50 text-blue-700',
            };
    }
}

export function findOrgChartNode(
    nodes: OrgChartNode[],
    id: number,
): OrgChartNode | null {
    for (const node of nodes) {
        if (node.id === id) {
            return node;
        }

        const match = findOrgChartNode(node.children, id);

        if (match) {
            return match;
        }
    }

    return null;
}

export function collectDescendantIds(node: OrgChartNode): number[] {
    const ids: number[] = [];

    for (const child of node.children) {
        ids.push(child.id, ...collectDescendantIds(child));
    }

    return ids;
}

export function flattenOrgChart(nodes: OrgChartNode[]): OrgChartNode[] {
    return nodes.flatMap((node) => [node, ...flattenOrgChart(node.children)]);
}

export function formatOrgChartDate(value: string | null): string {
    if (!value) {
        return 'Not recorded';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Not recorded';
    }

    return date.toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

/** Arrange published chart roles without changing the saved parent relationships. */
export function groupOrgChart(tree: OrgChartNode[], actingCaptain?: OrgChartPosition | null) {
    const positions = [...new Map(flattenOrgChart(tree).map((node) => [node.id, node])).values()];
    const isLeader = (position: OrgChartPosition) => /punong\s+barangay|barangay\s+captain|barangay\s+chair(?:man|person)/i.test(position.title);
    const isActing = (position: OrgChartPosition) => /\bacting\b|\boic\b|officer.in.charge/i.test(position.title);
    const leaders = positions.filter(isLeader);
    const captain = actingCaptain
        ?? leaders.find((node) => node.status === 'published' && isActing(node) && Boolean(node.assigned_person?.trim()))
        ?? leaders.find((node) => node.status === 'published' && !isActing(node))
        ?? leaders.find((node) => node.status === 'published')
        ?? leaders.find((node) => node.status !== 'archived')
        ?? leaders[0]
        ?? null;
    const nameKey = (name: string | null) => name?.trim().replace(/\s+/g, ' ').toLowerCase() || '';
    const remaining = positions.filter((node) => !isLeader(node) && node.id !== captain?.id
        && (!captain?.assigned_person || nameKey(node.assigned_person) !== nameKey(captain.assigned_person)));
    const kagawads = remaining.filter((node) => /kagawad|councilor|sangguniang\s+barangay/i.test(node.title));
    const legislativeIds = new Set(kagawads.map((node) => node.id));
    const appointed = remaining.filter((node) => !legislativeIds.has(node.id) && /secretary|treasurer|kalihim|ingat.?yaman/i.test(node.title));
    const appointedIds = new Set(appointed.map((node) => node.id));
    return {
        captain,
        isActing: Boolean(captain?.assigned_person?.trim() && (actingCaptain || isActing(captain))),
        kagawads,
        appointed,
        others: remaining.filter((node) => !legislativeIds.has(node.id) && !appointedIds.has(node.id)),
    };
}