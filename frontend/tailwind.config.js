/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}',
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50: '#eef2ff',
          100: '#e0e7ff',
          500: '#6366f1',
          600: '#4f46e5',
          700: '#4338ca',
          900: '#312e81',
        },
        // Azul marinho profundo e saturado: base visual de toda a aplicação.
        // Os tons escurecem conforme o número cresce, mantendo o azul vivo
        // (sem o aspecto acinzentado/pastel) mesmo nas profundidades.
        navy: {
          600: '#1e3a8a',
          700: '#172d6e',
          800: '#112352',
          900: '#0b1838',
          950: '#060f24',
        },
      },
      keyframes: {
        'fade-in': {
          from: { opacity: '0' },
          to: { opacity: '1' },
        },
        'fade-up': {
          from: { opacity: '0', transform: 'translateY(12px)' },
          to: { opacity: '1', transform: 'translateY(0)' },
        },
        // Brilho que atravessa o bloco da esquerda para a direita, no estilo
        // Netflix: o gradiente começa fora da área e termina do outro lado.
        shimmer: {
          '100%': { transform: 'translateX(100%)' },
        },
      },
      animation: {
        'fade-in': 'fade-in 0.25s ease-out',
        'fade-up': 'fade-up 0.3s ease-out',
        shimmer: 'shimmer 1.6s infinite',
      },
    },
  },
  plugins: [],
}
