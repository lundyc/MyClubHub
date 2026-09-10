<?php
declare(strict_types=1);

require_once __DIR__ . '/comet_match_report.php';

if (!defined('PDF_IMPORT_LEGACY_FILE')) define('PDF_IMPORT_LEGACY_FILE', __DIR__ . '/../data/matches.json');

const PDF_IMPORT_VERSION = 'comet-layout-1';
const PDF_IMPORT_MAX_BYTES = 20 * 1024 * 1024;

function pdf_import_json($value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function pdf_import_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS historical_pdf_imports (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        source_path VARCHAR(1000) NOT NULL, source_name VARCHAR(255) NOT NULL,
        sha256 CHAR(64) NOT NULL, status VARCHAR(24) NOT NULL DEFAULT 'queued',
        parser_version VARCHAR(50) NOT NULL DEFAULT '', report_json LONGTEXT NULL,
        review_json LONGTEXT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL,
        fixture_id INT UNSIGNED NULL, created_fixture TINYINT NOT NULL DEFAULT 0,
        error TEXT NULL, reviewed_by BIGINT UNSIGNED NULL, imported_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_pdf_hash (sha256), KEY idx_pdf_status (status), KEY idx_pdf_fixture (fixture_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS historical_pdf_identities (
        registration_id VARCHAR(32) PRIMARY KEY, player_id INT UNSIGNED NOT NULL,
        source_name VARCHAR(160) NOT NULL, import_id BIGINT UNSIGNED NOT NULL,
        KEY idx_pdf_identity_player (player_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS historical_pdf_audit (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, import_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL, user_id BIGINT UNSIGNED NULL, detail_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_pdf_audit_import (import_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function pdf_import_root(): string
{
    return dirname(__DIR__, 2) . '/PDF_imports';
}

function pdf_import_path(string $relative): string
{
    $root = realpath(pdf_import_root());
    $path = realpath(pdf_import_root() . '/' . $relative);
    if (!$root || !$path || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
        || !is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        throw new RuntimeException('The PDF is missing or is outside PDF_imports.');
    }
    return $path;
}

function pdf_import_scan(PDO $pdo): array
{
    if (!is_dir(pdf_import_root())) {
        throw new RuntimeException('PDF_imports folder was not found.');
    }
    $added = $duplicates = $waiting = 0;
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pdf_import_root(), FilesystemIterator::SKIP_DOTS));
    $insert = $pdo->prepare('INSERT IGNORE INTO historical_pdf_imports (source_path,source_name,sha256) VALUES (?,?,?)');
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'pdf') continue;
        $relative = substr($file->getPathname(), strlen(pdf_import_root()) + 1);
        $path = pdf_import_path($relative);
        if ($file->getMTime() > time() - 10) { $waiting++; continue; }
        if ($file->getSize() > PDF_IMPORT_MAX_BYTES || $file->getSize() < 5) { $waiting++; continue; }
        $handle = fopen($path, 'rb');
        $magic = fread($handle, 5);
        fclose($handle);
        if ($magic !== '%PDF-') { $waiting++; continue; }
        $hash = hash_file('sha256', $path);
        $archive = pdf_import_storage() . '/' . $hash . '.pdf';
        if (!is_file($archive)) {
            $temporary = tempnam(pdf_import_storage(), 'copy-');
            if (!copy($path, $temporary) || hash_file('sha256', $temporary) !== $hash) { @unlink($temporary); $waiting++; continue; }
            chmod($temporary, 0640);
            if (!rename($temporary, $archive)) { @unlink($temporary); throw new RuntimeException('Could not retain the source PDF.'); }
        }
        $insert->execute([$relative, mb_substr($file->getFilename(), 0, 255), $hash]);
        if ($insert->rowCount()) $added++; else $duplicates++;
    }
    return compact('added', 'duplicates', 'waiting');
}

function pdf_import_extract(string $path): array
{
    $command = ['/usr/bin/timeout', '45', '/usr/bin/python3', __DIR__ . '/pdf_import_extract.py', $path];
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['file','/dev/null','a']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start the PDF extractor.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1], 16 * 1024 * 1024);
    fclose($pipes[1]);
    $code = proc_close($process);
    $layout = json_decode($output ?: '', true);
    if ($code !== 0 || !is_array($layout) || isset($layout['error'])) {
        throw new RuntimeException($layout['error'] ?? 'PDF extraction failed or exceeded the 45-second limit.');
    }
    $parsed = comet_report_parse($layout['raw_text']);
    $isHome = str_contains(comet_report_normalize_name($parsed['home_team']), 'saltcoats');
    $isAway = str_contains(comet_report_normalize_name($parsed['away_team']), 'saltcoats');
    if ($isHome === $isAway) throw new RuntimeException('Could not identify Saltcoats Victoria in this report.');
    $report = [
        'home_team' => $parsed['home_team'], 'away_team' => $parsed['away_team'],
        'is_home' => $isHome, 'opponent' => $isHome ? $parsed['away_team'] : $parsed['home_team'],
        'match_date' => $parsed['match_date'], 'kickoff' => $parsed['kickoff'],
        'competition' => $parsed['competition'], 'stage' => $parsed['stage'], 'score' => $parsed['score'],
        'metadata' => [], 'lineups' => ['svfc' => [], 'opponent' => []], 'events' => [],
        'warnings' => $layout['warnings'], 'raw_text' => $layout['raw_text'], 'evidence' => $layout['evidence'],
        'pages' => $layout['pages'],
    ];
    foreach (['venue' => 'Stadium', 'attendance' => 'Attendance', 'age_category' => 'Age category', 'match_number' => 'Match number'] as $key => $label) {
        $report['metadata'][$key] = preg_match('/' . preg_quote($label, '/') . ':[ \t]*([^\n]*)/', $layout['raw_text'], $m) ? trim($m[1]) : '';
    }
    foreach (['MATCH OFFICIALS','STAFF','CONFIRMATIONS','REPORT METADATA'] as $section) {
        $report['metadata'][$section] = $layout['groups'][$section] ?? [];
    }
    foreach (['home', 'away'] as $venueSide) {
        $side = ($venueSide === 'home') === $isHome ? 'svfc' : 'opponent';
        $report['lineups'][$side] = $layout['rosters'][$venueSide];
        foreach (['GOALS','YELLOW CARDS','RED CARDS','SECOND YELLOW','SUBSTITUTIONS'] as $section) {
            $blocks = $layout['groups'][$section][$venueSide] ?? [];
            $text = implode("\n", array_column($blocks, 'text'));
            foreach (comet_report_split_on_minute($text) as $chunk) {
                $event = ['side' => $side, 'source' => $chunk, 'section' => $section];
                if ($section === 'SUBSTITUTIONS') {
                    if (!preg_match('/^(\d{1,3}(?:\+\d{1,2})?)[’\']\s*(\d{1,2})\s+(.+?)\s*(?:chevron_forward|→|›)\s*(\d{1,2})\s+(.+)$/u', $chunk, $m)) {
                        $report['warnings'][] = 'Unreadable substitution: ' . $chunk; continue;
                    }
                    $event += ['minute' => $m[1], 'type' => 'substitution', 'number' => (int)$m[2], 'name' => trim($m[3]), 'on_number' => (int)$m[4], 'on_name' => trim($m[5]), 'note' => ''];
                } else {
                    if (!preg_match('/^(\d{1,3}(?:\+\d{1,2})?)[’\']\s*(\d{1,2})\s+(.+)$/u', $chunk, $m)) {
                        $report['warnings'][] = 'Unreadable event: ' . $chunk; continue;
                    }
                    $nameAndNote = trim($m[3]);
                    $roster = $report['lineups'][$side];
                    $matches = array_values(array_filter($roster, static fn($p) => (int)$p['number'] === (int)$m[2] && str_starts_with(comet_report_normalize_name($nameAndNote), comet_report_normalize_name($p['name']))));
                    if (count($matches) !== 1) {
                        $report['warnings'][] = 'Could not associate event with one player: ' . $chunk; continue;
                    }
                    $name = $matches[0]['name'];
                    $note = trim(mb_substr($nameAndNote, mb_strlen($name)));
                    $type = match ($section) {
                        'YELLOW CARDS' => 'yellow_card', 'RED CARDS' => 'red_card', 'SECOND YELLOW' => 'second_yellow',
                        default => stripos($note, 'own goal') !== false ? 'own_goal' : (preg_match('/penalty|\bpen\b/i', $note) ? 'penalty_scored' : 'goal'),
                    };
                    $event += ['minute' => $m[1], 'number' => (int)$m[2], 'name' => $name, 'type' => $type, 'note' => $note];
                }
                $report['events'][] = $event;
            }
            if (trim($text) !== '' && !preg_match('/\d+[’\']/u', $text)) $report['warnings'][] = 'No timed events could be read in ' . $section . ' (' . $venueSide . ').';
        }
    }
    $totals = ['svfc'=>0,'opponent'=>0];
    foreach ($report['events'] as $event) if (in_array($event['type'],['goal','own_goal','penalty_scored'],true)) {
        $credited = $event['type']==='own_goal' ? ($event['side']==='svfc'?'opponent':'svfc') : $event['side'];
        $totals[$credited]++;
    }
    $goals = $report['is_home'] ? [$totals['svfc'],$totals['opponent']] : [$totals['opponent'],$totals['svfc']];
    $report['coverage'] = $goals === $report['score'] ? 'Lineups and recorded events' : 'Partial events — goal list differs from official score';
    return $report;
}

function pdf_import_validate(array $report): array
{
    $errors = [];
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($report['match_date'] ?? ''));
    if (!$date || $date->format('Y-m-d') !== ($report['match_date'] ?? '')) $errors[] = 'A valid match date is required.';
    if (trim((string)($report['opponent'] ?? '')) === '') $errors[] = 'Opponent is required.';
    if (($report['kickoff']??'')!=='' && !preg_match('/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/',(string)$report['kickoff'])) $errors[] = 'Kickoff must be a valid 24-hour time.';
    $registrations=[];
    foreach (['svfc','opponent'] as $side) {
        $players = $report['lineups'][$side] ?? [];
        $starters = array_filter($players, static fn($p) => !empty($p['starting']));
        if (count($starters) !== 11) $errors[] = $side . ': expected 11 starters; review the source.';
        $numbers = [];
        foreach ($players as $p) {
            if (empty($p['name']) || !preg_match('/^[0-9]{5,12}$/',(string)($p['registration_id']??''))) $errors[] = 'A player is missing a name or valid COMET registration ID.';
            if (isset($registrations[$p['registration_id']])) $errors[] = 'The same registration ID appears twice in this match.';
            $registrations[$p['registration_id']]=true;
            if ((int)$p['number']<1 || (int)$p['number']>99) $errors[] = 'Shirt numbers must be between 1 and 99.';
            if (isset($numbers[$p['number']])) $errors[] = $side . ': duplicate shirt number ' . $p['number'];
            $numbers[$p['number']] = $p;
        }
        $onField = [];
        foreach ($starters as $p) $onField[(int)$p['number']] = true;
        $events = array_values(array_filter($report['events'] ?? [], static fn($e) => $e['side'] === $side));
        usort($events, static fn($a,$b) => pdf_import_minute_sort($a['minute']) <=> pdf_import_minute_sort($b['minute']));
        foreach ($events as $event) {
            $number = (int)($event['number'] ?? 0);
            if (!isset($numbers[$number]) || comet_report_normalize_name($numbers[$number]['name']) !== comet_report_normalize_name($event['name'] ?? '')) $errors[] = 'Event player does not match the lineup: ' . ($event['name'] ?? '?');
            if (!preg_match('/^\d{1,3}(?:\+\d{1,2})?$/', (string)$event['minute'])) $errors[] = 'Invalid event minute.';
            if ($event['type'] === 'substitution') {
                $on = (int)($event['on_number'] ?? 0);
                if (!isset($numbers[$on]) || comet_report_normalize_name($numbers[$on]['name']) !== comet_report_normalize_name($event['on_name'] ?? '')) $errors[] = 'Substitute does not match the bench.';
                if (!isset($onField[$number]) || isset($onField[$on])) $errors[] = 'Substitution conflicts with who was on the pitch.';
                unset($onField[$number]); $onField[$on] = true;
            }
        }
    }
    foreach ($report['score'] ?? [] as $score) if ($score === null || !is_numeric($score) || $score < 0 || $score > 99) $errors[] = 'The official full-time score is required.';
    if (count($report['score'] ?? []) !== 2) $errors[] = 'Both scores are required.';
    return array_values(array_unique($errors));
}

function pdf_import_minute_sort(string $minute): int
{
    $parts = explode('+', $minute);
    return (int)$parts[0] * 100 + (int)($parts[1] ?? 0);
}

function pdf_import_process_one(PDO $pdo): bool
{
    if (!(int)$pdo->query("SELECT GET_LOCK('historical_pdf_extract',0)")->fetchColumn()) return false;
    try {
        $row = $pdo->query("SELECT * FROM historical_pdf_imports WHERE status='queued' ORDER BY id LIMIT 1")->fetch();
        if (!$row) return false;
        try {
            $path = pdf_import_document_path($row);
            if (!hash_equals($row['sha256'], hash_file('sha256',$path))) throw new RuntimeException('The source changed. Scan the folder to queue the new version.');
            $report = pdf_import_extract($path);
            $report['validation'] = pdf_import_validate($report);
            $pdo->prepare("UPDATE historical_pdf_imports SET report_json=?,parser_version=?,status='review',error=NULL WHERE id=?")->execute([pdf_import_json($report),PDF_IMPORT_VERSION,$row['id']]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE historical_pdf_imports SET status='failed',error=? WHERE id=?")->execute([$e->getMessage(),$row['id']]);
        }
        return true;
    } finally { $pdo->query("SELECT RELEASE_LOCK('historical_pdf_extract')"); }
}

function pdf_import_team_key(string $name): string
{
    return trim((string)preg_replace('/\b(?:f c|fc|jfc|a f c|afc|football club)\b/', '', comet_report_normalize_name($name)));
}

function pdf_import_candidates(PDO $pdo, array $report): array
{
    $st = $pdo->prepare('SELECT f.*,s.name AS season_name FROM match_fixtures f JOIN seasons s ON s.id=f.season_id WHERE f.match_date BETWEEN DATE_SUB(?,INTERVAL 7 DAY) AND DATE_ADD(?,INTERVAL 7 DAY) ORDER BY f.match_date,f.id');
    $st->execute([$report['match_date'],$report['match_date']]);
    $rows = [];
    foreach ($st as $row) {
        $row['exact'] = $row['match_date'] === $report['match_date'] && (bool)$row['is_home'] === (bool)$report['is_home'] && pdf_import_team_key($row['opponent']) === pdf_import_team_key($report['opponent']);
        $rows[] = $row;
    }
    usort($rows, static fn($a,$b) => (int)$b['exact'] <=> (int)$a['exact']);
    return $rows;
}

function pdf_import_player_options(PDO $pdo, array $report): array
{
    $players = $pdo->query('SELECT id,name,active,status FROM players ORDER BY name,id')->fetchAll();
    $identities = $pdo->query('SELECT registration_id,player_id FROM historical_pdf_identities')->fetchAll(PDO::FETCH_KEY_PAIR);
    $options = [];
    foreach ($report['lineups']['svfc'] as $p) {
        $id = $p['registration_id'];
        $exact = array_values(array_filter($players, static fn($row) => comet_report_normalize_name($row['name']) === comet_report_normalize_name($p['name'])));
        $selected = isset($identities[$id]) ? (string)$identities[$id] : (count($exact) === 1 ? (string)$exact[0]['id'] : '');
        $options[$id] = ['source'=>$p,'selected'=>$selected,'method'=>isset($identities[$id]) ? 'COMET ID' : ($selected !== '' ? 'Exact name' : 'Needs decision')];
    }
    return ['players'=>$players,'options'=>$options];
}

const PDF_IMPORT_RECORD_TABLES = ['matchday_lineups','matchday_events','matchday_subs','matchday_periods','matchday_formations'];

function pdf_import_snapshot(PDO $pdo, int $fixtureId): array
{
    $st = $pdo->prepare('SELECT * FROM match_fixtures WHERE id=?' . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $st->execute([$fixtureId]);
    $snapshot = ['fixture' => $st->fetch() ?: null, 'legacy' => pdf_import_legacy_row($fixtureId)];
    foreach (PDF_IMPORT_RECORD_TABLES as $table) {
        $st = $pdo->prepare("SELECT * FROM $table WHERE fixture_id=? ORDER BY id" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $st->execute([$fixtureId]);
        $snapshot[$table] = $st->fetchAll();
    }
    return $snapshot;
}

function pdf_import_fingerprint(array $snapshot): string
{
    return hash('sha256', pdf_import_json($snapshot));
}

function pdf_import_has_record(array $snapshot): bool
{
    if ($snapshot['matchday_lineups'] || $snapshot['matchday_events'] || $snapshot['matchday_subs'] || !empty($snapshot['legacy']['starters']) || !empty($snapshot['legacy']['events'])) return true;
    foreach (['starting11_starters_json','starting11_substitutes_json'] as $key) {
        $values = json_decode($snapshot['fixture'][$key] ?? '[]', true);
        if (is_array($values) && array_filter($values)) return true;
    }
    return false;
}

function pdf_import_audit(PDO $pdo, int $id, string $action, ?int $userId, array $detail): void
{
    $pdo->prepare('INSERT INTO historical_pdf_audit (import_id,action,user_id,detail_json) VALUES (?,?,?,?)')->execute([$id,$action,$userId,pdf_import_json($detail)]);
}

function pdf_import_get(PDO $pdo, int $id): array
{
    $st = $pdo->prepare('SELECT * FROM historical_pdf_imports WHERE id=?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('Import not found.');
    return $row;
}

/** Save review server-side; approval is invalidated by any changed source or match. */
function pdf_import_review(PDO $pdo, int $id, array $input, int $userId): void
{
    $row = pdf_import_get($pdo,$id);
    if (!in_array($row['status'],['review','ready'],true)) throw new RuntimeException('This report is not available for review.');
    $report = json_decode($row['report_json'],true,512,JSON_THROW_ON_ERROR);
    $errors = pdf_import_validate($report);
    if ($errors || $report['warnings']) throw new RuntimeException('Extraction needs attention: ' . implode(' ',array_merge($errors,$report['warnings'])));
    $fixtureId = (int)($input['fixture_id'] ?? 0);
    $snapshot = pdf_import_snapshot($pdo,$fixtureId);
    if ($fixtureId && !$snapshot['fixture']) throw new RuntimeException('Fixture not found.');
    if (!hash_equals((string)($input['preview_fingerprint'] ?? ''), pdf_import_fingerprint($snapshot))) throw new RuntimeException('The fixture changed since this preview. Reload it before approving.');
    $fixture = $snapshot['fixture'];
    $seasonId = $fixture ? (int)$fixture['season_id'] : (!empty($input['new_season']) ? 0 : (int)($input['season_id'] ?? 0));
    $st = $pdo->prepare('SELECT * FROM seasons WHERE id=?'); $st->execute([$seasonId]); $season = $st->fetch();
    if (!$season && empty($input['new_season'])) throw new RuntimeException('Choose a season or confirm creation of the proposed historical season.');
    if ($season && !empty($season['is_locked']) && empty($input['locked_ok'])) throw new RuntimeException('Confirm the controlled historical import into this locked season.');
    if ($season && $season['start_date'] && $season['end_date'] && ($report['match_date'] < $season['start_date'] || $report['match_date'] > $season['end_date'])) throw new RuntimeException('The report date is outside the selected season.');
    if ($fixture && ($fixture['match_date'] !== $report['match_date'] || (bool)$fixture['is_home'] !== $report['is_home'] || pdf_import_team_key($fixture['opponent']) !== pdf_import_team_key($report['opponent'])) && empty($input['match_override'])) throw new RuntimeException('The fixture details differ. Confirm that you checked the selected fixture against the PDF.');
    if ($fixture && (bool)$fixture['is_home'] !== $report['is_home']) throw new RuntimeException('Home/away differs. Choose the correct fixture before importing.');
    $mode = (string)($input['mode'] ?? 'missing');
    if (!in_array($mode,['missing','replace','metadata'],true)) throw new RuntimeException('Invalid import mode.');
    if ($mode === 'missing' && pdf_import_has_record($snapshot)) throw new RuntimeException('This fixture already has a match record. Choose preserve or explicitly replace after comparing.');
    $mapping = [];
    if ($mode !== 'metadata') {
        $allowed = $pdo->query('SELECT id FROM players')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($report['lineups']['svfc'] as $p) {
            $choice = (string)($input['players'][$p['registration_id']] ?? '');
            if ($choice !== 'new' && !in_array($choice,array_map('strval',$allowed),true)) throw new RuntimeException('Choose a player or create a historical player for ' . $p['name'] . '.');
            $mapping[$p['registration_id']] = $choice;
        }
        $existingIds = array_filter($mapping,static fn($v) => $v !== 'new');
        if (count($existingIds) !== count(array_unique($existingIds))) throw new RuntimeException('Two players in this report are mapped to the same person.');
    }
    if (empty($input['reviewed'])) throw new RuntimeException('Confirm that you have reviewed the extracted report.');
    $review = ['fixture_id'=>$fixtureId,'season_id'=>$seasonId,'new_season'=>!$season,'mode'=>$mode,'players'=>$mapping,
        'locked_ok'=>!empty($input['locked_ok']),'score_override'=>!empty($input['score_override']),
        'fingerprint'=>pdf_import_fingerprint($snapshot),'source_hash'=>$row['sha256'],'reviewed_by'=>$userId];
    $st=$pdo->prepare("UPDATE historical_pdf_imports SET review_json=?,status='ready',reviewed_by=?,error=NULL WHERE id=? AND status IN ('review','ready') AND report_json=?");
    $st->execute([pdf_import_json($review),$userId,$id,$row['report_json']]);
    if (!$st->rowCount()) throw new RuntimeException('The report changed while you reviewed it. Reload and try again.');
    pdf_import_audit($pdo,$id,'reviewed',$userId,$review);
}

function pdf_import_insert(PDO $pdo, string $table, array $fields): int
{
    $keys=array_keys($fields);
    $sql='INSERT INTO '.$table.' (`'.implode('`,`',$keys).'`) VALUES ('.implode(',',array_fill(0,count($keys),'?')).')';
    $pdo->prepare($sql)->execute(array_values($fields));
    return (int)$pdo->lastInsertId();
}

function pdf_import_apply(PDO $pdo, int $id, int $userId): int
{
    if (!(int)$pdo->query("SELECT GET_LOCK('historical_pdf_apply',10)")->fetchColumn()) throw new RuntimeException('Another import is being saved. Please retry.');
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM historical_pdf_imports WHERE id=? FOR UPDATE'); $st->execute([$id]); $row=$st->fetch();
        if (!$row || $row['status']!=='ready') throw new RuntimeException('Only reviewed, ready reports can be imported.');
        $report=json_decode($row['report_json'],true,512,JSON_THROW_ON_ERROR);
        $review=json_decode($row['review_json'],true,512,JSON_THROW_ON_ERROR);
        if (pdf_import_validate($report) || $report['warnings']) throw new RuntimeException('Report validation failed. Review the report again.');
        if (!hash_equals($row['sha256'],hash_file('sha256',pdf_import_document_path($row)))) throw new RuntimeException('The PDF changed after review. Scan and review the new version.');
        $fixtureId=(int)$review['fixture_id'];
        if ($fixtureId) { $st=$pdo->prepare('SELECT id FROM match_fixtures WHERE id=? FOR UPDATE'); $st->execute([$fixtureId]); }
        $before=pdf_import_snapshot($pdo,$fixtureId);
        if (!hash_equals($review['fingerprint'],pdf_import_fingerprint($before))) throw new RuntimeException('This fixture changed after review. Review it again before importing.');
        $st=$pdo->prepare("SELECT id FROM historical_pdf_imports WHERE fixture_id=? AND status IN ('pending','undo_pending') AND id<>?"); $st->execute([$fixtureId,$id]);
        if ($fixtureId && $st->fetchColumn()) throw new RuntimeException('This fixture has an unfinished import. Wait for it to finish first.');
        $seasonId=(int)$review['season_id'];
        if (!$fixtureId) {
            $st=$pdo->prepare('SELECT * FROM match_fixtures WHERE match_date=? AND is_home=? FOR UPDATE');
            $st->execute([$report['match_date'],(int)$report['is_home']]);
            foreach ($st as $f) if (pdf_import_team_key($f['opponent'])===pdf_import_team_key($report['opponent'])) throw new RuntimeException('A matching fixture now exists. Review and select it instead of creating a duplicate.');
            if ($review['new_season']) {
                $year=(int)substr($report['match_date'],0,4)-((int)substr($report['match_date'],5,2)<7 ? 1 : 0);
                $name=$year.' / '.($year+1);
                $st=$pdo->prepare('SELECT id FROM seasons WHERE name=?'); $st->execute([$name]); $seasonId=(int)$st->fetchColumn();
                if (!$seasonId) $seasonId=pdf_import_insert($pdo,'seasons',['name'=>$name,'start_date'=>"$year-07-01",'end_date'=>($year+1).'-06-30','is_current'=>0,'is_locked'=>1]);
            }
        }
        $st=$pdo->prepare('SELECT * FROM seasons WHERE id=?'); $st->execute([$seasonId]); $season=$st->fetch();
        if (!$season) throw new RuntimeException('Season no longer exists.');
        if ($season['is_locked'] && !$review['locked_ok'] && !$review['new_season']) throw new RuntimeException('Season is now locked. Review and confirm historical import.');
        if (!$fixtureId) {
            $opponentId=null;
            foreach ($pdo->query('SELECT id,clubname FROM match_opponents') as $opp) if (pdf_import_team_key($opp['clubname'])===pdf_import_team_key($report['opponent'])) {
                if ($opponentId!==null) throw new RuntimeException('Multiple opponent records match. Resolve the duplicate opponents first.');
                $opponentId=(int)$opp['id'];
            }
            if (!$opponentId) $opponentId=pdf_import_insert($pdo,'match_opponents',['clubname'=>$report['opponent']]);
            $fixtureId=pdf_import_insert($pdo,'match_fixtures',['season_id'=>$seasonId,'opponent_id'=>$opponentId,'match_date'=>$report['match_date'],
                'kickoff_time'=>$report['kickoff'] ?: null,'opponent'=>$report['opponent'],'competition'=>$report['competition'],
                'competition_stage'=>$report['stage'] ?: null,'venue'=>$report['metadata']['venue'] ?: null,'is_home'=>(int)$report['is_home'],'status'=>'played']);
        }
        $playerIds=[]; $createdPlayers=[]; $identityBefore=[];
        if ($review['mode']!=='metadata') {
            foreach ($report['lineups']['svfc'] as $p) {
                $registration=$p['registration_id'];
                $st=$pdo->prepare('SELECT * FROM historical_pdf_identities WHERE registration_id=? FOR UPDATE'); $st->execute([$registration]); $oldIdentity=$st->fetch() ?: null;
                $identityBefore[$registration]=$oldIdentity;
                $choice=$review['players'][$registration] ?? '';
                if ($choice==='new') {
                    if ($oldIdentity) $playerId=(int)$oldIdentity['player_id'];
                    else {
                        // Another reviewed report may have created the same name since preview.
                        $st=$pdo->prepare('SELECT id FROM players WHERE name=?'); $st->execute([$p['name']]);
                        if ($st->fetchColumn()) throw new RuntimeException('A player named '.$p['name'].' now exists. Review the player mapping again.');
                        $playerId=pdf_import_insert($pdo,'players',['name'=>$p['name'],'avatar'=>'','active'=>0,'status'=>'left']);
                        $createdPlayers[]=$playerId;
                    }
                } else {
                    $playerId=(int)$choice;
                    $st=$pdo->prepare('SELECT id FROM players WHERE id=?'); $st->execute([$playerId]);
                    if (!$st->fetchColumn()) throw new RuntimeException('A selected player no longer exists.');
                }
                if ($oldIdentity && (int)$oldIdentity['player_id']!==$playerId) throw new RuntimeException('COMET ID '.$registration.' is already linked to another player. Review the mapping.');
                if (!$oldIdentity) $pdo->prepare('INSERT INTO historical_pdf_identities (registration_id,player_id,source_name,import_id) VALUES (?,?,?,?)')->execute([$registration,$playerId,$p['name'],$id]);
                $playerIds[$registration]=$playerId;
            }
            if (count($playerIds)!==count(array_unique($playerIds))) throw new RuntimeException('Two report participants resolve to the same player.');
            // Replace the coherent football record only after explicit review. Metadata-only leaves it intact.
            foreach (['matchday_subs','matchday_events','matchday_lineups'] as $table) $pdo->prepare("DELETE FROM $table WHERE fixture_id=?")->execute([$fixtureId]);
            $lineupIds=[]; $names=[];
            foreach ($report['lineups'] as $side=>$players) foreach ($players as $order=>$p) {
                $playerId=$side==='svfc' ? $playerIds[$p['registration_id']] : null;
                $name=$p['name'];
                if ($playerId) { $st=$pdo->prepare('SELECT name FROM players WHERE id=?'); $st->execute([$playerId]); $name=(string)$st->fetchColumn(); }
                $names[$side][$p['number']]=$name;
                $lineupIds[$side][$p['number']]=pdf_import_insert($pdo,'matchday_lineups',[
                    'fixture_id'=>$fixtureId,'side'=>$side,'player_id'=>$playerId,'player_name'=>$name,'shirt_number'=>$p['number'],
                    'position_label'=>in_array($p['marker'],['G','GK'],true)?'GK':null,'is_starting'=>(int)$p['starting'],
                    'is_captain'=>(int)in_array($p['marker'],['C','CP'],true),'sort_order'=>($order+1)*10]);
            }
            foreach ($report['events'] as $sequence=>$event) {
                $side=$event['side']; $own=$event['type']==='own_goal';
                $credited=$own ? ($side==='svfc'?'opponent':'svfc') : $side;
                $minute=explode('+',$event['minute']);
                $onId=$event['type']==='substitution' ? $lineupIds[$side][$event['on_number']] : null;
                $onName=$onId ? $names[$side][$event['on_number']] : '';
                $eventId=pdf_import_insert($pdo,'matchday_events',['fixture_id'=>$fixtureId,'side'=>$credited,'type'=>$event['type'],
                    'minute'=>(int)$minute[0],'minute_extra'=>(int)($minute[1]??0),'player_lineup_id'=>$lineupIds[$side][$event['number']],
                    'player_name'=>$names[$side][$event['number']],'secondary_player_lineup_id'=>$onId,'secondary_player_name'=>$onName,
                    'own_goal'=>(int)$own,'card_type'=>match($event['type']){'yellow_card'=>'yellow','red_card','second_yellow'=>'red',default=>''},
                    'note'=>mb_substr($event['note'],0,500),'sequence'=>$sequence+1,'created_by'=>$userId ?: null]);
                if ($onId) pdf_import_insert($pdo,'matchday_subs',['fixture_id'=>$fixtureId,'side'=>$side,'minute'=>(int)$minute[0],
                    'minute_extra'=>(int)($minute[1]??0),'player_off_lineup_id'=>$lineupIds[$side][$event['number']],
                    'player_on_lineup_id'=>$onId,'player_off_name'=>$names[$side][$event['number']],'player_on_name'=>$onName,'event_id'=>$eventId]);
            }
            $st=$pdo->prepare('SELECT COUNT(*) FROM matchday_periods WHERE fixture_id=?'); $st->execute([$fixtureId]);
            if (!$st->fetchColumn()) foreach ([['first_half','First half',0,45,10],['second_half','Second half',45,90,20]] as $period) {
                pdf_import_insert($pdo,'matchday_periods',['fixture_id'=>$fixtureId,'period_key'=>$period[0],'label'=>$period[1],'start_minute'=>$period[2],'end_minute'=>$period[3],'sort_order'=>$period[4]]);
            }
            $starters=$bench=$numbers=[]; $captain='';
            foreach ($report['lineups']['svfc'] as $p) {
                $name=$names['svfc'][$p['number']];
                if ($p['starting']) $starters[]=$name; else $bench[]=$name;
                $numbers[$name]=$p['number'];
                if (in_array($p['marker'],['C','CP'],true)) $captain=$name;
            }
            $pdo->prepare('UPDATE match_fixtures SET starting11_starters_json=?,starting11_substitutes_json=?,starting11_squad_numbers_json=?,starting11_captain=? WHERE id=?')->execute([pdf_import_json($starters),pdf_import_json($bench),pdf_import_json($numbers),$captain ?: null,$fixtureId]);
        }
        $existing=$before['fixture'] ?? [];
        $updates=[]; $params=[];
        foreach (['kickoff_time'=>$report['kickoff'],'competition'=>$report['competition'],'competition_stage'=>$report['stage'],'venue'=>$report['metadata']['venue']] as $key=>$value) {
            if ($value!=='' && empty($existing[$key])) { $updates[]="$key=?"; $params[]=$value; }
        }
        foreach (['full_time_home_score'=>0,'full_time_away_score'=>1] as $key=>$index) {
            if (($existing[$key]??null)===null || $review['score_override']) { $updates[]="$key=?"; $params[]=$report['score'][$index]; }
        }
        if (!$existing || empty($existing['status']) || $existing['status']==='scheduled' || $review['score_override']) { $updates[]="status='played'"; }
        if ($updates) { $params[]=$fixtureId; $pdo->prepare('UPDATE match_fixtures SET '.implode(',',$updates).' WHERE id=?')->execute($params); }
        $after=pdf_import_snapshot($pdo,$fixtureId);
        $before['identities']=$identityBefore; $before['created_players']=$createdPlayers;
        $pdo->prepare("UPDATE historical_pdf_imports SET fixture_id=?,created_fixture=?,before_json=?,after_json=?,status='pending',imported_at=NOW(),error=NULL WHERE id=?")->execute([$fixtureId,(int)!$review['fixture_id'],pdf_import_json($before),pdf_import_json($after),$id]);
        pdf_import_audit($pdo,$id,'imported',$userId,['fixture_id'=>$fixtureId,'mode'=>$review['mode'],'created_players'=>$createdPlayers]);
        $pdo->commit();
        return $fixtureId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('historical_pdf_apply')"); }
}

function pdf_import_legacy_row(int $fixtureId): ?array
{
    $path=PDF_IMPORT_LEGACY_FILE;
    if (!is_file($path)) return null;
    $handle=fopen($path,'rb');
    if (!$handle) throw new RuntimeException('Could not read the legacy match archive.');
    try {
        if (!flock($handle,LOCK_SH)) throw new RuntimeException('Could not lock the legacy archive.');
        $text=stream_get_contents($handle);
        $rows=json_decode($text ?: '[]',true,512,JSON_THROW_ON_ERROR);
        foreach ($rows as $row) if ((int)($row['id']??0)===$fixtureId) return $row;
        return null;
    } finally { flock($handle,LOCK_UN); fclose($handle); }
}

/** Retryable outbox: update only this fixture while holding the archive file lock. */
function pdf_import_project_one(PDO $pdo): bool
{
    if (!(int)$pdo->query("SELECT GET_LOCK('historical_pdf_apply',0)")->fetchColumn()) return false;
    $handle=null; $temporary=null;
    try {
        $row=$pdo->query("SELECT * FROM historical_pdf_imports WHERE status IN ('pending','undo_pending') AND error IS NULL ORDER BY id LIMIT 1")->fetch();
        if (!$row) return false;
        $id=(int)$row['id']; $fixtureId=(int)$row['fixture_id'];
        $pdo->beginTransaction();
        $before=json_decode($row['before_json'],true,512,JSON_THROW_ON_ERROR);
        $after=json_decode($row['after_json'],true,512,JSON_THROW_ON_ERROR);
        $current=pdf_import_snapshot($pdo,$fixtureId);
        // File projection may already have succeeded before a crash. Compare canonical data separately.
        $expected=$after; unset($expected['legacy']); $canonical=$current; unset($canonical['legacy']);
        if (pdf_import_fingerprint($expected)!==pdf_import_fingerprint($canonical)) throw new RuntimeException('Match changed while display synchronization was pending. Review it before retrying.');
        $path=PDF_IMPORT_LEGACY_FILE;
        $handle=fopen($path.'.lock','c');
        if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Could not lock the legacy match archive.');
        $text=is_file($path)?file_get_contents($path):'[]';
        $rows=json_decode($text ?: '[]',true,512,JSON_THROW_ON_ERROR);
        if (!is_array($rows)) throw new RuntimeException('The legacy match archive is not valid JSON.');
        $index=null;
        foreach ($rows as $i=>$entry) if ((int)($entry['id']??0)===$fixtureId) { $index=$i; break; }
        $old=$index===null ? null : $rows[$index];
        if ($row['status']==='undo_pending') $patch=$before['legacy']??null;
        else {
            $fixture=$current['fixture'];
            $patch=$after['legacy']??['id'=>(string)$fixtureId];
            $patch['status']=$fixture['status'];
            $patch['match_date']=$fixture['match_date'];
            $patch['opponent']=$fixture['opponent'];
            $review=json_decode($row['review_json'],true);
            if ($review['mode']!=='metadata') {
                $patch['starters']=json_decode($fixture['starting11_starters_json']?:'[]',true);
                $patch['substitutes']=json_decode($fixture['starting11_substitutes_json']?:'[]',true);
                $patch['captain']=$fixture['starting11_captain']??'';
                $patch['events']=array_values(array_filter($patch['events']??[],static fn($e)=>!in_array($e['type']??'', ['goal','card','yellow_card','red_card','second_yellow','substitution','sub','penalty_miss','penalty_missed'],true) && !str_starts_with((string)($e['id']??''),'md')));
                foreach ($current['matchday_events'] as $ev) {
                    $type=match($ev['type']) {'own_goal','penalty_scored'=>'goal','yellow_card','red_card','second_yellow'=>'card',default=>$ev['type']};
                    $patch['events'][]=['id'=>'md'.$ev['id'],'type'=>$type,'minute'=>(string)$ev['minute'].($ev['minute_extra']?'+'.$ev['minute_extra']:''),
                        'team'=>$ev['side'],'player'=>$ev['player_name'],'secondary_player'=>$ev['secondary_player_name'],
                        'own_goal'=>(bool)$ev['own_goal'],'card_type'=>$ev['card_type'],'sequence'=>(int)$ev['sequence'],
                        'substitutions'=>$type==='substitution'?[['off'=>$ev['player_name'],'on'=>$ev['secondary_player_name']]]:[]];
                }
            }
        }
        if ($old!==($after['legacy']??null) && $old!==$patch) throw new RuntimeException('The legacy match entry changed after import. Display synchronization stopped to preserve that edit.');
        if ($patch===null) { if ($index!==null) unset($rows[$index]); }
        elseif ($index===null) $rows[]=$patch; else $rows[$index]=$patch;
        $json=pdf_import_json(array_values($rows))."\n";
        $temporary=tempnam(dirname($path),'.pdf-projection-');
        if (!$temporary || file_put_contents($temporary,$json)!==strlen($json)) throw new RuntimeException('Could not stage the legacy match archive. Retry synchronization.');
        chmod($temporary,is_file($path)?(fileperms($path)&0777):0664);
        if (!rename($temporary,$path)) throw new RuntimeException('Could not save the legacy match archive. Retry synchronization.');
        $temporary=null;
        flock($handle,LOCK_UN); fclose($handle); $handle=null;
        $after['legacy']=$patch;
        $pdo->prepare('UPDATE historical_pdf_imports SET status=?,after_json=?,error=NULL WHERE id=?')->execute([$row['status']==='undo_pending'?'undone':'imported',pdf_import_json($after),$id]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (isset($id)) $pdo->prepare('UPDATE historical_pdf_imports SET error=? WHERE id=?')->execute([$e->getMessage(),$id]);
        return true;
    } finally {
        if (is_resource($handle)) { flock($handle,LOCK_UN); fclose($handle); }
        if ($temporary && is_file($temporary)) @unlink($temporary);
        $pdo->query("SELECT RELEASE_LOCK('historical_pdf_apply')");
    }
}

function pdf_import_undo(PDO $pdo, int $id, int $userId): void
{
    if (!(int)$pdo->query("SELECT GET_LOCK('historical_pdf_apply',10)")->fetchColumn()) throw new RuntimeException('Another import is being saved. Retry shortly.');
    try {
        $pdo->beginTransaction();
        $st=$pdo->prepare('SELECT * FROM historical_pdf_imports WHERE id=? FOR UPDATE'); $st->execute([$id]); $row=$st->fetch();
        if (!$row || $row['status']!=='imported') throw new RuntimeException('Only completed imports can be undone.');
        $fixtureId=(int)$row['fixture_id'];
        $before=json_decode($row['before_json'],true,512,JSON_THROW_ON_ERROR);
        $after=json_decode($row['after_json'],true,512,JSON_THROW_ON_ERROR);
        $st=$pdo->prepare('SELECT id FROM match_fixtures WHERE id=? FOR UPDATE'); $st->execute([$fixtureId]);
        if (pdf_import_fingerprint(pdf_import_snapshot($pdo,$fixtureId))!==pdf_import_fingerprint($after)) throw new RuntimeException('This match has been edited since import. Undo is blocked to preserve those changes.');
        $st=$pdo->prepare("SELECT COUNT(*) FROM historical_pdf_audit a JOIN historical_pdf_imports i ON i.id=a.import_id WHERE i.fixture_id=? AND i.id<>? AND i.status IN ('pending','imported','undo_pending') AND a.action='imported' AND a.id>(SELECT COALESCE(MAX(id),0) FROM historical_pdf_audit WHERE import_id=? AND action='imported')");
        $st->execute([$fixtureId,$id,$id]);
        if ($st->fetchColumn()) throw new RuntimeException('A later report was imported into this fixture. Undo that report first.');
        if ($row['created_fixture']) {
            // Refuse deletion if any other fixture-linked feature has acquired data.
            $columns=$pdo->query("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME IN ('fixture_id','match_fixture_id')")->fetchAll();
            foreach ($columns as $column) {
                $table=$column['TABLE_NAME']; $key=$column['COLUMN_NAME'];
                if (in_array($table,array_merge(PDF_IMPORT_RECORD_TABLES,['historical_pdf_imports']),true)) continue;
                if (!preg_match('/^[a-zA-Z0-9_]+$/',$table)) continue;
                $st=$pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `$key`=?"); $st->execute([$fixtureId]);
                if ($st->fetchColumn()) throw new RuntimeException('This created fixture now has related records. Remove or review those before undoing its creation.');
            }
        }
        foreach (['matchday_subs','matchday_events','matchday_lineups','matchday_periods','matchday_formations'] as $table) {
            $pdo->prepare("DELETE FROM $table WHERE fixture_id=?")->execute([$fixtureId]);
            foreach ($before[$table] as $record) pdf_import_insert($pdo,$table,$record);
        }
        if ($before['fixture']) {
            $fields=$before['fixture']; unset($fields['id']);
            $sql='UPDATE match_fixtures SET '.implode(',',array_map(static fn($k)=>"`$k`=?",array_keys($fields))).' WHERE id=?';
            $pdo->prepare($sql)->execute([...array_values($fields),$fixtureId]);
        } else $pdo->prepare('DELETE FROM match_fixtures WHERE id=?')->execute([$fixtureId]);
        $restored=pdf_import_snapshot($pdo,$fixtureId);
        $pdo->prepare("UPDATE historical_pdf_imports SET status='undo_pending',after_json=?,error=NULL WHERE id=?")->execute([pdf_import_json($restored),$id]);
        pdf_import_audit($pdo,$id,'undone',$userId,['fixture_id'=>$fixtureId,'note'=>'Historical player identities, seasons and opponents are retained for other reports.']);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { $pdo->query("SELECT RELEASE_LOCK('historical_pdf_apply')"); }
}

function pdf_import_storage(): string
{
    $path=dirname(__DIR__,3).'/var/pdf_importer/sources';
    if (!is_dir($path) && !mkdir($path,0770,true) && !is_dir($path)) throw new RuntimeException('Could not create the private PDF source archive.');
    return $path;
}

function pdf_import_document_path(array $row): string
{
    if (!preg_match('/^[a-f0-9]{64}$/',$row['sha256'])) throw new RuntimeException('Invalid source hash.');
    $path=pdf_import_storage().'/'.$row['sha256'].'.pdf';
    if (!is_file($path)) throw new RuntimeException('The archived PDF is missing. Rescan PDF_imports to restore it.');
    return $path;
}

/** Corrections keep the original PDF/text and an audited before/after report. */
function pdf_import_correct(PDO $pdo,int $id,array $input,int $userId): void
{
    $row=pdf_import_get($pdo,$id);
    if(!in_array($row['status'],['review','ready'],true))throw new RuntimeException('Only reports awaiting import can be corrected.');
    if(!hash_equals(hash('sha256',$row['report_json']),(string)($input['report_fingerprint']??'')))throw new RuntimeException('This report changed. Reload before editing.');
    $original=json_decode($row['report_json'],true,512,JSON_THROW_ON_ERROR);$report=$original;
    foreach(['match_date','kickoff','opponent','competition','stage'] as $key)$report[$key]=mb_substr(trim((string)($input[$key]??'')),0,150);
    $report['is_home']=($input['is_home']??'')==='1';
    $report['home_team']=$report['is_home']?'Saltcoats Victoria F.C.':$report['opponent'];
    $report['away_team']=$report['is_home']?$report['opponent']:'Saltcoats Victoria F.C.';
    $report['score']=[filter_var($input['home_score']??'',FILTER_VALIDATE_INT),filter_var($input['away_score']??'',FILTER_VALIDATE_INT)];
    $report['metadata']['venue']=mb_substr(trim((string)($input['venue']??'')),0,150);
    foreach(['svfc','opponent'] as $side){
        $report['lineups'][$side]=[];
        foreach(array_slice((array)($input['lineups'][$side]??[]),0,40) as $index=>$p){
            if(trim((string)($p['name']??''))==='')continue;
            $existing=$original['lineups'][$side][$index]??[];
            $report['lineups'][$side][]=array_merge($existing,[
                'name'=>mb_substr(trim((string)$p['name']),0,120),'number'=>(int)($p['number']??0),
                'registration_id'=>trim((string)($p['registration_id']??'')),
                'marker'=>in_array($p['marker']??'',['','G','GK','C','CP','T'],true)?$p['marker']:'',
                'starting'=>!empty($p['starting']),'nationality'=>$existing['nationality']??'',
            ]);
        }
    }
    $report['events']=[];
    foreach(array_slice((array)($input['events']??[]),0,200) as $event){
        if(!empty($event['remove']))continue;
        if(trim((string)($event['minute']??''))==='' && empty($event['number']))continue;
        $side=($event['side']??'')==='svfc'?'svfc':'opponent';
        $type=(string)($event['type']??'');
        if(!in_array($type,['goal','own_goal','penalty_scored','penalty_missed','yellow_card','red_card','second_yellow','substitution'],true))throw new RuntimeException('Unknown event type.');
        $number=(int)($event['number']??0);$on=(int)($event['on_number']??0);$name=$onName='';
        foreach($report['lineups'][$side] as $p){if($p['number']===$number)$name=$p['name'];if($p['number']===$on)$onName=$p['name'];}
        $item=['minute'=>trim((string)$event['minute']),'side'=>$side,'type'=>$type,'number'=>$number,'name'=>$name,
            'note'=>mb_substr(trim((string)($event['note']??'')),0,10000),'source'=>'Manually corrected against PDF'];
        if($type==='substitution'){$item['on_number']=$on;$item['on_name']=$onName;}
        $report['events'][]=$item;
    }
    if(empty($input['corrections_checked']))throw new RuntimeException('Confirm the corrected data was checked against the PDF.');
    $report['warnings']=[];$report['validation']=pdf_import_validate($report);
    $report['coverage']='Manually reviewed match report';
    if($report['validation'])throw new RuntimeException(implode(' ',$report['validation']));
    $pdo->beginTransaction();
    try{
        $st=$pdo->prepare("UPDATE historical_pdf_imports SET report_json=?,review_json=NULL,status='review' WHERE id=? AND status IN ('review','ready') AND report_json=?");
        $st->execute([pdf_import_json($report),$id,$row['report_json']]);
        if(!$st->rowCount())throw new RuntimeException('Report changed while saving corrections.');
        pdf_import_audit($pdo,$id,'corrected',$userId,['before'=>$original,'after'=>$report]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
}
