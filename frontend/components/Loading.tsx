import { Spinner } from '@ablogcms/components/spinner';

/**
 * 読み込み中の表示（画面の中央寄せ）。
 */
export function Loading({ size = '2em' }: { size?: string }) {
  return (
    <div className="acms-admin-a2m-loading" role="status" aria-label="読み込み中">
      <Spinner size={size} />
    </div>
  );
}
