import { Alert } from '@ablogcms/components/alert';
import { Button } from '@ablogcms/components/button';
import { Checkbox } from '@ablogcms/components/checkbox';
import { Fieldset } from '@ablogcms/components/fieldset';
import { Input } from '@ablogcms/components/input';
import { HStack } from '@ablogcms/components/stack';
import { Modal, ModalBody, ModalFooter, ModalHeader } from '@ablogcms/components/modal';
import { Radio } from '@ablogcms/components/radio';
import Select from '@ablogcms/components/select';
import { useState } from 'react';
import { OWNER_LABEL, TARGET_LABEL } from '../../labels';
import type {
  Definitions,
  Enlarged,
  EntryStatus,
  FieldMode,
  JobOptions,
  OwnerType,
  TargetType,
} from '../../types';
import FieldKeyInput from './FieldKeyInput';
import { ALL_ENTRY_STATUS, ALL_OWNERS, ALL_TARGETS, toJobOptions, validateForm } from './options';
import type { FormState } from './options';

interface Props {
  definitions: Definitions;
  value: FormState;
  onChange: (next: FormState) => void;
  onSubmit: (options: JobOptions) => void;
  busy?: boolean;
}

const ENTRY_STATUS_LABEL: Record<EntryStatus, string> = {
  open: '公開',
  close: '非公開',
  draft: '下書き',
  trash: 'ゴミ箱のエントリー',
};

const FIELD_MODES: { value: FieldMode; label: string }[] = [
  {
    value: 'same',
    label: '同名のフィールドに書き込む（既定。テンプレートの同時差し替えが必要）',
  },
  {
    value: 'suffix',
    label: '別名のフィールドに書き込む（旧データを残して段階的に移行）',
  },
  { value: 'custom', label: 'カスタム（書き込み先を個別に指定）' },
];

function toggle<T>(list: T[], item: T, order: T[]): T[] {
  const next = list.includes(item) ? list.filter((v) => v !== item) : [...list, item];
  return order.filter((v) => next.includes(v));
}

export function SettingsStep({ definitions, value, onChange, onSubmit, busy = false }: Props) {
  const [errors, setErrors] = useState<string[]>([]);
  const [confirmSame, setConfirmSame] = useState(false);
  const detectedKeys = [
    ...new Set([...definitions.fieldKeys.image, ...definitions.fieldKeys.file]),
  ];
  const set = <K extends keyof FormState>(key: K, v: FormState[K]) =>
    onChange({ ...value, [key]: v });

  const submit = () => {
    const found = validateForm(value);
    setErrors(found);
    if (found.length > 0) return;
    if (value.fieldMode === 'same') {
      setConfirmSame(true);
      return;
    }
    onSubmit(toJobOptions(value));
  };

  const unitTargets = value.targets.includes('unit_image') || value.targets.includes('unit_file');
  const visibleSourceTypes = definitions.sourceUnitTypes.filter(({ type }) =>
    type.startsWith('image')
      ? value.targets.includes('unit_image')
      : value.targets.includes('unit_file'),
  );

  return (
    <section className="acms-admin-a2m-step" aria-labelledby="a2m-settings-title">
      <h2 id="a2m-settings-title" className="acms-admin-admin-title2">
        1. 設定
      </h2>

      {value.fieldMode === 'suffix' && (
        <Alert variant="warning" className="acms-admin-a2m-note" role="note">
          テンプレートを直す前にエントリーを再保存すると、書き込んだメディアの値は消えます。
          旧データは残るので表示は壊れません。テンプレート更新後に再実行すると揃います。
        </Alert>
      )}

      <Fieldset legend="変換する対象">
        <div className="acms-admin-a2m-checks">
          {ALL_TARGETS.map((t: TargetType) => (
            <Checkbox
              key={t}
              label={TARGET_LABEL[t]}
              checked={value.targets.includes(t)}
              onChange={() => set('targets', toggle(value.targets, t, ALL_TARGETS))}
            />
          ))}
        </div>
      </Fieldset>

      {unitTargets && (
        <Fieldset legend="ユニット種類のマッピング（変換元 → メディアユニット）">
          {visibleSourceTypes.length === 0 ? (
            <p className="acms-admin-a2m-muted">変換できる画像・ファイルユニットはありません。</p>
          ) : (
            <ul className="acms-admin-a2m-mapping">
              {visibleSourceTypes.map(({ type, count }) => (
                <li key={type}>
                  <label htmlFor={`a2m-map-${type}`}>
                    <code>{type}</code>（{count} 件）
                  </label>
                  <Select
                    id={`a2m-map-${type}`}
                    aria-label={`${type} の変換先ユニット`}
                    value={value.unitTypeMap[type] ?? definitions.mediaUnitTypes[0] ?? 'media'}
                    onChange={(e) =>
                      set('unitTypeMap', { ...value.unitTypeMap, [type]: e.target.value })
                    }
                  >
                    {definitions.mediaUnitTypes.map((m) => (
                      <option key={m} value={m}>
                        {m}
                      </option>
                    ))}
                  </Select>
                </li>
              ))}
            </ul>
          )}
        </Fieldset>
      )}

      <Fieldset legend="フィールドの書き込み先">
        <div className="acms-admin-a2m-checks acms-admin-a2m-checks--column">
          {FIELD_MODES.map((m) => (
            <Radio
              key={m.value}
              name="a2m-field-mode"
              label={m.label}
              checked={value.fieldMode === m.value}
              onChange={() => set('fieldMode', m.value)}
            />
          ))}
        </div>
        {value.fieldMode === 'suffix' && (
          <div className="acms-admin-a2m-inline">
            <label htmlFor="a2m-suffix">接尾辞</label>
            <Input
              id="a2m-suffix"
              aria-label="接尾辞"
              value={value.fieldSuffix}
              onChange={(e) => set('fieldSuffix', e.target.value)}
            />
            <span className="acms-admin-a2m-muted">
              例: main_image → main_image{value.fieldSuffix}
            </span>
          </div>
        )}
        {value.fieldMode === 'custom' && (
          <div className="acms-admin-a2m-block">
            <label htmlFor="a2m-custom">フィールド名の対応（元=先）</label>
            <textarea
              id="a2m-custom"
              aria-label="フィールド名の対応（元=先）"
              className="acms-admin-form-control acms-admin-a2m-textarea"
              rows={3}
              placeholder={'main_image=main_visual'}
              value={value.customText}
              onChange={(e) => set('customText', e.target.value)}
            />
            <p className="acms-admin-a2m-muted">
              1 行に 1 つ。書かなかったフィールドは接尾辞（{value.fieldSuffix}）を付けます。
            </p>
          </div>
        )}
        {value.fieldMode === 'same' && (
          <Alert variant="warning" className="acms-admin-a2m-note" role="note">
            <code>@path</code> などはメディアの値で上書きされます。<code>@path</code> は URL
            になるため、<code>{'%{ARCHIVES_DIR}{fd@path}'}</code> や <code>@largePath</code>{' '}
            などを使うテンプレートは、同時に差し替えるまで表示が壊れます。入力欄もメディア用に直すまで空に見え、その状態で保存するとメディアの値は消えます。
          </Alert>
        )}
        {value.fieldMode === 'same' && (
          <Checkbox
            label="旧キー（@path など）を削除する"
            checked={value.removeLegacyKeys}
            onChange={(e) => set('removeLegacyKeys', e.target.checked)}
          />
        )}
        <FieldKeyInput
          id="a2m-include"
          label="対象にするフィールド名（空なら全部）"
          value={value.includeKeysText}
          onChange={(v) => set('includeKeysText', v)}
          candidates={detectedKeys}
          hint="空なら全フィールドが対象です"
        />
        <FieldKeyInput
          id="a2m-exclude"
          label="除外するフィールド名"
          value={value.excludeKeysText}
          onChange={(v) => set('excludeKeysText', v)}
          candidates={detectedKeys}
          hint="ここに挙げた名前は変換しません。対象の指定より優先されます"
        />
        <Checkbox
          label="フィールドグループの定義に追記する"
          checked={value.appendToGroup}
          onChange={(e) => set('appendToGroup', e.target.checked)}
        />
      </Fieldset>

      <Fieldset legend="オプション">
        <div className="acms-admin-a2m-checks acms-admin-a2m-checks--column">
          <Checkbox
            label="リビジョンも変換する"
            checked={value.includeRevisions}
            onChange={(e) => set('includeRevisions', e.target.checked)}
          />
          <Checkbox
            label="子ブログも含める"
            checked={value.includeChildBlogs}
            onChange={(e) => set('includeChildBlogs', e.target.checked)}
          />
          <Checkbox
            label="新規メディアに alt・キャプションを設定する"
            checked={value.fillAltOnNew}
            onChange={(e) => set('fillAltOnNew', e.target.checked)}
          />
        </div>
        <div className="acms-admin-a2m-inline">
          <label htmlFor="a2m-enlarged">拡大表示</label>
          <Select
            id="a2m-enlarged"
            aria-label="拡大表示"
            value={value.enlarged}
            onChange={(e) => set('enlarged', e.target.value as Enlarged)}
          >
            <option value="auto">自動（リンクが空で large 版があれば拡大）</option>
            <option value="yes">常に拡大する</option>
            <option value="no">拡大しない</option>
          </Select>
        </div>
        <div className="acms-admin-a2m-inline">
          <label htmlFor="a2m-tags">新規メディアに付けるタグ</label>
          <Input
            id="a2m-tags"
            aria-label="新規メディアに付けるタグ"
            value={value.tagsText}
            onChange={(e) => set('tagsText', e.target.value)}
          />
        </div>
      </Fieldset>

      <Fieldset legend="対象のエントリーの状態">
        <div className="acms-admin-a2m-checks">
          {ALL_ENTRY_STATUS.map((s) => (
            <Checkbox
              key={s}
              label={ENTRY_STATUS_LABEL[s]}
              checked={value.entryStatus.includes(s)}
              onChange={() => set('entryStatus', toggle(value.entryStatus, s, ALL_ENTRY_STATUS))}
            />
          ))}
        </div>
      </Fieldset>

      <Fieldset legend="フィールドの所属">
        <div className="acms-admin-a2m-checks">
          {ALL_OWNERS.map((o: OwnerType) => (
            <Checkbox
              key={o}
              label={OWNER_LABEL[o] ?? o}
              checked={value.owners.includes(o)}
              onChange={() => set('owners', toggle(value.owners, o, ALL_OWNERS))}
            />
          ))}
        </div>
      </Fieldset>

      {errors.length > 0 && (
        <Alert variant="danger" role="alert">
          <ul className="acms-admin-a2m-errors">
            {errors.map((e) => (
              <li key={e}>{e}</li>
            ))}
          </ul>
        </Alert>
      )}

      <div className="acms-admin-a2m-actions">
        <Button variant="primary" onClick={submit} disabled={busy}>
          スキャンして確認へ
        </Button>
      </div>

      <Modal isOpen={confirmSame} onClose={() => setConfirmSame(false)} size="small">
        <ModalHeader>同名モードで実行します</ModalHeader>
        <ModalBody>
          <p>
            旧キー（<code>{'{fd}@path'}</code>{' '}
            など）を削除し、同名のメディアフィールドに置き換えます。
            テンプレートと入力欄を同時に差し替えないと、表示が壊れたり入力欄が空に見えたりします。
            ロールバックはできますが、先にステージングで試してください。
          </p>
        </ModalBody>
        <ModalFooter>
          <HStack spacing="0.5rem" justify="end">
            <Button onClick={() => setConfirmSame(false)}>キャンセル</Button>
            <Button
              variant="primary"
              onClick={() => {
                setConfirmSame(false);
                onSubmit(toJobOptions(value));
              }}
            >
              理解して続ける
            </Button>
          </HStack>
        </ModalFooter>
      </Modal>
    </section>
  );
}
