import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import type { Client } from './client';

const ApiContext = createContext<Client | null>(null);

export function ApiProvider({ client, children }: { client: Client; children: ReactNode }) {
  return <ApiContext.Provider value={client}>{children}</ApiContext.Provider>;
}

export function useApi(): Client {
  const client = useContext(ApiContext);
  if (client === null) {
    throw new Error('ApiProvider が必要です。');
  }
  return client;
}
