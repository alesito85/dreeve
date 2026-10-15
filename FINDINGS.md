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

Three layers, evaluated in order, in a new `ActivityIdentityMatcher`. `DuplicateActivityScanner` is
gone with both of its rules:

1. **File checksum**, SHA-256 of the uploaded bytes. A match means the identical file, so a merge
   would add nothing: skip, which is today's behaviour and the right one for this case.
2. **Device reference**, the primary identity for FIT: manufacturer, product and serial from
   `file_id`, stored as `289-2-22701926` and paired with the activity's `startDateTime` at a 30 s
   tolerance. The start instant is deliberately not baked into the stored key: a device column
   compared for equality plus a start column compared as a range is what makes the match a single
   indexed query, and a composed string key cannot be range searched at all, so the tolerance would
   only exist in prose. The pairing uses the **session** `start_time` rather than
   `file_id.time_created`, because the two differ by a second in these files and because a
   multi-sport file carries one `file_id` with several sessions, so only the session start is unique
   per activity. A rename, a re-export or a truncated sync changes neither the device nor the start
   instant. A `file_id` without a serial stores no reference at all, because a reference without a
   serial names a model rather than a device, and such a file is carried by the content gate alone.
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

**Storage.** Two nullable columns on `Activity`, `fileChecksum VARCHAR(64)` and `deviceReferenceId
VARCHAR(255)`, one index each, the device index being `(deviceReferenceId, startDateTime)` so the
device equality and the start range above are one index seek. `fileChecksum` is the hash of the
bytes as they arrived, gzip wrapper included, stamped by `ParseActivityFile` on every import so the
first import of an old file gives the later ones something to match on. The device reference is set
by the FIT parser itself, in `Activity::create()`, because the `file_id` fields it is built from are
already in scope there; TCX and GPX simply leave it null and no parser interface has to widen.
`DbalActivityRepository::update()` writes a narrower column set than `add()`, so it now carries both
columns as well, otherwise any merge or Strava refresh would quietly erase the identity that later
files are matched against.

**As implemented.** `ActivityIdentityMatcher` holds the three layers and `ResolveActivityIdentity` is
the pipeline step that calls it, at priority 97 so it runs right after parsing and before anything
touches an activity. Two decisions the prose above left open:

- the content gate measures its 5 % against the **incoming** distance, with a 50 m floor so two
  sessions that both have no distance at all, an indoor ride with a dead GPS, still line up instead
  of failing on float noise;
- more than one candidate on the **device** layer is not ambiguity. One device cannot record two
  activities at one instant, so two rows there are an activity Dreeve already duplicated, and the
  file belongs to all of them; the oldest row wins because it is also the one every later run picks.
  Ambiguity, and with it the refusal, only comes from the content gate, where two genuinely different
  activities can fit one file.

The refusal stores the bytes on the SKIPPED `FileImport` row and deletes the file from `watch`, so the
decision can be made later from the import log instead of the file being retried every run.

One honest limit in the start comparisons. `startDateTime` is stored as a wall-clock string in the
timezone the parser used, which is the configured one for every import source, so equal instants do
produce equal strings and the range search is sound. It is not an instant, so if the timezone setting
changes between two imports, a re-imported file's start moves by the offset and the device and content
layers miss. The checksum layer is timezone-proof and still catches the byte-identical case, which is
the common one.

## How the merge should work

The newer file wins wherever both sides have a value, and a hole in the newer file keeps the stored
value, so a re-import primarily adds what is missing. `ActivityStreamMerger` does the streams,
`Activity::mergeWith()` and `ActivityDynamics::mergeWith()` do the columns, `MergeActivityFile`
orchestrates and is the last step in the pipeline (priority 40), after the attribute steps have had
their say about the parsed file:

- the axis is the **union of both files' seconds**, each sample at its own start plus its offset, so
  a truncated file leaves the tail it never recorded intact and a fuller file appends the tail it now
  has. The offset only applies inside the same 30 s window the identity matcher already allowed; a
  larger disagreement between the two starts is a clock that was set wrong, so the files align head to
  head rather than leaving a hole no ride has;
- the axis stays **sparse where the files were sparse**. `ActivityStreamsMapper` deliberately does not
  fill TIME, so a file that recorded every ten seconds has two TIME samples, and merging it with a
  file that recorded the same instants has to give two back. Densifying it would rewrite every stream
  of an activity that did not change and put a version in the history for nothing;
- a second with no value on either side carries the last known value, and a stream that only starts
  reporting later reaches back to the start. That is the parser's own `fillGaps()` rule, and a merged
  stream has to read like a parsed one or a chart shows a hole after the merge where it showed a value
  before it;
- a stream type only the new file carries is added on that axis, which is exactly the case of these
  two files and their per-side pedal smoothness and torque effectiveness;
- a stream type only the existing activity has is kept, including samples past the end of a shorter
  file, and keeps the date it was first stored on. A stream the activity did not have yet is as old as
  the file that finally brought it;
- the `MOVING` stream is dropped rather than stitched, because it is derived from speed and
  `CalculateMovingStream` rebuilds it from the merged timeline, which is the only version that is
  true. No type-scoped delete is needed: the merge deletes all streams and re-adds what it merged;
- every merged stream is re-keyed to the activity being merged into. The parser built them with the id
  of an activity that is never stored, and `add()` is a bare insert on a primary key of activity and
  stream type, so stored streams go first and merged ones follow;
- scalars go newer-wins, `null` parsed values fall back to what is stored, and the monotone ones
  (`maxPower`, `maxSpeed`, `maxHeartRate`) take the larger value. Identity and gear are never touched:
  `externalReferenceId`, `importSource`, gear and the start date stay as they are, and a name or
  description that is already there is not replaced;
- laps and drivetrain usages are replaced wholesale, their primary keys do not add up across versions,
  but only when the new file says something about them. A file with no laps must not delete the ones
  that are stored;
- everything derived from streams is deleted and rebuilt by the metrics steps that already select
  their own pending work: combined streams, splits, best efforts, stream metrics and custom segment
  efforts, plus the custom segment scan markers. The stream scan markers survive, they are what keeps
  a file activity from being fetched from Strava again. Milestones and gear stats read the activity
  columns live, so they follow the newer totals on their own.
- a merged activity is not added to `ImportedActivities`, so the success notification does not report
  it as a new ride, and the watch file is deleted with a SUCCESS `FileImport` row pointing at the
  activity it merged into.

## Versions, so a replaced state is never lost

Before a merge overwrites anything, the state it replaces is written to a new `ActivityRevision`
table: revision number, a compact summary (totals, averages, which stream types exist and how many
samples each has), a link to the `FileImport` row that produced it, and a timestamp. The activity
stays a single row, which is why no read path in the 62 files that query `Activity` with raw SQL has
to change and no aggregate can double count.

A merge that changes nothing records nothing: the retired summary is compared to what the merge
produces and equal means no row. Otherwise a re-export of the same ride that only differs in its
author line (the bytes differ, so it is not the identical-file skip, but the ride is the same) would
put a version in the history and leave the user a list to read for no reason.

Restoring a version replays the bytes that `FileImport` already stores compressed, forced to win
regardless of timestamps, and appends the state it replaced as a new revision so history stays
linear. It deliberately replays rather than re-imports: no automation rules, no weather lookup, no
new row in the import log, because switching to a version of a ride from five years ago must not
re-decide its name, its sport type or its gear, and must not reach out to the network. Two
consequences worth stating: a version whose source was the Strava API has no bytes, so it can be
listed but not restored, and a parser change can make a replay differ slightly from the original
import.

### Where this shows up

The activity page grows an "Activity versions" panel as soon as an activity has history: one row per
version, newest first, with the moment it was made, the file it came from, its distance, time and
average power, and a chip for the data series this version has and the one before it does not. Each
row can be switched to. Switching posts a command to the same `/admin/dispatch-command` endpoint the
admin pages use. That endpoint and its token are metas in the head of an admin page, and the activity
page is not an admin page, so the form carries both itself and the command helper reads them from the
form when the head does not offer them. The token is one of the stateless ones listed in
`config/packages/csrf.yaml`, so it is the same value for every visitor and rendering it into a page
that is cached for all of them puts no session data into shared HTML. The app shell is untouched,
which keeps every public page rendering byte-identically for logged out and logged in visitors; an
earlier attempt that put the metas there made every cached page depend on whether you were logged in,
and `tests/Controller/CacheContextGuardTest.php` caught it. `public/js/dist/app.min.js` and
`admin.min.js` are rebuilt for the helper change.

The admin import log shows how many versions the activity a row wrote has, linked to that panel, and
offers a Re-import on the rows where the file is archived and did not make it in: failed, or skipped
because the match was ambiguous and a human has to decide. A successful row has no Re-import button
on purpose, replaying bytes that already produced this activity would only hit the checksum skip.

Two places where the code ended up elsewhere than the plan:

- the panel has no download link per version. Downloading an archived file is an admin route, so a
  link on a page that is also readable while logged out would either leak the original file or
  return a 404. The file name is shown instead, and the download stays where it already works.
- the include went in the generic sport template only, which turned out to need no extra work: the
  run and swim templates extend it and override narrow blocks (`mainStatsOnTop`, `splitsAndLaps`)
  around it, so one include gives every sport type its history.

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
