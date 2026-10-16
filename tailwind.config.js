import defaultTheme from 'tailwindcss/defaultTheme';
import containerQueries from '@tailwindcss/container-queries';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // Inter is bundled with the app (see app.js), so it looks the same with or without the internet.
            fontFamily: {
                sans: ['"Inter Variable"', 'Inter', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    // Container queries (@3xl:, @6xl:) size grids by the room the page really has, which changes when the
    // sidebar is folded, not by the width of the whole window.
    plugins: [forms, containerQueries],
};
