import { createInertiaApp } from '@inertiajs/react'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { StrictMode } from 'react'
import { createRoot, hydrateRoot } from 'react-dom/client'
import './styles/index.css'

const APP_NAME = import.meta.env['VITE_APP_NAME'] ?? 'Breeze AI'

void createInertiaApp({
  title: (title) => (title ? `${title} — ${APP_NAME}` : APP_NAME),

  resolve: (name) =>
    resolvePageComponent(
      `./Pages/${name}.tsx`,
      import.meta.glob('./Pages/**/*.tsx'),
    ),

  setup({ el, App, props }) {
    const app = (
      <StrictMode>
        <a
          href="#main-content"
          className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-200 focus:rounded-panel focus:bg-brand focus:px-4 focus:py-2 focus:text-brand-ink"
        >
          Skip to content
        </a>
        <App {...props} />
      </StrictMode>
    )

    // `data-server-rendered` is set when the page arrives pre-rendered.
    if (el.dataset['serverRendered'] === 'true') {
      hydrateRoot(el, app)
      return
    }

    createRoot(el).render(app)
  },

  progress: {
    color: '#33E3FF',
    showSpinner: false,
  },
})
