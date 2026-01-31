// StrictMode import removed - see comment below about LoaderCircle insertBefore error
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { ReactQueryDevtools } from '@tanstack/react-query-devtools'
import 'leaflet/dist/leaflet.css'
import './index.css'
import App from './App.tsx'

console.log('Main.tsx loaded');

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5, // 5 minutes default stale time
      refetchOnWindowFocus: false, // Optional: customize based on needs
    },
  },
})

const rootElement = document.getElementById('root');
console.log('Root element:', rootElement);

if (rootElement) {
  try {
    createRoot(rootElement).render(
      // StrictMode disabled temporarily to fix LoaderCircle insertBefore error
      // <StrictMode>
      <QueryClientProvider client={queryClient}>
        <App />
        <ReactQueryDevtools initialIsOpen={false} />
      </QueryClientProvider>
      // </StrictMode>,
    )
    console.log('React app rendered');
  } catch (error) {
    console.error('Error rendering React app:', error);
  }
} else {
  console.error('Root element not found');
}
