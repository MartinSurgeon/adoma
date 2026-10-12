<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import LedgerForm from '@/Components/LedgerForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps({
    kinds: Array,
    canShare: Boolean,
    chooseOffice: Boolean,
    activeOffice: String,
    branches: Array,
});
</script>

<template>
    <Head title="Add a ledger" />

    <AuthenticatedLayout>
        <template #header>
            <h1 class="text-xl font-semibold leading-tight text-gray-800">Add a ledger</h1>
            <p class="mt-1 text-sm text-gray-600">A ledger is an account for a person, bank or cost you record transactions against.</p>
        </template>

        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <LedgerForm
                :kinds="kinds"
                :can-share="canShare"
                :choose-office="chooseOffice"
                :active-office="activeOffice"
                :branches="branches"
                method="post"
                :action="route('ledgers.store')"
                submit-label="Add ledger"
            />
        </div>
    </AuthenticatedLayout>
</template>
