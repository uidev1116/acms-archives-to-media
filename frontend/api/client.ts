export class ApiError extends Error {
  readonly status: number;
  readonly code: string;

  constructor(message: string, status: number, code: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
  }
}

export interface Client {
  call<T>(action: string, params?: Record<string, unknown>): Promise<T>;
  exportCsv(jobId: number): void;
}

export interface ClientOptions {
  /** POST 先（管理画面の URL） */
  endpoint: string;
  /** ACMS_POST モジュール名（API） */
  apiAction: string;
  /** ACMS_POST モジュール名（CSV） */
  csvAction: string;
  /** CSRF トークン（管理画面の meta タグ由来） */
  getToken: () => string;
  fetchImpl?: typeof fetch;
  submitForm?: (form: HTMLFormElement) => void;
}

function encode(value: unknown): string {
  if (typeof value === 'boolean') return value ? '1' : '0';
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

export function createClient(options: ClientOptions): Client {
  const fetchImpl = options.fetchImpl ?? ((...args) => fetch(...args));

  return {
    async call<T>(action: string, params: Record<string, unknown> = {}): Promise<T> {
      const body = new FormData();
      body.set(options.apiAction, 'exec');
      body.set('formToken', options.getToken());
      body.set('action', action);
      for (const [key, value] of Object.entries(params)) {
        if (value === undefined || value === null) continue;
        body.set(key, encode(value));
      }

      let response: Response;
      try {
        response = await fetchImpl(options.endpoint, {
          method: 'POST',
          body,
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
      } catch (e) {
        throw new ApiError(e instanceof Error ? e.message : '通信に失敗しました。', 0, 'network');
      }

      let data: unknown;
      try {
        data = await response.json();
      } catch {
        throw new ApiError(
          'サーバーの応答を読み取れませんでした。ログインが切れている可能性があります。',
          response.status,
          'bad_response',
        );
      }

      if (!response.ok) {
        const err = (data ?? {}) as { error?: string; code?: string };
        throw new ApiError(
          err.error ?? `リクエストに失敗しました（${response.status}）。`,
          response.status,
          err.code ?? 'http_error',
        );
      }
      return data as T;
    },

    exportCsv(jobId: number): void {
      const form = document.createElement('form');
      form.method = 'post';
      form.action = options.endpoint;
      form.target = '_blank';
      form.style.display = 'none';
      const fields: Record<string, string> = {
        [options.csvAction]: 'exec',
        formToken: options.getToken(),
        jobId: String(jobId),
      };
      for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
      }
      // DOM に接続していないフォームはブラウザが submit しないので、submit の間だけ body に置く
      document.body.appendChild(form);
      try {
        (options.submitForm ?? ((f) => f.submit()))(form);
      } finally {
        form.remove();
      }
    },
  };
}
