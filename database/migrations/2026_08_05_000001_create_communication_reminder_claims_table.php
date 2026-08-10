<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record that makes a scheduled reminder fire once — and, when the date it was measured from
 * moves, exactly once more.
 *
 * NOT addMetaData(): that helper adds softDeletes(), and a soft-deleted row still occupies a unique
 * key. release() would then permanently block the milestone it was supposed to free — the precise
 * opposite of its purpose. added_by / modified_by are meaningless for rows only a cron writes.
 *
 * Kept apart from communication_sendings on purpose: that table records what the communications
 * system delivered, which is a different question from whether the app has decided to raise this
 * milestone yet. A send that fails must not un-remember the decision, or the next run tries again
 * forever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_reminder_claims', function (Blueprint $table) {
            $table->id();

            $table->string('reminder_key', 100);

            // 'b7' / 'd0' / 'a30' — ReminderOffset::storageKey(). Never a display label.
            $table->string('offset_key', 12);

            // What makes a SECOND claim legitimate.
            //   'once'        — RearmPolicy::ONCE_EVER. One claim for all time.
            //   '2026-08-12'  — RearmPolicy::PER_ANCHOR_DATE. The anchor as it stood.
            //   'none'        — PER_ANCHOR_DATE with a null anchor.
            // NOT NULL and part of the index on purpose: MySQL treats NULLs in a unique index as
            // distinct, so a nullable column here would let the same claim be taken an unbounded
            // number of times and look like it was working.
            $table->string('rearm_key', 20);

            $table->string('subject_type', 255);
            $table->unsignedBigInteger('subject_id');

            // Forensics. Under PER_ANCHOR_DATE this restates rearm_key; under ONCE_EVER it is the
            // only place the real date survives, which is what makes a support question answerable.
            $table->date('anchor_on')->nullable();

            // The value the dispatched event returns from getIdempotencyKey(). Written after the
            // event goes out, which is the only moment it is knowable. Nothing reads it back yet:
            // the join it exists for needs a matching column on communication_sendings, which this
            // change deliberately does not add. Until that lands this column is forensic only — do
            // not delete it on that basis.
            $table->string('dispatch_key', 191)->nullable();

            // No FK: the claims table outlives teams and must never block a team deletion. A
            // deliberate divergence from every other team_id in this package (communication_recipients,
            // communication_template_groups, communication_sendings, communication_sending_recipient_teams
            // all constrain onto teams) — those rows describe a team's own data, this one records a
            // decision the sweeper must never be asked to re-make.
            //
            // Deliberately NOT in the unique index, so per-team offsets are NOT supported yet: two
            // teams sharing a subject and an offset would produce one key and the second team would
            // be silently skipped. Adding this column to the index would not fix it while it stays
            // nullable — the same NULL-distinct rule noted on rearm_key would make every baseline
            // claim unique instead.
            $table->unsignedBigInteger('team_id')->nullable();

            // dateTime(), not timestamp(): this is the table's first TIMESTAMP column, and on a
            // server with explicit_defaults_for_timestamp=OFF (MySQL 5.7, MariaDB < 10.10 — this
            // package declares no MySQL floor) MySQL grants it ON UPDATE CURRENT_TIMESTAMP for
            // free. Stamping dispatched_at would then rewrite claimed_at on the same row, erasing
            // the gap below and sliding the prune window forward forever. Verified: that grant
            // really is emitted. ->useCurrent() would also suppress it, but costs the currently-good
            // property that omitting claimed_at on insert fails loudly (errno 1364 under strict mode).
            $table->dateTime('claimed_at');

            // NULL after a claim, stamped after event() returns. The gap between the two is what
            // distinguishes "another run holds this right now" from "this went out hours ago".
            $table->timestamp('dispatched_at')->nullable();

            // Explicit name: the auto-generated one for these five columns is 94 chars and MySQL
            // rejects it (errno 1059). The two indexes below would fit in 64; they are named for
            // symmetry only.
            $table->unique(
                ['reminder_key', 'offset_key', 'rearm_key', 'subject_type', 'subject_id'],
                'communication_reminder_claims_once',
            );

            // No code path reads this. It serves the ad-hoc support query — "what did we send this
            // person, and when" — which starts from a subject and has no reminder_key to lead with.
            // Carried from SISC's table, where it also has no code reader.
            $table->index(['subject_type', 'subject_id'], 'crc_subject_idx');

            // Serves the prune command.
            $table->index(['reminder_key', 'claimed_at'], 'crc_prune_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_reminder_claims');
    }
};
