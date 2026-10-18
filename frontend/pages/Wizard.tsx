import Alert from '@ablogcms/components/alert';
import ButtonV2 from '@ablogcms/components/button-v2';
import { useState } from 'react';
import { ApiError } from '../api/client';
import { useApi } from '../api/context';
import { useDefinitions } from '../definitions';
import { RunStep } from '../features/run/RunStep';
import { ScanStep } from '../features/scan/ScanStep';
import { defaultFormState } from '../features/settings/options';
import type { FormState } from '../features/settings/options';
import { SettingsStep } from '../features/settings/SettingsStep';
import type { Job, JobOptions } from '../types';
import { JobPanel } from './JobPanel';

type Step = 'settings' | 'scan' | 'run' | 'result';

const STEPS: { id: Step; label: string }[] = [
  { id: 'settings', label: '設定' },
  { id: 'scan', label: 'スキャンと確認' },
  { id: 'run', label: '実行' },
  { id: 'result', label: '結果' },
];

function Stepper({ current }: { current: Step }) {
  return (
    <ol className="acms-admin-a2m-stepper" aria-label="手順">
      {STEPS.map((s, i) => (
        <li
          key={s.id}
          aria-current={s.id === current ? 'step' : undefined}
          className={`acms-admin-a2m-stepper-item${s.id === current ? ' acms-admin-a2m-stepper-item--current' : ''}`}
        >
          <span className="acms-admin-a2m-stepper-no">{i + 1}</span> {s.label}
        </li>
      ))}
    </ol>
  );
}

/**
 * ウィザード型の画面（設定 → スキャンと確認 → 実行 → 結果）。
 * 実行の途中でタブを閉じても、サーバー側にジョブが残るので、過去の実行から再開できる。
 */
export function Wizard() {
  const api = useApi();
  const definitions = useDefinitions();
  const [step, setStep] = useState<Step>('settings');
  const [form, setForm] = useState<FormState>(() => defaultFormState(definitions));
  const [jobId, setJobId] = useState<number | null>(null);
  const [pending, setPending] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [scanKey, setScanKey] = useState(0);

  const submitSettings = async (options: JobOptions) => {
    setBusy(true);
    setError(null);
    try {
      if (jobId === null) {
        const res = await api.call<{ job: Job }>('create', { options });
        setJobId(res.job.id);
      } else {
        await api.call('update', { jobId, options });
      }
      setScanKey((k) => k + 1);
      setStep('scan');
    } catch (e) {
      setError(e instanceof ApiError ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  };

  const restart = () => {
    setJobId(null);
    setForm(defaultFormState(definitions));
    setError(null);
    setStep('settings');
  };

  return (
    <div className="acms-admin-a2m-wizard">
      <Stepper current={step} />

      {step === 'settings' && (
        <>
          {error !== null && (
            <Alert variant="danger" role="alert">
              {error}
            </Alert>
          )}
          <SettingsStep
            definitions={definitions}
            value={form}
            onChange={setForm}
            onSubmit={(o) => void submitSettings(o)}
            busy={busy}
          />
        </>
      )}

      {step === 'scan' && jobId !== null && (
        <ScanStep
          key={scanKey}
          jobId={jobId}
          onBack={() => setStep('settings')}
          onProceed={(count) => {
            setPending(count);
            setStep('run');
          }}
        />
      )}

      {step === 'run' && jobId !== null && (
        <RunStep jobId={jobId} total={pending} onFinished={() => setStep('result')} />
      )}

      {step === 'result' && jobId !== null && (
        <section className="acms-admin-a2m-step" aria-labelledby="a2m-result-title">
          <h2 id="a2m-result-title" className="acms-admin-admin-title2">
            4. 結果
          </h2>
          <JobPanel jobId={jobId} />
          <div className="acms-admin-a2m-actions">
            <a className="acms-admin-btn-v2" href="#/jobs">
              過去の実行を見る
            </a>
            <ButtonV2 onClick={restart}>新しい変換を始める</ButtonV2>
          </div>
        </section>
      )}
    </div>
  );
}
