<script setup>
import { nextTick, ref, watch } from 'vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';

const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    label: { type: String, required: true },
    kinds: { type: String, default: '' }, // comma separated, for example "customer,supplier"
    inputId: { type: String, default: 'ledger-picker' },
    error: { type: String, default: '' },
    help: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const results = ref([]);
const open = ref(false);
const active = ref(-1);
const selected = ref(null);
const loading = ref(false);
const failed = ref(false);

let timer = null;
let latest = 0;

async function search() {
    const mine = ++latest;
    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch(route('ledgers.search', { q: query.value, kinds: props.kinds }), {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!response.ok) throw new Error('search failed');
        const data = await response.json();
        if (mine === latest) results.value = data;
    } catch {
        if (mine === latest) {
            results.value = [];
            failed.value = true;
        }
    } finally {
        if (mine === latest) loading.value = false;
    }
}

// Search after a short pause, so the list does not flicker on every key.
watch(query, () => {
    clearTimeout(timer);
    timer = setTimeout(search, 200);
    open.value = true;
    active.value = -1;
});

function show() {
    open.value = true;
    if (!results.value.length && !loading.value) search();
}

function choose(item) {
    selected.value = item;
    emit('update:modelValue', item.id);
    open.value = false;
    query.value = '';
}

function change() {
    selected.value = null;
    emit('update:modelValue', '');
    nextTick(() => document.getElementById(props.inputId)?.focus());
}

function onKey(event) {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        open.value = true;
        active.value = Math.min(active.value + 1, results.value.length - 1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    } else if (event.key === 'Enter' && open.value && active.value >= 0) {
        event.preventDefault(); // choosing a ledger must not submit the form
        choose(results.value[active.value]);
    } else if (event.key === 'Escape') {
        open.value = false;
    }
}

function closeSoon() {
    // Let a click on a result land before the list closes.
    setTimeout(() => (open.value = false), 150);
}
</script>

<template>
    <div>
        <InputLabel :for="inputId" :value="label" />

        <div v-if="selected" class="mt-1 flex items-center justify-between gap-3 rounded-md border border-teal-600 bg-teal-50 p-3">
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-900">{{ selected.name }}</p>
                <p class="text-xs text-gray-700">{{ selected.kind_label }} · {{ selected.balance }}</p>
            </div>
            <button type="button" @click="change" class="min-h-[44px] shrink-0 rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-700 hover:bg-gray-50">
                Change
            </button>
        </div>

        <div v-else class="relative">
            <input
                :id="inputId"
                v-model="query"
                type="text"
                role="combobox"
                autocomplete="off"
                aria-autocomplete="list"
                :aria-expanded="open"
                :aria-controls="`${inputId}-list`"
                placeholder="Start typing a name"
                @focus="show"
                @blur="closeSoon"
                @keydown="onKey"
                class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
            />

            <ul
                v-show="open"
                :id="`${inputId}-list`"
                role="listbox"
                class="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-md border border-gray-200 bg-white py-1 shadow-lg"
            >
                <li v-if="loading" class="px-3 py-2 text-sm text-gray-500">Searching…</li>
                <li v-else-if="failed" class="px-3 py-2 text-sm text-red-700">Could not search. Check your connection and try again.</li>
                <li v-else-if="!results.length" class="px-3 py-2 text-sm text-gray-600">
                    {{ query ? 'No ledger matches that name.' : 'No ledgers yet.' }}
                </li>
                <li
                    v-for="(item, index) in results"
                    :key="item.id"
                    role="option"
                    :aria-selected="index === active"
                    @mousedown.prevent="choose(item)"
                    class="cursor-pointer px-3 py-2"
                    :class="index === active ? 'bg-teal-50' : 'hover:bg-gray-50'"
                >
                    <p class="text-sm font-medium text-gray-900">{{ item.name }}</p>
                    <p class="text-xs text-gray-600">{{ item.kind_label }} · {{ item.balance }}</p>
                </li>
            </ul>
        </div>

        <p v-if="help && !selected" class="mt-1 text-xs text-gray-500">{{ help }}</p>
        <InputError class="mt-2" :message="error" />
    </div>
</template>
