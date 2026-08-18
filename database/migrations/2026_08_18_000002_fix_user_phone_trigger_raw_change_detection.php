<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phone/WhatsApp uniqueness on UPDATE must use raw column change detection,
     * not normalized values (legacy 255… vs 0… was re-validated on every save).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tr_users_normalize_phone_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_users_normalize_phone_before_update');

        $normalize = "
            DECLARE p VARCHAR(50);
            DECLARE wa VARCHAR(50);
            DECLARE phone_changed TINYINT(1) DEFAULT 0;
            DECLARE wa_changed TINYINT(1) DEFAULT 0;

            IF NOT (NEW.phone <=> OLD.phone) THEN
                SET phone_changed = 1;
            END IF;
            IF NOT (NEW.whatsapp_number <=> OLD.whatsapp_number) THEN
                SET wa_changed = 1;
            END IF;

            IF NEW.phone IS NOT NULL AND TRIM(NEW.phone) != '' THEN
                SET p = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NEW.phone, ' ', ''), '-', ''), '.', ''), '(', ''), ')', ''));
                IF LEFT(p, 4) = '+255' AND LENGTH(p) = 13 THEN
                    SET NEW.phone = CONCAT('0', SUBSTRING(p, 5));
                ELSEIF LEFT(p, 3) = '255' AND LENGTH(p) = 12 THEN
                    SET NEW.phone = CONCAT('0', SUBSTRING(p, 4));
                ELSEIF p REGEXP '^[0-9]{9}$' THEN
                    SET NEW.phone = CONCAT('0', p);
                ELSE
                    SET NEW.phone = p;
                END IF;
            END IF;

            IF NEW.whatsapp_number IS NOT NULL AND TRIM(NEW.whatsapp_number) != '' THEN
                SET wa = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NEW.whatsapp_number, ' ', ''), '-', ''), '.', ''), '(', ''), ')', ''));
                IF LEFT(wa, 4) = '+255' AND LENGTH(wa) = 13 THEN
                    SET NEW.whatsapp_number = CONCAT('0', SUBSTRING(wa, 5));
                ELSEIF LEFT(wa, 3) = '255' AND LENGTH(wa) = 12 THEN
                    SET NEW.whatsapp_number = CONCAT('0', SUBSTRING(wa, 4));
                ELSEIF wa REGEXP '^[0-9]{9}$' THEN
                    SET NEW.whatsapp_number = CONCAT('0', wa);
                ELSE
                    SET NEW.whatsapp_number = wa;
                END IF;
            END IF;
        ";

        $insertChecks = "
            IF NEW.phone IS NOT NULL AND NEW.phone != '' THEN
                IF EXISTS (SELECT 1 FROM users u WHERE (u.phone = NEW.phone OR u.whatsapp_number = NEW.phone) AND u.id != IFNULL(NEW.id, 0)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This phone number is already registered';
                END IF;
            END IF;
            IF NEW.whatsapp_number IS NOT NULL AND NEW.whatsapp_number != '' AND (NEW.phone IS NULL OR NEW.whatsapp_number != NEW.phone) THEN
                IF EXISTS (SELECT 1 FROM users u WHERE (u.phone = NEW.whatsapp_number OR u.whatsapp_number = NEW.whatsapp_number) AND u.id != IFNULL(NEW.id, 0)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This WhatsApp number is already registered';
                END IF;
            END IF;
        ";

        $updateChecks = "
            IF phone_changed = 1 AND NEW.phone IS NOT NULL AND NEW.phone != '' THEN
                IF EXISTS (SELECT 1 FROM users u WHERE (u.phone = NEW.phone OR u.whatsapp_number = NEW.phone) AND u.id != IFNULL(NEW.id, 0)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This phone number is already registered';
                END IF;
            END IF;
            IF wa_changed = 1 AND NEW.whatsapp_number IS NOT NULL AND NEW.whatsapp_number != ''
                AND (NEW.phone IS NULL OR NEW.whatsapp_number != NEW.phone) THEN
                IF EXISTS (SELECT 1 FROM users u WHERE (u.phone = NEW.whatsapp_number OR u.whatsapp_number = NEW.whatsapp_number) AND u.id != IFNULL(NEW.id, 0)) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This WhatsApp number is already registered';
                END IF;
            END IF;
        ";

        DB::unprepared("
            CREATE TRIGGER tr_users_normalize_phone_before_insert
            BEFORE INSERT ON users
            FOR EACH ROW
            BEGIN
                DECLARE p VARCHAR(50);
                DECLARE wa VARCHAR(50);

                IF NEW.phone IS NOT NULL AND TRIM(NEW.phone) != '' THEN
                    SET p = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NEW.phone, ' ', ''), '-', ''), '.', ''), '(', ''), ')', ''));
                    IF LEFT(p, 4) = '+255' AND LENGTH(p) = 13 THEN
                        SET NEW.phone = CONCAT('0', SUBSTRING(p, 5));
                    ELSEIF LEFT(p, 3) = '255' AND LENGTH(p) = 12 THEN
                        SET NEW.phone = CONCAT('0', SUBSTRING(p, 4));
                    ELSEIF p REGEXP '^[0-9]{9}$' THEN
                        SET NEW.phone = CONCAT('0', p);
                    ELSE
                        SET NEW.phone = p;
                    END IF;
                END IF;

                IF NEW.whatsapp_number IS NOT NULL AND TRIM(NEW.whatsapp_number) != '' THEN
                    SET wa = TRIM(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(NEW.whatsapp_number, ' ', ''), '-', ''), '.', ''), '(', ''), ')', ''));
                    IF LEFT(wa, 4) = '+255' AND LENGTH(wa) = 13 THEN
                        SET NEW.whatsapp_number = CONCAT('0', SUBSTRING(wa, 5));
                    ELSEIF LEFT(wa, 3) = '255' AND LENGTH(wa) = 12 THEN
                        SET NEW.whatsapp_number = CONCAT('0', SUBSTRING(wa, 4));
                    ELSEIF wa REGEXP '^[0-9]{9}$' THEN
                        SET NEW.whatsapp_number = CONCAT('0', wa);
                    ELSE
                        SET NEW.whatsapp_number = wa;
                    END IF;
                END IF;

                {$insertChecks}
            END
        ");

        DB::unprepared("
            CREATE TRIGGER tr_users_normalize_phone_before_update
            BEFORE UPDATE ON users
            FOR EACH ROW
            BEGIN
                {$normalize}
                {$updateChecks}
            END
        ");
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'mysql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tr_users_normalize_phone_before_insert');
        DB::unprepared('DROP TRIGGER IF EXISTS tr_users_normalize_phone_before_update');
    }
};
