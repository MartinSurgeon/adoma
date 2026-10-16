<template>
    <!-- For a step that undoes something. Only ever inside a confirm dialog (see .btn-danger in app.css). -->
    <button class="btn btn-danger">
        <slot />
    </button>
</template>
