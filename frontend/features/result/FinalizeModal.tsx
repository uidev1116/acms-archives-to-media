import { Alert } from '@ablogcms/components/alert';
import { Button } from '@ablogcms/components/button';
import { Checkbox } from '@ablogcms/components/checkbox';
import { HStack } from '@ablogcms/components/stack';
import { Modal, ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import { useState } from 'react';

interface Props {
  isOpen: boolean;
  /** 確定する（ロールバックできなくなる）変換済みの件数 */
  count: number;
  /** 旧データ（{fd}@path など）のクリーンアップが済んでいない件数 */
  legacyCount?: number;
  onClose: () => void;
  /** @param keepLegacyKeys 旧データを残したまま確定する */
  onConfirm: (keepLegacyKeys: boolean) => void;
}

export function FinalizeModal({ isOpen, count, legacyCount = 0, onClose, onConfirm }: Props) {
  const [understood, setUnderstood] = useState(false);
  const [keepLegacy, setKeepLegacy] = useState(false);
  const reset = () => {
    setUnderstood(false);
    setKeepLegacy(false);
  };
  const close = () => {
    reset();
    onClose();
  };
  return (
    <Modal isOpen={isOpen} onClose={close} size="small">
      <ModalHeader>変換の確定</ModalHeader>
      <ModalBody>
        <p>{count} 件の変換を確定し、ロールバック用の記録を削除します。</p>
        <p>
          確定すると<strong>ロールバックできなくなります</strong>
          （スキップ・エラー分の再実行と旧データのクリーンアップも同様です）。作成したメディアは残ります。
          表示の確認とテンプレートの移行が済んでから行ってください。
        </p>
        <Checkbox
          label="ロールバックできなくなることを理解しました"
          checked={understood}
          onChange={(e) => setUnderstood(e.target.checked)}
        />
        {legacyCount > 0 && (
          <>
            <Alert variant="warning" role="note">
              旧データのクリーンアップが済んでいないフィールドが {legacyCount}{' '}
              件あります。確定するとクリーンアップできなくなるので、先に済ませてください。
            </Alert>
            <Checkbox
              label="旧データを残したまま確定します"
              checked={keepLegacy}
              onChange={(e) => setKeepLegacy(e.target.checked)}
            />
          </>
        )}
      </ModalBody>
      <ModalFooter>
        <HStack spacing="0.5rem" justify="end">
          <Button onClick={close}>キャンセル</Button>
          <Button
            variant="primary"
            disabled={!understood || (legacyCount > 0 && !keepLegacy)}
            onClick={() => {
              const keep = keepLegacy;
              reset();
              onConfirm(keep);
            }}
          >
            確定する
          </Button>
        </HStack>
      </ModalFooter>
    </Modal>
  );
}
