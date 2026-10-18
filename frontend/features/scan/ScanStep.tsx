import Alert from '@ablogcms/components/alert';
import Button from '@ablogcms/components/button';
import Checkbox from '@ablogcms/components/checkbox';
import { HStack } from '@ablogcms/components/stack';
import Modal, { ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import ProgressBar from '@ablogcms/components/progress-bar';
import Spinner from '@ablogcms/components/spinner';
import { useCallback, useEffect, useRef, useState } from 'react';
import { useApi } from '../../api/context';
import { useChunkRunner } from '../../hooks/useChunkRunner';
import type { JobDetail, ScanResult } from '../../types';
import { ItemTable } from './ItemTable';
import { ScanSummary } from './ScanSummary';

interface Props {
  jobId: number;
  onBack: () => void;
  /** 変換対象（待機中）の件数を渡して実行へ進む */
  onProceed: (pending: number) => void;
}

export function ScanStep({ jobId, onBack, onProceed }: Props) {
  const api = useApi();
  const [found, setFound] = useState(0);
  const [detail, setDetail] = useState<JobDetail | null>(null);
  const [confirming, setConfirming] = useState(false);
  const [backedUp, setBackedUp] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const runner = useChunkRunner<ScanResult>(() => api.call<ScanResult>('scan', { jobId }), {
    onResult: (r) => setFound(r.total),
  });

  const loadDetail = useCallback(async () => {
    setDetail(await api.call<JobDetail>('job', { jobId }));
  }, [api, jobId]);

  const started = useRef(false);
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void runner.start();
  }, [runner]);

  useEffect(() => {
    if (runner.finished) void loadDetail();
  }, [runner.finished, loadDetail]);

  const pending = detail?.counts.pending ?? 0;

  return (
    <section className="acms-admin-a2m-step" aria-labelledby="a2m-scan-title">
      <h2 id="a2m-scan-title" className="acms-admin-admin-title2">
        2. スキャンと確認
      </h2>

      {runner.error !== null && (
        <Alert variant="danger" role="alert">
          {runner.error.message}{' '}
          <Button size="small" onClick={() => void runner.start()}>
            再試行
          </Button>
        </Alert>
      )}

      {!runner.finished && runner.error === null && (
        <div className="acms-admin-a2m-progress" role="status">
          <Spinner size="1em" /> スキャン中です…（{found} 件見つかりました）
          <ProgressBar value={null} striped animated />
        </div>
      )}

      {runner.finished && detail !== null && (
        <>
          <p>スキャンが完了しました。{detail.job.total} 件の候補が見つかりました。</p>
          <Alert variant="info" role="note">
            スキャンでは何も書き換えません。新規登録・再利用の見込みは、内容のハッシュで判定しています。
          </Alert>
          <ScanSummary summary={detail.summary} />
          <ItemTable
            jobId={jobId}
            editable
            reloadKey={reloadKey}
            onChanged={() => {
              setReloadKey((k) => k + 1);
              void loadDetail();
            }}
          />
          <div className="acms-admin-a2m-actions">
            <Button onClick={onBack}>設定に戻る</Button>
            <Button variant="primary" disabled={pending === 0} onClick={() => setConfirming(true)}>
              変換の実行へ
            </Button>
          </div>
        </>
      )}

      {runner.finished && detail === null && <Spinner size="1.5em" />}

      <Modal isOpen={confirming} onClose={() => setConfirming(false)} size="small">
        <ModalHeader>変換を開始します</ModalHeader>
        <ModalBody>
          <p>
            {pending}{' '}
            件を変換します。本番サイトでは、ステージングで一通り試してから実行してください。
          </p>
          <p>
            変換後はジョブの画面からロールバックできますが、念のため DB
            のバックアップを取ってください。
          </p>
          <Checkbox
            label="DB のバックアップを取りました"
            checked={backedUp}
            onChange={(e) => setBackedUp(e.target.checked)}
          />
        </ModalBody>
        <ModalFooter>
          <HStack spacing="0.5rem" justify="end">
            <Button onClick={() => setConfirming(false)}>キャンセル</Button>
            <Button
              variant="primary"
              disabled={!backedUp}
              onClick={() => {
                setConfirming(false);
                onProceed(pending);
              }}
            >
              変換を開始する
            </Button>
          </HStack>
        </ModalFooter>
      </Modal>
    </section>
  );
}
