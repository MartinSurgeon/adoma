<script setup>
import { ref } from 'vue';
import Banner from '@/Components/Banner.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

/**
 * A new or reset account's sign-in details, shown once. The person who made the account reads or copies them
 * to the new user; the temporary password is never shown again.
 */
const props = defineProps({
    credentials: { type: Object, required: true }, // { name, email, password }
});

const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(`Email: ${props.credentials.email}\nTemporary password: ${props.credentials.password}`);
        copied.value = true;
    } catch {
        copied.value = false; // the details are still on screen to read out
    }
}
</script>

<template>
    <Banner variant="warning">
        <p class="font-semibold">Give {{ credentials.name }} these sign-in details now</p>
        <p class="mt-1">The password is shown only this once. {{ credentials.name }} chooses their own at first sign-in.</p>
        <dl class="mt-3 grid gap-x-4 gap-y-1 sm:grid-cols-[auto_1fr]">
            <dt>Email</dt>
            <dd class="break-all font-mono text-gray-900">{{ credentials.email }}</dd>
            <dt>Temporary password</dt>
            <dd class="font-mono text-lg font-semibold tracking-wide text-gray-900">{{ credentials.password }}</dd>
        </dl>
        <SecondaryButton type="button" class="mt-3" @click="copy">{{ copied ? 'Copied' : 'Copy email and password' }}</SecondaryButton>
    </Banner>
</template>
