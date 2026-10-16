<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import BranchForm from '@/Components/BranchForm.vue';

defineProps({
    branch: { type: Object, required: true },
    headOffice: { type: String, default: null },
});
</script>

<template>
    <Head :title="`Edit ${branch.name}`" />

    <AuthenticatedLayout :title="`Edit ${branch.name}`" :back="{ href: route('branches.show', branch.id), label: branch.name }" width="3xl">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
            <BranchForm
                :initial="branch"
                method="put"
                :action="route('branches.update', branch.id)"
                :cancel-href="route('branches.show', branch.id)"
                submit-label="Save changes"
                :head-office="headOffice"
            />
        </div>
    </AuthenticatedLayout>
</template>
