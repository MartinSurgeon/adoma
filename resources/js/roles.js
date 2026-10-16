/**
 * The roles, as people read them. Viewer, clerk and manager are given per branch; admin and owner cover every branch.
 * Each has a word and a look, always together, so the colour is never the only sign.
 */
export const ROLE_LABELS = { viewer: 'Viewer', clerk: 'Clerk', manager: 'Manager', admin: 'Admin', owner: 'Owner' };

export const ROLE_BADGES = {
    viewer: 'badge-gray',
    clerk: 'badge-teal',
    manager: 'badge-green',
    admin: 'badge-amber',
    owner: 'badge-amber',
};

export const roleLabel = (role) => ROLE_LABELS[role] ?? role;

/** The choices for a role in one branch, as a ladder from least to most: No access, Viewer, Clerk, Manager. */
export function branchRoleOptions(branchRoles) {
    const ladder = ['viewer', 'clerk', 'manager'].filter((role) => branchRoles.includes(role));
    return [{ value: '', label: 'No access' }, ...ladder.map((role) => ({ value: role, label: roleLabel(role) }))];
}

/** "Owner, every branch" or "Kumasi: Clerk · Central: Viewer", for the questions asked before saving. */
export function accessInWords(tenantRole, offices) {
    if (tenantRole === 'owner') return 'Owner, every branch';
    if (tenantRole === 'admin') return 'Admin, every branch';
    return offices.length ? offices.map((o) => `${o.name}: ${roleLabel(o.role)}`).join(' · ') : 'No branches';
}
