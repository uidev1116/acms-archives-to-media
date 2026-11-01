import { Input } from '@ablogcms/components/input';
import { parseList } from './options';

interface Props {
  id: string;
  label: string;
  value: string;
  onChange: (next: string) => void;
  candidates: string[];
  hint: string;
}

/** 複数指定できるフィールド名の入力。検出済みの名前はチップで追加・解除でき、直接入力（カンマ・空白区切り）もできる */
export default function FieldKeyInput({ id, label, value, onChange, candidates, hint }: Props) {
  const selected = parseList(value);
  const toggle = (key: string) => {
    onChange(
      (selected.includes(key) ? selected.filter((k) => k !== key) : [...selected, key]).join(', '),
    );
  };
  return (
    <div className="acms-admin-a2m-block">
      <label htmlFor={id}>{label}</label>
      <Input
        id={id}
        aria-label={label}
        value={value}
        placeholder="複数ならカンマか空白で区切る（例: cover, gallery）"
        onChange={(e) => onChange(e.target.value)}
      />
      {candidates.length > 0 && (
        <div className="acms-admin-a2m-chips" role="group" aria-label={`${label}の候補`}>
          {candidates.map((key) => (
            <button
              key={key}
              type="button"
              className="acms-admin-a2m-chip"
              aria-pressed={selected.includes(key)}
              onClick={() => toggle(key)}
            >
              {key}
            </button>
          ))}
        </div>
      )}
      <p className="acms-admin-a2m-muted">
        {hint}（{selected.length} 件指定中）
      </p>
    </div>
  );
}
