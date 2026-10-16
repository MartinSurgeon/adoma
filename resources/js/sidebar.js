import { ref, watch } from 'vue';

/**
 * Whether the sidebar is folded to icons, and whether the admin group is open. Each person's choice is kept in
 * this browser, so it is the same on the next page and the next day. If the browser will not keep it (a private
 * window, blocked storage), the sidebar simply starts open, as it would the first time.
 */
const read = (key, fallback) => {
    try {
        const value = window.localStorage.getItem(key);
        return value === null ? fallback : value === '1';
    } catch {
        return fallback;
    }
};

const write = (key, value) => {
    try {
        window.localStorage.setItem(key, value ? '1' : '0');
    } catch {
        // Not kept. It still works for this page.
    }
};

export const sidebarCollapsed = ref(read('adoma.sidebar.collapsed', false));
export const adminGroupOpen = ref(read('adoma.sidebar.admin', false));

// The width the rest of the page makes room for (the sticky form bar uses it too).
const applyWidth = (collapsed) => document.documentElement.style.setProperty('--sidebar-width', collapsed ? '5rem' : '16rem');

applyWidth(sidebarCollapsed.value);

watch(sidebarCollapsed, (collapsed) => {
    write('adoma.sidebar.collapsed', collapsed);
    applyWidth(collapsed);
});

watch(adminGroupOpen, (open) => write('adoma.sidebar.admin', open));
