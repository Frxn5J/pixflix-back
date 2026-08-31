<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION prevent_user_identity_collision()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $function$
                BEGIN
                    IF (NEW.email IS NOT NULL AND (NEW.email = NEW.phone OR NEW.email = NEW.username))
                       OR (NEW.phone IS NOT NULL AND NEW.phone = NEW.username)
                       OR EXISTS (
                           SELECT 1
                           FROM users existing
                           WHERE existing.id <> NEW.id
                             AND (
                                 (NEW.email IS NOT NULL AND (existing.email = NEW.email OR existing.phone = NEW.email OR existing.username = NEW.email))
                                 OR (NEW.phone IS NOT NULL AND (existing.email = NEW.phone OR existing.phone = NEW.phone OR existing.username = NEW.phone))
                                 OR (NEW.username IS NOT NULL AND (existing.email = NEW.username OR existing.phone = NEW.username OR existing.username = NEW.username))
                             )
                       ) THEN
                        RAISE EXCEPTION 'user identity already exists';
                    END IF;

                    RETURN NEW;
                END;
                $function$;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER users_prevent_identity_collision
                BEFORE INSERT OR UPDATE OF email, phone, username ON users
                FOR EACH ROW EXECUTE FUNCTION prevent_user_identity_collision();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER users_prevent_identity_collision_insert
                BEFORE INSERT ON users
                WHEN (NEW.email IS NOT NULL AND (NEW.email = NEW.phone OR NEW.email = NEW.username))
                  OR (NEW.phone IS NOT NULL AND NEW.phone = NEW.username)
                  OR EXISTS (
                      SELECT 1
                      FROM users existing
                      WHERE existing.id <> NEW.id
                        AND (
                            (NEW.email IS NOT NULL AND (existing.email = NEW.email OR existing.phone = NEW.email OR existing.username = NEW.email))
                            OR (NEW.phone IS NOT NULL AND (existing.email = NEW.phone OR existing.phone = NEW.phone OR existing.username = NEW.phone))
                            OR (NEW.username IS NOT NULL AND (existing.email = NEW.username OR existing.phone = NEW.username OR existing.username = NEW.username))
                        )
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'user identity already exists');
                END;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER users_prevent_identity_collision_update
                BEFORE UPDATE OF email, phone, username ON users
                WHEN (NEW.email IS NOT NULL AND (NEW.email = NEW.phone OR NEW.email = NEW.username))
                  OR (NEW.phone IS NOT NULL AND NEW.phone = NEW.username)
                  OR EXISTS (
                      SELECT 1
                      FROM users existing
                      WHERE existing.id <> NEW.id
                        AND (
                            (NEW.email IS NOT NULL AND (existing.email = NEW.email OR existing.phone = NEW.email OR existing.username = NEW.email))
                            OR (NEW.phone IS NOT NULL AND (existing.email = NEW.phone OR existing.phone = NEW.phone OR existing.username = NEW.phone))
                            OR (NEW.username IS NOT NULL AND (existing.email = NEW.username OR existing.phone = NEW.username OR existing.username = NEW.username))
                        )
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'user identity already exists');
                END;
            SQL);
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS users_prevent_identity_collision ON users');
            DB::unprepared('DROP FUNCTION IF EXISTS prevent_user_identity_collision()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS users_prevent_identity_collision_insert');
            DB::unprepared('DROP TRIGGER IF EXISTS users_prevent_identity_collision_update');
        }
    }
};
