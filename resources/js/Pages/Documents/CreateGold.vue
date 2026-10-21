<script setup>
import axios from 'axios';
import { computed, onMounted, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Banner from '@/Components/Banner.vue';
import FormSection from '@/Components/FormSection.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import SegmentedControl from '@/Components/SegmentedControl.vue';
import TextInput from '@/Components/TextInput.vue';
import TransactionLayout from '@/Components/TransactionLayout.vue';
import { cedis, grams, longDay } from '@/format';

const props = defineProps({
    type: String, // purchase or sale
    words: Object,
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    baseKarat: Number,
    inStock: { type: String, default: null }, // sales only: grams in stock
    prefill: { type: Object, default: null }, // "record another like this"
    lots: { type: Array, default: () => [] }, // sales only: the purchases still in stock, to choose from
});

const form = useForm({
    date: props.today ?? '',
    ledger_id: props.prefill?.ledger?.id ?? '',
    form: props.prefill?.form ?? 'raw',
    weight: '',
    big_weight: '',
    water_weight: '',
    karat: '',
    price: props.prefill?.price ?? '',
    note: props.prefill?.note ?? '',
    karat_override: false,
    override_reason: '',
    choose_lots: false,
    lots: props.lots.map((lot) => ({ id: lot.id, weight: '' })),
    idempotency_key: props.idempotencyKey ?? '',
});

// Only the purchases with a weight typed go to the server.
const chosenLots = () => form.lots.filter((lot) => lot.weight !== '' && Number(lot.weight) > 0);
form.transform((data) => ({ ...data, lots: data.choose_lots ? chosenLots() : [] }));

// Starting values are not "typed in", so they do not count as unsaved work.
form.defaults();

onMounted(() => {
    // The person comes first, so the cursor starts there (unless it is already chosen).
    if (!form.ledger_id) document.getElementById('party')?.focus();
});

const isRaw = computed(() => form.form === 'raw');
const isSale = computed(() => props.type === 'sale');

// The figures come from the server's own calculator, so what people see is what is saved.
const preview = ref(null); // { ok, figures?, field?, message?, stock_ok?, in_stock? }
const working = ref(false);
let timer = null;
let latest = 0;

const choosing = computed(() => isSale.value && form.choose_lots);
const ready = computed(() => (choosing.value
    ? form.price && chosenLots().length > 0
    : form.weight && form.price && (isRaw.value ? form.water_weight : form.karat)));

const lotInfo = Object.fromEntries(props.lots.map((lot) => [lot.id, lot]));
const takeAll = (row) => { row.weight = lotInfo[row.id].remaining; };
const pickedGrams = computed(() => chosenLots().reduce((sum, lot) => sum + Math.round(Number(lot.weight) * 100), 0) / 100);
const pickedText = computed(() => pickedGrams.value.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

async function calculate() {
    if (!ready.value) {
        preview.value = null;
        return;
    }

    const mine = ++latest;
    working.value = true;

    try {
        const { data } = await axios.post(route('gold.calculate'), {
            type: props.type,
            choose_lots: choosing.value,
            lots: choosing.value ? chosenLots() : [],
            form: form.form,
            weight: form.weight,
            big_weight: isRaw.value ? form.big_weight : null,
            water_weight: isRaw.value ? form.water_weight : null,
            karat: isRaw.value ? null : form.karat,
            price: form.price,
            karat_override: form.karat_override,
        });
        if (mine === latest) preview.value = data;
    } catch {
        // No connection or a server error: leave the form usable. Saving checks everything again.
        if (mine === latest) preview.value = null;
    } finally {
        if (mine === latest) working.value = false;
    }
}

watch(() => [form.form, form.weight, form.big_weight, form.water_weight, form.karat, form.price, form.karat_override, form.choose_lots, form.lots.map((lot) => lot.weight).join('|')], () => {
    clearTimeout(timer);
    timer = setTimeout(calculate, 300);
});

const figures = computed(() => (preview.value?.ok ? preview.value.figures : null));
const problem = (field) => (preview.value && !preview.value.ok && preview.value.field === field ? preview.value.message : '');
const notEnoughStock = computed(() => isSale.value && preview.value?.ok && preview.value.stock_ok === false);
const blocked = computed(() => (preview.value && !preview.value.ok) || notEnoughStock.value);

// A karat above 24 is normally a mistake in the weights. An admin can allow it, with a reason, for a second opinion.
const showOverride = computed(() => form.karat_override || preview.value?.can_override || figures.value?.above_max_karat);

const dash = (value) => value ?? '—';
const submit = () => form.post(route(`${props.type}s.store`));

// ---- The check before saving: who, what gold, the price, and the amount, last and largest.
const party = ref(props.prefill?.ledger ?? null);

const review = computed(() => {
    const f = figures.value;
    const count = chosenLots().length;
    const gold = choosing.value
        ? `${grams(f?.weight ?? pickedGrams.value)} from ${count} ${count === 1 ? 'purchase' : 'purchases'}`
        : `${grams(f?.weight ?? form.weight)} · ${isRaw.value ? 'raw' : 'refined'}`;

    return {
        title: isSale.value ? 'Save this sale?' : 'Save this purchase?',
        details: [
            { label: props.words.party, value: party.value?.name ?? '—' },
            { label: 'Date', value: longDay(form.date) },
            { label: 'Gold', value: gold },
            ...(f ? [{ label: choosing.value ? 'Average karat' : 'Karat', value: f.karat }] : []),
            ...(f?.paid_karat ? [{ label: 'Karat paid', value: `${f.paid_karat} (less ${f.karat_deduction})` }] : []),
            { label: 'Price per pound', value: cedis(form.price) },
            { label: props.words.owes, value: f?.amount_text ?? '—', strong: true },
        ],
        body: f?.above_max_karat ? `The karat is above 24. You are allowing it because: ${form.override_reason || '(no reason typed yet)'}` : '',
    };
});
</script>

<template>
    <TransactionLayout
        :title="words.title"
        :submit-label="words.button"
        :processing="form.processing"
        :blocked="blocked"
        :needs-office="needsOffice"
        :noun="type"
        :dirty="form.isDirty"
        :errors="form.errors"
        :review="review"
        @submit="submit"
    >
        <template #subtitle>
            {{ office }}<span v-if="isSale && inStock"> · {{ inStock }} g in stock</span>
        </template>

        <!-- The person first. -->
        <FormSection title="1. Who">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <LedgerPicker
                        v-model="form.ledger_id"
                        input-id="party"
                        :label="words.party"
                        kinds="customer,supplier"
                        :error="form.errors.ledger_id"
                        :initial="prefill?.ledger"
                        help="Customers and suppliers in your list."
                        @chosen="party = $event"
                    />
                </div>
                <div>
                    <InputLabel for="date" value="Date" />
                    <TextInput id="date" type="date" class="mt-1 block w-full" v-model="form.date" :max="today" required />
                    <InputError class="mt-2" :message="form.errors.date" />
                </div>
                <div class="sm:col-span-3">
                    <InputLabel for="note" value="Note (optional)" />
                    <TextInput id="note" class="mt-1 block w-full" v-model="form.note" maxlength="500" />
                    <InputError class="mt-2" :message="form.errors.note" />
                </div>
            </div>
        </FormSection>

        <!-- What was weighed, with the figures it works out to right beside the boxes that drive them. -->
        <FormSection title="2. Gold">
            <!-- A sale takes the oldest gold first, unless the seller picks the exact purchases. -->
            <fieldset v-if="isSale && lots.length" class="mb-4">
                <legend class="text-base font-medium text-gray-800">Which gold is being sold?</legend>
                <SegmentedControl
                    v-model="form.choose_lots"
                    name="choose_lots"
                    :options="[{ value: false, label: 'The oldest gold first' }, { value: true, label: 'Choose the purchases' }]"
                />
            </fieldset>

            <!-- The exact purchases being sold, and how much of each. What is not chosen stays in stock. -->
            <div v-if="choosing">
                <p class="text-base text-gray-700">Type how many grams to sell from each purchase, or press All. A purchase can be sold in part, and what you leave stays in stock.</p>
                <!-- One row per purchase: what it is on the left, how much to sell on the right. In a narrow space the
                     "Sell" box moves under the details instead of off the edge of the screen. -->
                <ul class="mt-3 divide-y divide-gray-200 overflow-hidden rounded-xl ring-1 ring-gray-200">
                    <li
                        v-for="row in form.lots"
                        :key="row.id"
                        class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3"
                        :class="{ 'bg-teal-50/60': row.weight !== '' && Number(row.weight) > 0 }"
                    >
                        <div class="min-w-0 flex-1 basis-60">
                            <p class="text-base text-gray-900">
                                <span class="whitespace-nowrap font-medium">{{ lotInfo[row.id].number ?? '—' }}</span>
                                <span v-if="lotInfo[row.id].from" class="text-gray-700"> · {{ lotInfo[row.id].from }}</span>
                            </p>
                            <p class="text-sm text-gray-700">
                                Bought {{ lotInfo[row.id].date }} · {{ lotInfo[row.id].form }} · {{ lotInfo[row.id].karat }} karat ·
                                <span class="whitespace-nowrap font-medium text-gray-900">{{ lotInfo[row.id].remaining_text }} g in stock</span>
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <label :for="`lot-${row.id}`" class="text-base font-medium text-gray-800">Sell</label>
                            <TextInput
                                :id="`lot-${row.id}`"
                                class="block w-32 text-right tabular-nums"
                                inputmode="decimal"
                                autocomplete="off"
                                placeholder="0.00"
                                :aria-label="`Sell, grams from ${lotInfo[row.id].number ?? 'this purchase'}`"
                                v-model="row.weight"
                            />
                            <span class="text-base text-gray-700" aria-hidden="true">g</span>
                            <button
                                type="button"
                                class="min-h-12 rounded-lg px-3 text-base font-medium text-teal-800 underline hover:bg-teal-50"
                                :aria-label="`Sell all ${lotInfo[row.id].remaining_text} g of ${lotInfo[row.id].number ?? 'this purchase'}`"
                                @click="takeAll(row)"
                            >
                                All
                            </button>
                        </div>
                    </li>
                </ul>
                <p class="mt-2 text-base font-medium text-gray-900">Chosen: {{ pickedText }} g</p>
                <InputError class="mt-2" :message="form.errors.lots || problem('lots')" />
            </div>

            <fieldset v-if="!choosing">
                <legend class="text-base font-medium text-gray-800">Form</legend>
                <SegmentedControl v-model="form.form" name="form" :options="[{ value: 'raw', label: 'Raw' }, { value: 'refined', label: 'Refined' }]" />
                <InputError class="mt-2" :message="form.errors.form" />
            </fieldset>

            <div v-if="!choosing" class="mt-4 grid gap-4" :class="isRaw ? 'sm:grid-cols-3' : 'sm:grid-cols-2'">
                <div>
                    <InputLabel for="weight" :value="isRaw ? 'All the gold (g)' : 'Weight (g)'" />
                    <TextInput id="weight" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.weight" required />
                    <p v-if="isRaw" class="mt-1 text-sm text-gray-700">Everything on the scale, particles and all. This gives the pounds and the amount.</p>
                    <InputError class="mt-2" :message="form.errors.weight || problem('weight')" />
                </div>

                <div v-if="isRaw">
                    <InputLabel for="big" value="Without particles (g)" />
                    <TextInput id="big" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="Same as all" v-model="form.big_weight" />
                    <p class="mt-1 text-sm text-gray-700">Weigh again after taking out the particles that have no volume. Leave empty if there are none.</p>
                    <InputError class="mt-2" :message="form.errors.big_weight || problem('big_weight')" />
                </div>

                <div v-if="isRaw">
                    <InputLabel for="water" value="Water weight (g)" />
                    <TextInput id="water" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.water_weight" />
                    <p class="mt-1 text-sm text-gray-700">The weight of the water the gold without particles pushes aside.</p>
                    <InputError class="mt-2" :message="form.errors.water_weight || problem('water_weight')" />
                </div>
                <div v-else>
                    <InputLabel for="karat" value="Karat" />
                    <TextInput id="karat" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="24.00" v-model="form.karat" />
                    <p class="mt-1 text-sm text-gray-700">Refined gold has its karat known, so there is no water weight.</p>
                    <InputError class="mt-2" :message="form.errors.karat || problem('karat')" />
                </div>
            </div>

            <!-- Worked out from the boxes above. Read only, shaded, and right under them. -->
            <div class="mt-4 rounded-xl bg-gray-50 p-4 ring-1 ring-inset ring-gray-200">
                <div class="flex items-center justify-between pb-2 border-b border-gray-200/80">
                    <span class="text-sm font-semibold uppercase tracking-wider text-gray-700">Calculated figures</span>
                    <span v-if="working" class="inline-flex items-center gap-1.5 text-sm font-medium text-teal-700">
                        <svg class="h-3.5 w-3.5 animate-spin text-teal-600" viewBox="0 0 24 24" fill="none">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        Working it out…
                    </span>
                    <span v-else-if="figures" class="text-sm font-medium text-green-800">✓ Updated</span>
                </div>
                <dl class="mt-3 grid grid-cols-3 gap-4 sm:grid-cols-5">
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Pounds</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900" :class="{ 'opacity-60': working }">{{ dash(figures?.pounds) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Density</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900" :class="{ 'opacity-60': working }">{{ isRaw && !choosing ? dash(figures?.density) : 'Not used' }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-600">{{ choosing ? 'Average karat' : 'Karat' }}</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900" :class="{ 'opacity-60': working }">{{ dash(figures?.karat) }}</dd>
                    </div>
                    <!-- A purchase is paid at the karat less the business's deduction (0.16), as the client's sheet does. -->
                    <div v-if="!isSale">
                        <dt class="text-sm font-medium text-gray-600">Karat paid<span v-if="figures?.karat_deduction" class="font-normal"> (−{{ figures.karat_deduction }})</span></dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900" :class="{ 'opacity-60': working }">{{ dash(figures?.paid_karat) }}</dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-600">Purity</dt>
                        <dd class="mt-1 text-lg font-semibold tabular-nums text-gray-900" :class="{ 'opacity-60': working }">{{ figures?.purity ? `${figures.purity}%` : '—' }}</dd>
                    </div>
                </dl>
            </div>

            <div v-if="showOverride" class="mt-4 rounded-xl bg-amber-50 p-4 ring-1 ring-inset ring-amber-300">
                <label class="flex min-h-12 cursor-pointer items-center gap-3 text-base font-medium text-gray-900">
                    <input id="karat_override" type="checkbox" v-model="form.karat_override" class="h-6 w-6 rounded border-gray-400 text-teal-700 focus:ring-teal-600" />
                    Allow this karat (above 24)
                </label>
                <div v-if="form.karat_override" class="mt-2">
                    <InputLabel for="override_reason" value="Why is it allowed?" />
                    <TextInput id="override_reason" class="mt-1 block w-full" v-model="form.override_reason" maxlength="300" />
                    <p class="mt-1 text-sm text-gray-700">Kept with the record, with your name, as the second opinion.</p>
                    <InputError class="mt-2" :message="form.errors.override_reason" />
                </div>
            </div>
        </FormSection>

        <!-- The price, and the amount it comes to: last on the form and the largest figure on it. -->
        <FormSection title="3. Price and amount">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <InputLabel for="price" :value="`Price per pound at ${baseKarat} karat (GH₵)`" />
                    <TextInput id="price" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.price" required />
                    <InputError class="mt-2" :message="form.errors.price || problem('price')" />
                </div>
                <div class="card-feature p-5">
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium uppercase tracking-wide text-amber-200">{{ words.owes }}</p>
                        <span v-if="working" class="h-2 w-2 animate-pulse rounded-full bg-amber-300" aria-hidden="true"></span>
                    </div>
                    <p class="mt-1 text-3xl font-bold tabular-nums text-white">{{ figures?.amount_text ?? (working ? 'Working it out…' : '—') }}</p>
                </div>
            </div>
        </FormSection>

        <template #summary>
            <div class="rounded-xl bg-gradient-to-b from-teal-50 to-white p-6 shadow-sm ring-1 ring-inset ring-teal-100">
                <h2 class="text-lg font-semibold text-gray-900">Summary</h2>

                <Banner v-if="notEnoughStock" variant="warning" class="mt-4">
                    Not enough gold in stock. {{ office }} has {{ preview.in_stock }} g.
                </Banner>
                <Banner v-else-if="preview && !preview.ok" variant="error" class="mt-4">{{ preview.message }}</Banner>

                <p v-if="figures" class="mt-4 text-base text-gray-900">
                    <template v-if="isSale">Takes {{ figures.weight }} g out of {{ office }}'s stock. The customer will owe you {{ figures.amount_text }}.</template>
                    <template v-else>Adds {{ figures.weight }} g to {{ office }}'s stock. You will owe the supplier {{ figures.amount_text }}.</template>
                </p>
                <p v-else-if="!preview" class="mt-4 text-base text-gray-700">
                    <template v-if="choosing">Choose how much of each purchase to sell, and the price, to see how this will be recorded.</template>
                    <template v-else>Fill in the weight, {{ isRaw ? 'water weight' : 'karat' }} and price to see how this will be recorded.</template>
                </p>

                <p class="mt-4 text-sm text-gray-700">The server works the figures out again when you save.</p>
            </div>
        </template>
    </TransactionLayout>
</template>
