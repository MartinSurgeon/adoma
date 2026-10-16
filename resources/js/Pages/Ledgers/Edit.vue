<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LedgerForm from '@/Components/LedgerForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({ ledger: Object });
</script>

<template>
    <Head :title="`Edit ${ledger.name}`" />

    <AuthenticatedLayout
        :title="`Edit ${ledger.name}`"
        :subtitle="`${ledger.kind_label} · ${ledger.office}`"
        :back="{ href: route('ledgers.index'), label: 'Ledgers' }"
        width="3xl"
    >
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <LedgerForm
                :initial="{ name: ledger.name, phone: ledger.phone ?? '' }"
                method="put"
                :action="route('ledgers.update', ledger.id)"
                submit-label="Save changes"
            />
            <p class="mt-4 text-base text-gray-700">
                The type and branch cannot be changed after a ledger is created, so past transactions stay correct.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
