import { CreatableSelect } from '@ablogcms/components/rich-select';
import { parseList } from './options';

interface Props {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  candidates: string[];
  hint: string;
}

interface Option {
  value: string;
  label: string;
}

const toOption = (key: string): Option => ({ value: key, label: key });

/** 複数指定できるフィールド名の入力。検出済みの名前から選ぶか、直接入力して Enter・カンマで追加する */
export default function FieldKeyInput({ id, label, value, onChange, candidates, hint }: Props) {
  return (
    <div className="acms-admin-a2m-block">
      <label htmlFor={id}>{label}</label>
      <CreatableSelect<Option, true>
        inputId={id}
        aria-label={label}
        isMulti
        value={parseList(value).map(toOption)}
        options={candidates.map(toOption)}
        placeholder="選ぶか、入力して Enter（複数可）"
        noOptionsMessage={() => '候補がありません'}
        formatCreateLabel={(input) => `「${input}」を追加`}
        onChange={(next) => onChange(next.map((o) => o.value).join(', '))}
      />
      <p className="acms-admin-a2m-muted">{hint}</p>
    </div>
  );
}
