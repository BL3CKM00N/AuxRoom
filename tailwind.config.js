import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                aux: {
                    bg: '#0a0d12',
                    sidebar: '#0c0f14',
                    card: '#12161d',
                    'card-hover': '#171c24',
                    border: 'rgba(255,255,255,0.07)',
                    text: '#f5f7fa',
                    muted: '#9aa4b2',
                    faint: '#6b7280',
                    accent: '#22c55e',
                    'accent-strong': '#16a34a',
                    'accent-soft': 'rgba(34,197,94,0.12)',
                },
            },
        },
    },

    plugins: [forms],
};
