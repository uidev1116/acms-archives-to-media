import { Alert } from '@ablogcms/components/alert';
import { Loading } from './components/Loading';
import { useEffect, useState } from 'react';
import { ApiError } from './api/client';
import { useApi } from './api/context';
import { DefinitionsProvider } from './definitions';
import { JobDetailPage } from './pages/JobDetailPage';
import { JobList } from './pages/JobList';
import { Wizard } from './pages/Wizard';
import { parseRoute } from './route';
import type { Route } from './route';
import type { Definitions } from './types';

function useRoute(): Route {
  const [route, setRoute] = useState<Route>(() => parseRoute(window.location.hash));
  useEffect(() => {
    const onChange = () => setRoute(parseRoute(window.location.hash));
    window.addEventListener('hashchange', onChange);
    return () => window.removeEventListener('hashchange', onChange);
  }, []);
  return route;
}

export function App() {
  const api = useApi();
  const route = useRoute();
  const [definitions, setDefinitions] = useState<Definitions | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let alive = true;
    api
      .call<Definitions>('definitions')
      .then((d) => {
        if (alive) setDefinitions(d);
      })
      .catch((e: unknown) => {
        if (alive) setError(e instanceof ApiError ? e.message : String(e));
      });
    return () => {
      alive = false;
    };
  }, [api]);

  if (error !== null) {
    return (
      <Alert variant="danger" role="alert">
        {error}
      </Alert>
    );
  }
  if (definitions === null) {
    return <Loading />;
  }

  return (
    <DefinitionsProvider value={definitions}>
      <nav className="acms-admin-a2m-nav" aria-label="ArchivesToMedia">
        <a href="#/" aria-current={route.name === 'wizard' ? 'page' : undefined}>
          新しい変換
        </a>
        <a href="#/jobs" aria-current={route.name !== 'wizard' ? 'page' : undefined}>
          過去の実行
        </a>
      </nav>
      {route.name === 'wizard' && <Wizard />}
      {route.name === 'jobs' && <JobList />}
      {route.name === 'job' && <JobDetailPage key={route.id} jobId={route.id} />}
    </DefinitionsProvider>
  );
}
