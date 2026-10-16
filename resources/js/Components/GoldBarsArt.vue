<script setup>
import { useId } from 'vue';

/**
 * A stack of gold bars, drawn in code: no picture file, so it is sharp on any screen, tiny, and needs no internet.
 *
 * Each bar is a box narrower at the top than at the bottom, seen from above at the usual isometric angle.
 * Six bars sit in a 3-2-1 pyramid; the painter draws the back ones first so the front ones cover them.
 */
const COS = Math.cos(Math.PI / 6);
const iso = (x, y, z) => [(x - z) * COS, (x + z) * 0.5 - y];

// One bar: length, width and height, and how far the top is set in from the bottom edges.
const L = 150;
const W = 70;
const H = 34;
const S = 12;
const T = 8;

function bar(px, py, pz) {
    const p = (x, y, z) => iso(px + x, py + y, pz + z);

    return {
        top: [p(S, H, T), p(L - S, H, T), p(L - S, H, W - T), p(S, H, W - T)],
        side: [p(0, 0, W), p(L, 0, W), p(L - S, H, W - T), p(S, H, W - T)],
        end: [p(L, 0, 0), p(L, 0, W), p(L - S, H, W - T), p(L - S, H, T)],
        stamp: [p(S + 24, H, T + 13), p(L - S - 24, H, T + 13), p(L - S - 24, H, W - T - 13), p(S + 24, H, W - T - 13)],
        edge: [p(S, H, W - T), p(L - S, H, W - T)],
    };
}

const gap = 6;
const stack = [
    // Bottom row, back to front.
    bar(0, 0, 0),
    bar(0, 0, W + gap),
    bar(0, 0, 2 * (W + gap)),
    // Middle row, each across two bars below.
    bar(0, H, (W + gap) / 2),
    bar(0, H, (W + gap) * 1.5),
    // The top bar.
    bar(0, 2 * H, W + gap),
];

const points = (list) => list.map(([x, y]) => `${x.toFixed(1)},${y.toFixed(1)}`).join(' ');

// Frame the drawing with room above for the glow and the sparkles.
const all = stack.flatMap((b) => [...b.top, ...b.side, ...b.end]);
const minX = Math.min(...all.map(([x]) => x));
const maxX = Math.max(...all.map(([x]) => x));
const minY = Math.min(...all.map(([, y]) => y));
const maxY = Math.max(...all.map(([, y]) => y));
const cx = (minX + maxX) / 2;
const cy = (minY + maxY) / 2;
const pad = 70;
const viewBox = `${(minX - pad).toFixed(0)} ${(minY - pad - 30).toFixed(0)} ${(maxX - minX + 2 * pad).toFixed(0)} ${(maxY - minY + 2 * pad + 50).toFixed(0)}`;

const sparkles = [
    { x: cx + 120, y: minY - 30, r: 1, delay: '0s' },
    { x: cx - 150, y: minY + 20, r: 0.7, delay: '1.2s' },
    { x: maxX + 20, y: cy + 10, r: 0.55, delay: '2.1s' },
    { x: minX - 10, y: maxY - 40, r: 0.6, delay: '0.6s' },
    { x: cx + 40, y: maxY + 40, r: 0.45, delay: '1.7s' },
];
const star = 'M0,-14 C1.6,-2.4 2.4,-1.6 14,0 C2.4,1.6 1.6,2.4 0,14 C-1.6,2.4 -2.4,1.6 -14,0 C-2.4,-1.6 -1.6,-2.4 0,-14 Z';

// Each drawing names its own colours. Two on one page (the phone banner and the wide panel) must not share names:
// a hidden one would take its colours with it.
const uid = `gb${useId().replace(/[^a-zA-Z0-9_-]/g, '')}`;
const paint = (name) => `url(#${uid}-${name})`;
</script>

<template>
    <svg :viewBox="viewBox" role="img" aria-label="A stack of gold bars" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient :id="`${uid}-glow`" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#fbbf24" stop-opacity="0.38" />
                <stop offset="60%" stop-color="#f59e0b" stop-opacity="0.08" />
                <stop offset="100%" stop-color="#f59e0b" stop-opacity="0" />
            </radialGradient>
            <radialGradient :id="`${uid}-shadow`" cx="50%" cy="50%" r="50%">
                <stop offset="0%" stop-color="#000" stop-opacity="0.45" />
                <stop offset="100%" stop-color="#000" stop-opacity="0" />
            </radialGradient>
            <linearGradient :id="`${uid}-top`" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#fef9c3" />
                <stop offset="45%" stop-color="#fcd34d" />
                <stop offset="100%" stop-color="#f59e0b" />
            </linearGradient>
            <linearGradient :id="`${uid}-side`" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#fbbf24" />
                <stop offset="55%" stop-color="#d97706" />
                <stop offset="100%" stop-color="#a16207" />
            </linearGradient>
            <linearGradient :id="`${uid}-end`" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stop-color="#b45309" />
                <stop offset="100%" stop-color="#78350f" />
            </linearGradient>
        </defs>

        <!-- Warm light behind, a soft shadow and a ring of light under the stack. -->
        <circle :cx="cx" :cy="cy - 10" :r="(maxX - minX) * 0.62" :fill="paint('glow')" />
        <ellipse :cx="cx - 20" :cy="maxY - 26" :rx="(maxX - minX) * 0.55" ry="46" :fill="paint('shadow')" />
        <ellipse :cx="cx - 20" :cy="maxY - 24" :rx="(maxX - minX) * 0.62" ry="60" fill="none" stroke="#fcd34d" stroke-opacity="0.22" stroke-width="1.5" />
        <ellipse :cx="cx - 20" :cy="maxY - 24" :rx="(maxX - minX) * 0.8" ry="84" fill="none" stroke="#ffffff" stroke-opacity="0.07" stroke-width="1.5" />

        <g v-for="(b, i) in stack" :key="i">
            <polygon :points="points(b.end)" :fill="paint('end')" />
            <polygon :points="points(b.side)" :fill="paint('side')" />
            <polygon :points="points(b.top)" :fill="paint('top')" stroke="#fffbeb" stroke-opacity="0.55" stroke-width="1" />
            <polygon :points="points(b.stamp)" fill="#ffffff" fill-opacity="0.1" stroke="#92400e" stroke-opacity="0.35" stroke-width="1.2" />
            <polyline :points="points(b.edge)" fill="none" stroke="#fffbeb" stroke-opacity="0.85" stroke-width="1.6" stroke-linecap="round" />
        </g>

        <path
            v-for="(s, i) in sparkles"
            :key="`s${i}`"
            :d="star"
            class="gold-twinkle"
            :style="{ animationDelay: s.delay }"
            :transform="`translate(${s.x.toFixed(1)} ${s.y.toFixed(1)}) scale(${s.r})`"
            fill="#fde68a"
        />
    </svg>
</template>
