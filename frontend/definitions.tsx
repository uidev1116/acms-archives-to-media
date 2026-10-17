import { createContext, useContext } from 'react';
import type { ReactNode } from 'react';
import type { Definitions } from './types';

const DefinitionsContext = createContext<Definitions | null>(null);

export function DefinitionsProvider({
  value,
  children,
}: {
  value: Definitions;
  children: ReactNode;
}) {
  return <DefinitionsContext.Provider value={value}>{children}</DefinitionsContext.Provider>;
}

export function useDefinitions(): Definitions {
  const value = useContext(DefinitionsContext);
  if (value === null) {
    throw new Error('DefinitionsProvider が必要です。');
  }
  return value;
}
