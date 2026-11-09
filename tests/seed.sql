USE InfrastructureLogging;

SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE ApiUser;
TRUNCATE TABLE PerformanceMetric;
TRUNCATE TABLE ExceptionLog;
TRUNCATE TABLE LogEntry;
TRUNCATE TABLE ScriptRun;
TRUNCATE TABLE ScriptCatalog;
SET FOREIGN_KEY_CHECKS = 1;

-- Test-only credentials (bcrypt, PHP password_hash format):
--   reader / ReaderPass123!   scope: read
--   writer / WriterPass123!   scope: read write
INSERT INTO ApiUser (UserName, PasswordHash, Scope) VALUES
 ('reader', '$2y$10$N3QGp8tS6ikhaaS67Qntfu6fX52KviphMEr83TKny7Xgvp4q/AZda', 'read'),
 ('writer', '$2y$10$vETxkGXwDeTswbw5vcM22OHfPOCSHZtwyHDEH8pf.s73b80bN5VMu', 'read write');

INSERT INTO ScriptCatalog (ScriptID, ScriptName, Description, Team, Repository, Owner) VALUES
 (1, 'Test-BackupServers', 'Nightly server backup', 'Infrastructure', 'https://github.com/example/backup', 'ops-team'),
 (2, 'Test-CleanupTemp',   'Temp folder cleanup',   'Desktop',        NULL,                                'desk-team');

-- R1 = Success, R2 = Failed, R3 = Running (used by the PATCH test)
INSERT INTO ScriptRun
 (RunID, ScriptID, ScriptName, ScriptVersion, ComputerName, UserName,
  StartTimeUtc, EndTimeUtc, Status, DurationMS, CorrelationID, Message) VALUES
 ('11111111-1111-4111-8111-111111111111', 1, 'Test-BackupServers', '1.0.0', 'SRV01', 'svc-ops',
  '2026-11-01 08:00:00.000', '2026-11-01 08:05:00.000', 'Success', 300000,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'Backup complete'),
 ('22222222-2222-4222-8222-222222222222', 1, 'Test-BackupServers', '1.0.0', 'SRV01', 'svc-ops',
  '2026-11-02 08:00:00.000', '2026-11-02 08:01:00.000', 'Failed', 60000,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'Disk full'),
 ('33333333-3333-4333-8333-333333333333', 2, 'Test-CleanupTemp', '2.0.0', 'PC42', 'svc-desk',
  '2026-11-03 08:00:00.000', NULL, 'Running', NULL,
  'cccccccc-cccc-4ccc-8ccc-cccccccccccc', NULL);

INSERT INTO LogEntry (RunID, TimestampUtc, Level, EventName, Message) VALUES
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:00:01.000', 'Information', 'Start',        'Backup started'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:03:00.000', 'Warning',     'SlowDisk',     'Disk latency high'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:04:59.000', 'Information', 'CopyComplete', 'All files copied'),
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:01.000', 'Information', 'Start',        'Backup started'),
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:59.000', 'Error',       'CopyFailed',   'Disk full');

INSERT INTO ExceptionLog (RunID, TimestampUtc, ExceptionType, Message) VALUES
 ('22222222-2222-4222-8222-222222222222', '2026-11-02 08:00:59.000', 'System.IO.IOException', 'There is not enough space on the disk.');

INSERT INTO PerformanceMetric (RunID, TimestampUtc, MetricName, MetricValue, Unit) VALUES
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:05:00.000', 'FilesCopied', 1200,       'count'),
 ('11111111-1111-4111-8111-111111111111', '2026-11-01 08:05:00.000', 'BytesCopied', 5368709120, 'bytes');
