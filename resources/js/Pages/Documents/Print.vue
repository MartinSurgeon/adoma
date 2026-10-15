<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    document: Object,
    gold: { type: Object, default: null },
    party_balance: String,
    layout: String, // a4 or thermal
    business: String,
});

const thermal = computed(() => props.layout === 'thermal');
const title = computed(() => ({ payment: 'Payment voucher', purchase: 'Purchase slip', sale: 'Sales receipt' })[props.document.type] ?? 'Receipt');
const partyLabel = computed(() => ({ receipt: 'Received from', payment: 'Paid to', purchase: 'Bought from', sale: 'Sold to' })[props.document.type] ?? 'With');
const rows = computed(() => {
    const list = [
        ['Number', props.document.number],
        ['Date', day(props.document.date)],
        ['Branch', props.document.office],
        [partyLabel.value, props.document.party?.name],
    ];

    if (props.document.cash) {
        list.push([props.document.type === 'receipt' ? 'Paid into' : 'Paid from', props.document.cash.name]);
    }

    if (props.gold) {
        list.push(['Form', props.gold.form === 'raw' ? 'Raw' : 'Refined'], ['Weight', `${props.gold.weight} g`]);
        if (props.gold.water_weight) list.push(['Water weight', `${props.gold.water_weight} g`]);
        list.push(['Pounds', props.gold.pounds]);
        if (props.gold.density) list.push(['Density', props.gold.density]);
        list.push(['Karat', props.gold.karat], [`Price per pound at ${props.gold.base_karat} karat`, props.gold.price]);
    }

    return list;
});
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <Head :title="`${title} ${document.number}`" />

    <!-- The page asks the printer for the right paper size. -->
    <component :is="'style'">{{ thermal ? '@page { size: 80mm auto; margin: 4mm; }' : '@page { size: A4; margin: 15mm; }' }}</component>

    <div class="min-h-screen bg-gray-100 p-4 print:bg-white print:p-0">
        <div class="mx-auto mb-4 flex max-w-[210mm] items-center justify-between print:hidden">
            <button type="button" onclick="history.length > 1 ? history.back() : window.close()" class="min-h-[44px] rounded-md border border-gray-300 bg-white px-4 text-sm text-gray-700">
                Back
            </button>
            <button type="button" onclick="window.print()" class="min-h-[44px] rounded-md bg-teal-700 px-5 text-sm font-semibold text-white hover:bg-teal-800">
                Print
            </button>
        </div>

        <article
            class="mx-auto bg-white text-black shadow print:shadow-none"
            :class="thermal ? 'w-[80mm] p-3 text-[12px] leading-snug' : 'max-w-[210mm] p-12 text-[14px] leading-relaxed'"
        >
            <header class="text-center">
                <p :class="thermal ? 'text-base font-bold' : 'text-2xl font-bold'">{{ business }}</p>
                <p :class="thermal ? 'mt-1 font-semibold uppercase' : 'mt-1 text-lg font-semibold uppercase tracking-wide'">{{ title }}</p>
                <p v-if="document.reverses" class="mt-1 font-semibold">REVERSAL OF {{ document.reverses.number }}</p>
                <p v-if="document.status === 'reversed'" class="mt-1 font-semibold">REVERSED BY {{ document.reversed_by?.number }}</p>
            </header>

            <dl class="mt-4 space-y-1" :class="thermal ? '' : 'grid grid-cols-[10rem_1fr] gap-y-2 space-y-0'">
                <template v-for="row in rows" :key="row[0]">
                    <div :class="thermal ? 'flex justify-between gap-2' : 'contents'">
                        <dt class="text-gray-700">{{ row[0] }}</dt>
                        <dd class="font-medium" :class="thermal ? 'text-right' : ''">{{ row[1] }}</dd>
                    </div>
                </template>
            </dl>

            <div class="my-4 border-y border-black py-3 text-center">
                <p class="text-gray-700">Amount</p>
                <p :class="thermal ? 'text-xl font-bold' : 'text-3xl font-bold'" class="tabular-nums">{{ document.total }}</p>
                <p class="mt-1 font-medium">{{ document.total_in_words }}</p>
            </div>

            <p v-if="document.note" class="mb-4"><span class="text-gray-700">Note:</span> {{ document.note }}</p>

            <div v-if="!thermal" class="mt-16 grid grid-cols-2 gap-12 text-sm">
                <p class="border-t border-black pt-1">Authorised by</p>
                <p class="border-t border-black pt-1">Received by</p>
            </div>
            <p v-else class="mt-4 text-center">Authorised by: ........................</p>

            <footer class="mt-6 text-center text-xs text-gray-600">
                Recorded by {{ document.recorded_by ?? 'Unknown' }}
            </footer>
        </article>
    </div>
</template>
