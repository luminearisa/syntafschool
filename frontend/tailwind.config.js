/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef7f1',
          100: '#dcefe2',
          200: '#c1e2cf',
          300: '#95cdae',
          400: '#62b187',
          500: '#358f69',
          600: '#187456',
          700: '#155b46',
          800: '#14493a',
          900: '#123c32',
        },
        slate: {
          50: '#f6f8f6',
          100: '#edf1ee',
          200: '#dfe6e0',
          300: '#cbd5ce',
          400: '#829087',
          500: '#5f6e63',
          600: '#53645a',
          700: '#3b4b41',
          800: '#27372e',
          900: '#19271f',
          950: '#0d1711',
        },
      },
      boxShadow: {
        soft: '0 2px 12px rgba(22, 54, 36, 0.05)',
      },
      borderRadius: {
        '2xl': '1.125rem',
      },
    },
  },
  plugins: [],
};
