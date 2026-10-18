import Button from '@ablogcms/components/button';
import { HStack } from '@ablogcms/components/stack';
import Modal, { ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';

interface Props {
  isOpen: boolean;
  jobId: number;
  onClose: () => void;
  onConfirm: () => void;
}

export function DeleteJobModal({ isOpen, jobId, onClose, onConfirm }: Props) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} size="small">
      <ModalHeader>ジョブの削除</ModalHeader>
      <ModalBody>
        <p>ジョブ #{jobId} とそのアイテムの記録（結果の一覧・CSV に使う情報）を削除します。</p>
        <p>変換済みのデータやメディアは変わりません。この操作は取り消せません。</p>
      </ModalBody>
      <ModalFooter>
        <HStack spacing="0.5rem" justify="end">
          <Button onClick={onClose}>キャンセル</Button>
          <Button variant="danger" onClick={onConfirm}>
            削除する
          </Button>
        </HStack>
      </ModalFooter>
    </Modal>
  );
}
