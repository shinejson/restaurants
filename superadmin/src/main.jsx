import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import App from './App';
import { SessionProvider } from './lib/session';
import './styles.css';

// Vite's BASE_URL is the configured build base ("/superadmin/" for root
// installs, "/restaurants/superadmin/" for sub-directory installs), which is
// exactly the router's basename.
const basename = import.meta.env.BASE_URL.replace(/\/+$/, '');

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <BrowserRouter basename={basename}>
      <SessionProvider>
        <App />
      </SessionProvider>
    </BrowserRouter>
  </React.StrictMode>,
);
