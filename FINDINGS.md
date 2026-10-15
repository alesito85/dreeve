# Re-importing an activity: findings and the design that follows

Two files were inspected, the ones that triggered this work:

| Path | Bytes | MD5 |
| --- | --- | --- |
| `/home/claude/21741882933.fit` | 122709 | `bdad0666bdb08b91f3ac74fd7bdb6a13` |
| `/home/claude/Afternoon_Ride (4).fit` | 122709 | `bdad0666bdb08b91f3ac74fd7bdb6a13` |

Both carry SHA-256 `182ed4bdffeadc5472fb1ddc66e717f048513d61c6eff335003ccbf46cbe99be`.

They are byte-identical. Same length, same MD5, same SHA-256. Different names, same activity. That
single fact settles the question of what may be used to decide whether a file is a re-import: the
filename may not be, and neither may the size or the modification time.

## What the file actually contains

FIT profile 2178, one file, one session, decoded through `docker/fit-tool`:

| Message | Count |
| --- | --- |
| record | 3674 |
| device_info | 10 |
| field_description | 16 |
| event | 5 |
| file_id, sport, lap, session, activity | 1 each |

`file_id`: manufacturer 289 (Hammerhead), product 2, serial 22701926, type 4 (activity),
`time_created` 2026-10-14T12:31:03Z.

`session`: `start_time` 2026-10-14T12:31:02Z, sport 2 (cycling), `total_timer_time` 3675 s,
`total_distance` 21599.32 m, `total_ascent` 307 m, `num_laps` 1, `avg_power` 182 W, `max_power` 801 W,
`normalized_power` 226 W, `threshold_power` 200 W, `avg_speed` and `enhanced_avg_speed` 5.882 m/s,
`max_speed` and `enhanced_max_speed` 16.437 m/s. It carries **no** pedal smoothness, torque
effectiveness or left/right balance aggregates, which is why the pedaling-dynamics table on the
activity page has nothing to show while the charts do: the charts read the records, the table reads
the session.

3674 records at 1 Hz, with per-field coverage (a null is a hole in that sample, not a missing field):

| Field | Samples | Field | Samples |
| --- | --- | --- | --- |
| timestamp, heart_rate, power, altitude, enhanced_altitude | 3674 | temperature, cadence, distance | 3673 |
| grade, speed, enhanced_speed, position_lat, position_long | 3669 | left and right pedal_smoothness | 3470 |
| left and right torque_effectiveness | 3457 | left_right_balance | 3422 |

So a single file already has holes, and the holes are per field: the dynamics stop being reported
roughly 200 samples before the recording ends. There are no workouts and no segment data, and the 16
developer field descriptions (battery, tyre pressure, suspension, gearing, skin temperature) never
appear in a record.

## Why the current comparison cannot hold

`src/Domain/Import/DuplicateActivityScanner.php` has two rules, both unable to answer the question
being asked:

1. `externalReferenceId = <filename>` with `importSource = 'stravaApi'` and the stored start within
   60 s. For file imports `externalReferenceId` **is** the filename, so this rule only ever fires
   against Strava API rows, and these two files have different names, so it does not fire at all.
2. an exact `startDateTime` string match plus `sportType`. This is the concrete duplicate hole: one
   second of drift, a re-export that rounds, or a timezone change and the same ride imports twice.

There is no file checksum and no device identity stored anywhere in `src/`: `Activity` has
`externalReferenceId` (the filename for every file parser) and nothing else that identifies a file.

## How identity should work

Three layers, evaluated in order, in a new `ActivityIdentityMatcher` that replaces the body of
`DuplicateActivityScanner`:

1. **File checksum**, SHA-256 of the uploaded bytes. A match means the identical file, so a merge
   would add nothing: skip, which is today's behaviour and the right one for this case.
2. **Device reference**, the primary identity for FIT: manufacturer, product and serial from
   `file_id`, paired with the **session** `start_time`, matched with a 30 s tolerance. For these
   files that is `289-2-22701926-2026-10-14T12:31:02Z`. The session start is used rather than
   `file_id.time_created` because the two differ by a second here, and because a multi-sport file
   carries one `file_id` with several sessions, so only the session start is unique per activity. A
   rename, a re-export or a truncated sync changes none of those four components. A `file_id`
   without a serial still yields a key, but a weaker one that the content gate has to confirm.
3. **Content gate**, the fallback for sources with no device identity (TCX, GPX, the Strava API):
   same `sportType`, start within 30 s, distance within 5 %. Duration is deliberately not a gate. An
   early partial sync followed by the full file is the same activity with distance off by more than
   5 % and duration off by minutes, and a duration gate would import that as a duplicate, which is
   the failure this whole feature exists to prevent.

A match on any layer merges into the existing activity. **Nothing may ever create a second row for
an activity Dreeve already has**, so when the content gate returns two or more candidates the import
refuses: it records a SKIPPED `FileImport` saying the file could not be safely matched and needs a
manual decision. That is not a theoretical branch, files with no sub-day time precision (a GPX that
defaults to midnight) and two indoor sessions on the same day all satisfy sport plus same 30 s plus
5 % distance at once.

## How the merge should work

The newer file wins wherever both sides have a value, and a hole in the newer file keeps the stored
value, so a re-import primarily adds what is missing:

- the merged timeline is the **union of both files keyed by absolute time**, so a truncated file
  leaves the tail it never recorded intact and a fuller file appends the tail it now has;
- a stream type only the new file carries is added on that axis, which is exactly the case of these
  two files and their per-side pedal smoothness and torque effectiveness;
- a stream type only the existing activity has is kept, including samples past the end of a shorter
  file;
- scalars go newer-wins, `null` parsed values fall back to what is stored, and the monotone ones
  (`maxPower`, `maxSpeed`, `maxHeartRate`) take the larger value;
- laps and drivetrain usages are replaced wholesale, their primary keys do not add up across
  versions;
- everything derived from streams is deleted and rebuilt by the metrics steps that already select
  their own pending work: combined streams, splits, best efforts, stream metrics, custom segment
  efforts and the moving stream. Milestones and gear stats read the activity columns live, so they
  follow the newer totals on their own.

## Versions, so a replaced state is never lost

Before a merge overwrites anything, the state it replaces is written to a new `ActivityRevision`
table: revision number, a compact summary (totals, averages, which stream types exist and how many
samples each has), a link to the `FileImport` row that produced it, and a timestamp. The activity
stays a single row, which is why no read path in the 62 files that query `Activity` with raw SQL has
to change and no aggregate can double count.

Restoring a version replays the bytes that `FileImport` already stores compressed, forced to win
regardless of timestamps, and appends the state it replaced as a new revision so history stays
linear. Two consequences worth stating: a version whose source was the Strava API has no bytes, so
it can be listed but not restored, and a parser change can make a replay differ slightly from the
original import.

## `.fit.gz`

`docker/fit-tool` now sniffs the gzip magic and unzips before decoding, so there is one code path and
the stored original stays the gz the user handed over. Verified against these files: `gzip -c` of
`21741882933.fit` decoded through the binary produces byte-identical JSON to the plain file, with no
warning on stderr.

On the PHP side `pathinfo` reports `gz`, so the extension gate needs to look through one trailing
`.gz` before choosing a parser; the enum cases stay, so the import source remains `FIT_FILE`.

While in that file: a file whose extension is not an activity format was retried forever, because the
watch directory listing was unfiltered and the branch that caught `UnsupportedFileType` recorded
nothing and deleted nothing. It is fixed by filtering the listing and reporting the leftovers on one
line, not by recording a FAILED `FileImport` and deleting them. A failed import needs an
`ImportSource`, `FileImport.source` is not nullable, and inventing an "unknown" source to fill that
column would put a meaningless option in the activity filter, where every case is a real source. The
column is not the reason though: a parse failure is a file Dreeve tried to read and could not, so
archiving its bytes and removing it is fair, while `notes.txt` is a file that was never an activity
and the watch folder is the user's. Dreeve should not delete what it has no business reading, so it
says what it ignored and leaves it alone.
