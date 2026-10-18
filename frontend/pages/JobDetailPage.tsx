import { useState } from 'react';
import { ItemTable } from '../features/scan/ItemTable';
import { JobPanel } from './JobPanel';

type Tab = 'overview' | 'items' | 'log';

const TABS: { id: Tab; label: string }[] = [
  { id: 'overview', label: '概要' },
  { id: 'items', label: 'アイテム' },
  { id: 'log', label: 'ログ' },
];

/**
 * ジョブ詳細。概要（件数・再開・再実行・ロールバック）、アイテム一覧、ログ（スキップ・エラー）。
 */
export function JobDetailPage({ jobId }: { jobId: number }) {
  const [tab, setTab] = useState<Tab>('overview');
  const [reloadKey, setReloadKey] = useState(0);

  return (
    <section className="acms-admin-a2m-step" aria-labelledby="a2m-job-title">
      <p>
        <a href="#/jobs">過去の実行に戻る</a>
      </p>
      <h2 id="a2m-job-title" className="acms-admin-admin-title2">
        ジョブ #{jobId}
      </h2>

      <div role="tablist" aria-label="ジョブの表示" className="acms-admin-a2m-tabs">
        {TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`a2m-tab-${t.id}`}
            aria-selected={tab === t.id}
            aria-controls={`a2m-panel-${t.id}`}
            className={`acms-admin-a2m-tab${tab === t.id ? ' acms-admin-a2m-tab--active' : ''}`}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>

      <div role="tabpanel" id={`a2m-panel-${tab}`} aria-labelledby={`a2m-tab-${tab}`}>
        {tab === 'overview' && (
          <JobPanel
            jobId={jobId}
            allowDelete
            onChanged={() => setReloadKey((k) => k + 1)}
            onDeleted={() => {
              window.location.hash = '#/jobs';
            }}
          />
        )}
        {tab === 'items' && <ItemTable jobId={jobId} editable={false} reloadKey={reloadKey} />}
        {tab === 'log' && (
          <ItemTable jobId={jobId} editable={false} reloadKey={reloadKey} initialStatus="skipped" />
        )}
      </div>
    </section>
  );
}
