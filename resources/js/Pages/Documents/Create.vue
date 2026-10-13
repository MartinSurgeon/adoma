<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import LedgerPicker from '@/Components/LedgerPicker.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';

const props = defineProps({
    type: String, // receipt or payment
    words: Object,
    needsOffice: Boolean,
    office: String,
    today: String,
    idempotencyKey: String,
    cashLedgers: { type: Array, default: () => [] },
});

const form = useForm({
    date: props.today ?? '',
    ledger_id: '',
    cash_ledger_id: props.cashLedgers[0]?.id ?? '',
    amount: '',
    note: '',
    idempotency_key: props.idempotencyKey ?? '',
});

const submit = () => form.post(route(`${props.type}s.store`));
</script>

<template>
    <Head :title="words.title" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-900">{{ words.title }}</h1>
            <p v-if="!needsOffice" class="mt-1 text-sm text-gray-600">{{ office }}</p>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <!-- Recording belongs to one office. -->
            <div v-if="needsOffice" class="rounded-lg border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-base font-semibold text-amber-900">Choose an office first</h2>
                <p class="mt-1 text-sm text-amber-900">
                    You are looking at all offices. A {{ type }} belongs to one office, so pick it from the office menu at the top of the page, then come back.
                </p>
            </div>

            <div v-else-if="!cashLedgers.length" class="rounded-lg border border-amber-300 bg-amber-50 p-6">
                <h2 class="text-base font-semibold text-amber-900">Add a cash or bank account first</h2>
                <p class="mt-1 text-sm text-amber-900">A {{ type }} needs a cash or bank account for the money to go {{ type === 'receipt' ? 'into' : 'out of' }}.</p>
                <Link :href="route('ledgers.create')" class="mt-4 inline-block">
                    <PrimaryButton type="button" class="min-h-[44px]">Add a ledger</PrimaryButton>
                </Link>
            </div>

            <form v-else @submit.prevent="submit" class="space-y-6 pb-24">
                <section class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">1. Who</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <LedgerPicker
                                v-model="form.ledger_id"
                                input-id="party"
                                :label="words.party"
                                kinds="customer,supplier,bank,cash,expense,branch"
                                :error="form.errors.ledger_id"
                                help="Customers, suppliers, banks and other accounts in your list."
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
                    <h2 class="text-base font-semibold text-gray-900">2. Money</h2>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2">
                        <div>
                            <InputLabel for="amount" value="Amount (GH₵)" />
                            <TextInput
                                id="amount"
                                class="mt-1 block w-full text-right tabular-nums"
                                inputmode="decimal"
                                autocomplete="off"
                                placeholder="0.00"
                                v-model="form.amount"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.amount" />
                        </div>
                        <div>
                            <InputLabel for="cash" :value="words.cash" />
                            <select
                                id="cash"
                                v-model="form.cash_ledger_id"
                                class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                            >
                                <option v-for="ledger in cashLedgers" :key="ledger.id" :value="ledger.id">{{ ledger.name }}</option>
                            </select>
                            <InputError class="mt-2" :message="form.errors.cash_ledger_id" />
                        </div>
                    </div>
                </section>

                <section class="rounded-lg bg-white p-6 shadow-sm">
                    <h2 class="text-base font-semibold text-gray-900">3. Note (optional)</h2>
                    <div class="mt-4">
                        <label for="note" class="sr-only">Note</label>
                        <textarea
                            id="note"
                            v-model="form.note"
                            rows="2"
                            maxlength="500"
                            placeholder="What is it for?"
                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-teal-600 focus:ring-teal-600"
                        ></textarea>
                        <InputError class="mt-2" :message="form.errors.note" />
                    </div>
                </section>

                <!-- Always in reach, one main button. -->
                <div class="fixed inset-x-0 bottom-[56px] z-20 border-t border-gray-200 bg-white px-4 py-3 lg:bottom-0 lg:left-64">
                    <div class="mx-auto flex max-w-3xl items-center justify-end gap-4">
                        <Link :href="route('dashboard')" class="text-sm text-gray-600 underline hover:text-gray-900">Cancel</Link>
                        <PrimaryButton class="min-h-[44px] min-w-[10rem]" :class="{ 'opacity-60': form.processing }" :disabled="form.processing">
                            {{ form.processing ? 'Saving…' : words.button }}
                        </PrimaryButton>
                    </div>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
