<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import NavIcon from '@/Components/NavIcon.vue';

/**
 * One branch's gold, for the owner looking at every branch: the total, how it splits into raw and refined (a bar and
 * the two figures), when it last changed, and what is on its way to it. The card opens that branch's lots.
 */
const props = defineProps({
    branch: { type: Object, required: true }, // from BranchStock on the server
});

const updated = computed(() => {
    if (!props.branch.updated_at) return 'no stock yet';
    const at = new Date(props.branch.updated_at);
    const now = new Date();
    const today = at.toDateString() === now.toDateString();
    return `updated ${today
        ? at.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true })
        : at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}`;
});

const barLabel = computed(() =>
    props.branch.raw_share === null ? 'No gold in stock' : `${props.branch.raw_share}% raw, ${100 - props.branch.raw_share}% refined`,
);
</script>

<template>
    <Link :href="branch.href" class="card card-link group flex h-full flex-col p-6">
        <div class="flex items-start justify-between gap-3">
            <h3 class="flex min-w-0 items-center gap-2 text-lg font-semibold text-gray-900">
                <NavIcon name="branch" class="h-5 w-5 shrink-0 text-gray-600" />
                <span class="truncate">{{ branch.name }}</span>
            </h3>
            <span v-if="branch.is_head_office" class="badge badge-amber">Head office</span>
        </div>

        <p class="mt-4 text-3xl font-semibold tabular-nums tracking-tight text-gray-900">
            {{ branch.grams }}<span class="ms-1 text-base font-medium text-gray-600">g</span>
        </p>
        <p class="mt-1 text-sm text-gray-700">Gold in stock · {{ updated }}</p>

        <!-- Raw and refined, as shares of one bar. The figures below say the same in words. -->
        <div class="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full bg-gray-100" role="img" :aria-label="barLabel">
            <template v-if="branch.raw_share !== null">
                <span v-if="branch.raw_share > 0" class="rounded-full bg-teal-800" :style="{ width: `${branch.raw_share}%` }"></span>
                <span v-if="branch.raw_share < 100" class="flex-1 rounded-full bg-amber-400"></span>
            </template>
        </div>
        <dl class="mt-3 grid grid-cols-2 gap-4">
            <div>
                <dt class="flex items-center gap-2 text-sm text-gray-700"><span class="h-2.5 w-2.5 rounded-sm bg-teal-800" aria-hidden="true"></span>Raw</dt>
                <dd class="mt-0.5 text-base font-medium tabular-nums text-gray-900">{{ branch.raw }} g</dd>
            </div>
            <div>
                <dt class="flex items-center gap-2 text-sm text-gray-700"><span class="h-2.5 w-2.5 rounded-sm bg-amber-400" aria-hidden="true"></span>Refined</dt>
                <dd class="mt-0.5 text-base font-medium tabular-nums text-gray-900">{{ branch.refined }} g</dd>
            </div>
        </dl>

        <div class="mt-auto pt-4">
            <div class="border-t border-gray-100 pt-3">
                <span v-if="branch.arriving" class="badge badge-teal">
                    <NavIcon name="truck" class="h-4 w-4" />
                    {{ branch.arriving.count }} arriving · {{ branch.arriving.grams }} g
                </span>
                <p v-else class="text-sm text-gray-700">Nothing arriving</p>
            </div>
        </div>
    </Link>
</template>
