<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    document: Object,
    party_balance: String,
    lines: Array,
    canReverse: Boolean,
    canRecordAnother: Boolean,
    history: { type: Array, default: null },
});

const page = usePage();
const success = computed(() => page.props.flash?.success);
const reversed = computed(() => props.document.status === 'reversed');
const noun = computed(() => props.document.type_label.toLowerCase());

const reverseForm = useForm({ reason: '' });

function reverse() {
    if (confirm(`Reverse ${props.document.number}? This posts the opposite entry. The original stays on record.`)) {
        reverseForm.post(route('documents.reverse', props.document.id), { preserveScroll: true });
    }
}

const when = (iso) =>
    new Date(iso).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
const day = (d) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
</script>

<template>
    <Head :title="document.number" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-xl font-semibold leading-tight text-gray-900">{{ document.type_label }} {{ document.number }}</h1>
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="reversed ? 'bg-gray-200 text-gray-800' : document.reverses ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-800'"
                >
                    {{ reversed ? 'Reversed' : document.reverses ? 'Reversal' : 'Saved' }}
                </span>
            </div>
        </template>

        <div class="mx-auto max-w-3xl space-y-6 px-4 py-8 sm:px-6 lg:px-8">
            <div v-if="success" role="status" class="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ success }}</div>

            <div v-if="reversed" role="note" class="rounded-md border border-gray-300 bg-gray-100 p-3 text-sm text-gray-800">
                This {{ noun }} was reversed by
                <Link :href="route('documents.show', document.reversed_by.id)" class="font-medium text-teal-800 underline">{{ document.reversed_by.number }}</Link>.
                Both stay on record.
            </div>
            <div v-if="document.reverses" role="note" class="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                This reverses
                <Link :href="route('documents.show', document.reverses.id)" class="font-medium underline">{{ document.reverses.number }}</Link>.
            </div>

            <!-- The amount first: it is what people look for. -->
            <section class="rounded-lg bg-white p-6 shadow-sm">
                <p class="text-sm text-gray-600">Amount</p>
                <p class="mt-1 text-3xl font-semibold tabular-nums text-gray-900">{{ document.total }}</p>
                <p class="mt-1 text-sm text-gray-700">{{ document.total_in_words }}</p>

                <dl class="mt-6 grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-gray-600">{{ document.type === 'receipt' ? 'Received from' : 'Paid to' }}</dt>
                        <dd class="mt-0.5 font-medium text-gray-900">
                            <Link v-if="document.party" :href="route('ledgers.statement', document.party.id)" class="text-teal-800 hover:underline">{{ document.party.name }}</Link>
                        </dd>
                        <dd v-if="party_balance" class="text-xs text-gray-600">Now: {{ party_balance }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">{{ document.type === 'receipt' ? 'Paid into' : 'Paid from' }}</dt>
                        <dd class="mt-0.5 font-medium text-gray-900">{{ document.cash?.name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">Date</dt>
                        <dd class="mt-0.5 font-medium text-gray-900">{{ day(document.date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">Office</dt>
                        <dd class="mt-0.5 font-medium text-gray-900">{{ document.office }}</dd>
                    </div>
                    <div v-if="document.note" class="sm:col-span-2">
                        <dt class="text-gray-600">Note</dt>
                        <dd class="mt-0.5 text-gray-900">{{ document.note }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-600">Recorded by</dt>
                        <dd class="mt-0.5 text-gray-900">{{ document.recorded_by ?? 'Unknown' }}</dd>
                    </div>
                </dl>
            </section>

            <!-- One main action, one quieter one. -->
            <section class="flex flex-wrap items-center gap-3">
                <a :href="route('documents.print', { document: document.id, layout: 'a4' })" target="_blank" rel="noopener">
                    <PrimaryButton type="button" class="min-h-[44px]">Print {{ noun }}</PrimaryButton>
                </a>
                <Link
                    v-if="canRecordAnother"
                    :href="route(`${document.type}s.create`)"
                    class="inline-flex min-h-[44px] items-center rounded-md border border-gray-300 px-5 text-sm font-medium text-gray-700 hover:bg-gray-50"
                >
                    Record another {{ noun }}
                </Link>
                <a
                    :href="route('documents.print', { document: document.id, layout: 'thermal' })"
                    target="_blank"
                    rel="noopener"
                    class="text-sm text-teal-800 underline"
                >
                    Print small receipt
                </a>
            </section>

            <!-- Details are one click away, not in the way. -->
            <details class="rounded-lg bg-white p-4 shadow-sm">
                <summary class="min-h-[44px] cursor-pointer text-sm font-medium text-gray-900">Accounting entries</summary>
                <table class="mt-3 min-w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <tr><th class="py-2">Ledger</th><th class="py-2 text-right">Debit</th><th class="py-2 text-right">Credit</th></tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="line in lines" :key="line.id">
                            <td class="py-2 text-gray-900">{{ line.ledger }}</td>
                            <td class="py-2 text-right tabular-nums">{{ line.debit }}</td>
                            <td class="py-2 text-right tabular-nums">{{ line.credit }}</td>
                        </tr>
                    </tbody>
                </table>
            </details>

            <details v-if="history && history.length" class="rounded-lg bg-white p-4 shadow-sm">
                <summary class="min-h-[44px] cursor-pointer text-sm font-medium text-gray-900">History</summary>
                <ul class="mt-3 space-y-2 text-sm">
                    <li v-for="entry in history" :key="entry.id">
                        <span class="font-semibold">{{ entry.actor }}</span> {{ entry.description }}
                        <span class="block text-xs text-gray-500">{{ when(entry.when) }}</span>
                    </li>
                </ul>
            </details>

            <details v-if="canReverse" class="rounded-lg border border-red-200 bg-white p-4 shadow-sm">
                <summary class="min-h-[44px] cursor-pointer text-sm font-medium text-red-800">Made a mistake? Reverse this {{ noun }}</summary>
                <p class="mt-2 text-sm text-gray-700">
                    A saved {{ noun }} cannot be edited. Reversing posts the opposite entry, so the books are put right and the history stays honest. Then you can record it again correctly.
                </p>
                <form @submit.prevent="reverse" class="mt-3">
                    <label for="reason" class="block text-sm font-medium text-gray-700">Why are you reversing it?</label>
                    <input
                        id="reason"
                        v-model="reverseForm.reason"
                        maxlength="200"
                        class="mt-1 block min-h-[44px] w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-red-600 focus:ring-red-600"
                        placeholder="For example: wrong amount entered"
                    />
                    <InputError class="mt-2" :message="reverseForm.errors.reason" />
                    <button
                        type="submit"
                        :disabled="reverseForm.processing"
                        class="mt-3 min-h-[44px] rounded-md bg-red-700 px-5 text-sm font-semibold text-white hover:bg-red-800 disabled:opacity-60"
                    >
                        {{ reverseForm.processing ? 'Reversing…' : `Reverse ${noun}` }}
                    </button>
                </form>
            </details>

            <Link :href="route('documents.index')" class="inline-block text-sm text-teal-800 underline">Back to all transactions</Link>
        </div>
    </AuthenticatedLayout>
</template>
