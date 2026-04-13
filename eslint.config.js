export default [
    {
        ignores: ['vendor/**', 'node_modules/**', 'eslint.config.js'],
    },
    {
        files: ['**/*.js'],
        languageOptions: {
            ecmaVersion: 2020,
            sourceType: 'script',
            globals: {
                // Browser
                window: 'readonly', document: 'readonly', navigator: 'readonly',
                console: 'readonly', setTimeout: 'readonly', setInterval: 'readonly',
                clearTimeout: 'readonly', clearInterval: 'readonly',
                fetch: 'readonly', URL: 'readonly', URLSearchParams: 'readonly',
                AbortController: 'readonly', FormData: 'readonly',
                HTMLElement: 'readonly', HTMLVideoElement: 'readonly',
                Event: 'readonly', CustomEvent: 'readonly',
                MutationObserver: 'readonly', IntersectionObserver: 'readonly',
                ResizeObserver: 'readonly', MediaSource: 'readonly',
                requestAnimationFrame: 'readonly', cancelAnimationFrame: 'readonly',
                localStorage: 'readonly', sessionStorage: 'readonly',
                location: 'readonly', history: 'readonly', alert: 'readonly',
                confirm: 'readonly', prompt: 'readonly', btoa: 'readonly', atob: 'readonly',
                performance: 'readonly', queueMicrotask: 'readonly',
                screen: 'readonly', Blob: 'readonly', TextEncoder: 'readonly',
                Image: 'readonly', getComputedStyle: 'readonly',
                // Libraries & inline config
                Hls: 'readonly', Chart: 'readonly', bootstrap: 'readonly',
                PLAYER_CONFIG: 'readonly',
            }
        },
        rules: {
            'no-undef': 'warn',
            'no-unused-vars': ['warn', { args: 'none', caughtErrors: 'none' }],
            'no-redeclare': 'error',
            'no-dupe-keys': 'error',
            'no-constant-condition': 'warn',
            'no-unreachable': 'error',
            'no-duplicate-case': 'error',
            'no-empty': ['warn', { allowEmptyCatch: true }],
            'no-self-assign': 'error',
        }
    }
];
