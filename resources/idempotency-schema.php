<?php

return "ikID bigint unsigned NOT NULL AUTO_INCREMENT,
  ikNamespace varchar(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ikKeyHash char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ikFingerprint char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ikClaimToken char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ikOwnerID int unsigned NOT NULL,
  ikOperationID int unsigned NULL DEFAULT NULL,
  ikState varchar(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  ikResponse longtext CHARACTER SET utf8mb4 NULL,
  ikCreatedAt bigint unsigned NOT NULL,
  ikExpiresAt bigint unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (ikID),
  UNIQUE KEY uq_idempotency_namespace_key (ikNamespace,ikKeyHash),
  KEY ix_idempotency_operation (ikOperationID),
  KEY ix_idempotency_expiry (ikExpiresAt),
  CONSTRAINT chk_idempotency_state CHECK (ikState IN ('pending','completed')),
  CONSTRAINT chk_idempotency_response CHECK (ikResponse IS NULL OR JSON_VALID(ikResponse))";
