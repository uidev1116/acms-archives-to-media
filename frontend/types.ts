export type JobStatus =
  | 'draft'
  | 'scanning'
  | 'scanned'
  | 'running'
  | 'paused'
  | 'completed'
  | 'failed'
  | 'rolling_back'
  | 'rolled_back';

export type TargetType = 'unit_image' | 'unit_file' | 'field_image' | 'field_file';
export type FieldMode = 'suffix' | 'same' | 'custom';
export type Enlarged = 'auto' | 'yes' | 'no';
export type OwnerType = 'entry' | 'category' | 'blog' | 'user' | 'module' | 'unit';
export type EntryStatus = 'open' | 'close' | 'draft' | 'trash';

export interface JobOptions {
  targets: TargetType[];
  unitTypeMap: Record<string, string>;
  field: {
    mode: FieldMode;
    suffix: string;
    custom: Record<string, string>;
    includeKeys: string[];
    excludeKeys: string[];
    appendToGroup: boolean;
    removeLegacyKeys: boolean;
  };
  owners: OwnerType[];
  includeRevisions: boolean;
  includeChildBlogs: boolean;
  entryStatus: EntryStatus[];
  media: { fillAltOnNew: boolean; enlarged: Enlarged; tags: string[] };
  chunkSize: number;
}

export interface Job {
  id: number;
  status: JobStatus;
  options: JobOptions;
  total: number;
  converted: number;
  skipped: number;
  error: number;
  message: string | null;
  userId: number;
  blogId: number;
  createdAt: string;
  startedAt: string | null;
  finishedAt: string | null;
}

export interface SummaryRow {
  target: TargetType;
  status: string;
  preview: string;
  reason: string;
  count: number;
}

export interface JobDetail {
  job: Job;
  summary: SummaryRow[];
  counts: Record<string, number>;
}

export interface ScanResult {
  status: JobStatus;
  scanned: number;
  total: number;
  done: boolean;
}

export interface LogEntry {
  itemId: number;
  status: string;
  reason: string;
  message: string;
}

export interface ChunkResult {
  status: JobStatus;
  processed: number;
  converted: number;
  skipped: number;
  error: number;
  done: boolean;
  remaining: number;
  log: LogEntry[];
}

export interface ItemRow {
  id: number;
  target: TargetType;
  table: string;
  ownerType: string;
  ownerId: string;
  sourcePaths: string[];
  preview: string;
  mediaIds: number[];
  status: string;
  reason: string;
  reasonLabel: string;
  message: string | null;
  blogId: number;
}

export interface ItemsPage {
  items: ItemRow[];
  total: number;
}

export interface Definitions {
  mediaUnitTypes: string[];
  sourceUnitTypes: { type: string; count: number }[];
  fieldKeys: { image: string[]; file: string[] };
}

export interface Bootstrap {
  blogId: number;
  version: string;
  adminUrl: string;
  actions: string[];
  csv: { action: string };
  api: { action: string };
}
