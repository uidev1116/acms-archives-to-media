import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { App } from './App';
import { ApiProvider } from './api/context';
import { buildClient } from './bootstrap';

const container = document.getElementById('a2m-root');
if (container !== null) {
  const client = buildClient(document, window as Window & { csrfToken?: string });
  createRoot(container).render(
    <StrictMode>
      <ApiProvider client={client}>
        <App />
      </ApiProvider>
    </StrictMode>,
  );
}
