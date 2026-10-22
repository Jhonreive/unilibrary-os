/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './includes/*.php',
    './script.js',
  ],
  // Preflight (Tailwind's CSS reset) is ON. src/input.css has a small "legacy defaults"
  // block that puts back the browser defaults (paragraph/heading/list spacing, line-height)
  // the old inline styles in includes/header.php still rely on.
  theme: {
    extend: {
      colors: {
        bg: { DEFAULT: '#f6f0e7', soft: '#ede2d2' },
        surface: '#ffffff',
        ink: { DEFAULT: '#2b2118', soft: '#6e5c4c', faint: '#9c8a78' },
        line: '#e4d9c8',
        accent: { DEFAULT: '#8a6f55', hover: '#6b4f38', tint: '#efe6d8', ink: '#ffffff' },
        danger: '#c23b2b',
      },
      fontFamily: {
        display: ['"Hanken Grotesk"', 'sans-serif'],
        sans: ['Inter', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
      },
      borderRadius: {
        sm: '8px',
        md: '14px',
        lg: '22px',
      },
      boxShadow: {
        card: '0 1px 0 rgba(22, 19, 14, 0.04)',
      },
      keyframes: {
        nudge: { '50%': { transform: 'translateY(3px)' } },
        orb: { to: { transform: 'translate3d(-60px, 70px, 0) scale(1.15)' } },
        up: { from: { opacity: '0', transform: 'translateY(18px)' }, to: { opacity: '1', transform: 'none' } },
        pop: { from: { opacity: '0', transform: 'translateY(22px) scale(.95)' }, to: { opacity: '1', transform: 'none' } },
        'vt-out': { to: { opacity: '0' } },
        'vt-in': { from: { opacity: '0', transform: 'translateY(10px)' } },
        fade: { from: { opacity: '0' } },
        'modal-pop': { from: { opacity: '0', transform: 'translateY(24px) scale(.94)' } },
        'sheet-up': { from: { opacity: '0', transform: 'translateY(40px)' } },
        shine: { to: { backgroundPosition: '-220% 0' } },
        grow: { from: { transform: 'scaleX(0)' } },
        sweep: { to: { transform: 'translateX(100%)' } },
        'brand-float': { '50%': { transform: 'translateY(-3px)' } },
        'brand-glint': { '0%': { left: '-80%' }, '35%, 100%': { left: '160%' } },
        'status-ping': {
          '0%': { boxShadow: '0 0 0 0 rgba(47,180,100,.55)' },
          '80%, 100%': { boxShadow: '0 0 0 9px rgba(47,180,100,0)' },
        },
      },
      animation: {
        nudge: 'nudge .7s ease-in-out infinite',
        orb: 'orb 26s ease-in-out infinite alternate',
        up: 'up .65s cubic-bezier(.2,.8,.2,1) both',
        pop: 'pop .6s cubic-bezier(.2,.8,.2,1) both',
        'vt-out': 'vt-out .16s ease both',
        'vt-in': 'vt-in .32s cubic-bezier(.2,.8,.2,1) both',
        fade: 'fade .25s both',
        'modal-pop': 'modal-pop .35s cubic-bezier(.2,.9,.3,1.2) both',
        'sheet-up': 'sheet-up .28s cubic-bezier(.2,.8,.2,1)',
        shine: 'shine 6s linear infinite',
        grow: 'grow 1.1s cubic-bezier(.2,.8,.2,1) .25s both',
        sweep: 'sweep 3.2s ease-in-out 1.4s infinite',
        'brand-float': 'brand-float 4s ease-in-out infinite',
        'brand-glint': 'brand-glint 4.5s ease-in-out 1.2s infinite',
        'status-ping': 'status-ping 2.4s ease-out infinite',
      },
    },
  },
  plugins: [],
};
