<script setup>
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    modelValue: { type: [Number, String], default: '' },
    label: { type: String, required: true },
    kinds: { type: String, default: '' }, // comma separated, for example "customer,supplier"
    inputId: { type: String, default: 'ledger-picker' },
    error: { type: String, default: '' },
    help: { type: String, default: '' },
    initial: { type: Object, default: null }, // a ledger to start with: { id, name, kind_label, balance }
});
const emit = defineEmits(['update:modelValue']);

const query = ref('');
const results = ref([]);
const open = ref(false);
const active = ref(-1);
const selected = ref(props.initial ?? null);

// Adding a ledger without leaving the form. Offered only for the kinds this list shows and this person may add.
const page = usePage();
const addable = computed(() => {
    const shown = props.kinds ? props.kinds.split(',').map((k) => k.trim()) : null;
    return (page.props.tenancy?.addable_ledger_kinds ?? []).filter((k) => !shown || shown.includes(k.value));
});
const adding = ref(false);
const adder = ref({ kind: '', name: '', phone: '' });
const addErrors = ref({});
const addWorking = ref(false);

function startAdding() {
    adder.value = { kind: addable.value[0]?.value ?? '', name: query.value.trim(), phone: '' };
    addErrors.value = {};
    open.value = false;
    adding.value = true;
}

async function saveNew() {
    addWorking.value = true;
    addErrors.value = {};

    try {
        const { data } = await axios.post(route('ledgers.store'), adder.value, { headers: { Accept: 'application/json' } });
        adding.value = false;
        choose(data);
    } catch (error) {
        const body = error.response?.data;
        addErrors.value = error.response?.status === 422
            ? Object.fromEntries(Object.entries(body.errors ?? {}).map(([field, messages]) => [field, messages[0]]))
            : { name: 'Could not add it. Check your connection and try again.' };
    } finally {
        addWorking.value = false;
    }
}
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

        <div v-if="selected" class="mt-1 flex items-center justify-between gap-3 rounded-xl bg-teal-50/70 p-3.5 shadow-sm ring-1 ring-inset ring-teal-600/40">
            <div class="flex items-center gap-3 min-w-0">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-teal-100 text-teal-800" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-gray-900">{{ selected.name }}</p>
                    <p class="text-sm text-gray-700">{{ selected.kind_label }} · {{ selected.balance }}</p>
                </div>
            </div>
            <button type="button" @click="change" class="btn btn-secondary shrink-0 px-4">
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
                class="mt-1 block min-h-12 w-full rounded-lg border-gray-300 pe-10 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
            />

            <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                <svg v-if="loading" class="h-5 w-5 animate-spin text-teal-600" viewBox="0 0 24 24" fill="none">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                </svg>
                <svg v-else class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd" />
                </svg>
            </div>

            <ul
                v-show="open"
                :id="`${inputId}-list`"
                role="listbox"
                class="absolute z-10 mt-1 max-h-72 w-full overflow-auto rounded-xl bg-white py-1 shadow-xl ring-1 ring-gray-200"
            >
                <li v-if="loading && !results.length" class="flex items-center gap-2 px-3 py-2 text-base text-gray-700">
                    <svg class="h-4 w-4 animate-spin text-teal-600" viewBox="0 0 24 24" fill="none">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                    Searching…
                </li>
                <li v-else-if="failed" class="px-3 py-2 text-sm text-red-700">Could not search. Check your connection and try again.</li>
                <li v-else-if="!results.length" class="px-3 py-2 text-base text-gray-700">
                    {{ query ? 'No ledger matches that name.' : 'No ledgers yet.' }}
                </li>
                <li
                    v-for="(item, index) in results"
                    :key="item.id"
                    role="option"
                    :aria-selected="index === active"
                    @mousedown.prevent="choose(item)"
                    class="cursor-pointer px-3 py-2.5 transition"
                    :class="index === active ? 'bg-teal-50 text-teal-900' : 'hover:bg-gray-50'"
                >
                    <p class="text-base font-medium text-gray-900">{{ item.name }}</p>
                    <p class="text-sm text-gray-700">{{ item.kind_label }} · {{ item.balance }}</p>
                </li>
                <li v-if="addable.length && !loading" role="option" aria-selected="false" class="border-t border-gray-200">
                    <button
                        type="button"
                        class="flex min-h-12 w-full items-center gap-2 px-3 text-left text-base font-medium text-teal-800 hover:bg-teal-50"
                        @mousedown.prevent="startAdding"
                    >
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded-full bg-teal-100 text-teal-800 font-bold text-sm" aria-hidden="true">+</span>
                        {{ query.trim() ? `Add “${query.trim()}” as new` : 'Add a new ledger' }}
                    </button>
                </li>
            </ul>
        </div>

        <p v-if="help && !selected" class="mt-1 text-base text-gray-700">{{ help }}</p>
        <InputError class="mt-2" :message="error" />

        <ConfirmDialog
            :show="adding"
            title="Add a new ledger"
            confirm-label="Add and use it"
            :processing="addWorking"
            @close="adding = false"
            @confirm="saveNew"
        >
            <div v-if="addable.length > 1">
                <InputLabel :for="`${inputId}-kind`" value="Type" />
                <select
                    :id="`${inputId}-kind`"
                    v-model="adder.kind"
                    class="mt-1 block min-h-12 w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-teal-600 focus:ring-teal-600"
                >
                    <option v-for="kind in addable" :key="kind.value" :value="kind.value">{{ kind.label }}</option>
                </select>
                <InputError class="mt-2" :message="addErrors.kind" />
            </div>
            <div>
                <InputLabel :for="`${inputId}-name`" value="Name" />
                <TextInput :id="`${inputId}-name`" v-model="adder.name" class="mt-1 block w-full" autocomplete="off" required />
                <InputError class="mt-2" :message="addErrors.name" />
            </div>
            <div>
                <InputLabel :for="`${inputId}-phone`" value="Phone (optional)" />
                <TextInput :id="`${inputId}-phone`" v-model="adder.phone" type="tel" class="mt-1 block w-full" autocomplete="off" />
                <InputError class="mt-2" :message="addErrors.phone" />
            </div>
        </ConfirmDialog>
    </div>
</template>
