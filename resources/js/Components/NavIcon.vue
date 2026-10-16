<script setup>
/** The app's line icons, 24 x 24, drawn with the current text colour. Decorative: the words beside them carry the meaning. */
defineProps({
    name: { type: String, required: true },
    strokeWidth: { type: [Number, String], default: 1.8 },
});

const paths = {
    home: 'M3 11l9-8 9 8M5 10v10h5v-6h4v6h5V10',
    purchase: 'M12 3v12m0 0l-4-4m4 4l4-4M4 17v3h16v-3',
    sale: 'M12 15V3m0 0L8 7m4-4l4 4M4 17v3h16v-3',
    receipt: 'M12 4v10m0 0l-4-4m4 4l4-4M5 20h14',
    payment: 'M12 20V10m0 0l-4 4m4-4l4 4M5 4h14',
    transactions: 'M5 6h14M5 12h14M5 18h9',
    transfer: 'M4 8h13m0 0l-3-3m3 3l-3 3M20 16H7m0 0l3-3m-3 3l3 3',
    stock: 'M3 9l9-5 9 5v8l-9 5-9-5V9zM3 9l9 5 9-5M12 14v8',
    inbox: 'M4 13l2-8h12l2 8M4 13v6h16v-6M4 13h5a3 3 0 006 0h5',
    reports: 'M4 20V10m6 10V4m6 16v-7m4 7H2',
    ledgers: 'M5 4h12a2 2 0 012 2v14H7a2 2 0 01-2-2V4zM9 8h6M9 12h6',
    users: 'M16 20v-1a4 4 0 00-4-4H8a4 4 0 00-4 4v1M10 11a3 3 0 100-6 3 3 0 000 6zM20 20v-1a3 3 0 00-2-2.8M15 5.2a3 3 0 010 5.6',
    activity: 'M12 7v5l3 2M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    plus: 'M12 5v14M5 12h14',
    menu: 'M4 6h16M4 12h16M4 18h16',
    close: 'M6 6l12 12M18 6L6 18',
    chevronDown: 'M6 9l6 6 6-6',
    chevronLeft: 'M15 6l-6 6 6 6',
    chevronRight: 'M9 6l6 6-6 6',
    branch: 'M4 21V5a2 2 0 012-2h8a2 2 0 012 2v16M16 9h2a2 2 0 012 2v10M3 21h18M8 7h4M8 11h4M8 15h4',
    settings: 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z',
    profile: 'M20 21v-1a5 5 0 00-5-5H9a5 5 0 00-5 5v1M12 11a4 4 0 100-8 4 4 0 000 8z',
    logout: 'M15 3h4a2 2 0 012 2v14a2 2 0 01-2 2h-4M10 17l5-5-5-5M15 12H3',
    back: 'M19 12H5m0 0l6-6m-6 6l6 6',
    truck: 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7 19a2 2 0 100-4 2 2 0 000 4zM17 19a2 2 0 100-4 2 2 0 000 4z',
    bank: 'M3 10l9-6 9 6M5 10v8m4-8v8m6-8v8m4-8v8M3 20h18',
    cash: 'M3 7h18v10H3zM12 15a3 3 0 100-6 3 3 0 000 6zM6 10v4m12-4v4',
    report: 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
    scale: 'M12 4v16M8 20h8M5 8h14M5 8l-3 6a3 3 0 006 0L5 8zM19 8l-3 6a3 3 0 006 0l-3-6z',
    person: 'M12 12a4 4 0 100-8 4 4 0 000 8zM4 21a8 8 0 0116 0',
    book: 'M5 4h11a3 3 0 013 3v13H8a3 3 0 01-3-3V4zM5 17a3 3 0 013-3h11',
    summary: 'M4 5h16M4 10h16M4 15h10M4 20h7',
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="strokeWidth" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path :d="paths[name]" />
    </svg>
</template>
