<script setup>
import axios from 'axios';
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    type: String, // purchase or sale
    words: Object,
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    baseKarat: Number,
    inStock: { type: String, default: null }, // sales only: grams in stock
});

const form = useForm({
    date: props.today ?? '',
    ledger_id: '',
    form: 'raw',
    weight: '',
    water_weight: '',
    karat: '',
    price: '',
    note: '',
    idempotency_key: props.idempotencyKey ?? '',
});

const isRaw = computed(() => form.form === 'raw');
const isSale = computed(() => props.type === 'sale');

// The figures shown beside the form come from the server's own calculator, so what people see is what is saved.
const preview = ref(null); // { ok, figures?, field?, message?, stock_ok?, in_stock? }
const working = ref(false);
let timer = null;
let latest = 0;

const ready = computed(() => form.weight && form.price && (isRaw.value ? form.water_weight : form.karat));

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
            form: form.form,
            weight: form.weight,
            water_weight: isRaw.value ? form.water_weight : null,
            karat: isRaw.value ? null : form.karat,
            price: form.price,
        });
        if (mine === latest) preview.value = data;
    } catch {
        // No connection or a server error: leave the form usable. Saving checks everything again.
        if (mine === latest) preview.value = null;
    } finally {
        if (mine === latest) working.value = false;
    }
}

watch(() => [form.form, form.weight, form.water_weight, form.karat, form.price], () => {
    clearTimeout(timer);
    timer = setTimeout(calculate, 300);
});

const figures = computed(() => (preview.value?.ok ? preview.value.figures : null));
const problem = (field) => (preview.value && !preview.value.ok && preview.value.field === field ? preview.value.message : '');
const notEnoughStock = computed(() => isSale.value && preview.value?.ok && preview.value.stock_ok === false);
const blocked = computed(() => (preview.value && !preview.value.ok) || notEnoughStock.value);

const submit = () => form.post(route(`${props.type}s.store`));
</script>

<template>
    <Head :title="words.title" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-900">{{ words.title }}</h1>
            <p v-if="!needsOffice" class="mt-1 text-sm text-gray-600">
                {{ office }}<span v-if="isSale && inStock"> · {{ inStock }} g in stock</span>
            </p>
        </template>

        <div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="needsOffice" class="rounded-lg border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-base font-semibold text-amber-900">Choose an office first</h2>
                <p class="mt-1 text-sm text-amber-900">
                    You are looking at all offices. A {{ type }} belongs to one office, so pick it from the office menu at the top of the page, then come back.
                </p>
            </div>

            <form v-else @submit.prevent="submit" class="grid gap-6 pb-24 lg:grid-cols-[1fr_20rem]">
                <div class="space-y-6">
                    <section class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-gray-900">1. Who</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <LedgerPicker
                                    v-model="form.ledger_id"
                                    input-id="party"
                                    :label="words.party"
                                    kinds="customer,supplier"
                                    :error="form.errors.ledger_id"
                                    help="Customers and suppliers in your list."
                                />
                            </div>
                            <div>
                                <InputLabel for="date" value="Date" />
                                <TextInput id="date" type="date" class="mt-1 block w-full" v-model="form.date" :max="today" required />
                                <InputError class="mt-2" :message="form.errors.date" />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-gray-900">2. Gold</h2>

                        <fieldset class="mt-4">
                            <legend class="text-sm font-medium text-gray-700">Form</legend>
                            <div class="mt-1 inline-flex overflow-hidden rounded-md border border-gray-300">
                                <label
                                    v-for="option in [{ value: 'raw', label: 'Raw' }, { value: 'refined', label: 'Refined' }]"
                                    :key="option.value"
                                    class="flex min-h-[44px] cursor-pointer items-center px-5 text-sm font-medium"
                                    :class="form.form === option.value ? 'bg-teal-700 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
                                >
                                    <input type="radio" v-model="form.form" :value="option.value" class="sr-only" />
                                    {{ option.label }}
                                </label>
                            </div>
                            <InputError class="mt-2" :message="form.errors.form" />
                        </fieldset>

                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="weight" value="Weight (g)" />
                                <TextInput id="weight" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.weight" required />
                                <InputError class="mt-2" :message="form.errors.weight || problem('weight')" />
                            </div>

                            <div v-if="isRaw">
                                <InputLabel for="water" value="Water weight (g)" />
                                <TextInput id="water" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.water_weight" />
                                <p class="mt-1 text-xs text-gray-500">The weight of the water the gold pushes aside.</p>
                                <InputError class="mt-2" :message="form.errors.water_weight || problem('water_weight')" />
                            </div>
                            <div v-else>
                                <InputLabel for="karat" value="Karat" />
                                <TextInput id="karat" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="24.00" v-model="form.karat" />
                                <p class="mt-1 text-xs text-gray-500">Refined gold has its karat known, so there is no water weight.</p>
                                <InputError class="mt-2" :message="form.errors.karat || problem('karat')" />
                            </div>
                        </div>
                    </section>

                    <section class="rounded-lg bg-white p-6 shadow-sm">
                        <h2 class="text-base font-semibold text-gray-900">3. Price</h2>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel for="price" :value="`Price per pound at ${baseKarat} karat (GH₵)`" />
                                <TextInput id="price" class="mt-1 block w-full text-right tabular-nums" inputmode="decimal" autocomplete="off" placeholder="0.00" v-model="form.price" required />
                                <InputError class="mt-2" :message="form.errors.price || problem('price')" />
                            </div>
                        </div>
                        <div class="mt-4">
                            <label for="note" class="block text-sm font-medium text-gray-700">Note (optional)</label>
                            <textarea
                                id="note"
                                v-model="form.note"
                                rows="2"
                                maxlength="500"
                                class="mt-1 block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                            ></textarea>
                            <InputError class="mt-2" :message="form.errors.note" />
                        </div>
                    </section>
                </div>

                <!-- What it works out to. Always visible beside the form on large screens. -->
                <aside class="lg:sticky lg:top-24 lg:self-start" aria-live="polite">
                    <div class="rounded-lg bg-teal-50 p-6 ring-1 ring-teal-100">
                        <h2 class="text-base font-semibold text-gray-900">It works out to</h2>

                        <dl v-if="figures" class="mt-4 space-y-3 text-sm">
                            <div class="flex justify-between"><dt class="text-gray-700">Pounds</dt><dd class="font-medium tabular-nums text-gray-900">{{ figures.pounds }}</dd></div>
                            <div v-if="figures.density" class="flex justify-between"><dt class="text-gray-700">Density</dt><dd class="font-medium tabular-nums text-gray-900">{{ figures.density }}</dd></div>
                            <div class="flex justify-between"><dt class="text-gray-700">Karat</dt><dd class="font-medium tabular-nums text-gray-900">{{ figures.karat }}</dd></div>
                            <div class="border-t border-teal-200 pt-3">
                                <dt class="text-gray-700">{{ words.owes }}</dt>
                                <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-900">{{ figures.amount_text }}</dd>
                            </div>
                        </dl>
                        <p v-else-if="preview && !preview.ok" class="mt-4 text-sm text-red-800">{{ preview.message }}</p>
                        <p v-else class="mt-4 text-sm text-gray-700">
                            {{ working ? 'Working it out…' : 'Fill in the weight, ' + (isRaw ? 'water weight' : 'karat') + ' and price to see the amount.' }}
                        </p>

                        <p v-if="notEnoughStock" class="mt-4 rounded-md bg-amber-100 p-3 text-sm text-amber-900">
                            Not enough gold in stock. {{ office }} has {{ preview.in_stock }} g.
                        </p>

                        <p class="mt-4 text-xs text-gray-600">The figures are worked out again when you save.</p>
                    </div>
                </aside>

                <!-- Always in reach, one main button. -->
                <div class="fixed inset-x-0 bottom-[56px] z-20 border-t border-gray-200 bg-white px-4 py-3 lg:bottom-0 lg:left-64">
                    <div class="mx-auto flex max-w-5xl items-center justify-end gap-4">
                        <Link :href="route('dashboard')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                        <PrimaryButton class="min-h-[44px] min-w-[10rem]" :class="{ 'opacity-60': form.processing || blocked }" :disabled="form.processing || blocked">
                            {{ form.processing ? 'Saving…' : words.button }}
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
