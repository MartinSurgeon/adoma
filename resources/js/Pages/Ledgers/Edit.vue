<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LedgerForm from '@/Components/LedgerForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({ ledger: Object });
</script>

<template>
    <Head :title="`Edit ${ledger.name}`" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Edit {{ ledger.name }}</h1>
            <p class="mt-1 text-sm text-gray-600">{{ ledger.kind_label }} · {{ ledger.office }}</p>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <LedgerForm
                :initial="{ name: ledger.name, phone: ledger.phone ?? '' }"
                method="put"
                :action="route('ledgers.update', ledger.id)"
                submit-label="Save changes"
            />
            <p class="mt-4 text-xs text-gray-500">
                The type and office cannot be changed after a ledger is created, so past transactions stay correct.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
