// StrictMode import removed - see comment below about LoaderCircle insertBefore error
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
import 'leaflet/dist/leaflet.css'
import './index.css'
import App from './App.tsx'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 menit
      refetchOnWindowFocus: false, // Optional: customize based on needs
    },
  },
})

const rootElement = document.getElementById('root');

if (rootElement) {
  createRoot(rootElement).render(
    // StrictMode disabled temporarily to fix LoaderCircle insertBefore error
    // <StrictMode>
    <QueryClientProvider client={queryClient}>
      <App />
      <ReactQueryDevtools initialIsOpen={false} />
    </QueryClientProvider>
    // </StrictMode>,
  )
} else {
  if (import.meta.env.DEV) {
    console.error('Root element not found');
  }
}
