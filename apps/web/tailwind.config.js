/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        bg:      '#f5f7f3',
        panel:   '#ffffff',
        ink:     '#17231d',
        muted:   '#657267',
        line:    '#dde7df',
        green:   '#0f6b45',
        green2:  '#16a36d',
        mint:    '#e8f6ef',
        blue:    '#2563eb',
        yellow:  '#d99106',
        red:     '#dc2626',
        purple:  '#7c3aed',
        slate:   '#334155',
        sidebar: '#0c3d2c',
      },
      borderRadius: {
        card:  '18px',
        btn:   '12px',
        badge: '999px',
        modal: '22px',
        drawer:'16px',
      },
      boxShadow: {
        card: '0 18px 45px rgba(17,40,29,.10)',
        btn:  '0 8px 18px rgba(15,107,69,.18)',
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Arial', 'sans-serif'],
      },
      fontSize: {
        'kpi': ['24px', { fontWeight: '950' }],
      },
      width: {
        sidebar: '282px',
        drawer:  '460px',
      },
    },
  },
  plugins: [],
}
