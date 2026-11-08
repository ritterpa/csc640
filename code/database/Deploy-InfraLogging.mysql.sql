/*
    InfraLogging MySQL Deployment
    Version: 1.0.0 (converted from SQL Server)

    Requirements: MySQL 8.0.19 or later, InnoDB.
      - CHECK constraints are enforced from 8.0.16
      - Expression defaults (UTC_TIMESTAMP) need 8.0.13
      - Descending indexes need 8.0

    Run with the mysql command-line client or MySQL Workbench as a user that
    can create databases, tables, procedures and roles. The DELIMITER lines
    are client commands; they are needed for the procedure bodies.

        mysql -u admin -p < Deploy-InfraLogging.mysql.sql

    Change the database name below before running if you need a different one.
*/

CREATE DATABASE IF NOT EXISTS InfrastructureLogging
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

USE InfrastructureLogging;

/* ------------------------------------------------------------------------
   Tables
   GUIDs are stored as CHAR(36) text (e.g. 0f8fad5b-d9cb-469f-a165-70867728950e)
   so PowerShell can pass [guid]::NewGuid().ToString() directly.
   JSON columns are LONGTEXT with a JSON_VALID check so the text is stored
   exactly as sent, matching the ISJSON() checks in the SQL Server version.
   Indexes are declared inline because MySQL has no CREATE INDEX IF NOT EXISTS.
   ------------------------------------------------------------------------ */

CREATE TABLE IF NOT EXISTS ScriptCatalog
(
    ScriptID        INT NOT NULL AUTO_INCREMENT,
    ScriptName      VARCHAR(200) NOT NULL,
    Description     LONGTEXT NULL,
    Team            VARCHAR(100) NULL,
    Repository      VARCHAR(500) NULL,
    Owner           VARCHAR(200) NULL,
    CreatedDateUtc  DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    ModifiedDateUtc DATETIME(3) NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    PRIMARY KEY (ScriptID),
    CONSTRAINT UQ_ScriptCatalog_ScriptName UNIQUE (ScriptName)
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS ScriptRun
(
    RunID             CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    ScriptID          INT NOT NULL,
    ScriptName        VARCHAR(200) NOT NULL,
    ScriptVersion     VARCHAR(50) NULL,
    Description       LONGTEXT NULL,
    ComputerName      VARCHAR(255) NULL,
    UserName          VARCHAR(255) NULL,
    StartTimeUtc      DATETIME(3) NOT NULL,
    EndTimeUtc        DATETIME(3) NULL,
    Status            VARCHAR(20) NOT NULL,
    DurationMS        BIGINT NULL,
    PowerShellVersion VARCHAR(50) NULL,
    PSEdition         VARCHAR(50) NULL,
    ProcessID         INT NULL,
    CorrelationID     CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    ParentRunID       CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NULL,
    Parameters        LONGTEXT NULL,
    GitCommit         VARCHAR(100) NULL,
    ScriptPath        VARCHAR(1000) NULL,
    Message           LONGTEXT NULL,
    PRIMARY KEY (RunID),
    INDEX IX_ScriptRun_ScriptID_StartTimeUtc (ScriptID, StartTimeUtc DESC),
    INDEX IX_ScriptRun_StartTimeUtc_Status (StartTimeUtc DESC, Status),
    INDEX IX_ScriptRun_CorrelationID (CorrelationID),
    INDEX IX_ScriptRun_ParentRunID (ParentRunID),
    CONSTRAINT FK_ScriptRun_ScriptCatalog
        FOREIGN KEY (ScriptID) REFERENCES ScriptCatalog (ScriptID),
    CONSTRAINT FK_ScriptRun_Parent
        FOREIGN KEY (ParentRunID) REFERENCES ScriptRun (RunID),
    CONSTRAINT CK_ScriptRun_Status
        CHECK (Status IN ('Running', 'Success', 'Failed', 'Cancelled')),
    CONSTRAINT CK_ScriptRun_Parameters_JSON
        CHECK (Parameters IS NULL OR JSON_VALID(Parameters))
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS LogEntry
(
    LogID        BIGINT NOT NULL AUTO_INCREMENT,
    RunID        CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    TimestampUtc DATETIME(3) NOT NULL,
    Level        VARCHAR(20) NOT NULL,
    EventName    VARCHAR(200) NULL,
    Message      LONGTEXT NULL,
    Category     VARCHAR(100) NULL,
    FunctionName VARCHAR(255) NULL,
    LineNumber   INT NULL,
    DurationMS   INT NULL,
    JsonData     LONGTEXT NULL,
    PRIMARY KEY (LogID),
    INDEX IX_LogEntry_RunID_TimestampUtc (RunID, TimestampUtc),
    INDEX IX_LogEntry_TimestampUtc_Level (TimestampUtc DESC, Level),
    INDEX IX_LogEntry_EventName_TimestampUtc (EventName, TimestampUtc DESC),
    CONSTRAINT FK_LogEntry_ScriptRun
        FOREIGN KEY (RunID) REFERENCES ScriptRun (RunID),
    CONSTRAINT CK_LogEntry_Level
        CHECK (Level IN ('Trace', 'Debug', 'Information', 'Warning', 'Error', 'Critical')),
    CONSTRAINT CK_LogEntry_Json
        CHECK (JsonData IS NULL OR JSON_VALID(JsonData))
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS ExceptionLog
(
    ExceptionID           BIGINT NOT NULL AUTO_INCREMENT,
    RunID                 CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    TimestampUtc          DATETIME(3) NOT NULL,
    ExceptionType         VARCHAR(500) NULL,
    Message               LONGTEXT NULL,
    StackTrace            LONGTEXT NULL,
    ScriptStackTrace      LONGTEXT NULL,
    FullyQualifiedErrorId VARCHAR(500) NULL,
    Category              VARCHAR(100) NULL,
    InvocationInfo        LONGTEXT NULL,
    JsonData              LONGTEXT NULL,
    PRIMARY KEY (ExceptionID),
    INDEX IX_ExceptionLog_RunID_TimestampUtc (RunID, TimestampUtc),
    INDEX IX_ExceptionLog_TimestampUtc (TimestampUtc DESC),
    CONSTRAINT FK_ExceptionLog_ScriptRun
        FOREIGN KEY (RunID) REFERENCES ScriptRun (RunID),
    CONSTRAINT CK_ExceptionLog_InvocationInfo_JSON
        CHECK (InvocationInfo IS NULL OR JSON_VALID(InvocationInfo)),
    CONSTRAINT CK_ExceptionLog_Json
        CHECK (JsonData IS NULL OR JSON_VALID(JsonData))
) ENGINE = InnoDB;

CREATE TABLE IF NOT EXISTS PerformanceMetric
(
    MetricID     BIGINT NOT NULL AUTO_INCREMENT,
    RunID        CHAR(36) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    TimestampUtc DATETIME(3) NOT NULL,
    MetricName   VARCHAR(200) NOT NULL,
    MetricValue  DECIMAL(38,10) NOT NULL,
    Unit         VARCHAR(50) NULL,
    Category     VARCHAR(100) NULL,
    JsonData     LONGTEXT NULL,
    PRIMARY KEY (MetricID),
    INDEX IX_PerformanceMetric_RunID_TimestampUtc (RunID, TimestampUtc),
    INDEX IX_PerformanceMetric_Name_TimestampUtc (MetricName, TimestampUtc DESC),
    CONSTRAINT FK_PerformanceMetric_ScriptRun
        FOREIGN KEY (RunID) REFERENCES ScriptRun (RunID),
    CONSTRAINT CK_PerformanceMetric_Json
        CHECK (JsonData IS NULL OR JSON_VALID(JsonData))
) ENGINE = InnoDB;

/* API login accounts. Created out of band by an administrator (no
   registration endpoint). PasswordHash holds a bcrypt hash from PHP's
   password_hash(); Scope is copied into the JWT the login endpoint issues. */
CREATE TABLE IF NOT EXISTS ApiUser
(
    UserID         INT NOT NULL AUTO_INCREMENT,
    UserName       VARCHAR(100) NOT NULL,
    PasswordHash   VARCHAR(255) NOT NULL,
    Scope          VARCHAR(50)  NOT NULL,
    IsActive       TINYINT(1)   NOT NULL DEFAULT 1,
    CreatedDateUtc DATETIME(3)  NOT NULL DEFAULT (UTC_TIMESTAMP(3)),
    PRIMARY KEY (UserID),
    CONSTRAINT UQ_ApiUser_UserName UNIQUE (UserName),
    CONSTRAINT CK_ApiUser_Scope CHECK (Scope IN ('read', 'read write'))
) ENGINE = InnoDB;

/* ------------------------------------------------------------------------
   Stored procedures
   MySQL procedure parameters have no @ sigil and no default values, so every
   parameter must be supplied (pass NULL for optional ones). Parameters are
   prefixed with p_ because a MySQL parameter with the same name as a column
   silently shadows that column inside the procedure.
   ------------------------------------------------------------------------ */

DELIMITER $$

DROP PROCEDURE IF EXISTS usp_InfraLogging_StartScriptRun $$
CREATE PROCEDURE usp_InfraLogging_StartScriptRun
(
    IN p_RunID             CHAR(36),
    IN p_ScriptName        VARCHAR(200),
    IN p_ScriptVersion     VARCHAR(50),
    IN p_Description       LONGTEXT,
    IN p_Team              VARCHAR(100),
    IN p_Repository        VARCHAR(500),
    IN p_Owner             VARCHAR(200),
    IN p_ComputerName      VARCHAR(255),
    IN p_UserName          VARCHAR(255),
    IN p_StartTimeUtc      DATETIME(3),
    IN p_PowerShellVersion VARCHAR(50),
    IN p_PSEdition         VARCHAR(50),
    IN p_ProcessId         INT,
    IN p_CorrelationID     CHAR(36),
    IN p_ParentRunID       CHAR(36),
    IN p_Parameters        LONGTEXT,
    IN p_GitCommit         VARCHAR(100),
    IN p_ScriptPath        VARCHAR(1000)
)
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
    DECLARE v_ScriptID INT;

    /* Equivalent of SET XACT_ABORT ON: roll back and re-raise on any error. */
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    /* Insert the catalog row, or update it if the script name already exists.
       LAST_INSERT_ID(ScriptID) makes LAST_INSERT_ID() return the existing ID
       on the update path, so both paths yield the correct ScriptID. */
    INSERT INTO ScriptCatalog
        (ScriptName, Description, Team, Repository, Owner)
    VALUES
        (p_ScriptName, p_Description, p_Team, p_Repository, p_Owner)
    ON DUPLICATE KEY UPDATE
        ScriptID        = LAST_INSERT_ID(ScriptID),
        Description     = COALESCE(p_Description, Description),
        Team            = COALESCE(p_Team, Team),
        Repository      = COALESCE(p_Repository, Repository),
        Owner           = COALESCE(p_Owner, Owner),
        ModifiedDateUtc = UTC_TIMESTAMP(3);

    SET v_ScriptID = LAST_INSERT_ID();

    INSERT INTO ScriptRun
    (
        RunID, ScriptID, ScriptName, ScriptVersion, Description,
        ComputerName, UserName, StartTimeUtc, Status,
        PowerShellVersion, PSEdition, ProcessID,
        CorrelationID, ParentRunID, Parameters, GitCommit, ScriptPath
    )
    VALUES
    (
        p_RunID, v_ScriptID, p_ScriptName, p_ScriptVersion, p_Description,
        p_ComputerName, p_UserName, p_StartTimeUtc, 'Running',
        p_PowerShellVersion, p_PSEdition, p_ProcessId,
        p_CorrelationID, p_ParentRunID, p_Parameters, p_GitCommit, p_ScriptPath
    );

    COMMIT;
END $$

DROP PROCEDURE IF EXISTS usp_InfraLogging_WriteLog $$
CREATE PROCEDURE usp_InfraLogging_WriteLog
(
    IN p_RunID        CHAR(36),
    IN p_TimestampUtc DATETIME(3),
    IN p_Level        VARCHAR(20),
    IN p_EventName    VARCHAR(200),
    IN p_Message      LONGTEXT,
    IN p_Category     VARCHAR(100),
    IN p_FunctionName VARCHAR(255),
    IN p_LineNumber   INT,
    IN p_DurationMS   INT,
    IN p_JsonData     LONGTEXT
)
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
    INSERT INTO LogEntry
    (
        RunID, TimestampUtc, Level, EventName, Message,
        Category, FunctionName, LineNumber, DurationMS, JsonData
    )
    VALUES
    (
        p_RunID, p_TimestampUtc, p_Level, p_EventName, p_Message,
        p_Category, p_FunctionName, p_LineNumber, p_DurationMS, p_JsonData
    );
END $$

DROP PROCEDURE IF EXISTS usp_InfraLogging_WriteException $$
CREATE PROCEDURE usp_InfraLogging_WriteException
(
    IN p_RunID                 CHAR(36),
    IN p_TimestampUtc          DATETIME(3),
    IN p_ExceptionType         VARCHAR(500),
    IN p_Message               LONGTEXT,
    IN p_StackTrace            LONGTEXT,
    IN p_ScriptStackTrace      LONGTEXT,
    IN p_FullyQualifiedErrorId VARCHAR(500),
    IN p_Category              VARCHAR(100),
    IN p_InvocationInfo        LONGTEXT,
    IN p_JsonData              LONGTEXT
)
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
    INSERT INTO ExceptionLog
    (
        RunID, TimestampUtc, ExceptionType, Message, StackTrace,
        ScriptStackTrace, FullyQualifiedErrorId, Category,
        InvocationInfo, JsonData
    )
    VALUES
    (
        p_RunID, p_TimestampUtc, p_ExceptionType, p_Message, p_StackTrace,
        p_ScriptStackTrace, p_FullyQualifiedErrorId, p_Category,
        p_InvocationInfo, p_JsonData
    );
END $$

DROP PROCEDURE IF EXISTS usp_InfraLogging_WriteMetric $$
CREATE PROCEDURE usp_InfraLogging_WriteMetric
(
    IN p_RunID        CHAR(36),
    IN p_TimestampUtc DATETIME(3),
    IN p_MetricName   VARCHAR(200),
    IN p_MetricValue  DECIMAL(38,10),
    IN p_Unit         VARCHAR(50),
    IN p_Category     VARCHAR(100),
    IN p_JsonData     LONGTEXT
)
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
    INSERT INTO PerformanceMetric
    (
        RunID, TimestampUtc, MetricName, MetricValue,
        Unit, Category, JsonData
    )
    VALUES
    (
        p_RunID, p_TimestampUtc, p_MetricName, p_MetricValue,
        p_Unit, p_Category, p_JsonData
    );
END $$

DROP PROCEDURE IF EXISTS usp_InfraLogging_EndScriptRun $$
CREATE PROCEDURE usp_InfraLogging_EndScriptRun
(
    IN p_RunID      CHAR(36),
    IN p_EndTimeUtc DATETIME(3),
    IN p_Status     VARCHAR(20),
    IN p_DurationMS BIGINT,
    IN p_Message    LONGTEXT
)
MODIFIES SQL DATA
SQL SECURITY DEFINER
BEGIN
    /* Existence is checked explicitly rather than with ROW_COUNT(), because
       MySQL's ROW_COUNT() reports 0 when an UPDATE matches a row but the
       values are unchanged (e.g. a retried call). */
    IF NOT EXISTS (SELECT 1 FROM ScriptRun WHERE RunID = p_RunID) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Script run not found.', MYSQL_ERRNO = 51000;
    END IF;

    UPDATE ScriptRun
    SET
        EndTimeUtc = p_EndTimeUtc,
        Status     = p_Status,
        DurationMS = p_DurationMS,
        Message    = p_Message
    WHERE RunID = p_RunID;
END $$

DELIMITER ;

/* ------------------------------------------------------------------------
   Permissions
   Create a dedicated role and grant only EXECUTE on the logging procedures.
   The procedures use SQL SECURITY DEFINER, so they run with the rights of
   the account that created them; the service account needs no INSERT,
   UPDATE or DELETE rights on the tables.

   MySQL has no Windows/AD logins in Community Edition, so a native MySQL
   account is shown. Replace the user name, host and password.

   Example:

   CREATE ROLE IF NOT EXISTS 'InfraLoggingWriter';
   GRANT EXECUTE ON PROCEDURE InfrastructureLogging.usp_InfraLogging_StartScriptRun TO 'InfraLoggingWriter';
   GRANT EXECUTE ON PROCEDURE InfrastructureLogging.usp_InfraLogging_WriteLog       TO 'InfraLoggingWriter';
   GRANT EXECUTE ON PROCEDURE InfrastructureLogging.usp_InfraLogging_WriteException TO 'InfraLoggingWriter';
   GRANT EXECUTE ON PROCEDURE InfrastructureLogging.usp_InfraLogging_WriteMetric    TO 'InfraLoggingWriter';
   GRANT EXECUTE ON PROCEDURE InfrastructureLogging.usp_InfraLogging_EndScriptRun   TO 'InfraLoggingWriter';

   CREATE USER IF NOT EXISTS 'infra_automation'@'%' IDENTIFIED BY 'ChangeMe!';
   GRANT 'InfraLoggingWriter' TO 'infra_automation'@'%';
   SET DEFAULT ROLE 'InfraLoggingWriter' TO 'infra_automation'@'%';
   ------------------------------------------------------------------------ */
