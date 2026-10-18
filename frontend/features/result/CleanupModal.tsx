import Button from '@ablogcms/components/button';
import Checkbox from '@ablogcms/components/checkbox';
import Modal, { ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import { useState } from 'react';

interface Props {
  isOpen: boolean;
  /** 旧キーを消す対象（変換済みのフィールド）の件数 */
  count: number;
  onClose: () => void;
  onConfirm: () => void;
}

export function CleanupModal({ isOpen, count, onClose, onConfirm }: Props) {
  const [migrated, setMigrated] = useState(false);
  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        setMigrated(false);
        onClose();
      }}
      size="small"
    >
      <ModalHeader>旧データのクリーンアップ</ModalHeader>
      <ModalBody>
        <p>
          {count} 件のフィールドの旧キー（<code>{'{fd}@path'}</code>{' '}
          など）を削除します。新しいフィールド（メディア）が無いものは、データを失わないよう削除しません。
        </p>
        <p>
          削除後は、旧テンプレートでは表示できなくなります。ロールバックすれば、旧キーも含めて元に戻せます。
        </p>
        <Checkbox
          label="テンプレートの移行は完了しています"
          checked={migrated}
          onChange={(e) => setMigrated(e.target.checked)}
        />
        <p className="acms-admin-a2m-muted">archives の元ファイルは削除しません。</p>
      </ModalBody>
      <ModalFooter>
        <Button
          onClick={() => {
            setMigrated(false);
            onClose();
          }}
        >
          キャンセル
        </Button>
        <Button
          variant="primary"
          disabled={!migrated}
          onClick={() => {
            setMigrated(false);
            onConfirm();
          }}
        >
          旧データを削除する
        </Button>
      </ModalFooter>
    </Modal>
  );
}
