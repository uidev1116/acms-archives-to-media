import Button from '@ablogcms/components/button';
import { useState } from 'react';
import type { JobOptions } from '../../types';
import { destName } from './destName';

interface Props {
  fieldKeys: { image: string[]; file: string[] };
  field: JobOptions['field'];
}

function mediaInput(dest: string, kind: 'image' | 'file'): string {
  const type = kind === 'image' ? 'image' : 'file';
  return [
    '<div class="js-media-field">',
    `  <div class="js-droparea" data-thumbnail="{${dest}@thumbnail}" data-type="${type}" data-thumbnail-type="{${dest}@type}" data-width="200px" data-height="200px"></div>`,
    '  <p class="js-text acms-admin-text-danger" style="display:none">許可されていないファイルのため挿入できません。</p>',
    `  <button type="button" class="js-insert acms-admin-btn" data-type="${type}">メディアを選択</button>`,
    `  <input type="hidden" class="js-value" name="${dest}" value="{${dest}}">`,
    `  <input type="hidden" name="field[]" value="${dest}">`,
    `  <input type="hidden" name="${dest}:extension" value="media">`,
    '</div>',
  ].join('\n');
}

function snippets(
  fd: string,
  dest: string,
  kind: 'image' | 'file',
): { old: string; next: string; input: string } {
  if (kind === 'image') {
    return {
      old: `<img src="%{ARCHIVES_DIR}{${fd}@path}" alt="{${fd}@alt}">`,
      next: `<img src="{${dest}@path}" alt="{${dest}@alt}">`,
      input: mediaInput(dest, kind),
    };
  }
  return {
    old: `<a href="%{ARCHIVES_DIR}{${fd}@path}">{${fd}@baseName}</a>`,
    next: `<a href="{${dest}@path}">{${dest}@name}</a>`,
    input: mediaInput(dest, kind),
  };
}

/**
 * テンプレートの移行ヒント。テンプレートは書き換えず、旧変数 → 新変数の対応を表示だけする。
 */
export function TemplateHints({ fieldKeys, field }: Props) {
  const [copied, setCopied] = useState<string | null>(null);
  const rows = [
    ...fieldKeys.image.map((fd) => ({ fd, kind: 'image' as const })),
    ...fieldKeys.file.map((fd) => ({ fd, kind: 'file' as const })),
  ];
  if (rows.length === 0) return null;

  const copy = async (fd: string, text: string) => {
    await navigator.clipboard.writeText(text);
    setCopied(fd);
  };

  return (
    <div className="acms-admin-a2m-hints">
      <h3 className="acms-admin-admin-title3">テンプレート移行のヒント</h3>
      <p className="acms-admin-a2m-muted">
        テンプレートは書き換えていません。
        {field.mode === 'same'
          ? '同名モードなので変数名は変わりません（表示側はメディアの値で上書きされます）。'
          : '新しいフィールドを使うには、次のように書き換えます。'}
      </p>
      {rows.map(({ fd, kind }) => {
        const dest = destName(fd, field);
        const s = snippets(fd, dest, kind);
        const text = `${s.next}\n${s.input}`;
        return (
          <div key={`${kind}-${fd}`} className="acms-admin-a2m-hint">
            <h4>
              <code>{fd}</code>
            </h4>
            <pre className="acms-admin-a2m-code">{`<!-- 旧 -->  ${s.old}\n<!-- 新 -->  ${s.next}\n<!-- 入力欄 -->\n${s.input}`}</pre>
            <Button
              size="small"
              aria-label={`${fd} の新しい書き方をコピー`}
              onClick={() => void copy(fd, text)}
            >
              コピー
            </Button>
            {copied === fd && <span role="status"> コピーしました</span>}
          </div>
        );
      })}
    </div>
  );
}
