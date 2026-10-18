import ButtonV2 from '@ablogcms/components/button-v2';
import Checkbox from '@ablogcms/components/checkbox';
import Modal, { ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import { useState } from 'react';

interface Props {
  isOpen: boolean;
  /** 戻す対象（変換済み）の件数 */
  count: number;
  onClose: () => void;
  onConfirm: (deleteCreatedMedia: boolean) => void;
}

export function RollbackModal({ isOpen, count, onClose, onConfirm }: Props) {
  const [deleteMedia, setDeleteMedia] = useState(false);
  return (
    <Modal isOpen={isOpen} onClose={onClose} size="small">
      <ModalHeader>ロールバックの確認</ModalHeader>
      <ModalBody>
        <p>
          {count} 件を変換前の状態に戻します。変換後に編集された行は戻さず、エラーとして残します。
        </p>
        <Checkbox
          label="このジョブで新規作成したメディアのうち、参照が無いものを削除する"
          checked={deleteMedia}
          onChange={(e) => setDeleteMedia(e.target.checked)}
        />
        <p className="acms-admin-a2m-muted">
          archives の元ファイルは、どちらを選んでも削除しません。
        </p>
      </ModalBody>
      <ModalFooter>
        <ButtonV2 onClick={onClose}>キャンセル</ButtonV2>
        <ButtonV2 variant="filled" onClick={() => onConfirm(deleteMedia)}>
          ロールバックする
        </ButtonV2>
      </ModalFooter>
    </Modal>
  );
}
