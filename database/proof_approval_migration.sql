-- Run once on an existing database before using proof approval.
-- MariaDB 10.4 compatible.
ALTER TABLE `proof_logs`
    MODIFY `action` ENUM('UPLOAD','DELETE','APPROVE','REJECT') NOT NULL;
